<?php

namespace Modules\Twilio;

use App\Models\NotificationLog;
use App\Models\NotificationRecipient;
use App\Models\Site;
use App\Services\Chat\ChatNotifier;
use App\Services\Twilio\OnCallResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Contracts\SmsNotifier;
use Throwable;

/**
 * High-level SMS dispatcher for site-down / site-up alerts on care-plan
 * sites. Implements the modular SmsNotifier contract so the core app
 * resolves this via the container without knowing Twilio exists.
 *
 * Behavior:
 *   - Care-plan-only filter at the entry point. Non-care-plan sites
 *     skip SMS (chat notifications still fire from the existing path).
 *   - Resolves on-call recipients via OnCallResolver — every recipient
 *     where enabled=true and no off-window currently matches.
 *   - Sends an SMS to each on-call recipient via Twilio.
 *   - If zero recipients are on-call, falls back to email
 *     (clockwork.alerts.email) AND a chat @channel "no on-call
 *     available" warning so the alert is never silently dropped.
 *   - Records each attempt in notification_log for audit.
 */
class TwilioSmsNotifier implements SmsNotifier
{
    public function __construct(
        protected TwilioClient $twilio,
        protected OnCallResolver $resolver,
        protected ChatNotifier $mattermost,
    ) {}

    public function isConfigured(): bool
    {
        return $this->twilio->isConfigured();
    }

    public function fromNumber(): string
    {
        return $this->twilio->fromNumber();
    }

    public function siteWentDown(Site $site, ?int $statusCode, ?string $error): bool
    {
        if (! $site->care_plan_enabled) {
            return false;
        }

        $body = $this->formatDownBody($site, $statusCode, $error);

        return $this->dispatch($site, NotificationLog::EVENT_SITE_DOWN, $body);
    }

    public function siteWentUp(Site $site, ?int $downtimeSec): bool
    {
        if (! $site->care_plan_enabled) {
            return false;
        }

        $body = $this->formatUpBody($site, $downtimeSec);

        return $this->dispatch($site, NotificationLog::EVENT_SITE_UP, $body);
    }

    /**
     * Send a one-off test SMS to a specific recipient. Returns true on
     * success. Used by the "Test SMS" button on /settings/notifications.
     */
    public function test(NotificationRecipient $recipient, string $body = 'Clockwork test message — your phone is configured correctly.'): bool
    {
        try {
            $result = $this->twilio->sms($recipient->phone, $body);
            $this->logAttempt($recipient, null, NotificationLog::EVENT_TEST, $body, true, null, $result['sid'] ?? null);

            return true;
        } catch (Throwable $e) {
            $this->logAttempt($recipient, null, NotificationLog::EVENT_TEST, $body, false, $e->getMessage(), null);

            return false;
        }
    }

    /**
     * How many successful site_down / site_up SMS sends in a 10-minute window
     * trigger the storm circuit-breaker. Keeps a DNS blip or mass-down event
     * from flooding on-call with hundreds of messages.
     */
    public const SMS_STORM_THRESHOLD = 4;

    public const SMS_STORM_WINDOW_MINUTES = 10;

    /**
     * Common dispatch path for site_down and site_up events. Returns
     * true if AT LEAST ONE recipient was successfully texted; false
     * if every channel (SMS, email, chat) failed.
     */
    protected function dispatch(Site $site, string $event, string $body): bool
    {
        if (! $this->twilio->isConfigured()) {
            // SMS path not enabled. Don't fall through to email — chat
            // is already firing from UptimeStateUpdater's existing path, and
            // this method is purely additive.
            return false;
        }

        // Storm circuit-breaker: if >= 4 successful SMS sends went out in the
        // last 10 minutes, pause further sends. A single chat warning fires
        // once per storm window so the on-call team still gets notified.
        $recentSent = NotificationLog::where('ok', true)
            ->whereIn('event', [NotificationLog::EVENT_SITE_DOWN, NotificationLog::EVENT_SITE_UP])
            ->where('sent_at', '>=', now()->subMinutes(self::SMS_STORM_WINDOW_MINUTES))
            ->count();

        if ($recentSent >= self::SMS_STORM_THRESHOLD) {
            $alreadyWarned = NotificationLog::where('event', NotificationLog::EVENT_SMS_STORM_PAUSED)
                ->where('sent_at', '>=', now()->subMinutes(self::SMS_STORM_WINDOW_MINUTES))
                ->exists();

            if (! $alreadyWarned) {
                $this->logAttempt(null, $site, NotificationLog::EVENT_SMS_STORM_PAUSED, $body, true, null, null);
                try {
                    $this->mattermost->send(
                        "⚠️ SMS storm detected: {$recentSent} messages sent in the last "
                        .self::SMS_STORM_WINDOW_MINUTES.' minutes. Pausing SMS alerts to prevent flooding. '
                        .'A mass-outage or DNS failure may be in progress — check /issues. '
                        .'SMS will resume automatically once the storm window passes.'
                    );
                } catch (Throwable $e) {
                    Log::warning('sms.storm_mattermost_failed', ['error' => $e->getMessage()]);
                }
            }

            return false;
        }

        $onCall = $this->resolver->activeAt(Carbon::now());

        if ($onCall->isEmpty()) {
            $this->fallback($site, $event, $body);

            return false;
        }

        $anySuccess = false;
        foreach ($onCall as $recipient) {
            try {
                $result = $this->twilio->sms($recipient->phone, $body);
                $this->logAttempt($recipient, $site, $event, $body, true, null, $result['sid'] ?? null);
                $anySuccess = true;
            } catch (Throwable $e) {
                $this->logAttempt($recipient, $site, $event, $body, false, $e->getMessage(), null);
                Log::warning('sms.send_failed', [
                    'recipient_id' => $recipient->id,
                    'site_id' => $site->id,
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // If every recipient errored (e.g. all numbers invalid, account
        // suspended), fall through to email so the alert isn't lost.
        if (! $anySuccess) {
            $this->fallback($site, $event, $body);
        }

        return $anySuccess;
    }

    /**
     * Email + chat fallback when SMS can't reach anyone — either
     * because no recipient is on-call right now, or because every send
     * failed. Records both as separate notification_log rows so the
     * audit trail captures the chain of attempts.
     */
    protected function fallback(Site $site, string $event, string $body): void
    {
        $email = (string) config('clockwork.alerts.email');
        if ($email !== '') {
            try {
                Mail::raw($body, function ($m) use ($email, $site, $event) {
                    $m->to($email);
                    $m->subject('[Clockwork on-call fallback] '.$event.' — '.$site->domain);
                });
                $this->logAttempt(null, $site, NotificationLog::EVENT_FALLBACK_EMAIL, $body, true, null, null, $email);
            } catch (Throwable $e) {
                $this->logAttempt(null, $site, NotificationLog::EVENT_FALLBACK_EMAIL, $body, false, $e->getMessage(), null, $email);
                Log::error('sms.fallback_email_failed', ['error' => $e->getMessage()]);
            }
        }

        try {
            $this->mattermost->send(
                "🔕 No on-call SMS recipient available for site_event=`{$event}` on **{$site->domain}**. "
                .($email !== '' ? "Email fallback sent to `{$email}`." : 'No email fallback configured.')
            );
            $this->logAttempt(null, $site, NotificationLog::EVENT_ALL_OFF_WARNING, $body, true, null, null);
        } catch (Throwable $e) {
            $this->logAttempt(null, $site, NotificationLog::EVENT_ALL_OFF_WARNING, $body, false, $e->getMessage(), null);
        }
    }

    protected function formatDownBody(Site $site, ?int $statusCode, ?string $error): string
    {
        $tail = $statusCode ? " (HTTP {$statusCode})" : ($error ? " — {$error}" : '');
        // Use the full https:// URL so the SMS client auto-linkifies the
        // domain. Bare domains don't reliably tap-through on most clients.
        $line = "Clockwork: https://{$site->domain} is DOWN{$tail}.";

        // Cap at 320 chars — well within a 2-segment GSM-7 SMS.
        return mb_strimwidth($line, 0, 320, '…');
    }

    protected function formatUpBody(Site $site, ?int $downtimeSec): string
    {
        $duration = $downtimeSec !== null ? ' after '.$this->humanize($downtimeSec) : '';
        $line = "Clockwork: https://{$site->domain} recovered{$duration}.";

        return mb_strimwidth($line, 0, 320, '…');
    }

    protected function humanize(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) {
            return "{$minutes}m";
        }
        $hours = (int) floor($minutes / 60);
        $remainder = $minutes % 60;

        return $remainder === 0 ? "{$hours}h" : "{$hours}h {$remainder}m";
    }

    protected function logAttempt(
        ?NotificationRecipient $recipient,
        ?Site $site,
        string $event,
        string $body,
        bool $ok,
        ?string $error,
        ?string $sid,
        ?string $phoneOverride = null,
    ): void {
        try {
            NotificationLog::create([
                'recipient_id' => $recipient?->id,
                'site_id' => $site?->id,
                'event' => $event,
                'phone' => $phoneOverride ?? $recipient?->phone,
                'ok' => $ok,
                'error' => $error,
                'body' => $body,
                'twilio_sid' => $sid,
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Logging failure mustn't break the notification path itself.
            Log::error('sms.log_write_failed', ['error' => $e->getMessage()]);
        }
    }
}
