<?php

namespace App\Console\Commands;

use App\Models\ActionLog;
use App\Models\Server;
use App\Models\ServerUpdateSnapshot;
use App\Services\ActionLog\ActionLogger;
use App\Services\Chat\ChatNotifier;
use App\Services\Servers\AptUpdateProbe;
use App\Services\Servers\ServerUpdater;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drains the queued-update queue. One server per tick by default — apt-get upgrade can
 * take minutes, so a small batch keeps the scheduler responsive and avoids overlapping
 * runs on the same server.
 *
 * Lifecycle for a server:
 *   queued  → running  → completed | failed
 *
 * A failure here writes a `server_update_failed` action_logs row and fires
 * the matching Mattermost/Slack alert — until this was added, a live
 * apt-get failure (as opposed to the process dying mid-run, which
 * ReapStaleServerUpdates already covers) left zero trace anywhere but
 * `last_update_log` on the server row itself, findable only by opening
 * that specific server's Operations page. Success is intentionally NOT
 * logged here — that would be a broader "give server updates the same
 * audit-trail parity as plugin updates" change nobody's asked for yet.
 */
class ProcessServerUpdates extends Command
{
    protected $signature = 'clockwork:process-server-updates
        {--limit=1 : Max servers to process this tick}';

    protected $description = 'Run apt-get update/upgrade on servers marked update_status=queued.';

    public function handle(ServerUpdater $updater, AptUpdateProbe $probe, ActionLogger $logger, ChatNotifier $chatNotifier): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $servers = Server::query()
            ->where('update_status', Server::UPDATE_STATUS_QUEUED)
            ->eligibleForSystemUpdates()
            ->orderBy('update_queued_at')
            ->limit($limit)
            ->get();

        if ($servers->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($servers as $server) {
            // Atomic claim: only flip queued → running if no one else has done it.
            // Without this check a second worker could double-run the same server.
            $claimed = Server::query()
                ->whereKey($server->id)
                ->where('update_status', Server::UPDATE_STATUS_QUEUED)
                ->update([
                    'update_status' => Server::UPDATE_STATUS_RUNNING,
                    'update_started_at' => Carbon::now(),
                ]);

            if (! $claimed) {
                continue;
            }

            $alwaysReboot = $server->scheduled_reboot_at !== null && $server->scheduled_reboot_at->isPast();
            $rebootAt = ($server->scheduled_reboot_at && ! $alwaysReboot)
                ? $server->scheduled_reboot_at->format('H:i')
                : null;

            Log::info('process_server_updates.start', [
                'server' => $server->name,
                'reboot_at' => $rebootAt,
                'always_reboot' => $alwaysReboot,
            ]);

            $result = $updater->update($server->fresh(), $rebootAt, $alwaysReboot);

            Log::info('process_server_updates.finish', [
                'server' => $server->name,
                'ok' => $result['ok'],
                'reboot_required_after' => $result['reboot_required_after'],
                'reboot_scheduled' => $result['reboot_scheduled'],
                'nginx_state_after' => $result['nginx_state_after'],
            ]);

            $server->update([
                'update_status' => $result['ok'] ? Server::UPDATE_STATUS_COMPLETED : Server::UPDATE_STATUS_FAILED,
                'update_completed_at' => Carbon::now(),
                'last_update_log' => $result['output'],
                // Now that we've upgraded, refresh the local mirror of the SpinupWP
                // booleans with the truth from the server itself.
                'reboot_required' => $result['reboot_required_after'],
                'upgrade_required' => false,
            ]);

            if (! $result['ok']) {
                $excerpt = Str::limit((string) $result['output'], 500);

                $logger->record(
                    actionType: ActionLog::TYPE_SERVER_UPDATE_FAILED,
                    summary: "Server update failed on {$server->name}",
                    server: $server,
                    details: $result,
                    ok: false,
                    error: $excerpt,
                    actor: 'scheduled',
                );

                try {
                    $chatNotifier->serverUpdateFailed($server->fresh(), $excerpt ?: 'No output captured.');
                } catch (Throwable $e) {
                    Log::warning('process_server_updates.notify_failed', [
                        'server' => $server->name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Re-poll the freshly-upgraded server so its server_update_snapshots
            // row reflects post-upgrade state. Without this, the Operations →
            // System updates dashboard keeps showing the PRE-upgrade pending
            // counts until the next scheduled poll at 04:15 UTC — making
            // successful upgrades look like no-ops from the UI for up to a
            // day. The poll is the same one PollSystemUpdates fires; we just
            // call it for one server inline so the snapshot stays consistent
            // with the rest of the row's updated fields.
            //
            // Only runs on the success path. On failure the snapshot stays
            // as-is (the failure log is on `last_update_log` for forensics).
            if ($result['ok']) {
                try {
                    $payload = $probe->probe($server->fresh());
                    ServerUpdateSnapshot::updateOrCreate(
                        ['server_id' => $server->id],
                        array_merge($payload, ['polled_at' => Carbon::now()]),
                    );
                } catch (Throwable $e) {
                    Log::warning('process_server_updates.repoll_failed', [
                        'server' => $server->name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $nginxNote = match ($result['nginx_state_after']) {
                'active' => '',
                'recovered' => ' (nginx restarted)',
                'failed' => ' (NGINX FAILED)',
                'absent' => '',
                default => ' (nginx state unknown)',
            };
            $this->info(sprintf(
                '%s: %s%s%s%s',
                $server->name,
                $result['ok'] ? 'OK' : 'FAILED',
                $result['reboot_required_after'] ? ' (reboot pending)' : '',
                $result['reboot_scheduled'] ? ' (reboot scheduled)' : '',
                $nginxNote,
            ));
        }

        return self::SUCCESS;
    }
}
