<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SitePerformanceScan;
use App\Services\Performance\PageSpeedInsightsClient;
use App\Services\Performance\PerformanceScanRecorder;
use App\Services\Performance\PerformanceScanResult;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Modules\Core\Contracts\HostingProvider;
use Modules\GTmetrix\GtmetrixClient;
use Modules\Pressable\PressableLighthouseClient;

/**
 * Lighthouse-equivalent scan against care-plan sites — weekly per site via
 * a nightly 1/7th-of-fleet rotation (see --weekly-rotation). Replaces
 * ManageWP's "Performance Check" at $0/site of additional cost beyond the
 * GTmetrix paid plan.
 *
 * Engines (2026-06-27 cutover):
 *   - Primary:  GTmetrix REST API v2 — pinned test location (Dallas default,
 *               per-site override via sites.performance_scan_region) +
 *               pinned browser version. Much tighter day-over-day variance
 *               than PSI's noise floor (±5 vs ±10-15 points typical).
 *   - Fallback: Google PageSpeed Insights v5 — kept wired up for 30 days
 *               so a transient GTmetrix outage (API hiccup, quota hit,
 *               etc.) doesn't lose a site's nightly data point. Rows
 *               written by the fallback path are tagged engine='psi-fallback'
 *               so the dashboard can call them out as "PSI ran because
 *               GTmetrix errored on this site tonight."
 *
 * Sequential by design — each engine takes 15-60s per scan and we don't
 * want to stampede either API. At ~50 care-plan sites that's ~15-30
 * minutes; well under the daily window.
 */
class RunPerformanceScans extends Command
{
    protected $signature = 'clockwork:run-performance-scans
        {--site= : Limit to a specific site ID or domain}
        {--strategy=mobile : "mobile" or "desktop"}
        {--include-unavailable : Include sites currently marked psi_unavailable_at — useful when manually probing whether either engine has recovered}
        {--engine= : Force a specific engine ("gtmetrix" or "psi"). Default: gtmetrix primary, psi fallback.}
        {--weekly-rotation : Scan only tonight\'s 1/7th slice of the fleet (sites ordered by domain, index % 7 == UTC weekday), so every site gets one scan per week within the GTmetrix daily credit budget. Ignored when --site is given.}';

    protected $description = 'Daily performance scan per care-plan site (GTmetrix primary, PSI fallback). Records performance score, Core Web Vitals, page weight.';

    public function handle(
        GtmetrixClient $gtmetrix,
        PageSpeedInsightsClient $psi,
        PressableLighthouseClient $pressableLighthouse,
        PerformanceScanRecorder $recorder,
        Settings $settings,
    ): int {
        $strategy = (string) $this->option('strategy');
        if (! in_array($strategy, ['mobile', 'desktop'], true)) {
            $this->error('--strategy must be "mobile" or "desktop".');

            return self::FAILURE;
        }

        $forcedEngine = (string) ($this->option('engine') ?? '');
        if ($forcedEngine !== '' && ! in_array($forcedEngine, ['gtmetrix', 'psi'], true)) {
            $this->error('--engine must be "gtmetrix" or "psi".');

            return self::FAILURE;
        }

        // At least one engine has to be configured for anything to run. If
        // both keys are unset the command short-circuits cleanly (matches
        // the pre-GTmetrix behavior when CLOCKWORK_PSI_API_KEY was empty).
        if (! $gtmetrix->isConfigured() && ! $psi->isConfigured()) {
            $this->error('Neither GTmetrix nor PSI API keys configured — nothing to run.');

            return self::FAILURE;
        }

        $sites = $this->targetSites();
        if ($sites->isEmpty()) {
            $this->warn('No sites match.');

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;
        $primaryOk = 0;
        $fallbackOk = 0;
        $duplicateSkipped = 0;

        $bar = $this->output->createProgressBar($sites->count());
        $bar->setFormat(' %current%/%max% [%bar%] %message%');
        $bar->setMessage('starting…');
        $bar->start();

        $newlySuspended = 0;
        $recovered = 0;

        foreach ($sites as $site) {
            $bar->setMessage($site->domain);

            // Capability-gated, not identity-gated: any future provider that
            // declares CAP_PERFORMANCE_SCAN takes this branch automatically.
            // The dispatch target itself is still concretely PressableLighthouseClient
            // — the one real implementation today — not worth a HostingProvider
            // contract method until a second one exists.
            $result = $site->host()->supports(HostingProvider::CAP_PERFORMANCE_SCAN)
                ? $pressableLighthouse->scanSite($site, $strategy)
                : $this->scanSiteWithFallback($site, $strategy, $gtmetrix, $psi, $forcedEngine);
            $recordedRow = $recorder->record($result);

            if ($result->isOk() && $recordedRow === null) {
                // Same underlying report as last time (Pressable's monthly
                // batch hasn't refreshed yet) — recorder silently skipped
                // the duplicate insert. Not a failure, just nothing new.
                $duplicateSkipped++;
                if ($this->getOutput()->isVerbose()) {
                    $bar->clear();
                    $this->line("  ⏭  {$site->domain}: no new report since last scan (source unchanged)");
                    $bar->display();
                }
            } elseif ($result->isOk()) {
                $ok++;
                if ($result->engine === SitePerformanceScan::ENGINE_PSI_FALLBACK) {
                    $fallbackOk++;
                } else {
                    $primaryOk++;
                }
                if ($this->clearUnavailableIfSet($site)) {
                    $recovered++;
                    if ($this->getOutput()->isVerbose()) {
                        $bar->clear();
                        $this->line("  ↺ {$site->domain}: scan recovered — flag cleared");
                        $bar->display();
                    }
                }
                if ($this->getOutput()->isVerbose()) {
                    $bar->clear();
                    $this->line("  ✓ {$site->domain}: score {$result->performanceScore}/100 (via {$result->engine})");
                    $bar->display();
                }
            } else {
                $failed++;
                if ($this->markUnavailableIfPersistent($site, $result->error)) {
                    $newlySuspended++;
                    $bar->clear();
                    $this->warn("  ⛔ {$site->domain}: 3 consecutive scan failures — marked unavailable");
                    $bar->display();
                } elseif ($this->getOutput()->isVerbose()) {
                    $bar->clear();
                    $this->line("  ✗ {$site->domain}: {$result->error}");
                    $bar->display();
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->line('');
        $this->info(sprintf(
            'Done. ok=%d (primary=%d, fallback=%d), failed=%d, newly_suspended=%d, recovered=%d, duplicate_skipped=%d',
            $ok, $primaryOk, $fallbackOk, $failed, $newlySuspended, $recovered, $duplicateSkipped,
        ));

        $settings->put('performance_scans.last_run_at', now()->toIso8601String());

        return $failed > 0 && $ok === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * GTmetrix → PSI fallback orchestration. Respects --engine override.
     */
    private function scanSiteWithFallback(
        Site $site,
        string $strategy,
        GtmetrixClient $gtmetrix,
        PageSpeedInsightsClient $psi,
        string $forcedEngine,
    ): PerformanceScanResult {
        // Forced engine paths bypass the fallback layer entirely. Useful
        // when manually probing one engine's recovery, or backfilling.
        if ($forcedEngine === 'psi') {
            return $psi->scanSite($site, $strategy);
        }
        if ($forcedEngine === 'gtmetrix') {
            return $gtmetrix->scanSite($site, $strategy);
        }

        // Default path: GTmetrix primary if configured, PSI fallback on
        // GTmetrix failure (so long as PSI is configured AND the site isn't
        // already flagged psi_unavailable_at — no point burning PSI quota
        // on a known-bad site).
        if ($gtmetrix->isConfigured()) {
            $primary = $gtmetrix->scanSite($site, $strategy);

            if ($primary->isOk() || ! $psi->isConfigured() || $site->psi_unavailable_at !== null) {
                return $primary;
            }

            // GTmetrix failed and PSI is available — try PSI, relabel as
            // 'psi-fallback' so the engine column distinguishes from a
            // primary PSI scan (during the 30-day transition we should
            // see fallback rows only rarely; if we don't, PSI's no longer
            // the right safety net).
            $fallback = $psi->scanSite($site, $strategy)
                ->withEngine(SitePerformanceScan::ENGINE_PSI_FALLBACK);

            // If the fallback also failed, surface the PRIMARY's error
            // since GTmetrix is the engine we're really running on. The
            // fallback failure adds little operational signal beyond
            // "PSI is also unhappy with this site."
            if (! $fallback->isOk()) {
                return $primary;
            }

            return $fallback;
        }

        // GTmetrix unconfigured — straight PSI fallback path. Useful for
        // local dev where the operator hasn't set the GTmetrix key yet.
        return $psi->scanSite($site, $strategy);
    }

    /**
     * Care-plan-only by default. `--site=X` overrides the gate so a specific
     * site can be smoke-tested even when its care_plan_enabled is false.
     *
     * @return Collection<int, Site>
     */
    private function targetSites()
    {
        $q = Site::query()
            ->with('server')
            ->hostMonitored()
            ->orderBy('domain');

        if ($siteOpt = $this->option('site')) {
            $q->where(function ($q) use ($siteOpt) {
                $q->where('id', is_numeric($siteOpt) ? (int) $siteOpt : 0)
                    ->orWhere('domain', $siteOpt);
            });

            return $q->get();
        }

        $q->carePlanEligible();
        if (! $this->option('include-unavailable')) {
            $q->whereNull('psi_unavailable_at');
        }

        $sites = $q->get();

        // Weekly rotation: slice the fleet into 7 near-equal buckets by
        // domain-sorted position and scan only tonight's bucket. GTmetrix
        // (Core tier) refills 10 API credits daily at ~04:27 UTC and credits
        // do NOT accumulate, so a full-fleet nightly run exhausted the day's
        // credits after ~10 sites and silently pushed the rest onto the PSI
        // fallback (mobile-throttled scores that read 30-60 where GTmetrix
        // desktop reads 70-95). ~75 sites / 7 nights ≈ 11 per night keeps
        // (nearly) every scan on the primary engine. Position-based
        // bucketing keeps bucket sizes even at the cost of a site's scan
        // weekday shifting when sites are added/removed — fine, since
        // scores are compared week-over-week either way.
        if ($this->option('weekly-rotation')) {
            $day = now('UTC')->dayOfWeek;

            return $sites->values()
                ->filter(fn (Site $site, int $i) => $i % 7 === $day)
                ->values();
        }

        return $sites;
    }

    /**
     * After 3 consecutive failures (across either engine, since the row's
     * fallback chain already collapses to one row per site/strategy), flip
     * `psi_unavailable_at` so the scheduler stops pinging this site. Column
     * name is historical — it now means "performance scan unavailable" but
     * we're keeping the name to avoid a schema rename mid-cutover.
     */
    private function markUnavailableIfPersistent(Site $site, ?string $reason): bool
    {
        if ($site->psi_unavailable_at !== null) {
            return false;
        }

        $recentFailureCount = SitePerformanceScan::query()
            ->where('site_id', $site->id)
            ->orderByDesc('id')
            ->limit(3)
            ->pluck('status')
            ->filter(fn ($s) => $s === 'failed')
            ->count();

        if ($recentFailureCount < 3) {
            return false;
        }

        $site->forceFill([
            'psi_unavailable_at' => now(),
            'psi_unavailable_reason' => $reason ? substr($reason, 0, 200) : 'Performance scans repeatedly failed',
        ])->save();

        return true;
    }

    /**
     * Symmetric clear — when a scan finally succeeds, drop the unavailable
     * flag so the daily scheduler picks the site back up the next cycle.
     */
    private function clearUnavailableIfSet(Site $site): bool
    {
        if ($site->psi_unavailable_at === null) {
            return false;
        }

        $site->forceFill([
            'psi_unavailable_at' => null,
            'psi_unavailable_reason' => null,
        ])->save();

        return true;
    }
}
