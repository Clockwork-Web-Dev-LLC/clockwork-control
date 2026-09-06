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
use Throwable;

/**
 * Pulls the last 30 days of access-log rollup from site_traffic_daily and POSTs
 * it to each Companion-equipped site's /traffic-report endpoint, populating the
 * Traffic admin page (1.16.0+) that clients see at wp-admin → Tools → Clockwork.
 *
 * Why a push (not a pull): same model as PushCompanionBackupsReport. Clients
 * can only reach their own WP site, not Clockwork. The data has to flow into
 * the WP database so they can render it in wp-admin.
 *
 * Cadence honesty: this is intentionally a once-a-day digest. The Companion
 * page renders a "Refreshed nightly — not live stats" banner. We do NOT push
 * mid-day on UI clicks unless the operator explicitly invokes Push update on
 * the site Settings tab; even then the rendered Companion view doesn't auto-
 * refresh — clients have to reload to see changes.
 */
class PushCompanionTraffic extends Command
{
    protected $signature = 'clockwork:push-companion-traffic
        {--site= : Limit to a single site (id or domain)}
        {--server= : Limit to sites on one server (id, name, or hostname)}';

    protected $description = 'Push 30-day traffic rollup to each Companion-equipped site for client visibility.';

    public function handle(): int
    {
        $sites = $this->resolveSites();
        if ($sites->isEmpty()) {
            $this->warn('No Companion-equipped sites matched.');

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

            if (! $site->supportsTrafficReport()) {
                try {
                    (new ClockworkCompanionClient($site))->pushTrafficReport([
                        'source' => 'clockwork-monitoring',
                        'supported' => false,
                        'reason' => 'ssh_not_configured',
                        'daily' => [],
                    ]);
                    $stats['ok']++;
                    $this->line("  [disabled] {$site->domain} — traffic monitoring unsupported (no SSH access), disabled on Companion");
                } catch (Throwable $e) {
                    $stats['failed']++;
                    Log::warning('companion.traffic_report.disable_failed', [
                        'site' => $site->domain,
                        'error' => $e->getMessage(),
                    ]);
                    $this->warn("  [fail] {$site->domain}: push disable failed — {$e->getMessage()}");
                }

                continue;
            }

            $report = $this->buildReport($site);
            if (! $report['has_data']) {
                $stats['skipped']++;
                $this->line("  [skip] {$site->domain} — no rollup data in the last 30 days");

                continue;
            }

            try {
                (new ClockworkCompanionClient($site))->pushTrafficReport($report);
            } catch (Throwable $e) {
                $stats['failed']++;
                Log::warning('companion.traffic_report.push_failed', [
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
            $tpPages = count($report['top_paths']['pages'] ?? []);
            $tpApi = count($report['top_paths']['api'] ?? []);
            $tpUploads = count($report['top_paths']['uploads'] ?? []);
            $this->line("  [ok]   {$site->domain} — today={$today} 30d={$month} rows={$rows} paths(pages={$tpPages}, api={$tpApi}, uploads={$tpUploads})");
        }

        $msg = sprintf(
            'companion.traffic_report.push complete: ok=%d failed=%d skipped=%d',
            $stats['ok'],
            $stats['failed'],
            $stats['skipped'],
        );
        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
    }

    /**
     * Build the payload for a single site. Pulls the last 30 calendar days of
     * site_traffic_daily rows, zero-fills missing days so the chart x-axis is
     * dense, computes today / 7-day / 30-day visit totals, and pulls top_paths
     * from the most recent row.
     *
     * @return array<string, mixed>
     */
    public function buildReport(Site $site): array
    {
        $today = Carbon::today();
        $start = $today->copy()->subDays(29); // 30-day window inclusive

        $rows = SiteTrafficDaily::query()
            ->where('site_id', $site->id)
            ->whereBetween('date', [$start->toDateString(), $today->toDateString()])
            ->orderBy('date')
            ->get()
            ->keyBy(fn ($r) => $r->date->toDateString());

        // Zero-fill missing calendar days so the chart doesn't have gaps.
        $daily = [];
        for ($cursor = $start->copy(); $cursor->lte($today); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $r = $rows->get($key);
            $daily[] = [
                'date' => $key,
                'requests' => (int) ($r->requests ?? 0),
                'visits' => (int) ($r->visits ?? 0),
                'status_2xx' => (int) ($r->status_2xx ?? 0),
                'status_3xx' => (int) ($r->status_3xx ?? 0),
                'status_4xx' => (int) ($r->status_4xx ?? 0),
                'status_5xx' => (int) ($r->status_5xx ?? 0),
            ];
        }

        // Visits-based totals — what clients expect for "how many people came".
        $todayKey = $today->toDateString();
        $todayVisits = (int) ($rows->get($todayKey)->visits ?? 0);
        $weekVisits = (int) $rows->filter(fn ($r) => $r->date->gte($today->copy()->subDays(6)))->sum('visits');
        $monthVisits = (int) $rows->sum('visits');

        // Top paths — three buckets (pages / api / uploads), most recent day
        // with non-empty data. Prefer today, fall back to the most recent day
        // with data so a quiet morning still shows yesterday's chart.
        //
        // Legacy-shape fallback: if a row predates the categorisation rollup
        // (still a flat list), drop the flat list into 'pages' so the payload
        // has *something* during the deploy → backfill window.
        $topPaths = ['pages' => [], 'api' => [], 'uploads' => []];
        $topPathsDate = '';
        foreach ($rows->reverse() as $key => $r) {
            $candidate = $r->top_paths;
            if (! is_array($candidate) || $candidate === []) {
                continue;
            }
            if (array_is_list($candidate)) {
                $topPaths = [
                    'pages' => array_slice($candidate, 0, 10),
                    'api' => [],
                    'uploads' => [],
                ];
            } else {
                $topPaths = [
                    'pages' => array_slice(is_array($candidate['pages'] ?? null) ? $candidate['pages'] : [], 0, 10),
                    'api' => array_slice(is_array($candidate['api'] ?? null) ? $candidate['api'] : [], 0, 10),
                    'uploads' => array_slice(is_array($candidate['uploads'] ?? null) ? $candidate['uploads'] : [], 0, 10),
                ];
            }
            $topPathsDate = $key;
            break;
        }

        $hasData = $rows->isNotEmpty();
        // Today's bar may still be growing — flag it so the client UI can render
        // a "partial" footnote on the Today hero stat.
        $todayPartial = $hasData && $rows->get($todayKey) !== null;

        return [
            'source' => 'clockwork-monitoring',
            'supported' => true,
            'fetched_at' => CarbonImmutable::now()->toIso8601String(),
            'refresh_cadence' => 'daily',
            'daily' => $daily,
            'totals' => [
                'today' => $todayVisits,
                'week_7d' => $weekVisits,
                'month_30d' => $monthVisits,
            ],
            'today_partial' => $todayPartial,
            'has_data' => $hasData,
            'top_paths' => $topPaths,
            'top_paths_date' => $topPathsDate,
        ];
    }

    /**
     * @return Collection<int, Site>
     */
    private function resolveSites(): Collection
    {
        $q = Site::query()
            ->where('companion_installed', true)
            ->whereNotNull('companion_secret')
            ->whereHas('server', fn ($qq) => $qq->monitored());

        if ($needle = $this->option('site')) {
            $q->where(function ($q) use ($needle) {
                $q->where('id', $needle)->orWhere('domain', $needle);
            });
        }

        if ($needle = $this->option('server')) {
            $q->whereHas('server', function ($q) use ($needle) {
                $q->where('id', $needle)
                    ->orWhere('name', $needle)
                    ->orWhere('hostname', $needle);
            });
        }

        return $q->orderBy('domain')->get();
    }
}
