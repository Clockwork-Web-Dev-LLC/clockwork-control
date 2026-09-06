<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteSecurityScan;
use App\Services\Security\SecurityScanRecorder;
use App\Services\Security\SucuriSiteCheckClient;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Run Sucuri's free SiteCheck scanner against every site (or a specific site
 * via --site=). Replaces ManageWP's "Security Check" feature — same scan
 * engine, queryable directly, $0 per site.
 *
 * Sequential by design: free tier is rate-limited around 30 req/min, so we
 * sleep ~250 ms between sites. At 150 sites that's roughly 40 seconds total
 * — fast enough that we don't bother with parallelism.
 */
class ScanSiteCheck extends Command
{
    protected $signature = 'clockwork:scan-sitecheck
        {--site= : Limit to a specific site ID or domain}
        {--include-unavailable : Re-include sites previously marked Sucuri-unavailable}';

    protected $description = "Scan every site against Sucuri's free SiteCheck API and record the result. Default scope: every site on a non-ignored server.";

    public function handle(SucuriSiteCheckClient $client, SecurityScanRecorder $recorder, Settings $settings): int
    {
        $sites = $this->targetSites();

        if ($sites->isEmpty()) {
            $this->warn('No sites match.');

            return self::SUCCESS;
        }

        $clean = 0;
        $issues = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($sites->count());
        $bar->setFormat(' %current%/%max% [%bar%] %message%');
        $bar->setMessage('starting…');
        $bar->start();

        $unavailable = 0;
        foreach ($sites as $i => $site) {
            $bar->setMessage($site->domain);

            $result = $client->scanSite($site);
            $recorder->record($result);

            if ($result->isClean()) {
                $clean++;
                if ($site->sucuri_unavailable_at !== null) {
                    $site->forceFill([
                        'sucuri_unavailable_at' => null,
                        'sucuri_unavailable_reason' => null,
                    ])->save();
                }
            } elseif ($result->hasIssues()) {
                $issues++;
                if ($this->getOutput()->isVerbose()) {
                    $bar->clear();
                    $this->line("  ⚠ {$site->domain}: {$result->summary}");
                    $bar->display();
                }
            } else {
                $failed++;
                if ($this->markUnavailableIfPersistent($site, $result->error)) {
                    $unavailable++;
                    if ($this->getOutput()->isVerbose()) {
                        $bar->clear();
                        $this->line("  ⛔ {$site->domain}: marked Sucuri-unavailable after 3 consecutive failures");
                        $bar->display();
                    }
                } elseif ($this->getOutput()->isVerbose()) {
                    $bar->clear();
                    $this->line("  ✗ {$site->domain}: {$result->error}");
                    $bar->display();
                }
            }

            $bar->advance();

            // Spacing between calls keeps us under Sucuri's free rate limit.
            // Skip the sleep on the last iteration so the command exits promptly.
            if ($i + 1 < $sites->count()) {
                usleep(250_000);
            }
        }

        $bar->finish();
        $this->line('');
        $this->info("Done. clean={$clean}, issues={$issues}, failed={$failed}, marked-unavailable={$unavailable}");

        $settings->put('security_scans.sitecheck_last_run_at', now()->toIso8601String());

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Care-plan filter: scans are a paid deliverable. The unfiltered, scheduled
     * run skips any site without `care_plan_enabled=true`. `--site=X` overrides
     * the gate so we can still smoke-test a specific site (or diagnose a
     * misconfigured care_plan flag) without flipping the column first.
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
            // --site bypasses both the care-plan filter and the unavailable
            // gate so the operator can manually retry a marked-unavailable
            // site (success will auto-clear the flag).
            $q->where(function ($q) use ($siteOpt) {
                $q->where('id', is_numeric($siteOpt) ? (int) $siteOpt : 0)
                    ->orWhere('domain', $siteOpt);
            });
        } else {
            $q->where('care_plan_enabled', true);
            if (! $this->option('include-unavailable')) {
                $q->whereNull('sucuri_unavailable_at');
            }
        }

        return $q->get();
    }

    /**
     * After 3 consecutive Sucuri SiteCheck failures (including the one just
     * recorded), flip `sucuri_unavailable_at` so the scheduled scan stops
     * pinging this site. The threshold matches the uptime probe's "wait for
     * a streak before acting" pattern — one transient timeout shouldn't
     * permanently disable a site.
     */
    private function markUnavailableIfPersistent(Site $site, ?string $reason): bool
    {
        if ($site->sucuri_unavailable_at !== null) {
            return false;
        }

        $recentFailureCount = SiteSecurityScan::query()
            ->where('site_id', $site->id)
            ->where('scan_type', SiteSecurityScan::TYPE_SITECHECK)
            ->orderByDesc('id')
            ->limit(3)
            ->pluck('status')
            ->filter(fn ($s) => $s === SiteSecurityScan::STATUS_FAILED)
            ->count();

        if ($recentFailureCount < 3) {
            return false;
        }

        $site->forceFill([
            'sucuri_unavailable_at' => now(),
            'sucuri_unavailable_reason' => $reason ? substr($reason, 0, 200) : 'Sucuri SiteCheck repeatedly failed',
        ])->save();

        return true;
    }
}
