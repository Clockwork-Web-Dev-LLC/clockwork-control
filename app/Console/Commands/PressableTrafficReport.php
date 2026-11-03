<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteTrafficDaily;
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
 *
 * Two jobs, deliberately independent of each other:
 *  1. History. Every Pressable site with a pressable_site_id gets the days
 *     Pressable actually returned upserted into site_traffic_daily, which
 *     is what the monthly client report and /capacity read. This runs
 *     whether or not Companion is installed, reachable, or new enough, so a
 *     Companion problem never leaves a report without traffic.
 *  2. Companion push. Sites that can take it also get the zero-filled
 *     30-day payload for their wp-admin Traffic page.
 */
class PressableTrafficReport extends Command
{
    protected $signature = 'clockwork:pressable-traffic-report
        {--site= : Limit to a single site (id or domain)}';

    protected $description = 'Record daily traffic for every Pressable site from Pressable\'s own metrics API, and push the 30-day rollup to each Companion-equipped one.';

    /** Days in the zero-filled Companion payload, today included. */
    private const PAYLOAD_DAYS = 30;

    /**
     * Days written to site_traffic_daily, today included. Shorter than the
     * payload on purpose: the API's "Past 1 month" window starts part-way
     * through a day that can be as recent as 28 days ago (after February),
     * and that first, partial day must not overwrite a complete row an
     * earlier run wrote. Every day is still rewritten 28 times while it
     * stays in the window, so history coverage is unchanged.
     */
    private const HISTORY_DAYS = 28;

    private const REQUEST_COLUMNS = ['requests', 'status_2xx', 'status_3xx', 'status_4xx', 'status_5xx'];

    private const VISIT_COLUMNS = ['unique_ips', 'visits'];

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
            $this->warn('No Pressable sites matched.');

            return self::SUCCESS;
        }

        // recorded = history written; pushed = also pushed to Companion;
        // push_skipped = recorded, but the site can't take the push.
        $stats = ['recorded' => 0, 'pushed' => 0, 'push_skipped' => 0, 'failed' => 0];

        foreach ($sites as $site) {
            try {
                $requestsByDay = $this->requestsByDayAndStatus($pressable, $site->pressable_site_id);
                $visitsByDay = $this->uniquesByDay($pressable, $site->pressable_site_id);
            } catch (Throwable $e) {
                $this->fetchFailed($site, $e, $stats);

                continue;
            }

            try {
                $days = $this->recordDailyTraffic($site, $requestsByDay, $visitsByDay);
            } catch (Throwable $e) {
                $stats['failed']++;
                Log::warning('companion.pressable_traffic_report.record_failed', [
                    'site' => $site->domain,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("  [fail] {$site->domain}: recording daily traffic failed — {$e->getMessage()}");

                continue;
            }
            $stats['recorded']++;

            $skipReason = $this->pushSkipReason($site);
            if ($skipReason !== null) {
                $stats['push_skipped']++;
                $this->line("  [rec]  {$site->domain} — days={$days}; push skipped: {$skipReason}");

                continue;
            }

            try {
                $report = $this->buildReport($pressable, $site, $requestsByDay, $visitsByDay);
            } catch (Throwable $e) {
                $this->fetchFailed($site, $e, $stats);

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

            $stats['pushed']++;
            $today = (int) ($report['totals']['today'] ?? 0);
            $month = (int) ($report['totals']['month_30d'] ?? 0);
            $this->line("  [ok]   {$site->domain} — today={$today} 30d={$month} days={$days}");
        }

        $msg = sprintf(
            'companion.pressable_traffic_report complete: recorded=%d pushed=%d push_skipped=%d failed=%d',
            $stats['recorded'],
            $stats['pushed'],
            $stats['push_skipped'],
            $stats['failed'],
        );
        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function fetchFailed(Site $site, Throwable $e, array &$stats): void
    {
        $stats['failed']++;
        Log::warning('companion.pressable_traffic_report.fetch_failed', [
            'site' => $site->domain,
            'error' => $e->getMessage(),
        ]);
        $this->warn("  [fail] {$site->domain}: Pressable fetch failed — {$e->getMessage()}");
    }

    /**
     * Why this site can't take the Companion push, or null when it can.
     */
    private function pushSkipReason(Site $site): ?string
    {
        // Raw value: a null check doesn't need the encrypted secret decrypted.
        if (! $site->companion_installed || $site->getRawOriginal('companion_secret') === null) {
            return 'Companion not installed';
        }

        if (! in_array('traffic-report', $site->companion_capabilities ?? [], true)) {
            return "capability 'traffic-report' not advertised (Companion 1.16.0+ required)";
        }

        return null;
    }

    /**
     * Upsert into site_traffic_daily only what Pressable actually returned.
     *
     * A day the API has no bucket for (before the site moved to Pressable,
     * or a gap on Pressable's side) is left alone rather than written as
     * zeros, so it can't erase an earlier row — including one the previous
     * host's access-log rollup wrote. The same goes per metric family: a
     * day with request buckets but no uniques bucket updates only the
     * request columns. bytes_sent and top_paths are never updated; Pressable
     * has no per-day figure for either.
     *
     * @param  array<string, array{'2xx': int, '3xx': int, '4xx': int, '5xx': int}>  $requestsByDay
     * @param  array<string, int>  $visitsByDay
     * @return int days written
     */
    private function recordDailyTraffic(Site $site, array $requestsByDay, array $visitsByDay): int
    {
        $from = Carbon::today()->subDays(self::HISTORY_DAYS - 1)->toDateString();
        $to = Carbon::today()->toDateString();

        $dates = array_filter(
            array_unique([...array_keys($requestsByDay), ...array_keys($visitsByDay)]),
            fn ($date) => $date >= $from && $date <= $to,
        );

        $now = now();
        $batches = [];
        foreach ($dates as $date) {
            $statuses = $requestsByDay[$date] ?? null;
            $visits = $visitsByDay[$date] ?? null;

            $update = [
                ...($statuses !== null ? self::REQUEST_COLUMNS : []),
                ...($visits !== null ? self::VISIT_COLUMNS : []),
                'updated_at',
            ];

            $key = implode(',', $update);
            $batches[$key]['update'] = $update;
            // Columns outside $update only take effect when the row is new.
            $batches[$key]['rows'][] = [
                'site_id' => $site->id,
                'date' => $date,
                'requests' => $statuses !== null ? array_sum($statuses) : 0,
                'unique_ips' => $visits ?? 0,
                'visits' => $visits ?? 0,
                'bytes_sent' => 0,
                'status_2xx' => $statuses['2xx'] ?? 0,
                'status_3xx' => $statuses['3xx'] ?? 0,
                'status_4xx' => $statuses['4xx'] ?? 0,
                'status_5xx' => $statuses['5xx'] ?? 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($batches as $batch) {
            SiteTrafficDaily::upsert($batch['rows'], ['site_id', 'date'], $batch['update']);
        }

        return count($dates);
    }

    /**
     * @param  array<string, array{'2xx': int, '3xx': int, '4xx': int, '5xx': int}>  $requestsByDay
     * @param  array<string, int>  $visitsByDay
     * @return array<string, mixed>
     */
    private function buildReport(PressableClient $pressable, Site $site, array $requestsByDay, array $visitsByDay): array
    {
        $pressableSiteId = $site->pressable_site_id;

        $daily = $this->buildDailyRows($requestsByDay, $visitsByDay);

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
     * The Companion payload's `daily` array: every day of the window, with
     * zeros for days Pressable returned nothing for, because the wp-admin
     * chart expects a continuous series. Never written to the DB as-is —
     * see recordDailyTraffic().
     *
     * @param  array<string, array{'2xx': int, '3xx': int, '4xx': int, '5xx': int}>  $requestsByDay
     * @param  array<string, int>  $visitsByDay
     * @return array<int, array<string, mixed>>
     */
    private function buildDailyRows(array $requestsByDay, array $visitsByDay): array
    {
        $today = Carbon::today();
        $start = $today->copy()->subDays(self::PAYLOAD_DAYS - 1);

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
     * days here. A bucket with no counts in it is not data, so a day made
     * only of empty buckets is left out, the same as a day with none.
     *
     * @return array<string, array{'2xx': int, '3xx': int, '4xx': int, '5xx': int}>
     */
    private function requestsByDayAndStatus(PressableClient $pressable, string $pressableSiteId): array
    {
        $periods = $pressable->siteMetrics($pressableSiteId, ['requests'], ['http_status'], 'Past 1 month');

        $out = [];
        foreach ($periods as $period) {
            $dimension = $period['dimension'] ?? [];
            if (! is_array($dimension) || $dimension === []) {
                continue;
            }

            $date = gmdate('Y-m-d', (int) ($period['timestamp'] ?? 0));
            $out[$date] ??= ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0];

            foreach ($dimension as $status => $count) {
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
     * unified per-day visitor count. Empty buckets are skipped, as above.
     *
     * @return array<string, int>
     */
    private function uniquesByDay(PressableClient $pressable, string $pressableSiteId): array
    {
        $periods = $pressable->siteMetrics($pressableSiteId, ['uniques'], ['hostname'], 'Past 1 month');

        $out = [];
        foreach ($periods as $period) {
            $counts = $period['hostname']['uniques'] ?? $period['dimension'] ?? [];
            if (! is_array($counts) || $counts === []) {
                continue;
            }

            $date = gmdate('Y-m-d', (int) ($period['timestamp'] ?? 0));
            $sum = 0;
            foreach ($counts as $count) {
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
     * Every Pressable site the API can be asked about, Companion or not —
     * the history write needs all of them; pushSkipReason() decides which
     * also get the Companion push.
     *
     * @return Collection<int, Site>
     */
    private function resolveSites(): Collection
    {
        $q = Site::query()
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
