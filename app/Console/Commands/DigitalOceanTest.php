<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\DigitalOcean\DigitalOceanClient;

#[Signature('clockwork:digitalocean-test')]
#[Description('Verify the DigitalOcean API token by fetching the account and droplet count.')]
class DigitalOceanTest extends Command
{
    public function handle(DigitalOceanClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('CLOCKWORK_DIGITALOCEAN_TOKEN is not set in .env.');

            return self::FAILURE;
        }

        try {
            $account = $client->account();
            $droplets = $client->droplets();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Authenticated as: '.($account['email'] ?? '(unknown)'));
        $this->info('UUID: '.($account['uuid'] ?? '(unknown)'));
        $this->info('Droplets visible: '.count($droplets));

        if ($droplets !== []) {
            $this->newLine();
            $this->table(
                ['ID', 'Name', 'Region', 'Status', 'Public IPv4'],
                collect($droplets)->take(10)->map(fn (array $d) => [
                    $d['id'] ?? '',
                    $d['name'] ?? '',
                    $d['region']['slug'] ?? '',
                    $d['status'] ?? '',
                    collect($d['networks']['v4'] ?? [])->firstWhere('type', 'public')['ip_address'] ?? '',
                ])->all(),
            );

            if (count($droplets) > 10) {
                $this->line('… and '.(count($droplets) - 10).' more.');
            }
        }

        return self::SUCCESS;
    }
}
