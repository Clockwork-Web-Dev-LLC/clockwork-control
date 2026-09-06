<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\Pressable\PressableClient;

#[Signature('clockwork:pressable-test', ['clockwork:test-pressable'])]
#[Description('Verify the Pressable API credentials by fetching account info and listing sites.')]
class PressableTest extends Command
{
    public function handle(PressableClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('CLOCKWORK_PRESSABLE_CLIENT_ID / CLOCKWORK_PRESSABLE_CLIENT_SECRET are not set in .env.');

            return self::FAILURE;
        }

        $this->info('Testing Pressable API connection…');

        try {
            $mode = $client->isViewOnly() ? 'View Only (Read-Only)' : 'Full Access (Read/Write)';
            $this->line("  Operating Mode:   <comment>{$mode}</comment>");

            $account = $client->account();
            $sites = $client->sites();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Organization: '.($account['organization'] ?? 'unknown'));
        $this->info('Sites visible: '.count($sites));

        if ($sites !== []) {
            $this->newLine();
            $this->table(
                ['ID', 'Domain', 'State', 'PHP', 'Datacenter'],
                collect($sites)->take(10)->map(fn (array $s) => [
                    $s['id'] ?? '',
                    $s['url'] ?? '',
                    $s['state'] ?? '',
                    $s['phpVersion'] ?? '',
                    $s['datacenterCode'] ?? '',
                ])->all(),
            );

            if (count($sites) > 10) {
                $this->line('… and '.(count($sites) - 10).' more.');
            }
        }

        return self::SUCCESS;
    }
}
