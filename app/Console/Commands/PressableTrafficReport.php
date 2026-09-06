<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Pressable\PressableClient;
use Throwable;

/**
 * Pushes a real 30-day daily traffic rollup to each Companion-equipped
 * Pressable site's /traffic-report endpoint, sourced from Pressable's own
 * time-series metrics API (POST /sites/{id}/metrics) — NOT the coarse
 * period-totals endpoint used by the first version of this command.
 *
 * Found 2026-08-29: that first version undersold what Pressable actually
 * exposes. get_site_metrics/`/metrics` gives real per-day requests (by
 * HTTP status), real daily unique visitors, and per-path request counts —
 * close enough to the SpinupWP nginx-log rollup that this command builds
 * the *exact same* payload shape PushCompanionTraffic does. Companion
 * needs zero changes: a populated `daily` array makes TrafficPage take the
 * normal chart-rendering path (the period_summary fallback built for the
 * old coarse data stays in place for any future host that only exposes
 * period totals, but Pressable no longer needs it).
 *
 * Two real API quirks worth knowing before touching this:
 *  - Metrics/dimensions must come from the same "family" (Edge Logs,
 *    PHP Logs, Uniques & Views, MySQL/CGroup) to combine — mixing e.g.
 *    `views` (Uniques & Views) with `http_status` (Edge Logs) silently
 *    returns an empty result rather than an error.
 *  - Resolution is auto-selected by the API based on the requested time
 *    range, and differs BY FAMILY for the same range: Uniques & Views over
 *    "Past 1 month" returns clean daily (86400s) buckets, but Edge Logs
 *    metrics (requests, status, etc.) over the same range return 8-hour
 *    (28800s) buckets instead — aggregated into calendar days here.
 */
class PressableTrafficReport extends Command
{
    protected $signature = 'clockwork:pressable-traffic-report
        {--site= : Limit to a single site (id or domain)}';

    protected $description = 'Push a real daily traffic rollup (sourced from Pressable\'s own metrics API) to each Companion-equipped Pressable site.';

    /**
     * Path-categorisation rules ported from RollupTraffic::applyBucketFilter()
     * so Pressable's top-paths bucketing matches the SpinupWP nginx-log
     * version exactly — same three buckets, same client-facing meaning.
     */
    private const API_PREFIXES = ['/wp-admin/', '/wp-json/', '/wp-content/themes/', '/wp-content/plugins/', '/feed'];

    private const API_SUFFIXES = ['/feed/'];

    private const API_EXACT = ['/robots.txt', '/xmlrpc.php', '/wp-cron.php'];

    private const API_REGEX = '#^/(wp-)?sitemap.*\.xml$#';

    private const UPLOADS_PREFIX = '/wp-content/uploads/';

    public function handle(PressableClient $pressable): int
    {
        if (! $pressable->isConfigured()) {
            $this->error('Pressable API credentials not configured.');

            return self::FAILURE;
        }

        $sites = $this->resolveSites();
        if ($sites->isEmpty()) {
            $this->warn('No Companion-equipped Pressable sites matched.');

            return self::SUCCESS;
        }

        $stats = ['ok' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($sites as $site) {
            $caps = $site->companion_capabilities ?? [];
            if (! in_array('traffic-report', $caps, true)) {
                $stats['skipped']++;
                $this->line("  [skip] {$site->domain} — capability 'traffic-report' not advertised (Companion 1.16.0+ required)");

                continue;
            }

            try {
                $report = $this->buildReport($pressable, $site);
            } catch (Throwable $e) {
                $stats['failed']++;
                Log::warning('companion.pressable_traffic_report.fetch_failed', [
                    'site' => $site->domain,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("  [fail] {$site->domain}: Pressable fetch failed — {$e->getMessage()}");

                continue;
            }

            try {
                (new ClockworkCompanionClient($site))->pushTrafficReport($report);
            } catch (Throwable $e) {
                $stats['failed']++;
                Log::warning('companion.pressable_traffic_report.push_failed', [
                    'site' => $site->domain,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("  [fail] {$site->domain}: push failed — {$e->getMessage()}");

                continue;
            }

            $stats['ok']++;
            $today = (int) ($report['totals']['today'] ?? 0);
            $month = (int) ($report['totals']['month_30d'] ?? 0);
            $rows = count($report['daily']);
            $this->line("  [ok]   {$site->domain} — today={$today} 30d={$month} rows={$rows}");
        }

        $msg = sprintf(
            'companion.pressable_traffic_report.push complete: ok=%d failed=%d skipped=%d',
            $stats['ok'],
            $stats['failed'],
            $stats['skipped'],
        );
        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReport(PressableClient $pressable, Site $site): array
    {
        $pressableSiteId = $site->pressable_site_id;

        $daily = $this->buildDailyRows($pressable, $pressableSiteId);

        $today = Carbon::today()->toDateString();
        $byDate = collect($daily)->keyBy('date');
        $todayVisits = (int) ($byDate->get($today)['visits'] ?? 0);
        $weekVisits = (int) collect($daily)
            ->filter(fn ($r) => $r['date'] >= Carbon::today()->subDays(6)->toDateString())
            ->sum('visits');
        $monthVisits = (int) collect($daily)->sum('visits');

        $topPaths = $this->buildTopPaths($pressable, $pressableSiteId);

        $hasData = collect($daily)->sum('requests') > 0;

        return [
            'source' => 'pressable',
            'fetched_at' => CarbonImmutable::now()->toIso8601String(),
            'refresh_cadence' => 'daily',
            'daily' => $daily,
            'totals' => [
                'today' => $todayVisits,
                'week_7d' => $weekVisits,
                'month_30d' => $monthVisits,
            ],
            'today_partial' => $hasData && $byDate->has($today),
            'has_data' => $hasData,
            'top_paths' => $topPaths['buckets'],
            'top_paths_date' => $topPaths['date'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDailyRows(PressableClient $pressable, string $pressableSiteId): array
    {
        $requestsByDay = $this->requestsByDayAndStatus($pressable, $pressableSiteId);
        $visitsByDay = $this->uniquesByDay($pressable, $pressableSiteId);

        $today = Carbon::today();
        $start = $today->copy()->subDays(29);

        $daily = [];
        for ($cursor = $start->copy(); $cursor->lte($today); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $statuses = $requestsByDay[$key] ?? ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0];
            $daily[] = [
                'date' => $key,
                'requests' => array_sum($statuses),
                'visits' => $visitsByDay[$key] ?? 0,
                'status_2xx' => $statuses['2xx'],
                'status_3xx' => $statuses['3xx'],
                'status_4xx' => $statuses['4xx'],
                'status_5xx' => $statuses['5xx'],
            ];
        }

        return $daily;
    }

    /**
     * Edge Logs family: requests × http_status, auto-resolved to 8-hour
     * buckets over a 1-month window (not daily) — aggregated into calendar
     * days here.
     *
     * @return array<string, array{'2xx': int, '3xx': int, '4xx': int, '5xx': int}>
     */
    private function requestsByDayAndStatus(PressableClient $pressable, string $pressableSiteId): array
    {
        $periods = $pressable->siteMetrics($pressableSiteId, ['requests'], ['http_status'], 'Past 1 month');

        $out = [];
        foreach ($periods as $period) {
            $date = gmdate('Y-m-d', (int) ($period['timestamp'] ?? 0));
            $out[$date] ??= ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0];

            foreach ($period['dimension'] ?? [] as $status => $count) {
                $bucket = match (true) {
                    (int) $status >= 200 && (int) $status < 300 => '2xx',
                    (int) $status >= 300 && (int) $status < 400 => '3xx',
                    (int) $status >= 400 && (int) $status < 500 => '4xx',
                    (int) $status >= 500 => '5xx',
                    default => null,
                };
                if ($bucket !== null) {
                    $out[$date][$bucket] += (int) $count;
                }
            }
        }

        return $out;
    }

    /**
     * Uniques & Views family: uniques × hostname, real daily (86400s)
     * buckets. Summed across every hostname variant (bare + www) for one
     * unified per-day visitor count.
     *
     * @return array<string, int>
     */
    private function uniquesByDay(PressableClient $pressable, string $pressableSiteId): array
    {
        $periods = $pressable->siteMetrics($pressableSiteId, ['uniques'], ['hostname'], 'Past 1 month');

        $out = [];
        foreach ($periods as $period) {
            $date = gmdate('Y-m-d', (int) ($period['timestamp'] ?? 0));
            $sum = 0;
            foreach ($period['hostname']['uniques'] ?? $period['dimension'] ?? [] as $count) {
                $sum += (int) $count;
            }
            $out[$date] = ($out[$date] ?? 0) + $sum;
        }

        return $out;
    }

    /**
     * Top paths for the most recent day with traffic — categorised into the
     * same pages/api/uploads buckets as the SpinupWP rollup, capped at 10
     * each. Pressable has no per-day breakdown for this (would need one
     * metrics call per day), so this reflects the last 24h rather than a
     * specific historical day — close enough for "what's getting hit right
     * now."
     *
     * @return array{buckets: array{pages: list<array>, api: list<array>, uploads: list<array>}, date: string}
     */
    private function buildTopPaths(PressableClient $pressable, string $pressableSiteId): array
    {
        $periods = $pressable->siteMetrics($pressableSiteId, ['requests'], ['path'], 'Past 1 day');

        $counts = [];
        foreach ($periods as $period) {
            foreach ($period['dimension'] ?? [] as $path => $count) {
                $counts[$path] = ($counts[$path] ?? 0) + (int) $count;
            }
        }
        arsort($counts);

        $buckets = ['pages' => [], 'api' => [], 'uploads' => []];
        foreach ($counts as $path => $hits) {
            $bucket = $this->categorisePath((string) $path);
            if (count($buckets[$bucket]) >= 10) {
                continue;
            }
            $buckets[$bucket][] = ['path' => $path, 'hits' => $hits];
        }

        return ['buckets' => $buckets, 'date' => Carbon::today()->toDateString()];
    }

    private function categorisePath(string $path): string
    {
        if (str_starts_with($path, self::UPLOADS_PREFIX)) {
            return 'uploads';
        }

        foreach (self::API_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return 'api';
            }
        }
        foreach (self::API_SUFFIXES as $suffix) {
            if (str_ends_with($path, $suffix)) {
                return 'api';
            }
        }
        if (in_array($path, self::API_EXACT, true)) {
            return 'api';
        }
        if (preg_match(self::API_REGEX, $path) === 1) {
            return 'api';
        }

        return 'pages';
    }

    /**
     * @return Collection<int, Site>
     */
    private function resolveSites(): Collection
    {
        $q = Site::query()
            ->where('companion_installed', true)
            ->whereNotNull('companion_secret')
            ->where('hosting_provider', Site::HOSTING_PROVIDER_PRESSABLE)
            ->whereNotNull('pressable_site_id');

        if ($needle = $this->option('site')) {
            $q->where(function ($q) use ($needle) {
                $q->where('id', $needle)->orWhere('domain', $needle);
            });
        }

        return $q->orderBy('domain')->get();
    }
}
