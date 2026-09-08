<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\ServerUpdateSnapshot;
use App\Services\Servers\AptUpdateProbe;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily probe of "what apt updates are pending?" — runs against servers the
 * SpinupWP mirror has already flagged with `upgrade_required = true`, plus
 * every non-SpinupWP-managed server (spinupwp_id null), since nothing else
 * ever sets that flag for GridPane/Hetzner/custom-VPS boxes. Idempotent:
 * each run upserts one row per server in server_update_snapshots (via the
 * unique server_id constraint).
 *
 * Operator visibility: the per-server Updates tab reads the latest snapshot
 * to render "12 updates, 3 security, reboot pending: kernel libc6". That
 * lets the operator see how far behind a box is before deciding to run
 * updates, instead of clicking blind.
 */
#[Signature('clockwork:poll-system-updates {--server= : Limit to one server (id or name)} {--all : Probe every non-ignored server, not just upgrade_required=true}')]
#[Description('SSH-poll Ubuntu fleet for apt-check counts and reboot-required packages. Persists one snapshot per server.')]
class PollSystemUpdates extends Command
{
    public function handle(AptUpdateProbe $probe): int
    {
        $query = Server::query()
            ->whereNotNull('hostname')
            ->where('is_ignored', false);

        if (! $this->option('all')) {
            // upgrade_required is only ever populated by the SpinupWP import
            // mirror — a GridPane/Hetzner/custom-VPS server never gets it
            // set to true by anything, so it would otherwise never be
            // probed until the weekly --all sweep. Always include servers
            // with no spinupwp_id (i.e. not SpinupWP-managed) alongside
            // anything SpinupWP has actually flagged.
            $query->monitored()->where(function ($q) {
                $q->where('upgrade_required', true)
                    ->orWhereNull('spinupwp_id');
            });
        }

        if ($filter = $this->option('server')) {
            $query->where(function ($q) use ($filter) {
                if (ctype_digit((string) $filter)) {
                    $q->where('id', (int) $filter);
                } else {
                    $q->where('name', $filter);
                }
            });
        }

        $servers = $query->get();

        if ($servers->isEmpty()) {
            $this->info('No servers match — nothing to poll.');

            return self::SUCCESS;
        }

        $this->info("Polling {$servers->count()} server(s)…");

        $stats = ['ok' => 0, 'ssh_failed' => 0, 'parse_failed' => 0];

        foreach ($servers as $server) {
            try {
                $payload = $probe->probe($server);

                ServerUpdateSnapshot::updateOrCreate(
                    ['server_id' => $server->id],
                    array_merge($payload, ['polled_at' => now()]),
                );

                // Sync the denormalised flags on the server row from the fresh
                // SSH probe. Without this, SpinupWP's nightly view of these
                // flags wins — and SpinupWP can lag for days after unattended-
                // upgrades or a manual `apt upgrade` clears the queue, leaving
                // ghost rows on /issues' Patches Available + Reboot Required
                // cards. The Companion-side apt-check is closer to ground truth.
                // Only act on a successful probe; a parse_failed / ssh_failed
                // shouldn't clobber the SpinupWP-sourced state.
                if ($payload['poll_status'] === ServerUpdateSnapshot::STATUS_OK) {
                    $server->forceFill([
                        'upgrade_required' => ($payload['total_updates'] > 0),
                        'reboot_required' => (bool) $payload['reboot_required'],
                    ])->save();
                }

                $stats[$payload['poll_status']] = ($stats[$payload['poll_status']] ?? 0) + 1;

                $this->line(sprintf(
                    '  %-40s %s  total=%d security=%d reboot=%s',
                    $server->name,
                    str_pad($payload['poll_status'], 13),
                    $payload['total_updates'],
                    $payload['security_updates'],
                    $payload['reboot_required'] ? 'yes' : 'no',
                ));

                if ($payload['poll_status'] !== ServerUpdateSnapshot::STATUS_OK && $payload['poll_error']) {
                    $this->line('      ! '.mb_substr($payload['poll_error'], 0, 200));
                }
            } catch (\Throwable $e) {
                $stats['ssh_failed'] = ($stats['ssh_failed'] ?? 0) + 1;

                ServerUpdateSnapshot::updateOrCreate(
                    ['server_id' => $server->id],
                    [
                        'total_updates' => 0,
                        'security_updates' => 0,
                        'reboot_required' => false,
                        'reboot_required_pkgs' => [],
                        'upgradable_pkgs' => [],
                        'poll_status' => ServerUpdateSnapshot::STATUS_SSH_FAILED,
                        'poll_error' => mb_substr($e->getMessage(), 0, 2000),
                        'polled_at' => now(),
                    ],
                );

                $this->line(sprintf(
                    '  %-40s %s  error=%s',
                    $server->name,
                    str_pad(ServerUpdateSnapshot::STATUS_SSH_FAILED, 13),
                    mb_substr($e->getMessage(), 0, 200),
                ));
            }
        }

        $this->info(sprintf(
            'Done. ok=%d ssh_failed=%d parse_failed=%d',
            $stats['ok'],
            $stats['ssh_failed'],
            $stats['parse_failed'],
        ));

        return self::SUCCESS;
    }
}
