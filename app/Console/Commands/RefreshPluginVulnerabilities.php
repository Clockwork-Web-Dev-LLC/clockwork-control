<?php

namespace App\Console\Commands;

use App\Services\Security\WpVulnerabilityClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('clockwork:refresh-plugin-vulnerabilities')]
#[Description('Walk every fleet site\'s installed plugin slugs, pull vulnerabilities from wpvulnerability.net (free, public, aggregates CVE+Patchstack+WPScan+Wordfence), and refresh the local mirror used by the Issues page security flag.')]
class RefreshPluginVulnerabilities extends Command
{
    public function handle(WpVulnerabilityClient $client): int
    {
        $this->info('Walking fleet plugin inventory + querying wpvulnerability.net…');

        $bar = null;

        $result = $client->refresh(function (string $slug, int $vulnsForSlug, int $idx, int $total) use (&$bar) {
            if ($bar === null) {
                $bar = $this->output->createProgressBar($total);
                $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%  %message%');
                $bar->setMessage('starting');
                $bar->start();
            }
            $bar->setMessage(sprintf('%-30s %d vulns', mb_substr($slug, 0, 30), $vulnsForSlug));
            $bar->advance();
        });

        if ($bar) {
            $bar->finish();
            $this->newLine();
        }

        if (! $result['ok']) {
            $this->error('Refresh failed: '.($result['error'] ?? 'unknown'));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Done. unique slugs=%d  fetched_ok=%d  fetch_failed=%d  rows_inserted=%d',
            $result['slugs'],
            $result['fetched_ok'],
            $result['fetch_failed'],
            $result['rows_inserted'],
        ));

        return self::SUCCESS;
    }
}
