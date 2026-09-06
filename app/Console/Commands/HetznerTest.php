<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\Hetzner\HetznerClient;

#[Signature('clockwork:hetzner-test')]
#[Description('Verify the Hetzner Cloud API token by listing servers in the project.')]
class HetznerTest extends Command
{
    public function handle(HetznerClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('CLOCKWORK_HETZNER_TOKEN is not set in .env.');

            return self::FAILURE;
        }

        try {
            $locations = $client->account();
            $servers = $client->servers();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Token authenticated. Locations visible: '.count($locations).'.');
        $this->info('Servers visible: '.count($servers));

        if ($servers !== []) {
            $this->newLine();
            $this->table(
                ['ID', 'Name', 'Type', 'Datacenter', 'Status', 'Public IPv4'],
                collect($servers)->take(10)->map(fn (array $s) => [
                    $s['id'] ?? '',
                    $s['name'] ?? '',
                    $s['server_type']['name'] ?? '',
                    $s['datacenter']['name'] ?? '',
                    $s['status'] ?? '',
                    $s['public_net']['ipv4']['ip'] ?? '',
                ])->all(),
            );

            if (count($servers) > 10) {
                $this->line('… and '.(count($servers) - 10).' more.');
            }
        }

        return self::SUCCESS;
    }
}
