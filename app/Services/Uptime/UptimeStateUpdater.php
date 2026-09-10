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
use Modules\Core\Contracts\SmsNotifier;

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

        if ($probe->isMaintenance) {
            $this->handleMaintenance($site, $probe, $previousState, $now);
        } elseif ($probe->succeeded) {
            $this->handleSuccess($site, $probe, $previousState, $now);
        } else {
            $this->handleFailure($site, $probe, $previousState, $now);
        }

        $site->forceFill([
            'uptime_last_checked_at' => $now,
            'uptime_last_status_code' => $probe->statusCode,
        ])->save();
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
        $downSince = $site->uptime_down_since;
        $maintSince = $site->uptime_maintenance_since;

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
        $threshold = (int) app(Settings::class)->get(
            'monitoring.uptime_failure_threshold',
            self::FAILURE_THRESHOLD_FOR_DOWN,
        );
        if ($previousState !== 'down' && $newFailures >= $threshold) {
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
}
