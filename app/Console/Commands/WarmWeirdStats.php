<?php

namespace App\Console\Commands;

use App\Services\Stats\WeirdStatsAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Recomputes the threat_logs-derived stats so the /settings/weird-stats page
 * always serves warm cache. Cold compute is ~30s on a 2M+ row threat_logs
 * window — well past PHP-FPM's default max_execution_time, so we can't let
 * the operator hit the cold path on a page load. Scheduled every 9 minutes
 * (just under the 10-min cache TTL).
 */
class WarmWeirdStats extends Command
{
    protected $signature = 'clockwork:warm-weird-stats
        {--force : Bust the cache before warming (otherwise we just refresh entries near expiry)}';

    protected $description = 'Pre-compute the threat_logs-derived weird stats so /settings/weird-stats always serves warm cache.';

    public function handle(WeirdStatsAggregator $stats): int
    {
        // Always forget before recomputing. Cache::remember inside the aggregator
        // would no-op if the keys still exist — we want to replace them with
        // fresh values on every scheduled tick so the page never gets staler
        // than the warm cadence (9 min).
        Cache::forget('weird_stats:most_attacked_paths');
        Cache::forget('weird_stats:hour_histogram');
        Cache::forget('weird_stats:attacks_7d_count');
        Cache::forget('weird_stats:cf_attack_reduction');

        $start = microtime(true);

        // Touch each cached method. The aggregator handles the Cache::remember
        // wrapping internally — we just call the methods.
        $stats->summaryTiles();
        $stats->mostAttackedPaths();
        $stats->attackHourHistogram();
        $stats->settlingPointStats();

        $elapsed = round((microtime(true) - $start) * 1000);
        $msg = "weird_stats.warmed elapsed_ms={$elapsed}";
        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
    }
}
