<?php

namespace App\Console\Commands;

use App\Services\Security\PluginDirectoryClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('clockwork:refresh-closed-plugins {--slug= : Specific plugin slug to check}')]
#[Description('Check WordPress.org plugin directory status for closed/zombieware plugins across the fleet.')]
class RefreshClosedPlugins extends Command
{
    public function handle(PluginDirectoryClient $client): int
    {
        $specificSlug = $this->option('slug');
        $slugs = $specificSlug ? [trim((string) $specificSlug)] : null;

        $this->info('Checking plugin directory statuses on WordPress.org…');

        $bar = null;

        $result = $client->refresh(function (string $slug, array $status, int $idx, int $total) use (&$bar) {
            if ($bar === null) {
                $bar = $this->output->createProgressBar($total);
                $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%  %message%');
                $bar->setMessage('starting');
                $bar->start();
            }
            $bar->setMessage(sprintf('%-30s %s', mb_substr($slug, 0, 30), $status['status']));
            $bar->advance();
        }, $slugs);

        if ($bar) {
            $bar->finish();
            $this->newLine();
        }

        $this->info(sprintf(
            'Done. total=%d  open=%d  closed=%d  not_found=%d  errors=%d',
            $result['total'],
            $result['open'],
            $result['closed'],
            $result['not_found'],
            $result['errors'],
        ));

        return self::SUCCESS;
    }
}
