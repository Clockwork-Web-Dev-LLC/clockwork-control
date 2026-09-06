<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Companion\CompanionBrandingManager;
use Illuminate\Console\Command;

class PushCompanionBranding extends Command
{
    protected $signature = 'clockwork:push-companion-branding
        {--site= : Limit to a single site (id or domain)}';

    protected $description = 'Push white-label branding configuration to Companion-equipped WordPress sites.';

    public function handle(CompanionBrandingManager $brandingManager): int
    {
        $siteFilter = $this->option('site');

        $query = Site::query()
            ->where('companion_installed', true)
            ->where('is_inactive', false);

        if ($siteFilter) {
            $query->where(function ($q) use ($siteFilter) {
                if (is_numeric($siteFilter)) {
                    $q->where('id', (int) $siteFilter);
                } else {
                    $q->where('domain', $siteFilter);
                }
            });
        }

        $sites = $query->get();

        if ($sites->isEmpty()) {
            $this->warn('No eligible Companion sites found.');

            return self::SUCCESS;
        }

        $branding = $brandingManager->get();
        $this->info("Pushing branding [Company: {$branding['company_name']}, Plugin: {$branding['plugin_name']}] to {$sites->count()} site(s)...");

        $ok = 0;
        $failed = 0;

        foreach ($sites as $site) {
            if ($brandingManager->syncSite($site)) {
                $ok++;
                $this->line("  ✓ {$site->domain} synced.");
            } else {
                $failed++;
                $this->error("  ✗ {$site->domain} failed to sync.");
            }
        }

        $this->info("Completed: {$ok} synced, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
