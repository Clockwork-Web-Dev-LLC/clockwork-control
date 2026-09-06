<?php

namespace Modules\WPEngine\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\WPEngine\WPEngineClient;

#[Signature('clockwork:wpengine-test', ['clockwork:test-wpengine'])]
#[Description('Verify the WP Engine API credentials by checking authentication and listing installs.')]
class WPEngineTest extends Command
{
    public function handle(WPEngineClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('CLOCKWORK_WPENGINE_API_USER_ID / CLOCKWORK_WPENGINE_API_PASSWORD are not set in .env.');

            return self::FAILURE;
        }

        $this->info('Testing WP Engine API connection…');

        try {
            $mode = $client->isViewOnly() ? 'View Only (Read-Only)' : 'Full Access (Read/Write)';
            $this->line("  Operating Mode:   <comment>{$mode}</comment>");

            $probe = $client->probe();
            $total = $probe['count'] ?? 0;
            $installs = $client->installs(10);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('  Installs visible: '.($total ?: count($installs)));

        if ($installs !== []) {
            $this->newLine();
            $this->table(
                ['ID', 'Name', 'Environment', 'Primary Domain', 'Status'],
                collect($installs)->take(10)->map(fn (array $i) => [
                    $i['id'] ?? '',
                    $i['name'] ?? '',
                    $i['environment'] ?? '',
                    $i['primary_domain'] ?? '',
                    $i['status'] ?? 'active',
                ])->all(),
            );

            if (count($installs) > 10) {
                $this->line('… and '.(count($installs) - 10).' more.');
            }
        }

        return self::SUCCESS;
    }
}
