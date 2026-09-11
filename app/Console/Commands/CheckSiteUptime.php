<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Seo\IndexabilityChecker;
use App\Services\Uptime\UptimeProber;
use App\Services\Uptime\UptimeProbeResult;
use App\Services\Uptime\UptimeStateUpdater;
use Illuminate\Console\Command;
use Throwable;

/**
 * Probes every monitored site every 5 minutes. Drives the uptime state
 * machine that fires Mattermost alerts on transitions.
 *
 * Sequential probing — at 150 sites × ~1s per probe, the whole pass takes
 * ~2-3 minutes. Well under the 5-minute scheduler tick. Parallel probing
 * isn't needed at this fleet size; if it becomes one, switch to PoolHttp
 * with a small concurrency cap (~10).
 *
 * Skips: archived sites (default Site scope), sites on ignored servers,
 * sites with uptime_monitoring_enabled=false.
 */
class CheckSiteUptime extends Command
{
    protected $signature = 'clockwork:check-site-uptime
                            {--site= : Limit to a single site ID (manual smoke test)}';

    protected $description = 'Probe every monitored site, transition uptime state, fire Mattermost alerts on changes.';

    public function handle(UptimeProber $prober, UptimeStateUpdater $updater, IndexabilityChecker $seoChecker): int
    {
        $query = Site::query()
            ->where('uptime_monitoring_enabled', true)
            ->hostMonitored();

        if ($siteId = $this->option('site')) {
            $query->where('id', (int) $siteId);
        }

        $sites = $query->get();
        $this->line("Probing {$sites->count()} sites...");

        $up = 0;
        $down = 0;
        $start = microtime(true);

        foreach ($sites as $site) {
            $url = 'https://'.$site->domain.'/';
            try {
                $probe = $prober->probe($url, requireKeyword: $site->uptime_require_keyword);
                $updater->update($site, $probe);
                try {
                    $seoChecker->checkFromProbeResult($site, $probe);
                } catch (Throwable) {
                    // SEO check failure must not fail the uptime monitoring pass
                }
                $probe->succeeded ? $up++ : $down++;
            } catch (Throwable $e) {
                // Synthesize a transport-failed probe and run it through the
                // state machine so consecutive_failures still ticks up — a
                // bare catch+continue would let DNS NXDOMAIN or socket errors
                // slip past the down-alert threshold forever.
                $this->error("  {$site->domain}: probe threw: {$e->getMessage()} — recording as transport-failed");
                try {
                    $synthetic = UptimeProbeResult::transportFailed(
                        substr($e->getMessage(), 0, 500)
                    );
                    $updater->update($site, $synthetic);
                } catch (Throwable $e2) {
                    // If the state updater itself throws, log and move on so
                    // the rest of the fleet still gets probed.
                    $this->error("  {$site->domain}: state-updater also threw: {$e2->getMessage()}");
                }
                $down++;
            }
        }

        $elapsed = (int) round(microtime(true) - $start);
        $this->info("Done. up={$up} down={$down} elapsed={$elapsed}s");

        return self::SUCCESS;
    }
}
