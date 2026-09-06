<?php

namespace App\Services\ActionLog;

use App\Models\ActionLog;
use App\Models\Server;
use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use App\Services\Companion\CompanionInstaller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single chokepoint for writing rows to the action_logs table.
 *
 * Why a service instead of `ActionLog::create()` calls inline at every site:
 *   - Mirroring the write to Log::info gives us a Laravel log line for free.
 *   - Defaults (actor='manual', ran_at=now()) live in one place.
 *   - The "logging must never throw" guarantee is enforced here, so callers
 *     can drop in a record() call without wrapping it in try/catch.
 *
 * Future home for: tagging by request id, batching writes, dispatching a
 * "new action" notification (Mattermost/email), updating a care-plan-month
 * counter cache. For now: single insert.
 */
class ActionLogger
{
    /**
     * Record an action. Returns the saved model on success, null on any
     * failure — failures are logged via Log::warning so we know they happened
     * but never propagate to the caller. Logging an action MUST NOT break
     * the action it's recording.
     *
     * @param  array<string, mixed>|null  $details
     */
    public function record(
        string $actionType,
        string $summary,
        ?Site $site = null,
        ?Server $server = null,
        ?string $target = null,
        ?array $details = null,
        bool $ok = true,
        ?string $error = null,
        ?int $elapsedMs = null,
        string $actor = 'manual',
        ?Carbon $ranAt = null,
    ): ?ActionLog {
        $ranAt ??= Carbon::now();

        // Mirror to Laravel log first — even if the DB write fails, we still
        // have the action_logs.* line in Laravel's log file.
        Log::info("action_log.{$actionType}", [
            'site_id' => $site?->id,
            'server_id' => $server !== null ? $server->id : $site?->server_id,
            'target' => $target,
            'summary' => $summary,
            'ok' => $ok,
            'error' => $error,
            'elapsed_ms' => $elapsedMs,
            'actor' => $actor,
        ]);

        try {
            $row = ActionLog::create([
                'site_id' => $site?->id,
                'server_id' => $server !== null ? $server->id : $site?->server_id,
                'action_type' => $actionType,
                'target' => $target,
                'summary' => $summary,
                'details' => $details,
                'ok' => $ok,
                'error' => $error,
                'elapsed_ms' => $elapsedMs,
                'actor' => $actor,
                'ran_at' => $ranAt,
            ]);
        } catch (Throwable $e) {
            Log::warning('action_log.write_failed', [
                'action_type' => $actionType,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        // Mirror to Companion whenever it's installed. We deliberately don't
        // gate on `companion_capabilities` containing 'action-log': capabilities
        // only refresh on install/upgrade (see CompanionInstaller::install), so
        // a site whose plugin gained the action-log endpoint AFTER its last
        // install/upgrade run carries a stale capability list and would silently
        // miss every push. Better to attempt the call: the endpoint either
        // exists (push lands), or doesn't (404 -> RuntimeException -> swallowed
        // warning in pushToCompanion). Either way we don't break the action.
        //
        // Server-only actions (no site) don't push — the Companion mirror is a
        // per-site client-facing log, not a fleet log.
        if ($site !== null && $site->companion_installed && ! empty($site->companion_secret)) {
            $this->pushToCompanion($site, $row, $actionType, $target, $summary, $details, $ok, $error, $elapsedMs, $actor, $ranAt);
        }

        return $row;
    }

    /**
     * Maps a CompanionInstaller / PressableCompanionInstaller result array to
     * an action_logs row. Every install call site was hand-rolling its own
     * subset of this mapping — and most of them (CompanionCanaryDeploy,
     * CompanionFleetDeploy, InstallCompanion, InstallCompanionPressable, and
     * even the UI's SitesController::installCompanion) either logged nothing
     * at all or silently dropped RESULT_FAILED. Confirmed live 2026-08-31:
     * two real Pressable install failures (tourscompany.example,
     * auctioneersite.example) left zero action_logs trace anywhere,
     * because CompanionCanaryDeploy never called ActionLogger at all —
     * meaning failed installs were invisible to both the maintenance-history
     * log and any alerting built on top of it. Centralizing here fixes every
     * call site at once.
     *
     * ALREADY_CURRENT and SKIPPED_NOT_WP aren't logged — neither represents
     * a state change worth an audit-log row.
     *
     * @param  array{result: string, message: string, version?: string}  $result
     */
    public function recordCompanionInstall(Site $site, array $result): ?ActionLog
    {
        $outcome = $result['result'] ?? '';
        $version = $result['version'] ?? null;

        [$type, $summary] = match ($outcome) {
            CompanionInstaller::RESULT_INSTALLED => [
                ActionLog::TYPE_COMPANION_INSTALL,
                'Installed Companion'.($version ? " v{$version}" : '')." on {$site->domain}.",
            ],
            CompanionInstaller::RESULT_UPDATED => [
                ActionLog::TYPE_COMPANION_UPDATE,
                'Upgraded Companion to v'.($version ?: '?')." on {$site->domain}.",
            ],
            CompanionInstaller::RESULT_FAILED => [
                ActionLog::TYPE_COMPANION_INSTALL,
                "Companion install failed on {$site->domain}.",
            ],
            default => [null, null],
        };

        if ($type === null) {
            return null;
        }

        return $this->record(
            actionType: $type,
            summary: $summary,
            site: $site,
            target: $version,
            details: $result,
            ok: $outcome !== CompanionInstaller::RESULT_FAILED,
            error: $outcome === CompanionInstaller::RESULT_FAILED ? ($result['message'] ?? null) : null,
        );
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    private function pushToCompanion(
        Site $site,
        ActionLog $row,
        string $actionType,
        ?string $target,
        string $summary,
        ?array $details,
        bool $ok,
        ?string $error,
        ?int $elapsedMs,
        string $actor,
        Carbon $ranAt,
    ): void {
        try {
            (new ClockworkCompanionClient($site))->appendActionLog([
                'action_type' => $actionType,
                'target' => $target,
                'summary' => $summary,
                'details' => $details,
                'ok' => $ok,
                'error' => $error,
                'elapsed_ms' => $elapsedMs,
                'actor' => $actor,
                'care_plan_enabled' => (bool) $site->care_plan_enabled,
                'ran_at' => $ranAt->toIso8601String(),
            ]);
            // Mark the row pushed so the backfill command knows to skip it
            // on future re-syncs. forceFill avoids re-triggering observers.
            $row->forceFill(['companion_pushed_at' => now()])->save();
        } catch (Throwable $e) {
            Log::warning('action_log.companion_push_failed', [
                'site_id' => $site->id,
                'action_type' => $actionType,
                'local_action_log_id' => $row->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
