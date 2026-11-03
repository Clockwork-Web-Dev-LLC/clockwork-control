<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SitePerformanceScan;
use App\Services\Performance\PageSpeedInsightsClient;
use App\Services\Performance\PerformanceScanRecorder;
use App\Services\Performance\PerformanceScanResult;
use App\Support\Settings;
use Carbon\CarbonInterface;
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
        {--weekly-rotation : Scan only tonight\'s 1/7th slice of the fleet (each site has a fixed weekday, crc32(domain) % 7 == UTC weekday), so every site gets one scan per week within the GTmetrix daily credit budget. In the last 7 days of a month, sites with no scan that month are added within the same budget. Ignored when --site is given.}
        {--host-reports : Only sites whose host runs its own Lighthouse report (Pressable). Reading it is free, so these are read every night instead of joining the rotation. Ignored when --site is given.}';

    protected $description = 'Daily performance scan per care-plan site (GTmetrix primary, PSI fallback). Records performance score, Core Web Vitals, page weight.';

    /**
     * Month-end catch-up window: on each of a month's last this-many nights
     * the rotation also takes care-plan sites with no OK scan that month.
     * Seven, so every weekday's bucket comes round once inside the window.
     */
    public const CATCH_UP_DAYS = 7;

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
        // Reading a host's own report (--host-reports) needs neither key.
        if (! $this->option('host-reports') && ! $gtmetrix->isConfigured() && ! $psi->isConfigured()) {
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
                // the duplicate insert. Not a failure, just nothing new,
                // and a working read, so any unavailable flag is cleared.
                $duplicateSkipped++;
                if ($this->clearUnavailableIfSet($site)) {
                    $recovered++;
                }
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
                // The unavailable flag protects the GTmetrix/PSI quota. A host's
                // own report costs nothing to read, so its failures never switch
                // the site off — it's simply read again tomorrow.
                if ($result->engine !== SitePerformanceScan::ENGINE_PRESSABLE
                    && $this->markUnavailableIfPersistent($site, $result->error)) {
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

        return $failed > 0 && $ok + $duplicateSkipped === 0 ? self::FAILURE : self::SUCCESS;
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
        // The unavailable flag doesn't apply to free host-report reads.
        if (! $this->option('include-unavailable') && ! $this->option('host-reports')) {
            $q->whereNull('psi_unavailable_at');
        }

        // Hosts with their own report (Pressable) run the test themselves about
        // once a month and we can only read the latest one. Reading is free and
        // an unchanged report is skipped, so they're read nightly by
        // --host-reports and kept out of the GTmetrix rotation, so a new
        // report is picked up within a day rather than up to three weeks later.
        $hasHostReport = fn (Site $site) => $site->host()->supports(HostingProvider::CAP_PERFORMANCE_SCAN);
        $sites = $q->get();
        if ($this->option('host-reports')) {
            return $sites->filter($hasHostReport)->values();
        }
        if ($this->option('weekly-rotation')) {
            $sites = $sites->reject($hasHostReport)->values();
        }

        // Weekly rotation: split the fleet into 7 buckets and scan only
        // tonight's. GTmetrix (Core tier) refills 10 API credits daily at
        // ~04:27 UTC and credits do NOT accumulate, so a full-fleet nightly
        // run exhausted the day's credits after ~10 sites and silently pushed
        // the rest onto the PSI fallback (mobile-throttled scores that read
        // 30-60 where GTmetrix desktop reads 70-95). ~75 sites / 7 nights ≈ 11
        // per night keeps (nearly) every scan on the primary engine.
        //
        // Each site's bucket comes from its own domain (rotationBucket), not
        // its position in the list. Position-based buckets moved every site's
        // weekday whenever a site was enrolled or removed, so some sites
        // went more than a week without a scan and others were scanned twice.
        if ($this->option('weekly-rotation')) {
            $day = now('UTC')->dayOfWeek;
            $tonight = $sites->filter(fn (Site $site) => self::rotationBucket($site) === $day)->values();

            return $this->withMonthEndCatchUp($sites, $tonight);
        }

        return $sites;
    }

    /**
     * A site's weekday in the weekly rotation (0 = Sunday, like Carbon's
     * dayOfWeek). Depends on the domain alone, so enrolling or removing
     * other sites never moves it.
     */
    public static function rotationBucket(Site $site): int
    {
        return crc32(strtolower((string) $site->domain)) % 7;
    }

    /**
     * Month-end catch-up for the weekly rotation. A client report only shows
     * a speed test run inside its month, and a site can miss its weekday: the
     * scan failed, or the site came under care after its slot had passed. On
     * each of the month's last CATCH_UP_DAYS nights, rotation sites with no
     * OK scan since the 1st join tonight's run, within the daily GTmetrix
     * credit budget:
     *
     *  - credits tonight's bucket leaves free go to those sites, the ones
     *    whose own weekday won't come round again this month first;
     *  - if those still don't fit, tonight's sites that already have a scan
     *    this month give up their slot (most recently scanned first). Their
     *    report is covered and they're scanned again next week;
     *  - anything left waits for the next night. It is never pushed onto the
     *    PSI fallback, whose mobile scores read far lower than GTmetrix's.
     *
     * Credits are spent in run order, so sites with no scan this month run
     * before those that already have one.
     *
     * @param  Collection<int, Site>  $eligible  every site in the rotation
     * @param  Collection<int, Site>  $tonight  tonight's bucket
     * @return Collection<int, Site>
     */
    private function withMonthEndCatchUp(Collection $eligible, Collection $tonight): Collection
    {
        $now = now('UTC');
        $nightsLeft = $now->daysInMonth - $now->day; // after tonight
        if ($nightsLeft >= self::CATCH_UP_DAYS || $eligible->isEmpty()) {
            return $tonight;
        }

        $lastOk = $this->lastOkScanSince($eligible, $now->copy()->startOfMonth());
        $candidates = $eligible
            ->reject(fn (Site $site) => isset($lastOk[$site->id]) || $tonight->contains($site))
            ->values();
        if ($candidates->isEmpty()) {
            return $tonight;
        }

        // A candidate isn't in tonight's bucket, so its weekday is 1-6 nights
        // away; it still gets its own slot this month if that's within the
        // nights left.
        [$slotLeft, $noSlotLeft] = $candidates->partition(
            fn (Site $site) => (self::rotationBucket($site) - $now->dayOfWeek + 7) % 7 <= $nightsLeft
        );
        $noSlotLeft = $noSlotLeft->values();
        [$covered, $uncovered] = $tonight->partition(fn (Site $site) => isset($lastOk[$site->id]));

        $budget = max(0, (int) config('clockwork.gtmetrix.daily_credits', 10));
        $free = max(0, $budget - $tonight->count());
        $room = max(0, $budget - $uncovered->count());

        $added = $noSlotLeft->concat($slotLeft)->take($free)->values();
        $swappedIn = $noSlotLeft->slice($added->count())->take(max(0, $room - $added->count()))->values();
        $deferred = $covered->sortByDesc(fn (Site $site) => $lastOk[$site->id])->take($swappedIn->count());
        $keptCovered = $covered->reject(fn (Site $site) => $deferred->contains($site));
        $waiting = $candidates->count() - $added->count() - $swappedIn->count();

        $this->line(sprintf(
            'Month-end catch-up: %d site(s) with no scan this month added, %d already-scanned site(s) moved to next week to make room, %d waiting for the next night.',
            $added->count() + $swappedIn->count(), $deferred->count(), $waiting,
        ));

        return $uncovered->concat($added)->concat($swappedIn)->concat($keptCovered)->values();
    }

    /**
     * Latest OK scan per site since $since, keyed by site id. Dated the way
     * the client report dates a scan (the host's own run time when it has
     * one), so "covered" here means covered in the report.
     *
     * @param  Collection<int, Site>  $sites
     * @return array<int, string>
     */
    private function lastOkScanSince(Collection $sites, CarbonInterface $since): array
    {
        return SitePerformanceScan::query()
            ->whereIn('site_id', $sites->modelKeys())
            ->where('status', SitePerformanceScan::STATUS_OK)
            ->whereRaw('COALESCE(source_generated_at, scanned_at) >= ?', [$since])
            ->groupBy('site_id')
            ->selectRaw('site_id, MAX(COALESCE(source_generated_at, scanned_at)) AS last_ok_at')
            ->pluck('last_ok_at', 'site_id')
            ->all();
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
