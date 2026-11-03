<?php

namespace App\Services\Uptime;

use App\Models\ActionLog;
use App\Models\Site;
use App\Models\SiteUptimeEvent;
use App\Services\ActionLog\ActionLogger;
use App\Services\Chat\ChatNotifier;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\AiRemedy\Services\AiRemedyTriager;
use Modules\Core\Contracts\SmsNotifier;
use Modules\Core\ModuleStateResolver;

/**
 * State machine wrapping per-site uptime tracking.
 *
 * Inputs: current Site state (from columns) + a fresh UptimeProbeResult.
 * Outputs: updated Site columns; on transitions, a SiteUptimeEvent row, a
 * Mattermost notification, and an action_log entry.
 *
 * Thresholds (per architecture decision #28):
 *   - up → down: 2 consecutive failures (~10 min at 5-min cadence)
 *   - down → up: 1 success (instant recovery)
 *   - unknown → up: instant, no event/alert (initial state, not a transition)
 *   - unknown → down: 2 failures, but emits a "first detected down" event
 *     (we still want to know about a site we've never seen up)
 *   - up/unknown → probe_blocked: the site answered HTTP 429 (a rate
 *     limiter or anti-bot challenge refused the probe) as many times in a
 *     row as it takes failures to go down. That is neither a failure nor
 *     proof of life: the failure streak is not incremented, last-up is not
 *     set, and the site never goes down on it. A shorter run of 429s leaves
 *     no trace. Entering the state records a probe_blocked event dated at
 *     the run's first 429. Staying blocked for the configured number of
 *     hours sends one ops chat notice. A sub-threshold failure while
 *     blocked leaves the blocked stretch open; a success, maintenance, or a
 *     real down transition ends it.
 *   - down/maintenance → probe_blocked: only after 429s for the configured
 *     number of hours. Until then the outage (or maintenance) stays open
 *     and nothing is recorded or sent: a 429 during a real outage is often
 *     the overloaded origin's rate limiter, so a later failure belongs to
 *     the same outage and a success is its normal recovery (with the
 *     recovery alerts). A run that long closes the outage at the first 429,
 *     so a site that only rate-limits the probe doesn't show days down.
 *   Any answer other than a 429 ends a run of 429s.
 */
class UptimeStateUpdater
{
    /**
     * Default failure threshold before a site transitions to 'down'. The
     * effective value at runtime comes from the `monitoring.uptime_failure_threshold`
     * Settings key; this constant is the fallback default + the seed value
     * displayed on the Monitoring → Settings page until the operator saves.
     */
    public const FAILURE_THRESHOLD_FOR_DOWN = 2;

    /**
     * Hours a site may keep rate-limiting the probe before ops gets a chat
     * notice. Runtime value comes from the Settings key below (Monitoring →
     * Settings); this is the default.
     */
    public const PROBE_BLOCKED_ALERT_HOURS = 6;

    public const SETTING_PROBE_BLOCKED_ALERT_HOURS = 'monitoring.uptime_probe_blocked_alert_hours';

    public function __construct(
        protected ChatNotifier $mattermost,
        protected ActionLogger $actionLogger,
        protected SmsNotifier $sms,
        protected UptimeDiagnostician $diagnostician,
    ) {}

    public function update(Site $site, UptimeProbeResult $probe): void
    {
        $now = Carbon::now();
        $previousState = $site->uptime_state ?? 'unknown';

        if ($probe->isProbeBlocked) {
            $this->handleBlocked($site, $probe, $previousState, $now);
        } elseif ($probe->isMaintenance) {
            $this->handleMaintenance($site, $probe, $previousState, $now);
        } elseif ($probe->succeeded) {
            $this->handleSuccess($site, $probe, $previousState, $now);
        } else {
            $this->handleFailure($site, $probe, $previousState, $now);
        }

        $fields = [
            'uptime_last_checked_at' => $now,
            'uptime_last_status_code' => $probe->statusCode,
        ];

        // Any real answer ends a run of 429s, and the blocked stretch ends
        // once the site has left the probe_blocked state on one (up,
        // maintenance, or a confirmed outage).
        if (! $probe->isProbeBlocked) {
            $fields['uptime_consecutive_blocked'] = 0;
            if ($site->uptime_state !== Site::UPTIME_STATE_PROBE_BLOCKED) {
                $fields['uptime_probe_blocked_since'] = null;
                $fields['uptime_probe_blocked_alerted_at'] = null;
            }
        }

        $site->forceFill($fields)->save();
    }

    /**
     * HTTP 429: the site is refusing the probe, so we can't tell whether it is
     * up. Never counts toward the failure streak, never sets last-up, never
     * opens an outage. See the class docblock for when a run of 429s becomes
     * the probe_blocked state.
     */
    private function handleBlocked(Site $site, UptimeProbeResult $probe, string $previousState, Carbon $now): void
    {
        $run = (int) $site->uptime_consecutive_blocked + 1;
        // The first 429 of the run: anything else in between resets it.
        $blockedSince = $site->uptime_probe_blocked_since ?? $now;

        if ($previousState !== Site::UPTIME_STATE_PROBE_BLOCKED) {
            $confirmedState = in_array($previousState, [Site::UPTIME_STATE_DOWN, 'maintenance'], true);
            $proven = $confirmedState
                ? abs($blockedSince->diffInSeconds($now)) >= $this->probeBlockedAlertHours() * 3600
                : $run >= $this->failureThreshold();

            if (! $proven) {
                // Remember the run; change nothing else. A down site stays
                // down and keeps its failure streak, so a failure after this
                // is the same outage and a success its recovery.
                $site->forceFill([
                    'uptime_consecutive_blocked' => $run,
                    'uptime_probe_blocked_since' => $blockedSince,
                ])->save();

                return;
            }
        }

        $isTransition = $previousState !== Site::UPTIME_STATE_PROBE_BLOCKED;
        $wasDown = $previousState === Site::UPTIME_STATE_DOWN;
        $downSince = $site->uptime_down_since;

        $fields = [
            'uptime_state' => Site::UPTIME_STATE_PROBE_BLOCKED,
            'uptime_probe_blocked_since' => $blockedSince,
            'uptime_consecutive_blocked' => $run,
            'uptime_down_since' => null,
            'uptime_maintenance_since' => null,
        ];

        // Closing an outage resets the streak the way a success would;
        // otherwise a single blip after this would count as the second
        // failure of an outage that has already ended.
        if ($wasDown) {
            $fields['uptime_consecutive_failures'] = 0;
        }

        $site->forceFill($fields)->save();

        if ($isTransition) {
            // Dated at the run's first 429: only 429s came after it, so the
            // event can't overlap another, and the outage it closes ends there.
            $this->recordEvent($site, SiteUptimeEvent::TYPE_PROBE_BLOCKED, $probe, $blockedSince);
            $this->logProbeBlocked(
                $site,
                $probe,
                $wasDown && $downSince !== null ? (int) abs($downSince->diffInSeconds($blockedSince)) : null,
                $blockedSince,
            );
        }

        $this->maybeNotifyProbeBlocked($site, $probe, $blockedSince, $now);
    }

    /** Consecutive failures (and, before probe_blocked, 429s) it takes to change state. */
    private function failureThreshold(): int
    {
        return max(1, (int) app(Settings::class)->get('monitoring.uptime_failure_threshold', self::FAILURE_THRESHOLD_FOR_DOWN));
    }

    private function logProbeBlocked(Site $site, UptimeProbeResult $probe, ?int $closedOutageSec, Carbon $at): void
    {
        $summary = $closedOutageSec !== null
            ? sprintf('%s answers again but is blocking the uptime probe (HTTP %d) — outage closed after %d min; recovery not confirmed', $site->domain, (int) $probe->statusCode, (int) round($closedOutageSec / 60))
            : sprintf('%s is blocking the uptime probe (HTTP %d) — not counted as downtime', $site->domain, (int) $probe->statusCode);

        $this->actionLogger->record(
            actionType: ActionLog::TYPE_UPTIME_TRANSITION,
            summary: $summary,
            site: $site,
            target: Site::UPTIME_STATE_PROBE_BLOCKED,
            details: array_filter([
                'status_code' => $probe->statusCode,
                'retry_after' => $probe->retryAfter,
                'closed_outage_sec' => $closedOutageSec,
            ], fn ($v) => $v !== null),
            ok: false,
            error: $probe->error,
            elapsedMs: $probe->responseTimeMs,
            actor: 'scheduled',
            ranAt: $at,
        );
    }

    /**
     * One chat notice per blocked stretch, once it has lasted the configured
     * number of hours. Ignored sites stay silent (same rule as down alerts);
     * the dispatcher also silences inactive sites.
     */
    private function maybeNotifyProbeBlocked(Site $site, UptimeProbeResult $probe, Carbon $blockedSince, Carbon $now): void
    {
        if ($site->uptime_probe_blocked_alerted_at !== null || $site->isUptimeIgnored()) {
            return;
        }

        $blockedSec = (int) abs($blockedSince->diffInSeconds($now));
        if ($blockedSec < $this->probeBlockedAlertHours() * 3600) {
            return;
        }

        $site->forceFill(['uptime_probe_blocked_alerted_at' => $now])->save();

        try {
            $this->mattermost->uptimeProbeBlocked($site, $blockedSec, $probe->statusCode, $probe->retryAfter);
        } catch (\Throwable $e) {
            Log::warning('uptime.mattermost_probe_blocked_failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }

        $this->actionLogger->record(
            actionType: ActionLog::TYPE_UPTIME_TRANSITION,
            summary: sprintf('%s has blocked the uptime probe for %d h (HTTP %d) — ops notified', $site->domain, intdiv($blockedSec, 3600), (int) $probe->statusCode),
            site: $site,
            target: Site::UPTIME_STATE_PROBE_BLOCKED,
            details: ['status_code' => $probe->statusCode, 'blocked_sec' => $blockedSec, 'notified' => true],
            ok: false,
            error: $probe->error,
            elapsedMs: $probe->responseTimeMs,
            actor: 'scheduled',
        );
    }

    private function probeBlockedAlertHours(): int
    {
        $hours = (int) app(Settings::class)->get(self::SETTING_PROBE_BLOCKED_ALERT_HOURS, self::PROBE_BLOCKED_ALERT_HOURS);

        return max(1, $hours);
    }

    private function handleMaintenance(Site $site, UptimeProbeResult $probe, string $previousState, Carbon $now): void
    {
        $isTransition = $previousState !== 'maintenance';

        $site->forceFill([
            'uptime_state' => 'maintenance',
            'uptime_consecutive_failures' => 0,
            'uptime_down_since' => null,
            'uptime_maintenance_since' => $site->uptime_maintenance_since ?? $now,
        ])->save();

        if ($isTransition) {
            $this->recordEvent($site, SiteUptimeEvent::TYPE_MAINTENANCE, $probe, $now);

            if (! $site->isUptimeIgnored()) {
                $this->fireMaintenanceNotification($site, $probe);
            }
        }
    }

    private function handleSuccess(Site $site, UptimeProbeResult $probe, string $previousState, Carbon $now): void
    {
        $isRecoveryFromDown = $previousState === 'down';
        $isRecoveryFromMaint = $previousState === 'maintenance';
        $isRecoveryFromBlocked = $previousState === Site::UPTIME_STATE_PROBE_BLOCKED;
        $downSince = $site->uptime_down_since;
        $maintSince = $site->uptime_maintenance_since;
        $blockedSince = $site->uptime_probe_blocked_since;

        $site->forceFill([
            'uptime_state' => 'up',
            'uptime_last_up_at' => $now,
            'uptime_consecutive_failures' => 0,
            'uptime_down_since' => null,
            'uptime_maintenance_since' => null,
        ])->save();

        if ($previousState === 'unknown') {
            // First-ever probe success. No Mattermost alert (nothing recovered),
            // but seed an action_log so the Companion's Uptime page has a baseline
            // event to render the green UP circle and 100% over its windows.
            // Without this seed, brand-new sites that have never gone down show
            // an empty hero — accurate but unhelpful.
            $this->seedInitialUpEvent($site, $probe, $now);

            return;
        }

        if ($isRecoveryFromDown) {
            // Carbon 3 returns signed diffs; ordinarily downSince is in the
            // past so this is positive, but an NTP step backwards mid-flight
            // can flip the sign. abs() makes the math sign-independent so
            // recovery alerts never display negative downtime.
            $downtimeSec = $downSince !== null ? (int) abs($downSince->diffInSeconds($now)) : null;
            $this->recordEvent($site, SiteUptimeEvent::TYPE_UP, $probe, $now);

            // Same suppression rule as the down-alert: ignored sites stay
            // silent in both directions. Operator checks the site detail
            // page (or `/monitoring`) for current state when they care.
            if (! $site->isUptimeIgnored()) {
                $this->fireRecoveryNotification($site, $downtimeSec, $probe);
            }
        } elseif ($isRecoveryFromMaint) {
            $maintSec = $maintSince !== null ? (int) abs($maintSince->diffInSeconds($now)) : null;
            $this->recordEvent($site, SiteUptimeEvent::TYPE_UP, $probe, $now);

            if (! $site->isUptimeIgnored()) {
                $this->fireMaintenanceRecoveryNotification($site, $maintSec, $probe);
            }
        } elseif ($isRecoveryFromBlocked) {
            // Closes the blocked stretch in the event log. No chat: nothing
            // was down, and the stretch was never reported as an outage.
            $blockedSec = $blockedSince !== null ? (int) abs($blockedSince->diffInSeconds($now)) : null;
            $this->recordEvent($site, SiteUptimeEvent::TYPE_UP, $probe, $now);

            $this->actionLogger->record(
                actionType: ActionLog::TYPE_UPTIME_TRANSITION,
                summary: "{$site->domain} uptime probe gets through again (was blocked for ".($blockedSec !== null ? (int) round($blockedSec / 60) : 0).' min)',
                site: $site,
                target: 'up',
                details: ['status_code' => $probe->statusCode, 'probe_blocked_sec' => $blockedSec],
                ok: true,
                elapsedMs: $probe->responseTimeMs,
                actor: 'scheduled',
            );
        }
    }

    private function seedInitialUpEvent(Site $site, UptimeProbeResult $probe, Carbon $at): void
    {
        $this->actionLogger->record(
            actionType: ActionLog::TYPE_UPTIME_TRANSITION,
            summary: "{$site->domain} monitoring started — confirmed up",
            site: $site,
            target: 'up',
            details: ['status_code' => $probe->statusCode, 'seed' => true],
            ok: true,
            elapsedMs: $probe->responseTimeMs,
            actor: 'scheduled',
        );
    }

    private function handleFailure(Site $site, UptimeProbeResult $probe, string $previousState, Carbon $now): void
    {
        $newFailures = (int) $site->uptime_consecutive_failures + 1;

        $site->forceFill(['uptime_consecutive_failures' => $newFailures])->save();

        // Transition to 'down' once threshold is crossed (and not already down).
        // Threshold comes from Settings (Monitoring → Settings page) and falls
        // back to the constant default for fresh installs.
        if ($previousState !== 'down' && $newFailures >= $this->failureThreshold()) {
            // Run the SSH diagnostic ONCE per outage at the transition point.
            // If the probe was a bare 503 without headers, but the SSH diagnostician
            // finds maintenance mode active (.maintenance file or nginx maintenance.conf),
            // pivot to maintenance state instead of down.
            $diagnosis = $this->safelyDiagnose($site);

            if ($probe->statusCode === 503 && ($diagnosis['maintenance_mode'] ?? false) === true) {
                $alreadyMaint = $previousState === 'maintenance';

                $site->forceFill([
                    'uptime_state' => 'maintenance',
                    'uptime_down_since' => null,
                    'uptime_maintenance_since' => $site->uptime_maintenance_since ?? $now,
                    'uptime_consecutive_failures' => 0,
                ])->save();

                // Bare nginx `return 503` never matches HTTP signatures, so every
                // subsequent probe still looks like a failure. Without this guard
                // we'd re-enter here every threshold (~10 min) and spam chat.
                if (! $alreadyMaint) {
                    $this->recordEvent($site, SiteUptimeEvent::TYPE_MAINTENANCE, $probe, $now, $diagnosis);

                    if (! $site->isUptimeIgnored()) {
                        $this->fireMaintenanceNotification($site, $probe, $diagnosis);
                    }
                }

                return;
            }

            $site->forceFill([
                'uptime_state' => 'down',
                'uptime_down_since' => $now,
                'uptime_maintenance_since' => null,
            ])->save();

            $this->recordEvent($site, SiteUptimeEvent::TYPE_DOWN, $probe, $now, $diagnosis);

            // Trigger AiRemedy triage (in Watch Mode by default: safe passive diagnosis with zero mutations)
            $this->triggerAiRemedyTriage($site, $probe, $diagnosis);

            // Suppress the alert if this site is being ignored. The ignore flag
            // is the operator saying "I know about this; don't page me." The
            // event log + state column still tell the story, the channel just
            // stays quiet.
            if (! $site->isUptimeIgnored()) {
                $this->fireDownNotification($site, $probe, $diagnosis);
            }
        }
    }

    /**
     * @return ?array<string, mixed>
     */
    private function safelyDiagnose(Site $site): ?array
    {
        try {
            return $this->diagnostician->diagnose($site);
        } catch (\Throwable $e) {
            Log::warning('uptime.diagnose_failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  ?array<string, mixed>  $diagnosis
     */
    private function recordEvent(Site $site, string $type, UptimeProbeResult $probe, Carbon $at, ?array $diagnosis = null): void
    {
        $isExempt = $type === SiteUptimeEvent::TYPE_DOWN && $site->isUptimeSlaExempt();

        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => $type,
            'status_code' => $probe->statusCode,
            'error' => $probe->error,
            'response_time_ms' => $probe->responseTimeMs,
            'diagnosis' => $diagnosis,
            'event_at' => $at,
            'is_sla_exempt' => $isExempt,
            'exemption_reason' => $isExempt ? $site->uptime_exemption_reason : null,
            'exempted_at' => $isExempt ? $at : null,
            'exempted_by' => $isExempt ? 'policy' : null,
        ]);
    }

    /**
     * @param  ?array<string, mixed>  $diagnosis
     */
    private function fireDownNotification(Site $site, UptimeProbeResult $probe, ?array $diagnosis = null): void
    {
        try {
            $this->mattermost->siteWentDown($site, $probe->statusCode, $probe->error, $probe->isLikelyWafBlock(), $diagnosis);
        } catch (\Throwable $e) {
            Log::warning('uptime.mattermost_down_failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }

        // Parallel SMS channel — only fires on care-plan sites and only
        // pages whoever's currently on-call. Wrapped in its own try/catch
        // so a Twilio outage can't take down the Mattermost path or the
        // action_log write below.
        try {
            $this->sms->siteWentDown($site, $probe->statusCode, $probe->error);
        } catch (\Throwable $e) {
            Log::warning('uptime.sms_down_failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }

        $this->actionLogger->record(
            actionType: ActionLog::TYPE_UPTIME_TRANSITION,
            summary: "{$site->domain} went down".($probe->statusCode ? " (HTTP {$probe->statusCode})" : " ({$probe->error})"),
            site: $site,
            target: 'down',
            details: ['status_code' => $probe->statusCode, 'error' => $probe->error, 'diagnosis' => $diagnosis],
            ok: false,
            error: $probe->error,
            actor: 'scheduled',
        );
    }

    private function fireRecoveryNotification(Site $site, ?int $downtimeSec, UptimeProbeResult $probe): void
    {
        try {
            $this->mattermost->siteWentUp($site, $downtimeSec);
        } catch (\Throwable $e) {
            Log::warning('uptime.mattermost_up_failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }

        try {
            $this->sms->siteWentUp($site, $downtimeSec);
        } catch (\Throwable $e) {
            Log::warning('uptime.sms_up_failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }

        $downtimeMin = $downtimeSec !== null ? (int) round($downtimeSec / 60) : 0;
        $this->actionLogger->record(
            actionType: ActionLog::TYPE_UPTIME_TRANSITION,
            summary: "{$site->domain} recovered (was down for {$downtimeMin} min)",
            site: $site,
            target: 'up',
            details: ['status_code' => $probe->statusCode, 'downtime_sec' => $downtimeSec],
            ok: true,
            elapsedMs: $probe->responseTimeMs,
            actor: 'scheduled',
        );
    }

    private function fireMaintenanceNotification(Site $site, UptimeProbeResult $probe, ?array $diagnosis = null): void
    {
        try {
            $this->mattermost->siteEnteredMaintenance($site, $probe->statusCode, $probe->error, $probe->retryAfter);
        } catch (\Throwable $e) {
            Log::warning('uptime.mattermost_maintenance_failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }

        $this->actionLogger->record(
            actionType: ActionLog::TYPE_UPTIME_TRANSITION,
            summary: "{$site->domain} entered scheduled maintenance".($probe->statusCode ? " (HTTP {$probe->statusCode})" : ''),
            site: $site,
            target: 'maintenance',
            details: ['status_code' => $probe->statusCode, 'retry_after' => $probe->retryAfter, 'diagnosis' => $diagnosis],
            ok: true,
            elapsedMs: $probe->responseTimeMs,
            actor: 'scheduled',
        );
    }

    private function fireMaintenanceRecoveryNotification(Site $site, ?int $maintenanceSec, UptimeProbeResult $probe): void
    {
        try {
            $this->mattermost->siteExitedMaintenance($site, $maintenanceSec);
        } catch (\Throwable $e) {
            Log::warning('uptime.mattermost_maintenance_recovered_failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }

        $durationMin = $maintenanceSec !== null ? (int) round($maintenanceSec / 60) : 0;
        $this->actionLogger->record(
            actionType: ActionLog::TYPE_UPTIME_TRANSITION,
            summary: "{$site->domain} exited maintenance mode (was in maintenance for {$durationMin} min)",
            site: $site,
            target: 'up',
            details: ['status_code' => $probe->statusCode, 'maintenance_sec' => $maintenanceSec],
            ok: true,
            elapsedMs: $probe->responseTimeMs,
            actor: 'scheduled',
        );
    }

    /**
     * Trigger passive or autonomous AiRemedy triage when a site outage is confirmed.
     *
     * @param  ?array<string, mixed>  $diagnosis
     */
    private function triggerAiRemedyTriage(Site $site, UptimeProbeResult $probe, ?array $diagnosis = null): void
    {
        if (! class_exists(AiRemedyTriager::class)) {
            return;
        }

        try {
            $resolver = app(ModuleStateResolver::class);
            if (! $resolver->isEnabled('ai-remedy')) {
                return;
            }

            app(AiRemedyTriager::class)->triageSiteDowntime($site, $probe, $diagnosis);
        } catch (\Throwable $e) {
            Log::warning('uptime.ai_remedy_triage_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
