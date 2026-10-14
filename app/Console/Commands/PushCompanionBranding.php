<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Companion\CompanionBrandingManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class PushCompanionBranding extends Command
{
    protected $signature = 'clockwork:push-companion-branding
        {--site= : Limit to a single site (id or domain)}
        {--verify : Read each site\'s live branding first and only re-push where it differs (weekly self-heal)}';

    protected $description = 'Push white-label branding configuration to Companion-equipped WordPress sites.';

    public function handle(CompanionBrandingManager $brandingManager, ActionLogger $actionLogger): int
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

        $sites = $query->orderBy('domain')->get();

        if ($sites->isEmpty()) {
            $this->warn('No eligible Companion sites found.');

            return self::SUCCESS;
        }

        $branding = $brandingManager->get();

        return $this->option('verify')
            ? $this->verifyAndHeal($sites, $brandingManager, $actionLogger, $branding)
            : $this->pushAll($sites, $brandingManager, $branding);
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @param  array<string, mixed>  $branding
     */
    private function pushAll($sites, CompanionBrandingManager $brandingManager, array $branding): int
    {
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

    /**
     * Read live branding, re-push only where it drifted, then read back to
     * confirm. Sites that can't be read (offline, signature mismatch) are
     * reported but not pushed to — a push would fail the same way.
     *
     * @param  Collection<int, Site>  $sites
     * @param  array<string, mixed>  $branding
     */
    private function verifyAndHeal($sites, CompanionBrandingManager $brandingManager, ActionLogger $actionLogger, array $branding): int
    {
        $this->info("Verifying branding [Company: {$branding['company_name']}] on {$sites->count()} site(s)...");

        $inSync = 0;
        $healed = [];
        $failed = [];
        $unreachable = [];

        foreach ($sites as $site) {
            try {
                $drift = $brandingManager->drift($site);
            } catch (Throwable $e) {
                // Error pages come back as HTML bodies — flatten to one readable line.
                $reason = trim((string) preg_replace('/\s+/', ' ', strip_tags($e->getMessage())));
                $unreachable[$site->domain] = mb_substr($reason, 0, 160);
                $this->error("  ? {$site->domain}: unreachable — ".mb_substr($reason, 0, 100));

                continue;
            }

            if ($drift === []) {
                $inSync++;

                continue;
            }

            $fields = implode(', ', array_keys($drift));
            $remaining = null;
            if ($brandingManager->syncSite($site)) {
                try {
                    $remaining = $brandingManager->drift($site);
                } catch (Throwable) {
                    $remaining = null;
                }
            }

            if ($remaining === []) {
                $healed[$site->domain] = array_keys($drift);
                $this->line("  ✓ {$site->domain}: re-pushed ({$fields} had drifted).");
            } else {
                $failed[$site->domain] = array_keys($drift);
                $this->error("  ✗ {$site->domain}: still out of sync after re-push ({$fields}).");
            }
        }

        $this->info(sprintf(
            'Completed: %d in sync, %d healed, %d failed, %d unreachable.',
            $inSync, count($healed), count($failed), count($unreachable),
        ));

        if ($healed !== [] || $failed !== [] || $unreachable !== []) {
            $actionLogger->record(
                actionType: 'companion_branding_verified',
                summary: sprintf(
                    'Branding check: %d healed, %d failed, %d unreachable (of %d)',
                    count($healed), count($failed), count($unreachable), $sites->count(),
                ),
                target: 'companion-branding',
                details: ['healed' => $healed, 'failed' => $failed, 'unreachable' => $unreachable],
                ok: $failed === [] && $unreachable === [],
                actor: 'scheduled',
            );
        }

        return ($failed !== [] || $unreachable !== []) ? self::FAILURE : self::SUCCESS;
    }
}
