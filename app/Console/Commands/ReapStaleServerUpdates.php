<?php

namespace App\Console\Commands;

use App\Models\ActionLog;
use App\Models\Server;
use App\Services\ActionLog\ActionLogger;
use App\Services\Chat\ChatNotifier;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Safety net for the rare case the apt-update SSH session dies mid-upgrade
 * — laptop sleep, network drop, OOM kill of the artisan worker, or the
 * remote box rebooting on its own mid-`apt-get upgrade`. The server's
 * `update_status` row would otherwise stay 'running' indefinitely, the
 * per-row badge would lie, and the row couldn't be re-queued until
 * somebody manually reset it. Real incident: web47 sat in 'running'
 * for FOUR WEEKS before anyone noticed.
 *
 * Threshold: 2 hours since update_started_at. `apt-get update && upgrade`
 * on a server with many pending packages can legitimately take 5-30 min
 * (post-install scripts on big libc / nginx / database upgrades, slow
 * mirrors, etc.); we want the threshold well past the worst legit case
 * so we never reap a real long-running update. 2 hours is comfortable.
 *
 * Scheduled hourly. Idempotent — re-running over an already-reaped row
 * is a no-op (filter is status='running' AND started_at < threshold).
 *
 * Stale rows are flipped to 'failed' (not 'completed') because we don't
 * know whether the upgrade actually succeeded on the box. Operator can
 * re-queue from the Operations → System updates page; next snapshot
 * poll will reveal the true post-upgrade state.
 *
 * Alerting: pings Mattermost/Slack (`server_update_failed`) for every
 * reaped row — this is exactly the "web47 sat unnoticed for 4 weeks"
 * incident above, and until this was added, reaping it left zero trace
 * anywhere but the action_logs row. Unlike ReapStaleUpdateJobs there's no
 * nightly-vs-manual distinction to gate on: server updates are always
 * queued manually from the Operations page but then run asynchronously via
 * the scheduled clockwork:process-server-updates, so the operator isn't
 * watching a live progress bar the way they are on /updates — always alert.
 */
class ReapStaleServerUpdates extends Command
{
    public const STALE_THRESHOLD_MINUTES = 120;

    protected $signature = 'clockwork:reap-stale-server-updates';

    protected $description = 'Flip server rows stuck in `update_status=running` for >2h to `failed` so they can be re-queued.';

    public function handle(ActionLogger $log, ChatNotifier $chatNotifier): int
    {
        $threshold = Carbon::now()->subMinutes(self::STALE_THRESHOLD_MINUTES);

        $stale = Server::query()
            ->where('update_status', Server::UPDATE_STATUS_RUNNING)
            ->where('update_started_at', '<', $threshold)
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stale running server updates.');

            return self::SUCCESS;
        }

        $now = Carbon::now();
        foreach ($stale as $server) {
            $stuckFor = $server->update_started_at?->diffForHumans($now, CarbonInterface::DIFF_ABSOLUTE) ?? 'unknown';
            $reason = "reaped: stuck in running for {$stuckFor} (threshold ".self::STALE_THRESHOLD_MINUTES.'m)';

            $server->forceFill([
                'update_status' => Server::UPDATE_STATUS_FAILED,
                'update_completed_at' => $now,
                'last_update_log' => $reason,
            ])->save();

            // Audit log so the operator has a trail when they come back to
            // /operations/system-updates and find a row in 'failed' state.
            $log->record(
                actionType: ActionLog::TYPE_SERVER_UPDATE_REAPED,
                summary: "Reaped stale server update on {$server->name} (was running for {$stuckFor})",
                server: $server,
                details: ['stuck_for_minutes' => $server->update_started_at?->diffInMinutes($now)],
                ok: false,
                actor: 'scheduled',
            );

            $this->warn("  Reaped {$server->name} (stuck for {$stuckFor})");

            try {
                $chatNotifier->serverUpdateFailed($server, $reason);
            } catch (Throwable $e) {
                Log::warning('reap_stale_server_updates.notify_failed', [
                    'server_id' => $server->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Reaped {$stale->count()} stale server update(s).");

        return self::SUCCESS;
    }
}
