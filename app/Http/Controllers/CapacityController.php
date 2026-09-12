<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;
use App\Models\SiteTrafficDaily;
use App\Models\Tag;
use App\Services\Process\BackgroundArtisan;
use App\Services\Runtime\RuntimeEolEvaluator;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CapacityController extends Controller
{
    /**
     * Default threshold in WP-Engine-style monthly visits above which a site on a SHARED server
     * is flagged for billing/migration attention.
     */
    public const DEFAULT_VISIT_THRESHOLD = 30_000;

    public const VISIT_THRESHOLD = self::DEFAULT_VISIT_THRESHOLD;

    public const DEFAULT_ROLLING_DAYS = 30;

    public const DEFAULT_TRENDING_WINDOW = 7;

    public const SETTING_VISIT_THRESHOLD = 'capacity.visit_threshold';

    public const SETTING_ROLLING_DAYS = 'capacity.rolling_days';

    public const SETTING_TRENDING_WINDOW = 'capacity.trending_window_days';

    public const SETTING_CPU_THRESHOLD = 'capacity.cpu_threshold';

    public const SETTING_MEMORY_THRESHOLD = 'capacity.memory_threshold';

    public const SETTING_DISK_THRESHOLD = 'capacity.disk_threshold';

    public function visitThreshold(): int
    {
        return (int) app(Settings::class)->get(self::SETTING_VISIT_THRESHOLD, self::DEFAULT_VISIT_THRESHOLD);
    }

    public function rollingDays(): int
    {
        return (int) app(Settings::class)->get(self::SETTING_ROLLING_DAYS, self::DEFAULT_ROLLING_DAYS);
    }

    public function trendingWindow(): int
    {
        return (int) app(Settings::class)->get(self::SETTING_TRENDING_WINDOW, self::DEFAULT_TRENDING_WINDOW);
    }

    /**
     * A shared server is classified "Pressure" when any of these 24h-average resource
     * metrics meets/exceeds its yellow threshold. Anything not matching falls into
     * "Headroom". Mirrors IssueCounter::countHotServers() so the badge math agrees.
     */
    private function pressureThresholds(): array
    {
        $settings = app(Settings::class);

        return [
            'cpu' => (float) $settings->get(self::SETTING_CPU_THRESHOLD, config('clockwork.monitoring.cpu_yellow_threshold', 70)),
            'memory' => (float) $settings->get(self::SETTING_MEMORY_THRESHOLD, config('clockwork.monitoring.memory_yellow_threshold', 80)),
            'disk' => (float) $settings->get(self::SETTING_DISK_THRESHOLD, config('clockwork.monitoring.disk_yellow_threshold', 85)),
        ];
    }

    private function isUnderPressure(array $row, array $thresholds): bool
    {
        return ($row['avg_cpu'] !== null && $row['avg_cpu'] >= $thresholds['cpu'])
            || ($row['avg_memory'] !== null && $row['avg_memory'] >= $thresholds['memory'])
            || ($row['avg_disk'] !== null && $row['avg_disk'] >= $thresholds['disk']);
    }

    public function index(): View
    {
        $runtimeEol = $this->runtimeEolData();
        $sharedTagId = Tag::where('name', 'Shared')->value('id');

        if ($sharedTagId === null) {
            return view('dashboard.capacity', [
                'sharedTagMissing' => true,
                'pressure' => collect(),
                'headroom' => collect(),
                'overQuota' => collect(),
                'threshold' => $this->visitThreshold(),
                'monthLabel' => CarbonImmutable::now()->format('F Y'),
                'rollingDays' => $this->rollingDays(),
                'trendingWindow' => $this->trendingWindow(),
                'runtimeEol' => $runtimeEol,
            ]);
        }

        $sharedServers = Server::query()
            ->where('is_ignored', false)
            ->whereHas('tags', fn ($q) => $q->where('tags.id', $sharedTagId))
            ->withCount('sites')
            ->get();

        $serverIds = $sharedServers->pluck('id');

        $cpuMemDsk = ServerMetric::query()
            ->whereIn('server_id', $serverIds)
            ->where('recorded_at', '>=', now()->subDay())
            ->selectRaw('server_id, AVG(cpu_pct) AS avg_cpu, AVG(memory_pct) AS avg_memory, AVG(disk_pct) AS avg_disk, MAX(cpu_pct) AS peak_cpu')
            ->groupBy('server_id')
            ->get()
            ->keyBy('server_id');

        // Two windows matter:
        //   - rolling days: early-warning signal — triggers the "over quota" alert
        //     before the calendar month closes, so the user can act in time.
        //   - month-to-date: the calendar window the user actually invoices on.
        $today = CarbonImmutable::now()->startOfDay();
        $monthStart = CarbonImmutable::now()->startOfMonth();
        $rollingDays = $this->rollingDays();
        $trendingWindow = $this->trendingWindow();
        $threshold = $this->visitThreshold();
        $rolling30Start = $today->subDays($rollingDays - 1);

        // Headroom table shows rolling visits — more honest read of the box's load.
        $visitsByServer = SiteTrafficDaily::query()
            ->join('sites', 'sites.id', '=', 'site_traffic_daily.site_id')
            ->whereIn('sites.server_id', $serverIds)
            ->where('site_traffic_daily.date', '>=', $rolling30Start->toDateString())
            ->selectRaw('sites.server_id, SUM(site_traffic_daily.visits) AS visits')
            ->groupBy('sites.server_id')
            ->pluck('visits', 'server_id');

        $rows = $sharedServers->map(function (Server $s) use ($cpuMemDsk, $visitsByServer) {
            $m = $cpuMemDsk->get($s->id);

            return [
                'server' => $s,
                'avg_cpu' => $m?->avg_cpu !== null ? (float) $m->avg_cpu : null,
                'avg_memory' => $m?->avg_memory !== null ? (float) $m->avg_memory : null,
                'avg_disk' => $m?->avg_disk !== null ? (float) $m->avg_disk : null,
                'peak_cpu' => $m?->peak_cpu !== null ? (float) $m->peak_cpu : null,
                'site_count' => (int) $s->sites_count,
                'visits_mtd' => (int) ($visitsByServer[$s->id] ?? 0),
            ];
        });

        // Partition into mutually-exclusive lists: a server is either under pressure
        // (any 24h average over its yellow threshold) or has headroom — never both.
        $thresholds = $this->pressureThresholds();

        $pressure = $rows
            ->filter(fn ($r) => $this->isUnderPressure($r, $thresholds))
            ->sortByDesc(fn ($r) => max($r['avg_cpu'] ?? 0, $r['avg_memory'] ?? 0, $r['avg_disk'] ?? 0))
            ->values();

        $headroom = $rows
            ->reject(fn ($r) => $this->isUnderPressure($r, $thresholds))
            ->sortBy(fn ($r) => max($r['avg_cpu'] ?? 0, $r['avg_memory'] ?? 0, $r['avg_disk'] ?? 0))
            ->values();

        // Over-quota sites: rolling-day sum > threshold (early warning), surfaced
        // alongside the calendar-month-to-date number that drives invoicing.
        // Pull rolling, MTD, trending-window visits per site in one pass — used by
        // both the "Over visit threshold" and "Trending toward overage" tables.
        $perSiteVisits = SiteTrafficDaily::query()
            ->join('sites', 'sites.id', '=', 'site_traffic_daily.site_id')
            ->whereIn('sites.server_id', $serverIds)
            ->where('site_traffic_daily.date', '>=', $rolling30Start->toDateString())
            ->selectRaw('
                site_traffic_daily.site_id,
                SUM(site_traffic_daily.visits) AS rolling_visits,
                SUM(CASE WHEN site_traffic_daily.date >= ? THEN site_traffic_daily.visits ELSE 0 END) AS month_visits,
                SUM(CASE WHEN site_traffic_daily.date >= ? THEN site_traffic_daily.visits ELSE 0 END) AS last_7d_visits
            ', [$monthStart->toDateString(), $today->subDays($trendingWindow - 1)->toDateString()])
            ->groupBy('site_traffic_daily.site_id')
            ->get();

        $overQuota = $perSiteVisits
            ->filter(fn ($row) => (int) $row->rolling_visits > $threshold)
            ->sortByDesc('rolling_visits')
            ->map(function ($row) use ($threshold) {
                $site = Site::with('server')->find($row->site_id);
                if (! $site) {
                    return null;
                }
                $rollingVisits = (int) $row->rolling_visits;

                return [
                    'site' => $site,
                    'rolling_visits' => $rollingVisits,
                    'month_visits' => (int) $row->month_visits,
                    'over_by' => $rollingVisits - $threshold,
                    'pct_over' => $threshold > 0
                        ? round(($rollingVisits - $threshold) / $threshold * 100, 1)
                        : 0.0,
                    'last_7d_visits' => (int) $row->last_7d_visits,
                ];
            })
            ->filter()
            ->values();

        // Trending toward overage: sites NOT currently over the rolling
        // threshold, but whose trending rate projects past it. Linear extrapolation
        // (last_N_days × rollingDays / trendingWindow). Catches ramping sites before they cross the line so
        // we can have the conversation BEFORE the overage hits the invoice.
        $trending = $perSiteVisits
            ->filter(function ($row) use ($trendingWindow, $rollingDays, $threshold) {
                $rolling = (int) $row->rolling_visits;
                $last7 = (int) $row->last_7d_visits;
                if ($rolling > $threshold) {
                    return false; // already in Over-visit-threshold table
                }
                if ($last7 <= 0) {
                    return false; // no recent traffic, no projection
                }
                $projected = (int) round($last7 * ($rollingDays / $trendingWindow));

                return $projected > $threshold;
            })
            ->map(function ($row) use ($trendingWindow, $rollingDays, $threshold) {
                $site = Site::with('server')->find($row->site_id);
                if (! $site) {
                    return null;
                }
                $last7 = (int) $row->last_7d_visits;
                $projected = (int) round($last7 * ($rollingDays / $trendingWindow));

                return [
                    'site' => $site,
                    'rolling_visits' => (int) $row->rolling_visits,
                    'month_visits' => (int) $row->month_visits,
                    'last_7d_visits' => $last7,
                    'daily_avg_7d' => (int) round($last7 / $trendingWindow),
                    'projected_30d' => $projected,
                    'projected_over_by' => $projected - $threshold,
                    'projected_pct_over' => $threshold > 0
                        ? round(($projected - $threshold) / $threshold * 100, 1)
                        : 0.0,
                ];
            })
            ->filter()
            ->sortByDesc('projected_30d')
            ->values();

        // Resource leaderboard: average + peak CPU and memory per server
        // over the last 7 and 30 day windows. Reads from server_metrics
        // (populated every 10 min by clockwork:poll-servers from DO's
        // monitoring API). Single query, two windows in CASE expressions
        // so we hit server_metrics once instead of twice.
        $now = now();
        $window7Start = $now->copy()->subDays(7);
        $window30Start = $now->copy()->subDays(30);

        $allServers = Server::query()
            ->where('is_ignored', false)
            ->orderBy('name')
            ->get();

        $metricRows = DB::table('server_metrics')
            ->whereIn('server_id', $allServers->pluck('id'))
            ->where('recorded_at', '>=', $window30Start)
            ->selectRaw('
                server_id,
                AVG(CASE WHEN recorded_at >= ? THEN cpu_pct    END) AS avg_cpu_7d,
                MAX(CASE WHEN recorded_at >= ? THEN cpu_pct    END) AS peak_cpu_7d,
                AVG(CASE WHEN recorded_at >= ? THEN memory_pct END) AS avg_mem_7d,
                MAX(CASE WHEN recorded_at >= ? THEN memory_pct END) AS peak_mem_7d,
                AVG(cpu_pct)                                       AS avg_cpu_30d,
                MAX(cpu_pct)                                       AS peak_cpu_30d,
                AVG(memory_pct)                                    AS avg_mem_30d,
                MAX(memory_pct)                                    AS peak_mem_30d,
                COUNT(*)                                           AS samples_30d,
                SUM(CASE WHEN recorded_at >= ? THEN 1 ELSE 0 END)  AS samples_7d
            ', [$window7Start, $window7Start, $window7Start, $window7Start, $window7Start])
            ->groupBy('server_id')
            ->get()
            ->keyBy('server_id');

        // 7-day CPU sparkline data — bucket by 6-hour windows so each server
        // has ~28 points (manageable for inline SVG, dense enough to show
        // the trend). One grouped query covers the whole fleet at once.
        $sparkRows = DB::table('server_metrics')
            ->whereIn('server_id', $allServers->pluck('id'))
            ->where('recorded_at', '>=', $window7Start)
            ->selectRaw('
                server_id,
                FLOOR(UNIX_TIMESTAMP(recorded_at) / 21600) AS bucket,
                AVG(cpu_pct) AS avg_cpu
            ')
            ->groupBy('server_id', 'bucket')
            ->orderBy('server_id')
            ->orderBy('bucket')
            ->get();
        $sparkByServer = $sparkRows
            ->groupBy('server_id')
            ->map(fn ($g) => $g->map(fn ($r) => $r->avg_cpu === null ? null : round((float) $r->avg_cpu, 2))->values()->all());

        $resourceLeaderboard = $allServers->map(function (Server $s) use ($metricRows, $sparkByServer) {
            $m = $metricRows->get($s->id);

            return [
                'server' => $s,
                'avg_cpu_7d' => $m && $m->avg_cpu_7d !== null ? (float) $m->avg_cpu_7d : null,
                'peak_cpu_7d' => $m && $m->peak_cpu_7d !== null ? (float) $m->peak_cpu_7d : null,
                'avg_mem_7d' => $m && $m->avg_mem_7d !== null ? (float) $m->avg_mem_7d : null,
                'peak_mem_7d' => $m && $m->peak_mem_7d !== null ? (float) $m->peak_mem_7d : null,
                'avg_cpu_30d' => $m && $m->avg_cpu_30d !== null ? (float) $m->avg_cpu_30d : null,
                'peak_cpu_30d' => $m && $m->peak_cpu_30d !== null ? (float) $m->peak_cpu_30d : null,
                'avg_mem_30d' => $m && $m->avg_mem_30d !== null ? (float) $m->avg_mem_30d : null,
                'peak_mem_30d' => $m && $m->peak_mem_30d !== null ? (float) $m->peak_mem_30d : null,
                'samples_7d' => $m ? (int) $m->samples_7d : 0,
                'samples_30d' => $m ? (int) $m->samples_30d : 0,
                'cpu_spark_7d' => $sparkByServer->get($s->id, []),
            ];
        })->values();

        // Per-site CPU leaderboard (7d). Reads from site_metrics, populated
        // by clockwork:pull-site-metrics every 15 min from each Companion
        // 1.17.0+ site. PHP CPU only — see capacity.blade.php tooltip on
        // what the data covers and what it misses (DB/nginx/Redis).
        $siteWindow7Start = $now->copy()->subDays(7);
        $siteLeaderRows = DB::table('site_metrics')
            ->selectRaw('
                site_id,
                SUM(cpu_us_total)  AS cpu_us_7d,
                SUM(wall_us_total) AS wall_us_7d,
                MAX(mem_peak_bytes) AS peak_mem_7d,
                SUM(requests)       AS reqs_7d
            ')
            ->where('bucket_at', '>=', $siteWindow7Start)
            ->groupBy('site_id')
            ->orderByDesc('cpu_us_7d')
            ->limit(10)
            ->get()
            ->keyBy('site_id');

        $siteLookups = Site::query()
            ->whereIn('id', $siteLeaderRows->keys())
            ->with('server')
            ->get()
            ->keyBy('id');

        $siteLeaderboard = $siteLeaderRows->map(function ($row) use ($siteLookups) {
            $site = $siteLookups->get($row->site_id);
            if (! $site) {
                return null;
            }

            return [
                'site' => $site,
                'cpu_seconds_7d' => (int) round($row->cpu_us_7d / 1_000_000),
                'wall_seconds_7d' => (int) round($row->wall_us_7d / 1_000_000),
                'peak_mem_bytes_7d' => (int) $row->peak_mem_7d,
                'requests_7d' => (int) $row->reqs_7d,
            ];
        })->filter()->values();

        return view('dashboard.capacity', [
            'sharedTagMissing' => false,
            'pressure' => $pressure,
            'headroom' => $headroom,
            'overQuota' => $overQuota,
            'trending' => $trending,
            'trendingWindow' => $trendingWindow,
            'resourceLeaderboard' => $resourceLeaderboard,
            'siteLeaderboard' => $siteLeaderboard,
            'siteMetricsEnabled' => (bool) app(Settings::class)->get('monitoring.site_metrics_enabled', true),
            'threshold' => $threshold,
            'monthLabel' => $monthStart->format('F Y'),
            'rollingDays' => $rollingDays,
            'pressureThresholds' => $thresholds,
            'runtimeEol' => $runtimeEol,
        ]);
    }

    /**
     * Toggle per-site CPU/memory metrics collection.
     *
     * Three things happen in order:
     *  1. Flip `monitoring.site_metrics_enabled` so the scheduled ingest's
     *     `->when()` guard short-circuits on the next tick.
     *  2. Push the new state to every site advertising the
     *     `resource-sampler-toggle` capability (Companion 1.17.1+) so the
     *     sampler stops writing rows at source — eliminates the per-request
     *     DB write overhead while paused.
     *  3. Sites without the toggle capability (e.g. still on 1.17.0) keep
     *     sampling locally but Clockwork stops ingesting, so they cost the
     *     sampler's per-request UPSERT but produce no further leaderboard
     *     data. Documented in the flash message so the operator knows.
     *
     * The Settings flip is in-request (one row). The Companion fan-out
     * runs in the background via BackgroundArtisan — a 132-site HTTP
     * push cannot finish before the proxy times out.
     */
    public function toggleSiteMetrics(Settings $settings): RedirectResponse
    {
        $current = (bool) $settings->get('monitoring.site_metrics_enabled', true);
        $newState = ! $current;
        $settings->put('monitoring.site_metrics_enabled', $newState);

        $verb = $newState ? 'resumed' : 'paused';
        $result = app(BackgroundArtisan::class)->start(
            'capacity.push_sampler_state',
            ['clockwork:push-site-metrics-state'],
            600,
            'site-metrics-toggle-bg',
        );

        $msg = "Per-site CPU collection {$verb}.";
        if ($result->alreadyRunning()) {
            $msg .= ' A Companion push is already running.';
        } elseif ($result->failed()) {
            return back()->with('status_error', $msg.' '.$result->error);
        } else {
            $msg .= ' Companion sites are being notified in the background.';
        }

        return back()->with('status', $msg);
    }

    public function settings(Settings $settings): View
    {
        $sharedTagId = Tag::where('name', 'Shared')->value('id');
        $sharedServerCount = $sharedTagId !== null
            ? Server::query()
                ->where('is_ignored', false)
                ->whereHas('tags', fn ($q) => $q->where('tags.id', $sharedTagId))
                ->count()
            : 0;

        return view('dashboard.capacity-settings', [
            'visitThreshold' => (int) $settings->get(self::SETTING_VISIT_THRESHOLD, self::DEFAULT_VISIT_THRESHOLD),
            'rollingDays' => (int) $settings->get(self::SETTING_ROLLING_DAYS, self::DEFAULT_ROLLING_DAYS),
            'trendingWindow' => (int) $settings->get(self::SETTING_TRENDING_WINDOW, self::DEFAULT_TRENDING_WINDOW),
            'cpuThreshold' => (float) $settings->get(self::SETTING_CPU_THRESHOLD, config('clockwork.monitoring.cpu_yellow_threshold', 70)),
            'memoryThreshold' => (float) $settings->get(self::SETTING_MEMORY_THRESHOLD, config('clockwork.monitoring.memory_yellow_threshold', 80)),
            'diskThreshold' => (float) $settings->get(self::SETTING_DISK_THRESHOLD, config('clockwork.monitoring.disk_yellow_threshold', 85)),
            'siteMetricsEnabled' => (bool) $settings->get('monitoring.site_metrics_enabled', true),
            'sharedServerCount' => $sharedServerCount,
        ]);
    }

    public function updateSettings(Request $request, Settings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'visit_threshold' => ['required', 'integer', 'min:1000', 'max:50000000'],
            'rolling_days' => ['required', 'integer', 'min:7', 'max:90'],
            'trending_window_days' => ['required', 'integer', 'min:1', 'max:30'],
            'cpu_threshold' => ['required', 'numeric', 'min:10', 'max:100'],
            'memory_threshold' => ['required', 'numeric', 'min:10', 'max:100'],
            'disk_threshold' => ['required', 'numeric', 'min:10', 'max:100'],
        ]);

        $settings->putMany([
            self::SETTING_VISIT_THRESHOLD => (int) $validated['visit_threshold'],
            self::SETTING_ROLLING_DAYS => (int) $validated['rolling_days'],
            self::SETTING_TRENDING_WINDOW => (int) $validated['trending_window_days'],
            self::SETTING_CPU_THRESHOLD => (float) $validated['cpu_threshold'],
            self::SETTING_MEMORY_THRESHOLD => (float) $validated['memory_threshold'],
            self::SETTING_DISK_THRESHOLD => (float) $validated['disk_threshold'],
        ]);

        return redirect()
            ->route('capacity.settings')
            ->with('status', 'Capacity settings saved. Threshold updates take effect immediately across the Capacity dashboard and issue counting.');
    }

    /**
     * @return array{
     *   fetchedAt: ?string,
     *   isStale: bool,
     *   counts: array{eol: int, security_only: int, active_support: int, unknown: int},
     *   rows: list<array{site: Site, php_version: string, cycle: ?string, status: string, detail: string, date: ?string}>
     * }
     */
    private function runtimeEolData(): array
    {
        $settings = app(Settings::class);
        $phpCycles = (array) $settings->get('runtime_eol.php_cycles', []);
        $fetchedAt = $settings->get('runtime_eol.fetched_at');

        $isStale = empty($fetchedAt)
            || Carbon::parse((string) $fetchedAt)->diffInHours(now()) > 48;

        $evaluator = new RuntimeEolEvaluator;
        $sites = Site::query()
            ->where('is_inactive', false)
            ->with('server')
            ->orderBy('domain')
            ->get();

        $rows = [];
        $counts = [
            'eol' => 0,
            'security_only' => 0,
            'active_support' => 0,
            'unknown' => 0,
        ];

        foreach ($sites as $site) {
            $version = $site->companion_snapshot['environment']['php_version'] ?? null;
            if (! is_string($version) || trim($version) === '') {
                continue;
            }

            $classification = $evaluator->evaluate($phpCycles, $version);
            $status = $classification['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;

            $rows[] = [
                'site' => $site,
                'php_version' => $version,
                'cycle' => $classification['cycle'],
                'status' => $status,
                'detail' => $classification['detail'],
                'date' => $classification['date'],
            ];
        }

        return [
            'fetchedAt' => is_string($fetchedAt) ? $fetchedAt : null,
            'isStale' => $isStale,
            'counts' => $counts,
            'rows' => $rows,
        ];
    }
}
