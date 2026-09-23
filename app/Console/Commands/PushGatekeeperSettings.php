<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Gatekeeper\GatekeeperSettingsPusher;
use Illuminate\Console\Command;

class PushGatekeeperSettings extends Command
{
    protected $signature = 'clockwork:push-gatekeeper-settings
        {--site= : Limit to a single site (id or domain)}';

    protected $description = 'Push Gatekeeper lockout settings to WordPress via Companion or Renegade.';

    public function handle(GatekeeperSettingsPusher $pusher): int
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

        $this->info("Pushing Gatekeeper settings to {$sites->count()} site(s)...");

        $ok = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($sites as $site) {
            $capabilities = is_array($site->companion_capabilities) ? $site->companion_capabilities : [];
            if (! in_array('gatekeeper', $capabilities, true)) {
                $skipped++;

                continue;
            }

            if ($pusher->maybePush($site)) {
                $ok++;
                $this->line("  ✓ {$site->domain} synced.");
            } else {
                $failed++;
                $this->error("  ✗ {$site->domain} failed to sync.");
            }
        }

        $this->info("Completed: {$ok} synced, {$skipped} skipped (capability missing), {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
