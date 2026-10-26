<?php

namespace Modules\TurnkeyWp\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\TurnkeyWp\TurnkeyWpClient;

#[Signature('clockwork:test-turnkeywp')]
#[Description('Verify the TurnkeyWP API token and show which servers and sites it can see. Read-only.')]
class TurnkeyWpTest extends Command
{
    public function handle(TurnkeyWpClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('TURNKEYWP_TOKEN is not configured in .env or Settings.');

            return self::FAILURE;
        }

        $this->info('Testing TurnkeyWP API at '.$client->baseUrl().' …');

        try {
            $user = $client->user();
            $this->info('Authenticated as: '.($user['email'] ?? $user['name'] ?? 'unknown user'));
            $this->line('  Operating mode:  <comment>'.($client->isViewOnly() ? 'View Only (Read-Only)' : 'Full Access (Read/Write)').'</comment>');

            $servers = $client->servers();
            $serversPartial = $client->wasPartial();
            $sites = $client->sites();
            $sitesPartial = $client->wasPartial();
        } catch (\Throwable $e) {
            $this->error('API request failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('  Servers visible: '.count($servers));
        $this->line('  Sites visible:   '.count($sites));
        $this->line('  <comment>TurnkeyWP only lists what this token\'s user can see. Use an Owner\'s token to import the whole fleet.</comment>');
        if ($serversPartial || $sitesPartial) {
            $this->warn('  A listing stopped early after API errors; counts may be incomplete.');
        }

        if ($servers !== []) {
            $this->newLine();
            $this->table(
                ['ID', 'Name', 'IP', 'Provider', 'Status', 'SSH user', 'Sites'],
                collect($servers)->map(fn (array $s) => [
                    $s['id'] ?? '',
                    $s['name'] ?? '',
                    $s['ip_address'] ?? '',
                    $s['provider_name'] ?? '',
                    $s['status'] ?? '',
                    $s['ssh_user'] ?? '',
                    $s['sites_count'] ?? '',
                ])->all(),
            );
        }

        if ($sites !== []) {
            $this->newLine();
            $this->table(
                ['ID', 'Domain', 'Server ID', 'Site user', 'Status', 'WordPress', 'Staging'],
                collect($sites)->take(25)->map(fn (array $s) => [
                    $s['id'] ?? '',
                    $s['domain'] ?? '',
                    $s['server_id'] ?? '',
                    $s['site_user'] ?? '',
                    $s['status'] ?? '',
                    ! empty($s['is_wordpress']) ? 'yes' : 'no',
                    ! empty($s['is_staging']) ? 'yes' : 'no',
                ])->all(),
            );

            if (count($sites) > 25) {
                $this->line('… and '.(count($sites) - 25).' more.');
            }
        }

        $this->newLine();
        $this->info('TurnkeyWP API connection test successful.');

        return self::SUCCESS;
    }
}
