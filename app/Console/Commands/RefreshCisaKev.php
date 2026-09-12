<?php

namespace App\Console\Commands;

use App\Services\Security\CisaKevClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('clockwork:refresh-cisa-kev')]
#[Description('Fetch the CISA Known Exploited Vulnerabilities (KEV) catalog and refresh local mirror for actively exploited badges.')]
class RefreshCisaKev extends Command
{
    public function handle(CisaKevClient $client): int
    {
        $this->info('Fetching CISA Known Exploited Vulnerabilities catalog…');

        $result = $client->refresh(function (int $count) {
            $this->line("Parsed {$count} KEV catalog entries from feed.");
        });

        if (! $result['ok']) {
            $this->error('Refresh failed: '.($result['error'] ?? 'unknown'));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Done. Catalog count=%d  rows_inserted=%d',
            $result['count'],
            $result['rows_inserted'],
        ));

        return self::SUCCESS;
    }
}
