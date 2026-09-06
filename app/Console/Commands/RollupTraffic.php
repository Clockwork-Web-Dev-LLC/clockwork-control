<?php

namespace App\Console\Commands;

use App\Models\AllowedBot;
use App\Models\Site;
use App\Models\SiteTrafficDaily;
use App\Models\ThreatLog;
use App\Support\FleetSelfIps;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class RollupTraffic extends Command
{
    protected $signature = 'clockwork:rollup-traffic
        {--backfill=2 : Number of days back to (re)compute, including today}
        {--site= : Optional site ID or domain to limit to}';

    protected $description = 'Aggregate nginx threat_logs into per-site daily traffic rollups.';

    /** Compiled MySQL REGEXP for known bots (allowed_bots), or null if no bots configured. */
    private ?string $botRegex = null;

    /** MySQL REGEXP for static-asset paths (excluded from visit counts). */
    private const STATIC_ASSET_REGEX = '\\.(jpg|jpeg|png|gif|webp|svg|ico|css|js|woff|woff2|ttf|eot|otf|map|pdf|zip|mp3|mp4|webm|avif)(\\?|$)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('backfill'));
        $today = CarbonImmutable::now()->startOfDay();
        $start = $today->subDays($days - 1);

        $sites = Site::query()
            ->when($this->option('site'), function ($q, $site) {
                if (ctype_digit((string) $site)) {
                    $q->where('id', (int) $site);
                } else {
                    $q->where('domain', $site);
                }
            })
            ->get();

        if ($sites->isEmpty()) {
            $this->warn('No sites matched.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Rolling up traffic for %d site(s) across %d day(s) (%s → %s)…',
            $sites->count(),
            $days,
            $start->toDateString(),
            $today->toDateString(),
        ));

        $rowsTouched = 0;

        foreach ($sites as $site) {
            $rowsTouched += $this->rollupSite($site, $start, $today);
        }

        $this->info("Done. {$rowsTouched} daily rollup row(s) written/updated.");

        return self::SUCCESS;
    }

    /**
     * Build a single MySQL REGEXP from all allowed_bot patterns. Substring patterns are
     * concatenated as alternation; regex-typed patterns are passed through. Result is
     * used in `WHERE user_agent NOT REGEXP ?` to exclude known bots from visit counts.
     */
    private function buildBotRegex(): ?string
    {
        $bots = AllowedBot::query()->get(['ua_pattern', 'pattern_type']);

        if ($bots->isEmpty()) {
            return null;
        }

        $parts = [];
        foreach ($bots as $bot) {
            if ($bot->pattern_type === AllowedBot::PATTERN_REGEX) {
                // Bot regex patterns are stored without delimiters, intended for PCRE.
                // MySQL REGEXP is POSIX ERE — most patterns translate cleanly. Skip
                // anything with a PCRE-only construct (lookahead/lookbehind/named groups).
                if (preg_match('/\(\?[=!<:P]/', $bot->ua_pattern)) {
                    continue;
                }
                $parts[] = '('.$bot->ua_pattern.')';
            } else {
                // Substring → escape any POSIX ERE metachars so the literal matches.
                $escaped = preg_replace('/[.\\\\^$|()\\[\\]+*?{}]/', '\\\\$0', $bot->ua_pattern);
                $parts[] = preg_quote($escaped, '~');
            }
        }

        return $parts === [] ? null : '('.implode('|', $parts).')';
    }

    private function rollupSite(Site $site, CarbonImmutable $start, CarbonImmutable $endInclusive): int
    {
        // Aggregate per-day request stats for this site over [start, endExclusive).
        $endExclusive = $endInclusive->addDay();

        $rows = DB::table('threat_logs')
            ->selectRaw("
                DATE(event_at) as date,
                COUNT(*) as requests,
                COUNT(DISTINCT ip) as unique_ips,
                COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.size')) AS UNSIGNED)), 0) as bytes_sent,
                SUM(CASE WHEN status_code BETWEEN 200 AND 299 THEN 1 ELSE 0 END) as status_2xx,
                SUM(CASE WHEN status_code BETWEEN 300 AND 399 THEN 1 ELSE 0 END) as status_3xx,
                SUM(CASE WHEN status_code BETWEEN 400 AND 499 THEN 1 ELSE 0 END) as status_4xx,
                SUM(CASE WHEN status_code BETWEEN 500 AND 599 THEN 1 ELSE 0 END) as status_5xx
            ")
            ->where('site_id', $site->id)
            ->where('source', ThreatLog::SOURCE_NGINX)
            ->where('event_at', '>=', $start)
            ->where('event_at', '<', $endExclusive)
            ->groupByRaw('DATE(event_at)')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $now = now();
        $payload = [];

        foreach ($rows as $row) {
            $date = $row->date;

            $payload[] = [
                'site_id' => $site->id,
                'date' => $date,
                'requests' => (int) $row->requests,
                'unique_ips' => (int) $row->unique_ips,
                'visits' => $this->visitsForDay($site->id, $date),
                'bytes_sent' => (int) $row->bytes_sent,
                'status_2xx' => (int) $row->status_2xx,
                'status_3xx' => (int) $row->status_3xx,
                'status_4xx' => (int) $row->status_4xx,
                'status_5xx' => (int) $row->status_5xx,
                'top_paths' => json_encode($this->topPaths($site->id, $date)),
                'top_ips' => json_encode($this->topIps($site->id, $date)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        SiteTrafficDaily::upsert(
            $payload,
            ['site_id', 'date'],
            [
                'requests', 'unique_ips', 'visits', 'bytes_sent',
                'status_2xx', 'status_3xx', 'status_4xx', 'status_5xx',
                'top_paths', 'top_ips', 'updated_at',
            ],
        );

        return count($payload);
    }

    /**
     * WP-Engine-style visit count: DISTINCT IP for the day, excluding 403s and static
     * assets. We deliberately DON'T regex-match the user-agent against allowed_bots here
     * — it makes the per-day query 50–100× slower across the whole fleet. For sites
     * behind Cloudflare, CF's bot management does this filtering upstream; for non-CF
     * sites, the visit numbers will be inflated by bots and that's a known tradeoff.
     * Cloudflare-proxied sites will see CF edge IPs at origin, so visit counts trend
     * lower than browser-side analytics for CF traffic — also a known limitation.
     */
    private function visitsForDay(int $siteId, string $date): int
    {
        return (int) DB::table('threat_logs')
            ->where('site_id', $siteId)
            ->where('source', ThreatLog::SOURCE_NGINX)
            ->whereRaw('DATE(event_at) = ?', [$date])
            ->where('status_code', '!=', 403)
            ->whereRaw('request_path NOT REGEXP ?', [self::STATIC_ASSET_REGEX])
            ->distinct()
            ->count('ip');
    }

    /**
     * Top paths bucketed into three lists so the rendered UI can show
     * Pages/Posts (human-browsed), API/Bots (WP internals + sitemap/json
     * stuff), and Media Library (/wp-content/uploads/) as separate cards.
     *
     * Three SQL queries (one per bucket) rather than one + PHP partition
     * because skewed days (e.g. 90% uploads) would starve the small
     * buckets if we only kept top-10 overall.
     *
     * Storage keys are lowercase code-friendly (`pages` / `api` /
     * `uploads`); display labels are renderer's choice.
     *
     * @return array{pages: list<array{path: string, hits: int}>, api: list<array{path: string, hits: int}>, uploads: list<array{path: string, hits: int}>}
     */
    private function topPaths(int $siteId, string $date): array
    {
        return [
            'pages' => $this->topPathsForBucket($siteId, $date, 'pages'),
            'api' => $this->topPathsForBucket($siteId, $date, 'api'),
            'uploads' => $this->topPathsForBucket($siteId, $date, 'uploads'),
        ];
    }

    /**
     * @return list<array{path: string, hits: int}>
     */
    private function topPathsForBucket(int $siteId, string $date, string $bucket): array
    {
        $query = DB::table('threat_logs')
            ->selectRaw('request_path as path, COUNT(*) as hits')
            ->where('site_id', $siteId)
            ->where('source', ThreatLog::SOURCE_NGINX)
            ->whereRaw('DATE(event_at) = ?', [$date]);

        $this->applyBucketFilter($query, $bucket);

        return $query->groupBy('request_path')
            ->orderByDesc('hits')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['path' => mb_substr((string) $row->path, 0, 256), 'hits' => (int) $row->hits])
            ->all();
    }

    /**
     * Apply the WHERE clauses that select a single bucket. Uploads is the
     * narrowest; api is a compound OR-group of WP-internal patterns; pages
     * is everything that didn't match either of the above (a NOT-IN chain).
     */
    private function applyBucketFilter(Builder $q, string $bucket): void
    {
        $apiPatterns = [
            '/wp-admin/%',
            '/wp-json/%',
            '/wp-content/themes/%',
            '/wp-content/plugins/%',
            '/feed%',
            '%/feed/',
        ];
        $apiExact = ['/robots.txt', '/xmlrpc.php', '/wp-cron.php'];
        $apiRegex = '^/(wp-)?sitemap.*\\.xml$';
        $uploadsPattern = '/wp-content/uploads/%';

        if ($bucket === 'uploads') {
            $q->where('request_path', 'LIKE', $uploadsPattern);

            return;
        }

        if ($bucket === 'api') {
            $q->where(function ($qq) use ($apiPatterns, $apiExact, $apiRegex) {
                foreach ($apiPatterns as $p) {
                    $qq->orWhere('request_path', 'LIKE', $p);
                }
                $qq->orWhereIn('request_path', $apiExact);
                $qq->orWhereRaw('request_path REGEXP ?', [$apiRegex]);
            });

            return;
        }

        // pages = everything that's NOT uploads and NOT api
        $q->where('request_path', 'NOT LIKE', $uploadsPattern);
        $q->where(function ($qq) use ($apiPatterns, $apiExact, $apiRegex) {
            foreach ($apiPatterns as $p) {
                $qq->where('request_path', 'NOT LIKE', $p);
            }
            $qq->whereNotIn('request_path', $apiExact);
            $qq->whereRaw('request_path NOT REGEXP ?', [$apiRegex]);
        });
    }

    /**
     * @return array<int, array{ip: string, hits: int}>
     */
    private function topIps(int $siteId, string $date): array
    {
        $selfIps = app(FleetSelfIps::class)->list();

        return DB::table('threat_logs')
            ->selectRaw('ip, COUNT(*) as hits')
            ->where('site_id', $siteId)
            ->where('source', ThreatLog::SOURCE_NGINX)
            ->whereRaw('DATE(event_at) = ?', [$date])
            ->when(! empty($selfIps), fn ($q) => $q->whereNotIn('ip', $selfIps))
            ->groupBy('ip')
            ->orderByDesc('hits')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['ip' => (string) $row->ip, 'hits' => (int) $row->hits])
            ->all();
    }
}
