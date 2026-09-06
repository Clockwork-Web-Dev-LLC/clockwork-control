<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Companion\ResourceMetricsIngestor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Pull hourly CPU/memory rollups from every Companion site that advertises
 * `resource-sampler` and write them into `site_metrics`. Runs every 15 min;
 * feeds the per-site leaderboard on /capacity.
 *
 * SSH-free — pure HTTPS + HMAC. Sequential loop is fine at fleet size
 * (~150 sites × ~200ms per call ≈ 30s total).
 */
class PullSiteResourceMetrics extends Command
{
    protected $signature = 'clockwork:pull-site-metrics
        {--site= : limit to one site (id or domain)}';

    protected $description = 'Pull per-site CPU/memory hourly rollups from Companion into site_metrics.';

    public function handle(): int
    {
        $sites = $this->candidates();
        if ($sites->isEmpty()) {
            $this->info('No matching sites.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Pulling resource metrics for %d site%s…', $sites->count(), $sites->count() === 1 ? '' : 's'));

        $ok = 0;
        $skipped = 0;
        $failed = 0;
        $rowsTotal = 0;

        foreach ($sites as $site) {
            // Default factory — produces a fresh ClockworkCompanionClient
            // per site. Tests can inject a closure that returns a mock.
            $ingestor = new ResourceMetricsIngestor;

            try {
                $result = $ingestor->ingest($site);
                if ($result->skipReason !== null) {
                    $skipped++;
                    if ($this->getOutput()->isVerbose()) {
                        $this->line("  · {$site->domain}: {$result->skipReason}");
                    }

                    continue;
                }
                $ok++;
                $rowsTotal += $result->rowsWritten;
                if ($this->getOutput()->isVerbose()) {
                    $this->line(sprintf('  ✓ %s: wrote %d rows', $site->domain, $result->rowsWritten));
                }
            } catch (Throwable $e) {
                $failed++;
                $this->line("  ✗ {$site->domain}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf(
            'Done. ok=%d skipped=%d failed=%d rows_written=%d',
            $ok,
            $skipped,
            $failed,
            $rowsTotal,
        ));

        // Exit FAILURE only when nothing succeeded — a systemic issue
        // (DB down, all Companion sites unreachable) should poison the
        // scheduler history, but a single chronically-failing site
        // shouldn't make every 15-min tick look broken. The per-site
        // error line above + the summary count are how partial
        // failures get surfaced.
        $systemicFailure = $ok === 0 && $failed > 0;

        return $systemicFailure ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, Site>
     */
    private function candidates()
    {
        $q = Site::query()
            ->where('companion_installed', true)
            ->hostMonitored()
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
