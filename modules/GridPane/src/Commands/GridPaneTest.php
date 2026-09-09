<?php

namespace Modules\GridPane\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\GridPane\GridPaneClient;

#[Signature('clockwork:test-gridpane')]
#[Description('Verify the GridPane API key by checking user account, servers, and sites.')]
class GridPaneTest extends Command
{
    public function handle(GridPaneClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('GRIDPANE_API_KEY is not configured in .env or Settings.');

            return self::FAILURE;
        }

        $this->info('Testing GridPane API connection…');

        try {
            $user = $client->user();
            $email = $user['email'] ?? $user['data']['email'] ?? $user['name'] ?? 'Authorized';
            $this->info("Authenticated as: {$email}");

            $mode = $client->isViewOnly() ? 'View Only (Read-Only)' : 'Full Access (Read/Write)';
            $this->line("  Operating Mode:   <comment>{$mode}</comment>");

            $servers = $client->servers();
            $sites = $client->sites();
        } catch (\Throwable $e) {
            $this->error('API Request Failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('  Servers visible: '.count($servers));
        $this->line('  Sites visible:   '.count($sites));
        if ($client->wasPartial()) {
            $this->warn('  Note: the last fetch above stopped early after hitting API errors mid-pagination; counts may be incomplete.');
        }

        if ($servers !== []) {
            $this->newLine();
            $this->table(
                ['ID', 'Label / Name', 'IP', 'Datacenter', 'Webserver', 'Database'],
                collect($servers)->take(10)->map(fn (array $s) => [
                    $s['id'] ?? '',
                    $s['label'] ?? $s['name'] ?? '',
                    $s['ip'] ?? $s['server_ip'] ?? '',
                    $s['datacenter'] ?? $s['region'] ?? '',
                    $s['webserver'] ?? 'nginx',
                    $s['database'] ?? 'percona',
                ])->all(),
            );

            if (count($servers) > 10) {
                $this->line('… and '.(count($servers) - 10).' more.');
            }
        }

        if ($sites !== []) {
            $this->newLine();
            $this->table(
                ['ID', 'Domain / URL', 'Server ID', 'System User'],
                collect($sites)->take(10)->map(fn (array $s) => [
                    $s['id'] ?? '',
                    $s['url'] ?? $s['domain'] ?? '',
                    $s['server_id'] ?? '',
                    $s['system_user'] ?? $s['user'] ?? 'gridpane',
                ])->all(),
            );

            if (count($sites) > 10) {
                $this->line('… and '.(count($sites) - 10).' more.');
            }
        }

        $this->newLine();
        $this->info('GridPane API connection test successful!');

        return self::SUCCESS;
    }
}
