<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Sites\WpPluginDetector;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class DetectWpPlugins extends Command
{
    protected $signature = 'clockwork:detect-wp-plugins
        {--site= : Limit to a specific site ID or domain}';

    protected $description = 'Probe each WordPress site over SSH (wp-cli) to detect whether LLAR and Wordfence are active, and write the truth to the local mirror. Default scope: every WordPress site on a non-ignored server.';

    public function handle(WpPluginDetector $detector): int
    {
        $sites = $this->targetSites();

        if ($sites->isEmpty()) {
            $this->warn('No sites match.');

            return self::SUCCESS;
        }

        $detected = 0;
        $failed = 0;
        $skipped = 0;
        $changes = 0;

        $bar = $this->output->createProgressBar($sites->count());
        $bar->setFormat(' %current%/%max% [%bar%] %message%');
        $bar->setMessage('starting…');
        $bar->start();

        foreach ($sites as $site) {
            $bar->setMessage($site->domain);

            // Snapshot the before-state so we can report what actually changed.
            $beforeLlar = (bool) $site->llar_enabled;
            $beforeWf = (bool) $site->wordfence_enabled;

            $r = $detector->detect($site);

            switch ($r['result']) {
                case WpPluginDetector::RESULT_DETECTED:
                    $detected++;
                    if ($beforeLlar !== $r['llar'] || $beforeWf !== $r['wordfence']) {
                        $changes++;
                    }
                    break;
                case WpPluginDetector::RESULT_SKIPPED:
                    $skipped++;
                    break;
                default:
                    $failed++;
                    if ($this->getOutput()->isVerbose()) {
                        $bar->clear();
                        $this->line("  ✗ {$site->domain}: {$r['message']}");
                        $bar->display();
                    }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->line('');
        $this->info("Done. detected={$detected}, changes={$changes}, skipped={$skipped}, failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, Site> */
    private function targetSites()
    {
        $q = Site::query()
            ->with('server')
            ->where('is_wordpress', true)
            ->whereHas('server', fn ($q) => $q->monitored())
            ->orderBy('domain');

        if ($siteOpt = $this->option('site')) {
            $q->where(function ($q) use ($siteOpt) {
                $q->where('id', is_numeric($siteOpt) ? (int) $siteOpt : 0)
                    ->orWhere('domain', $siteOpt);
            });
        }

        return $q->get();
    }
}
