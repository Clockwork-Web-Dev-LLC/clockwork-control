<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Seo\IndexabilityChecker;
use Illuminate\Console\Command;
use Throwable;

class CheckRobotsTxt extends Command
{
    protected $signature = 'clockwork:check-robots-txt
                            {--site= : Limit to a single site ID}';

    protected $description = 'Check /robots.txt directives across monitored sites for search engine disallow rules.';

    public function handle(IndexabilityChecker $checker): int
    {
        $query = Site::query()
            ->whereNotNull('domain')
            ->where('is_inactive', false)
            ->where('seo_monitoring_enabled', true)
            ->hostMonitored();

        if ($siteId = $this->option('site')) {
            $query->where('id', (int) $siteId);
        }

        $sites = $query->get();
        $this->line("Checking robots.txt across {$sites->count()} sites...");

        $checked = 0;
        $failed = 0;

        foreach ($sites as $site) {
            try {
                $checker->checkRobotsTxt($site);
                $checked++;
            } catch (Throwable $e) {
                $this->error("  {$site->domain}: robots.txt check failed: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Done. checked={$checked} failed={$failed}");

        return self::SUCCESS;
    }
}
