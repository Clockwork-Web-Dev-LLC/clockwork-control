<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Servers\ConsoleAccessProvisioner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('clockwork:provision-console-access {--server=* : Limit to one or more servers (id or name; repeatable)} {--all : Run against every non-ignored server} {--show-output : Print the full per-server provisioning log}')]
#[Description('Add a Match-loopback sshd drop-in so the DO Web Console can log in as root via droplet-agent. Validates with sshd -t, reloads (not restarts), auto-rollback on failure.')]
class ProvisionConsoleAccess extends Command
{
    public function handle(ConsoleAccessProvisioner $provisioner): int
    {
        $filters = (array) $this->option('server');

        $query = Server::query()
            ->monitored()
            ->whereNotNull('hostname');

        if (! empty($filters)) {
            $query->where(function ($q) use ($filters) {
                foreach ($filters as $f) {
                    if (ctype_digit((string) $f)) {
                        $q->orWhere('id', (int) $f);
                    } else {
                        $q->orWhere('name', $f);
                    }
                }
            });
        } elseif (! $this->option('all')) {
            $this->error('Provide --server=<id|name> (repeatable) or --all.');

            return self::FAILURE;
        }

        $servers = $query->orderBy('name')->get();

        if ($servers->isEmpty()) {
            $this->info('No servers matched.');

            return self::SUCCESS;
        }

        $this->info("Provisioning console access on {$servers->count()} server(s)…");
        $this->line('  reload (not restart) — current SSH sessions stay alive.');
        $this->line('  sshd -t validates BEFORE reload.');
        $this->line('  auto-rollback if anything fails.');
        $this->newLine();

        $stats = ['already_active' => 0, 'provisioned' => 0, 'rolled_back' => 0, 'ssh_failed' => 0, 'failed' => 0];

        foreach ($servers as $server) {
            $r = $provisioner->provision($server);
            $stats[$r['status']] = ($stats[$r['status']] ?? 0) + 1;
            $icon = match ($r['status']) {
                'already_active' => 'OK ',
                'provisioned' => 'NEW',
                'rolled_back' => 'RB ',
                'ssh_failed' => 'SSH',
                default => '!! ',
            };
            $this->line(sprintf('  [%s] %-40s %s', $icon, $server->name, $r['message']));
            if ($this->option('show-output') || ! $r['ok']) {
                foreach (preg_split('/\R/', mb_substr($r['output'], -1500)) ?: [] as $line) {
                    $this->line('       │ '.$line);
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. already_active=%d provisioned=%d rolled_back=%d ssh_failed=%d failed=%d',
            $stats['already_active'] ?? 0,
            $stats['provisioned'] ?? 0,
            $stats['rolled_back'] ?? 0,
            $stats['ssh_failed'] ?? 0,
            $stats['failed'] ?? 0,
        ));

        $hadFailure = ($stats['failed'] ?? 0) > 0 || ($stats['ssh_failed'] ?? 0) > 0;

        return $hadFailure ? self::FAILURE : self::SUCCESS;
    }
}
