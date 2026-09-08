<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Screenshots\SiteScreenshotService;
use Illuminate\Console\Command;

class CaptureSiteScreenshots extends Command
{
    protected $signature = 'clockwork:capture-site-screenshots
                            {--site= : Specific site domain or ID to capture}
                            {--force : Re-capture even if captured recently}
                            {--limit=50 : Maximum number of sites to process}';

    protected $description = 'Capture and cache site homepage screenshots from Automattic mShots';

    public function handle(SiteScreenshotService $service): int
    {
        $siteParam = $this->option('site');
        $force = (bool) $this->option('force');
        $limit = max(1, (int) $this->option('limit'));

        $query = Site::query()->where('is_inactive', false);

        if ($siteParam) {
            $query->where(function ($q) use ($siteParam) {
                if (is_numeric($siteParam)) {
                    $q->where('id', (int) $siteParam);
                } else {
                    $q->where('domain', $siteParam);
                }
            });
        } elseif (! $force) {
            // Priority to sites with no screenshot or stale screenshots (> 7 days)
            $query->where(function ($q) {
                $q->whereNull('screenshot_captured_at')
                    ->orWhere('screenshot_captured_at', '<', now()->subDays(7));
            });
        }

        $sites = $query->orderByRaw('screenshot_captured_at IS NULL DESC')
            ->orderBy('screenshot_captured_at', 'asc')
            ->limit($limit)
            ->get();

        if ($sites->isEmpty()) {
            $this->info('No sites need screenshot capture.');

            return Command::SUCCESS;
        }

        $this->line("Capturing screenshots for {$sites->count()} site(s)...");

        $success = 0;
        $failed = 0;

        foreach ($sites as $site) {
            $this->output->write("Capturing {$site->domain}... ");
            $ok = $service->capture($site, force: $force);

            if ($ok) {
                $this->info('OK');
                $success++;
            } else {
                $this->warn('FAILED');
                $failed++;
            }
        }

        $this->info("Screenshot capture complete: {$success} succeeded, {$failed} failed.");

        return Command::SUCCESS;
    }
}
