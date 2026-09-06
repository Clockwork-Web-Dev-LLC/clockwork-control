<?php

namespace Modules\Cloudways\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\Cloudways\CloudwaysClient;

#[Signature('clockwork:cloudways-test', ['clockwork:test-cloudways'])]
#[Description('Verify the Cloudways API credentials by fetching token and listing servers and apps.')]
class CloudwaysTest extends Command
{
    public function handle(CloudwaysClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('CLOCKWORK_CLOUDWAYS_API_KEY / CLOCKWORK_CLOUDWAYS_EMAIL are not set in .env.');

            return self::FAILURE;
        }

        $this->info('Testing Cloudways API connection…');

        try {
            $mode = $client->isViewOnly() ? 'View Only (Read-Only)' : 'Full Access (Read/Write)';
            $this->line("  Operating Mode:   <comment>{$mode}</comment>");

            $servers = $client->servers();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $appCount = 0;
        foreach ($servers as $s) {
            $appCount += count($s['apps'] ?? []);
        }

        $this->line('  Servers visible: '.count($servers));
        $this->line('  Apps visible:    '.$appCount);

        if ($servers !== []) {
            $this->newLine();
            $this->table(
                ['ID', 'Label / Name', 'IP', 'Cloud', 'Status', 'Apps'],
                collect($servers)->take(10)->map(fn (array $s) => [
                    $s['id'] ?? '',
                    $s['label'] ?? ($s['name'] ?? ''),
                    $s['public_ip'] ?? ($s['server_ip'] ?? ''),
                    $s['cloud'] ?? ($s['provider'] ?? ''),
                    $s['status'] ?? '',
                    count($s['apps'] ?? []),
                ])->all(),
            );

            if (count($servers) > 10) {
                $this->line('… and '.(count($servers) - 10).' more.');
            }
        }

        return self::SUCCESS;
    }
}
