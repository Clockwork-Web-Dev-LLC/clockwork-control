<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Sites\WpConfigConstantInjector;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Idempotently inject `define('CLOCKWORK_COMPANION_TRUST_PROXY', true);` into
 * wp-config.php on every care-plan auto-update site so the Companion plugin's
 * per-IP rate limiter sees the real client IP behind Cloudflare instead of
 * the CF edge IP. See clockwork-companion CHANGELOG 1.16.9 for context.
 *
 * Scope mirrors `RunNightlyPluginUpdates::loadCandidateSites` minus the
 * `wp_plugin_updates` filter — we want the constant present on every
 * auto-update site, not just the ones with pending updates tonight. Also
 * skip sites on ignored/staging servers via the Server::scopeMonitored()
 * gate that was added when staging-tag exclusion went in.
 *
 * Cloudflare gate: only inject on `cloudflare_state = 'proxied'` sites. The
 * constant tells Companion to trust `CF-Connecting-IP` / `X-Forwarded-For`,
 * which is unsafe on direct-served origins (an attacker can spoof the
 * header to neutralise the rate limiter). Sites that move from direct →
 * proxied later will pick up the constant on the next nightly run.
 *
 * Safe to re-run: the injector returns false (no write) when the constant
 * is already defined, so the daily schedule just verifies the fleet stays
 * configured as new sites become eligible.
 */
class EnsureCompanionTrustProxy extends Command
{
    protected $signature = 'clockwork:ensure-companion-trust-proxy
                            {--site= : limit to one site (id or domain)}
                            {--dry-run : print which sites would be touched, do not write}';

    protected $description = 'Ensure CLOCKWORK_COMPANION_TRUST_PROXY is defined in wp-config.php on every care-plan auto-update site.';

    public function handle(WpConfigConstantInjector $injector): int
    {
        $sites = $this->candidates();
        if ($sites->isEmpty()) {
            $this->info('No matching sites.');

            return self::SUCCESS;
        }

        $isDryRun = (bool) $this->option('dry-run');

        $this->line(sprintf(
            '%d candidate site%s%s.',
            $sites->count(),
            $sites->count() === 1 ? '' : 's',
            $isDryRun ? ' (dry-run)' : '',
        ));

        $written = 0;
        $alreadySet = 0;
        $failed = 0;

        foreach ($sites as $site) {
            if ($isDryRun) {
                $this->line("  → would check {$site->domain}");

                continue;
            }

            try {
                $didWrite = $injector->ensureDefined($site, 'CLOCKWORK_COMPANION_TRUST_PROXY', true);
                if ($didWrite) {
                    $written++;
                    $this->line("  ✓ injected into {$site->domain}");
                } else {
                    $alreadySet++;
                    if ($this->getOutput()->isVerbose()) {
                        $this->line("  · already set on {$site->domain}");
                    }
                }
            } catch (Throwable $e) {
                $failed++;
                $this->line("  ✗ {$site->domain}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf(
            'Done. injected=%d already-set=%d failed=%d',
            $written,
            $alreadySet,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, Site>
     */
    private function candidates()
    {
        $q = Site::query()
            ->carePlanEligible()
            ->where('auto_updates_paused', false)
            ->where('companion_installed', true)
            ->whereNotNull('companion_snapshot')
            ->where('cloudflare_state', 'proxied')
            ->whereHas('server', fn ($q) => $q->monitored())
            ->orderBy('domain');

        if ($filter = $this->option('site')) {
            if (ctype_digit((string) $filter)) {
                $q->where('id', (int) $filter);
            } else {
                $q->where('domain', $filter);
            }
        }

        return $q->get();
    }
}
