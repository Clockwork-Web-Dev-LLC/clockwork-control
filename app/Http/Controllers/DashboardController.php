<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;
use App\Models\Tag;
use App\Models\ThreatLog;
use App\Services\Servers\LiveServerLoad;
use App\Services\Servers\PhpFpmPoolStats;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        // Optional tag filter — query string ?tag=<slug>. Empty/unknown slug is ignored.
        $activeTag = null;
        if ($slug = $request->query('tag')) {
            $activeTag = Tag::where('slug', $slug)->first();
        }

        // Sort by severity first so anything needing eyeballs floats to the top of the grid:
        // red (alert) → yellow (watch) → green (healthy) → unknown. Then alphabetical within group.
        $allServers = Server::query()
            ->with('tags')
            ->withCount('sites')
            ->when($activeTag, fn ($q) => $q->whereHas('tags', fn ($qq) => $qq->where('tags.id', $activeTag->id)))
            // CASE instead of MySQL's FIELD() so the query is portable
            // (sqlite in tests has no FIELD function).
            ->orderByRaw('CASE status WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END', [
                Server::STATUS_RED,
                Server::STATUS_YELLOW,
                Server::STATUS_GREEN,
            ])
            ->orderByDesc('name')
            ->get();

        $servers = $allServers->where('is_ignored', false)->values();
        $ignoredServers = $allServers->where('is_ignored', true)->values();

        // All tags for the filter chip strip — only those that actually have
        // at least one server. whereHas instead of HAVING on the withCount
        // subselect: sqlite (tests) rejects HAVING on a non-aggregate query.
        $tags = Tag::query()
            ->withCount('servers')
            ->whereHas('servers')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $statusCounts = [
            Server::STATUS_GREEN => $servers->where('status', Server::STATUS_GREEN)->count(),
            Server::STATUS_YELLOW => $servers->where('status', Server::STATUS_YELLOW)->count(),
            Server::STATUS_RED => $servers->where('status', Server::STATUS_RED)->count(),
            Server::STATUS_UNKNOWN => $servers->where('status', Server::STATUS_UNKNOWN)->count(),
        ];

        $totalSites = $servers->sum('sites_count');

        // Patch/reboot roll-ups for the top-of-dashboard alert banner. We compute counts
        // here so the banner is dismissible-via-action (run updates) without needing
        // another query later.
        $patchCounts = [
            'patches' => $servers->where('upgrade_required', true)->count(),
            'reboots' => $servers->where('reboot_required', true)->count(),
        ];

        // Last 24h CPU sparkline data + latest snapshot for each server.
        $sparklines = $this->buildSparklines($servers);

        $siteIndex = Site::query()
            ->select('id', 'domain', 'server_id')
            ->with('server:id,name')
            ->orderBy('domain')
            ->get()
            ->map(fn (Site $s) => [
                'id' => $s->id,
                'domain' => $s->domain,
                'server_id' => $s->server_id,
                'server_name' => $s->server?->name,
                'url' => route('sites.show', $s),
            ])
            ->values();

        return view('dashboard.index', compact('servers', 'ignoredServers', 'statusCounts', 'totalSites', 'siteIndex', 'sparklines', 'tags', 'activeTag', 'patchCounts'));
    }

    /**
     * Build a per-server bag of: last-24h CPU samples (for sparklines) + the latest reading.
     *
     * One round-trip query for the whole fleet.
     *
     * @return array<int, array{cpu: array<int,float>, latest: ?ServerMetric}>
     */
    private function buildSparklines(Collection $servers): array
    {
        $serverIds = $servers->pluck('id')->all();
        if ($serverIds === []) {
            return [];
        }

        $rows = ServerMetric::query()
            ->whereIn('server_id', $serverIds)
            ->where('recorded_at', '>=', now()->subDay())
            ->orderBy('recorded_at')
            ->get(['server_id', 'recorded_at', 'cpu_pct', 'memory_pct', 'disk_pct', 'load_1']);

        $byServer = [];
        foreach ($rows as $row) {
            $byServer[$row->server_id]['cpu'][] = (float) ($row->cpu_pct ?? 0);
            $byServer[$row->server_id]['latest'] = $row;
        }

        return $byServer;
    }

    public function show(Server $server, Request $request, ?string $tab = null): View
    {
        $allowedTabs = ['sites', 'stats', 'updates', 'bans', 'settings'];
        $tab = in_array($tab, $allowedTabs, true) ? $tab : 'sites';

        // Always loaded — the header and tab nav need these regardless of which tab is active.
        $server->load(['sites' => fn ($q) => $q->orderBy('domain'), 'tags']);

        // Collapse www.* aliases: if both "example.com" and "www.example.com" live
        // on this server, drop the www. row — it's just a redirect, not a distinct site.
        $bareDomains = $server->sites
            ->filter(fn ($s) => ! str_starts_with($s->domain, 'www.'))
            ->pluck('domain')
            ->flip();
        $server->setRelation(
            'sites',
            $server->sites->filter(fn ($s) => ! str_starts_with($s->domain, 'www.')
                || ! $bareDomains->has(substr($s->domain, 4))
            )->values()
        );

        // Tab-specific data is loaded lazily — keeps the Sites tab fast and skips the live
        // SSH probe when you're not on Stats. This is the main reason for path-based tabs:
        // each tab does only the work it needs.
        $data = match ($tab) {
            'sites' => $this->loadSitesTab($server),
            'stats' => $this->loadStatsTab($server, $request),
            'updates' => $this->loadUpdatesTab($server),
            'bans' => $this->loadBansTab($server),
            'settings' => [],
        };

        return view('dashboard.server', array_merge([
            'server' => $server,
            'tab' => $tab,
        ], $data));
    }

    /**
     * @return array{poolStats: array<string, mixed>, requestsBySite: array<int, int>}
     */
    private function loadSitesTab(Server $server): array
    {
        $poolStats = [];
        // Only probe live php-fpm stats when SSH was healthy in the last
        // few minutes. Without this gate, a server that's been deleted at
        // the cloud provider (but still has an old last_ssh_ok_at) makes
        // the page hang while the SSH attempt times out. The staleness
        // window matches a typical poll cadence (5 min) — fresher than
        // that and we know the box is alive.
        if ($this->shouldProbeLive($server)) {
            try {
                $poolStats = app(PhpFpmPoolStats::class)->snapshot($server);
            } catch (\Throwable) {
                $poolStats = [];
            }
        }

        $requestsBySite = ThreatLog::query()
            ->whereIn('site_id', $server->sites->pluck('id'))
            ->where('event_at', '>=', now()->subHour())
            ->selectRaw('site_id, COUNT(*) AS n')
            ->groupBy('site_id')
            ->pluck('n', 'site_id')
            ->all();

        return compact('poolStats', 'requestsBySite');
    }

    /**
     * Updates tab — eager-load the latest apt-update snapshot so the blade
     * can render counts + security split + reboot-required packages without
     * a lazy-load query on every page render. Snapshot may be null if the
     * server has never been polled (e.g. brand new, or apt-check unavailable).
     *
     * @return array<string, mixed>
     */
    private function loadUpdatesTab(Server $server): array
    {
        $server->loadMissing('updateSnapshot');

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadStatsTab(Server $server, Request $request): array
    {
        // Range selector drives both the date filter and the Chart.js time-scale formatting.
        $rangeMap = [
            '1h' => ['since' => now()->subHour(), 'unit' => 'minute', 'step' => 10, 'fmt' => 'HH:mm', 'label' => 'Last hour'],
            '6h' => ['since' => now()->subHours(6), 'unit' => 'minute', 'step' => 30, 'fmt' => 'HH:mm', 'label' => 'Last 6 hours'],
            '24h' => ['since' => now()->subDay(), 'unit' => 'hour', 'step' => 2, 'fmt' => 'HH:mm', 'label' => 'Last 24 hours'],
            '7d' => ['since' => now()->subDays(7), 'unit' => 'day', 'step' => 1, 'fmt' => 'EEE HH:mm', 'label' => 'Last 7 days'],
        ];
        $range = (string) $request->query('range', '7d');
        $chartRange = array_key_exists($range, $rangeMap) ? $range : '7d';
        $rangeConfig = $rangeMap[$chartRange];

        $metrics = $server->metrics()
            ->where('recorded_at', '>=', $rangeConfig['since'])
            ->orderBy('recorded_at')
            ->get(['recorded_at', 'cpu_pct', 'memory_pct', 'disk_pct', 'load_1']);

        $liveLoad = null;
        if ($this->shouldProbeLive($server)) {
            try {
                $liveLoad = app(LiveServerLoad::class)->snapshot($server);
            } catch (\Throwable) {
                $liveLoad = null;
            }
        }

        return [
            'metrics' => $metrics,
            'latestMetric' => $metrics->last(),
            'liveLoad' => $liveLoad,
            'chartRange' => $chartRange,
            'rangeConfig' => $rangeConfig,
        ];
    }

    /**
     * @return array{bannedIps: \Illuminate\Database\Eloquent\Collection}
     */
    private function loadBansTab(Server $server): array
    {
        $bannedIps = $server->blockedIps()
            ->whereNull('unbanned_at')
            ->orderByDesc('banned_at')
            ->get();

        return compact('bannedIps');
    }

    /**
     * Cheap pre-check before any synchronous SSH probe in show().
     * Returns true only when the server is non-ignored AND we have a
     * recent (~5 min) confirmation SSH worked. Older than that and we
     * skip the live probe to keep the page snappy — a dead server won't
     * make the dashboard time out.
     */
    private function shouldProbeLive(Server $server): bool
    {
        if ($server->is_ignored) {
            return false;
        }
        if (! $server->last_ssh_ok_at) {
            return false;
        }

        // 30-min default keeps healthy-but-quiet servers showing live php-fpm
        // / load data when the operator visits, while dead servers (whose
        // last_ssh_ok_at hasn't ticked in hours) short-circuit instantly.
        // The TCP pre-flight in SshClient::connect is the hard backstop
        // for the case where SSH was fine 10 min ago but the box is now gone.
        $staleAfterMinutes = (int) config('clockwork.monitoring.live_probe_freshness_minutes', 30);

        return $server->last_ssh_ok_at->gt(now()->subMinutes($staleAfterMinutes));
    }
}
