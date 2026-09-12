<?php

namespace App\Console\Commands;

use App\Services\Runtime\EndOfLifeClient;
use App\Support\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('clockwork:refresh-runtime-eol')]
#[Description('Fetch runtime lifecycle data from endoflife.date for PHP and WordPress releases.')]
class RefreshRuntimeEol extends Command
{
    public function handle(EndOfLifeClient $client, Settings $settings): int
    {
        $this->info('Fetching runtime lifecycle data from endoflife.date…');

        $result = $client->refresh($settings);

        if (! $result['ok']) {
            $this->error('Refresh failed: '.($result['error'] ?? 'unknown'));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Done. PHP cycles=%d  WordPress cycles=%d',
            $result['php_count'],
            $result['wp_count'],
        ));

        return self::SUCCESS;
    }
}
