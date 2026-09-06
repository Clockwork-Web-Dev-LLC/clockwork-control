<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Servers\DropletAgentProvisioner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('clockwork:provision-droplet-agent {--server=* : One or more server names or ids} {--all : Run against every non-ignored server} {--restart : Restart the daemon (clears "Registering SSH Keys" hangs)}')]
#[Description('Install/start DigitalOcean droplet-agent so the DO Web Console works.')]
class ProvisionDropletAgent extends Command
{
    public function handle(DropletAgentProvisioner $provisioner): int
    {
        $names = (array) $this->option('server');
        $all = (bool) $this->option('all');

        if (! $all && empty($names)) {
            $this->error('Provide --server=<id|name> (repeatable) or --all.');

            return self::FAILURE;
        }

        $query = Server::query()
            ->monitored()
            ->whereNotNull('hostname');

        if (! $all) {
            $query->where(function ($q) use ($names) {
                foreach ($names as $n) {
                    if (ctype_digit((string) $n)) {
                        $q->orWhere('id', (int) $n);
                    } else {
                        $q->orWhere('name', $n);
                    }
                }
            });
        }

        $servers = $query->orderBy('name')->get();

        if ($servers->isEmpty()) {
            $this->info('No servers matched.');

            return self::SUCCESS;
        }

        $action = $this->option('restart') ? 'Restarting' : 'Provisioning';
        $this->info("$action droplet-agent on {$servers->count()} server(s)…");

        $stats = [];

        foreach ($servers as $server) {
            $r = $this->option('restart') ? $provisioner->restart($server) : $provisioner->provision($server);
            $stats[$r['status']] = ($stats[$r['status']] ?? 0) + 1;
            $this->line(sprintf('  %-40s %s', $server->name, $r['status']));
            if (! $r['ok']) {
                $this->line('      ! '.mb_substr($r['output'], -300));
            }
        }

        $this->info('Done. '.collect($stats)->map(fn ($v, $k) => "$k=$v")->implode(' '));

        return self::SUCCESS;
    }
}
