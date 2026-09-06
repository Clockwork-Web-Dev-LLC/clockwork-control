<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\SpinupWp\SpinupWpClient;

#[Signature('clockwork:spinupwp-test', ['clockwork:test-spinupwp'])]
#[Description('Verify the SpinupWP API token by listing servers and sites.')]
class SpinupWpTest extends Command
{
    public function handle(SpinupWpClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('CLOCKWORK_SPINUPWP_TOKEN is not set in .env.');

            return self::FAILURE;
        }

        $this->info('Testing SpinupWP API connection…');

        try {
            $mode = $client->isViewOnly() ? 'View Only (Read-Only)' : 'Full Access (Read/Write)';
            $this->line("  Operating Mode:   <comment>{$mode}</comment>");

            $servers = $client->servers();
            $sites = $client->sites();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Servers visible: '.count($servers));
        $this->info('Sites visible: '.count($sites));

        if ($servers !== []) {
            $this->newLine();
            $this->table(
                ['ID', 'Name', 'IP', 'Provider', 'Status'],
                collect($servers)->take(10)->map(fn (array $s) => [
                    $s['id'] ?? '',
                    $s['name'] ?? '',
                    $s['ip_address'] ?? '',
                    $s['provider_name'] ?? '',
                    $s['connection_status'] ?? ($s['status'] ?? ''),
                ])->all(),
            );

            if (count($servers) > 10) {
                $this->line('… and '.(count($servers) - 10).' more.');
            }
        }

        return self::SUCCESS;
    }
}
