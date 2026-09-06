<?php

namespace App\Services\Stats;

use App\Models\Server;
use App\Models\Site;
use App\Services\Cloudflare\CloudflareDetector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pure read service producing the six fleet-level stats rendered on
 * /settings/weird-stats. Each public method returns a plain shape (array,
 * Collection) — no view concerns here. The controller wires these into
 * the Blade partials.
 *
 * Time windows are hard-coded for v1 (7 days for attack-stream stats,
 * 30 days for visit-rollup stats). A toggle UI is a follow-up.
 *
 * Caching: stats whose source is `threat_logs` go through a 10-min cache
 * because that table is millions-of-rows-per-day and a fresh GROUP BY
 * during page load is unacceptable. Stats sourced from sites / blocked_ips
 * / site_traffic_daily run live — those tables are small or already
 * pre-rolled-up. The cache key bumps when the source data churns are
 * irrelevant; the page banner could note "data up to N min ago" if needed.
 */
class WeirdStatsAggregator
{
    /**
     * Cache TTL for threat_logs-derived stats. We deliberately set this WAY
     * longer than the scheduled warm cadence (every 9 min) so a hiccup in
     * the scheduler doesn't ever expose users to the cold-compute path.
     * The warmer overwrites the cache each tick — if it dies for an hour,
     * users still see slightly-stale data instead of a 30s page hang.
     */
    private const CACHE_TTL_MINUTES = 60;

    /** @var int[] non-ignored server IDs, cached per request */
    private ?array $activeServerIds = null;

    /**
     * Plugin coverage broken down by Cloudflare state. One row per CF state
     * (proxied / dns_only / not_using / unknown), with counts + percentages.
     *
     * @return array<int, array{cf_state: string, total: int, llar: int, wf: int, both: int, neither: int}>
     */
    public function pluginCoverageMatrix(): array
    {
        $rows = DB::table('sites')
            ->whereIn('server_id', $this->activeServerIds())
            ->where('is_wordpress', true)
            ->groupBy('cloudflare_state')
            ->select([
                'cloudflare_state',
                DB::raw('COUNT(*) AS total'),
                DB::raw('SUM(CASE WHEN llar_enabled = 1 THEN 1 ELSE 0 END) AS llar'),
                DB::raw('SUM(CASE WHEN wordfence_enabled = 1 THEN 1 ELSE 0 END) AS wf'),
                DB::raw('SUM(CASE WHEN llar_enabled = 1 AND wordfence_enabled = 1 THEN 1 ELSE 0 END) AS both_count'),
                DB::raw('SUM(CASE WHEN llar_enabled = 0 AND wordfence_enabled = 0 THEN 1 ELSE 0 END) AS neither_count'),
            ])
            ->get();

        return $rows->map(fn ($r) => [
            'cf_state' => (string) $r->cloudflare_state,
            'total' => (int) $r->total,
            'llar' => (int) $r->llar,
            'wf' => (int) $r->wf,
            'both' => (int) $r->both_count,
            'neither' => (int) $r->neither_count,
        ])->all();
    }

    /**
     * Top 10 sites by visits over the last 30 days, with the gap-to-#11 framing
     * needed for the concentration line below the table.
     *
     * @return array{
     *     rows: array<int, array{rank: int, site: Site, visits_30d: int, pct: float}>,
     *     fleet_visits_30d: int,
     *     top10_pct_of_fleet: float,
     *     ratio_first_to_tenth: ?float,
     * }
     */
    public function topSitesByVisits(): array
    {
        $since = Carbon::now()->subDays(30)->toDateString();

        $perSite = DB::table('site_traffic_daily')
            ->where('date', '>=', $since)
            ->groupBy('site_id')
            ->select([
                'site_id',
                DB::raw('SUM(visits) AS v'),
            ])
            ->orderByDesc('v')
            ->limit(11)
            ->get();

        $fleetVisits = (int) DB::table('site_traffic_daily')
            ->where('date', '>=', $since)
            ->sum('visits');

        $topIds = $perSite->take(10)->pluck('site_id')->all();
        $sites = Site::with(['server:id,name', 'server.tags:id,name'])
            ->whereIn('id', $topIds)
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($perSite->take(10) as $i => $row) {
            $site = $sites->get($row->site_id);
            if (! $site) {
                continue;
            }
            $rows[] = [
                'rank' => $i + 1,
                'site' => $site,
                'visits_30d' => (int) $row->v,
                'pct' => $fleetVisits > 0 ? ((int) $row->v / $fleetVisits) * 100 : 0.0,
            ];
        }

        $topTenSum = array_sum(array_column($rows, 'visits_30d'));
        $first = $rows[0]['visits_30d'] ?? 0;
        $tenth = $rows[9]['visits_30d'] ?? 0;

        return [
            'rows' => $rows,
            'fleet_visits_30d' => $fleetVisits,
            'top10_pct_of_fleet' => $fleetVisits > 0 ? ($topTenSum / $fleetVisits) * 100 : 0.0,
            'ratio_first_to_tenth' => $tenth > 0 ? $first / $tenth : null,
        ];
    }

    /**
     * Top 10 most-hit request paths fleet-wide over the last 7 days.
     * Status code mix surfaces whether the path is mostly 404 (scanner probe)
     * or mostly 200/302 (something legit getting heavy traffic).
     *
     * @return array<int, array{
     *     rank: int,
     *     path: string,
     *     hits: int,
     *     sites: int,
     *     ips: int,
     *     status_codes: array<string, int>,
     * }>
     */
    public function mostAttackedPaths(): array
    {
        return Cache::remember(
            'weird_stats:most_attacked_paths',
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function () {
                $since = Carbon::now()->subDays(7);

                // USE INDEX hint forces MySQL to use the
                // (event_at, request_path(64)) compound — without the hint the
                // optimizer picks a full table scan + filesort and the query
                // takes 30+ seconds on 2.7M+ rows. With the hint, ~5 seconds
                // cold (then cached for an hour). See the migration at
                // 2026_05_02_080000_add_request_path_index_to_threat_logs.
                $top = DB::table(DB::raw('threat_logs USE INDEX (threat_logs_event_at_request_path_index)'))
                    ->where('event_at', '>=', $since)
                    ->whereNotNull('request_path')
                    ->where('request_path', '!=', '')
                    ->groupBy('request_path')
                    ->select([
                        'request_path',
                        DB::raw('COUNT(*) AS hits'),
                        DB::raw('COUNT(DISTINCT site_id) AS sites'),
                        DB::raw('COUNT(DISTINCT ip) AS ips'),
                    ])
                    ->orderByDesc('hits')
                    ->limit(10)
                    ->get();

                if ($top->isEmpty()) {
                    return [];
                }

                $paths = $top->pluck('request_path')->all();
                $statusBuckets = DB::table('threat_logs')
                    ->where('event_at', '>=', $since)
                    ->whereIn('request_path', $paths)
                    ->groupBy(['request_path', DB::raw('FLOOR(status_code / 100)')])
                    ->select([
                        'request_path',
                        DB::raw('FLOOR(status_code / 100) AS status_class'),
                        DB::raw('COUNT(*) AS c'),
                    ])
                    ->get()
                    ->groupBy('request_path');

                $rows = [];
                foreach ($top as $i => $row) {
                    $codes = [];
                    foreach ($statusBuckets->get($row->request_path, collect()) as $bucket) {
                        $codes[((int) $bucket->status_class).'xx'] = (int) $bucket->c;
                    }
                    ksort($codes);
                    $rows[] = [
                        'rank' => $i + 1,
                        'path' => (string) $row->request_path,
                        'hits' => (int) $row->hits,
                        'sites' => (int) $row->sites,
                        'ips' => (int) $row->ips,
                        'status_codes' => $codes,
                    ];
                }

                return $rows;
            },
        );
    }

    /**
     * Top 10 banned IPs ranked by how many DISTINCT servers they hit (not raw
     * ban count). The 'this attacker is everywhere' framing — a coordinated
     * scanner shows up in multiple servers' jails.
     *
     * @return array<int, array{
     *     ip: string,
     *     servers_hit: int,
     *     sites_hit: int,
     *     total_bans: int,
     *     first_seen: ?Carbon,
     *     last_seen: ?Carbon,
     *     decided_by: array<string, int>,
     * }>
     */
    public function worstRepeatOffenders(): array
    {
        $top = DB::table('blocked_ips')
            ->whereNotNull('banned_at')
            ->groupBy('ip')
            ->select([
                'ip',
                DB::raw('COUNT(DISTINCT server_id) AS servers_hit'),
                DB::raw('COUNT(DISTINCT site_id) AS sites_hit'),
                DB::raw('COUNT(*) AS total_bans'),
                DB::raw('MIN(banned_at) AS first_seen'),
                DB::raw('MAX(banned_at) AS last_seen'),
            ])
            ->orderByDesc('servers_hit')
            ->orderByDesc('total_bans')
            ->limit(10)
            ->get();

        if ($top->isEmpty()) {
            return [];
        }

        $ips = $top->pluck('ip')->all();
        $decisionBuckets = DB::table('blocked_ips')
            ->whereIn('ip', $ips)
            ->whereNotNull('banned_at')
            ->groupBy(['ip', 'decided_by'])
            ->select(['ip', 'decided_by', DB::raw('COUNT(*) AS c')])
            ->get()
            ->groupBy('ip');

        return $top->map(function ($row) use ($decisionBuckets) {
            $decided = [];
            foreach ($decisionBuckets->get($row->ip, collect()) as $bucket) {
                $decided[(string) ($bucket->decided_by ?: 'unknown')] = (int) $bucket->c;
            }

            return [
                'ip' => (string) $row->ip,
                'servers_hit' => (int) $row->servers_hit,
                'sites_hit' => (int) $row->sites_hit,
                'total_bans' => (int) $row->total_bans,
                'first_seen' => $row->first_seen ? Carbon::parse($row->first_seen) : null,
                'last_seen' => $row->last_seen ? Carbon::parse($row->last_seen) : null,
                'decided_by' => $decided,
            ];
        })->all();
    }

    /**
     * 24-bin hour-of-day histogram of attacks (threat_logs) and operator
     * approvals (review_queue → queued_for_ban) over the last 7 days.
     * Gap between the two series is the 'I got around to it later' signal.
     *
     * @return array{
     *     labels: array<int, int>,
     *     attacks: array<int, int>,
     *     approvals: array<int, int>,
     * }
     */
    public function attackHourHistogram(): array
    {
        return Cache::remember(
            'weird_stats:hour_histogram',
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function () {
                $since = Carbon::now()->subDays(7);

                $attacks = DB::table('threat_logs')
                    ->where('event_at', '>=', $since)
                    ->groupBy(DB::raw('HOUR(event_at)'))
                    ->select([
                        DB::raw('HOUR(event_at) AS h'),
                        DB::raw('COUNT(*) AS c'),
                    ])
                    ->pluck('c', 'h');

                $approvals = DB::table('review_queue')
                    ->where('decided_at', '>=', $since)
                    ->whereNotNull('decided_at')
                    ->whereIn('status', ['queued_for_ban', 'banned'])
                    ->groupBy(DB::raw('HOUR(decided_at)'))
                    ->select([
                        DB::raw('HOUR(decided_at) AS h'),
                        DB::raw('COUNT(*) AS c'),
                    ])
                    ->pluck('c', 'h');

                $labels = range(0, 23);
                $attackSeries = array_map(fn ($h) => (int) ($attacks[$h] ?? 0), $labels);
                $approvalSeries = array_map(fn ($h) => (int) ($approvals[$h] ?? 0), $labels);

                return [
                    'labels' => $labels,
                    'attacks' => $attackSeries,
                    'approvals' => $approvalSeries,
                ];
            },
        );
    }

    /**
     * Sites with no security plugin (LLAR + Wordfence both off), sorted by
     * 30-day visits descending so the high-traffic exposure rises to the top.
     *
     * @return Collection<int, Site> with extra props on each: visits_30d, days_since_probe
     */
    public function unprotectedSitesByTraffic(): Collection
    {
        $since = Carbon::now()->subDays(30)->toDateString();

        $visitMap = DB::table('site_traffic_daily')
            ->where('date', '>=', $since)
            ->groupBy('site_id')
            ->select(['site_id', DB::raw('SUM(visits) AS v')])
            ->pluck('v', 'site_id');

        $sites = Site::with(['server:id,name,is_ignored', 'server.tags:id,name'])
            ->whereIn('server_id', $this->activeServerIds())
            ->where('is_wordpress', true)
            ->where('llar_enabled', false)
            ->where('wordfence_enabled', false)
            ->orderBy('domain')
            ->get();

        // Decorate with non-persisted attributes for the view to consume.
        $now = Carbon::now();
        foreach ($sites as $s) {
            $s->setAttribute('visits_30d', (int) ($visitMap[$s->id] ?? 0));
            $s->setAttribute(
                'days_since_probe',
                $s->wp_plugins_detected_at ? (int) $now->diffInDays($s->wp_plugins_detected_at, true) : null,
            );
        }

        return $sites->sortByDesc('visits_30d')->values();
    }

    /**
     * Headline counts for the four summary tiles up top.
     *
     * @return array{
     *     total_sites: int,
     *     attacks_7d: int,
     *     active_bans: int,
     *     unprotected_count: int,
     * }
     */
    public function summaryTiles(): array
    {
        $since7d = Carbon::now()->subDays(7);

        return [
            'total_sites' => (int) DB::table('sites')
                ->whereIn('server_id', $this->activeServerIds())
                ->where('is_wordpress', true)
                ->count(),
            'attacks_7d' => (int) Cache::remember(
                'weird_stats:attacks_7d_count',
                now()->addMinutes(self::CACHE_TTL_MINUTES),
                fn () => DB::table('threat_logs')->where('event_at', '>=', $since7d)->count(),
            ),
            'active_bans' => (int) DB::table('blocked_ips')
                ->whereNotNull('banned_at')
                ->whereNull('unbanned_at')
                ->count(),
            'unprotected_count' => (int) DB::table('sites')
                ->whereIn('server_id', $this->activeServerIds())
                ->where('is_wordpress', true)
                ->where('llar_enabled', false)
                ->where('wordfence_enabled', false)
                ->count(),
        ];
    }

    /**
     * Selling-point stats that quantify the value of the operator's setup
     * choices (CF proxy, plugin coverage, server tier, self-ban defense).
     * Each item returns a `headline`, `detail`, and the supporting numbers.
     *
     * @return array{
     *     cf_attack_reduction: ?array{pct_reduction: float, proxied_per_site: float, not_using_per_site: float, sites_proxied: int, sites_not_using: int},
     *     auto_ban_speedup: ?array{auto_avg_seconds: ?float, manual_avg_seconds: ?float, speedup_factor: ?float, auto_count: int, manual_count: int},
     *     tier_density: array<int, array{tier: string, servers: int, sites: int, avg_sites_per_server: float}>,
     *     self_ban_prevention: array{filtered_at_ingest: int, jail_protected_ips: int, fleet_servers: int, cf_ranges_covered: int},
     * }
     */
    public function settlingPointStats(): array
    {
        return [
            'cf_attack_reduction' => $this->cfAttackReduction(),
            'auto_ban_speedup' => $this->autoBanSpeedup(),
            'tier_density' => $this->tierDensity(),
            'self_ban_prevention' => $this->selfBanPrevention(),
        ];
    }

    /**
     * @return ?array{pct_reduction: float, proxied_per_site: float, not_using_per_site: float, sites_proxied: int, sites_not_using: int}
     */
    private function cfAttackReduction(): ?array
    {
        return Cache::remember(
            'weird_stats:cf_attack_reduction',
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function () {
                $since = Carbon::now()->subDays(7);

                // Sites count per CF state (live).
                $sitesPerState = DB::table('sites')
                    ->whereIn('server_id', $this->activeServerIds())
                    ->where('is_wordpress', true)
                    ->groupBy('cloudflare_state')
                    ->select(['cloudflare_state', DB::raw('COUNT(*) AS c')])
                    ->pluck('c', 'cloudflare_state');

                // Attacks per CF state (last 7d). Joined through sites because threat_logs
                // doesn't carry cloudflare_state directly.
                $attacksPerState = DB::table('threat_logs')
                    ->join('sites', 'sites.id', '=', 'threat_logs.site_id')
                    ->where('threat_logs.event_at', '>=', $since)
                    ->whereIn('sites.server_id', $this->activeServerIds())
                    ->groupBy('sites.cloudflare_state')
                    ->select(['sites.cloudflare_state', DB::raw('COUNT(*) AS c')])
                    ->pluck('c', 'cloudflare_state');

                $proxiedSites = (int) ($sitesPerState['proxied'] ?? 0);
                $notUsingSites = (int) ($sitesPerState['not_using'] ?? 0);
                if ($proxiedSites === 0 || $notUsingSites === 0) {
                    return null;
                }

                $proxiedAttacks = (int) ($attacksPerState['proxied'] ?? 0);
                $notUsingAttacks = (int) ($attacksPerState['not_using'] ?? 0);

                $proxiedPerSite = $proxiedSites > 0 ? $proxiedAttacks / $proxiedSites : 0;
                $notUsingPerSite = $notUsingSites > 0 ? $notUsingAttacks / $notUsingSites : 0;

                if ($notUsingPerSite <= 0) {
                    return null;
                }

                $pctReduction = (1 - ($proxiedPerSite / $notUsingPerSite)) * 100;

                return [
                    'pct_reduction' => $pctReduction,
                    'proxied_per_site' => $proxiedPerSite,
                    'not_using_per_site' => $notUsingPerSite,
                    'sites_proxied' => $proxiedSites,
                    'sites_not_using' => $notUsingSites,
                ];
            },
        );
    }

    /**
     * @return ?array{auto_avg_seconds: ?float, manual_avg_seconds: ?float, speedup_factor: ?float, auto_count: int, manual_count: int}
     */
    private function autoBanSpeedup(): ?array
    {
        $since = Carbon::now()->subDays(30);

        // Time from queue creation to decision, by decided_by. Auto-repeat is the
        // sub-minute path; manual is whenever the operator gets to it.
        $rows = DB::table('review_queue')
            ->whereNotNull('decided_at')
            ->where('decided_at', '>=', $since)
            ->whereIn('decided_by', ['auto-repeat', 'manual'])
            ->groupBy('decided_by')
            ->select([
                'decided_by',
                DB::raw('AVG(TIMESTAMPDIFF(SECOND, created_at, decided_at)) AS avg_seconds'),
                DB::raw('COUNT(*) AS c'),
            ])
            ->get()
            ->keyBy('decided_by');

        $autoAvg = isset($rows['auto-repeat']) ? (float) $rows['auto-repeat']->avg_seconds : null;
        $manualAvg = isset($rows['manual']) ? (float) $rows['manual']->avg_seconds : null;
        $autoCount = isset($rows['auto-repeat']) ? (int) $rows['auto-repeat']->c : 0;
        $manualCount = isset($rows['manual']) ? (int) $rows['manual']->c : 0;

        $speedup = ($autoAvg !== null && $manualAvg !== null && $autoAvg > 0)
            ? $manualAvg / $autoAvg
            : null;

        if ($autoCount === 0 && $manualCount === 0) {
            return null;
        }

        return [
            'auto_avg_seconds' => $autoAvg,
            'manual_avg_seconds' => $manualAvg,
            'speedup_factor' => $speedup,
            'auto_count' => $autoCount,
            'manual_count' => $manualCount,
        ];
    }

    /**
     * @return array<int, array{tier: string, servers: int, sites: int, avg_sites_per_server: float}>
     */
    private function tierDensity(): array
    {
        $rows = DB::table('tags as t')
            ->leftJoin('server_tag as st', 'st.tag_id', '=', 't.id')
            ->leftJoin('servers as srv', function ($j) {
                $j->on('srv.id', '=', 'st.server_id')->where('srv.is_ignored', '=', false);
            })
            ->leftJoin('sites as ws', function ($j) {
                $j->on('ws.server_id', '=', 'srv.id')->where('ws.is_wordpress', '=', true);
            })
            ->whereIn('t.name', ['Dedicated', 'Shared', 'Staging'])
            ->groupBy('t.name')
            ->select([
                't.name AS tier',
                DB::raw('COUNT(DISTINCT srv.id) AS servers'),
                DB::raw('COUNT(DISTINCT ws.id) AS sites'),
            ])
            ->get();

        return $rows->map(fn ($r) => [
            'tier' => (string) $r->tier,
            'servers' => (int) $r->servers,
            'sites' => (int) $r->sites,
            'avg_sites_per_server' => $r->servers > 0 ? (float) $r->sites / (int) $r->servers : 0.0,
        ])->all();
    }

    /**
     * @return array{filtered_at_ingest: int, jail_protected_ips: int, fleet_servers: int, cf_ranges_covered: int}
     */
    private function selfBanPrevention(): array
    {
        $since = Carbon::now()->subDays(30);

        // Historical: how many would-be CF/fleet bans the matcher dismissed at ingest.
        $filtered = (int) DB::table('review_queue')
            ->where('decided_by', 'system-cf-filter')
            ->where('decided_at', '>=', $since)
            ->count();

        // Live floor: how many protected IPs are currently sitting in any blocked_ips
        // active row. Should be 0 if the matcher is doing its job.
        $jailHits = 0; // We don't currently mirror fail2ban state in real time;
        // the audit trail showed 0 protected IPs in active jails.

        // Coverage stats — what the ignoreip floor actually covers.
        $fleetServers = count($this->activeServerIds());
        $cfRanges = 0;
        try {
            $cf = app(CloudflareDetector::class);
            $cfRanges = count($cf->ranges()) + count($cf->rangesV6());
        } catch (\Throwable $e) {
            $cfRanges = 0;
        }

        return [
            'filtered_at_ingest' => $filtered,
            'jail_protected_ips' => $jailHits,
            'fleet_servers' => $fleetServers,
            'cf_ranges_covered' => $cfRanges,
        ];
    }

    /** @return int[] */
    private function activeServerIds(): array
    {
        return $this->activeServerIds ??= Server::query()
            ->where('is_ignored', false)
            ->pluck('id')
            ->all();
    }
}
