<?php

namespace App\Http\Controllers;

use App\Console\Commands\PushCompanionTraffic;
use App\Mail\SiteVulnerabilityReportMail;
use App\Models\ActionLog;
use App\Models\BlockedIp;
use App\Models\Site;
use App\Models\SitePerformanceScan;
use App\Models\SiteSecurityScan;
use App\Models\SiteTrafficDaily;
use App\Services\ActionLog\ActionLogger;
use App\Services\Companion\ClockworkCompanionClient;
use App\Services\Companion\CompanionInstaller;
use App\Services\DigitalOcean\SpacesClient;
use App\Services\Fail2ban\Fail2banClient;
use App\Services\HostingProvider\HostingProviderRegistry;
use App\Services\Security\PluginVulnerabilityMatcher;
use App\Services\Sites\LlarInstaller;
use App\Services\Sites\WpConfigExtractor;
use App\Services\Sites\WpPluginDetector;
use App\Services\Ssl\SiteCertRefresher;
use App\Services\Uptime\UptimeProber;
use App\Services\Uptime\UptimeStateUpdater;
use App\Support\FleetSelfIps;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\ModuleStateResolver;
use Modules\Pressable\PressableClient;
use Modules\SpinupWp\SpinupWpClient;
use Throwable;

class SitesController extends Controller
{
    /**
     * Fleet-wide site listing across both hosting providers — the SpinupWP
     * dashboard is Servers-first, so Pressable sites (which have no server
     * to hang off of) are otherwise invisible in the nav.
     */
    public function index(Request $request): View
    {
        // Only providers whose module is actually enabled — an operator
        // running just GridPane + Vultr should never see SpinupWP/Pressable
        // tabs (or be able to filter by them), regardless of what this
        // fleet's `sites.hosting_provider` column happens to contain.
        // HostingProviderRegistry::all() is already enablement-gated: each
        // module only registers a HostingProvider contribution when its own
        // ModuleServiceProvider::enabled() check passes.
        $enabledProviders = app(HostingProviderRegistry::class)->all();
        $enabledProviderIds = array_map(fn ($p) => $p->id(), $enabledProviders);

        $provider = $request->query('provider', 'all');
        $provider = in_array($provider, $enabledProviderIds, true) ? $provider : null;

        $q = trim((string) $request->query('q', ''));

        $sites = Site::query()
            ->with('server:id,name')
            ->when($provider, fn ($query) => $query->where('hosting_provider', $provider))
            ->when($q !== '', fn ($query) => $query->where('domain', 'like', '%'.$q.'%'))
            ->orderBy('domain')
            ->paginate(50)
            ->withQueryString();

        $counts = ['all' => Site::query()->count()];
        $providerTabs = ['all' => 'All'];
        foreach ($enabledProviders as $hp) {
            $counts[$hp->id()] = Site::query()->where('hosting_provider', $hp->id())->count();
            $providerTabs[$hp->id()] = $hp->label();
        }

        return view('dashboard.sites', [
            'sites' => $sites,
            'counts' => $counts,
            // Only worth showing tabs when there's an actual choice — one
            // (or zero) enabled hosting-provider module means "All" and the
            // single provider are the same set.
            'providerTabs' => count($enabledProviders) > 1 ? $providerTabs : [],
            'activeProvider' => $provider ?? 'all',
            'q' => $q,
        ]);
    }

    public function show(Site $site, ?string $tab = null): View
    {
        $allowedTabs = ['overview', 'traffic', 'bans', 'security', 'performance', 'settings', 'updates'];
        if (app(ModuleStateResolver::class)->isEnabled('contact-forms')) {
            $allowedTabs[] = 'forms';
        }
        $tab = in_array($tab, $allowedTabs, true) ? $tab : 'overview';

        // Traffic and Bans both require SSH/server access — fall back to
        // Overview for a direct/bookmarked URL hit, same gate tab-nav.blade.php
        // uses to hide the tab link itself.
        if (! $site->host()->supports(HostingProvider::CAP_SSH) && in_array($tab, ['traffic', 'bans'], true)) {
            $tab = 'overview';
        }

        $site->load('server');

        // The header + tab nav need the active-bans count regardless of which tab is open.
        $bansCount = $site->blockedIps()->whereNull('unbanned_at')->count();

        // Tab-specific data is loaded lazily — Traffic skips threat-log reads, Settings
        // skips ECharts payload generation, etc. Mirrors the servers.show pattern.
        $data = match ($tab) {
            'overview' => $this->loadOverviewTab($site),
            'traffic' => $this->loadTrafficTab($site),
            'bans' => $this->loadBansTab($site),
            'security' => $this->loadSecurityTab($site),
            'performance' => $this->loadPerformanceTab($site),
            'forms' => $this->loadFormsTab($site),
            'updates' => $this->loadUpdatesTab($site),
            'settings' => [],
        };

        return view('dashboard.site', array_merge([
            'site' => $site,
            'tab' => $tab,
            'bansCount' => $bansCount,
        ], $data));
    }

    /**
     * @return array{
     *     recentLogs: \Illuminate\Database\Eloquent\Collection,
     *     logCount24h: int,
     *     logCountTotal: int,
     *     topIps24h: Collection,
     *     recentActivity: \Illuminate\Database\Eloquent\Collection
     * }
     */
    private function loadOverviewTab(Site $site): array
    {
        $recentLogs = $site->threatLogs()
            ->orderByDesc('event_at')
            ->limit(50)
            ->get();

        $logCount24h = $site->threatLogs()
            ->where('event_at', '>=', now()->subDay())
            ->count();

        $logCountTotal = $site->threatLogs()->count();

        // Exclude fleet self-IPs — WordPress core's loopback (Site Health,
        // wp-cron, plugin updaters) phones the public hostname, which resolves
        // back to the hosting server's IP, so nginx logs the server's own IP
        // as the "client". See App\Support\FleetSelfIps for the list source.
        $selfIps = app(FleetSelfIps::class)->list();
        $topIps24h = $site->threatLogs()
            ->where('event_at', '>=', now()->subDay())
            ->when(! empty($selfIps), fn ($q) => $q->whereNotIn('ip', $selfIps))
            ->selectRaw('ip, COUNT(*) as hits, MAX(event_at) as last_seen')
            ->groupBy('ip')
            ->orderByDesc('hits')
            ->limit(10)
            ->get();

        $recentActivity = ActionLog::query()
            ->where('site_id', $site->id)
            ->orderByDesc('ran_at')
            ->limit(25)
            ->get();

        // 1. Uptime widget data
        $uptimePercentage30d = $site->computeUptimePercentage(30);
        $recentUptimeEvents = $site->uptimeEvents()
            ->orderByDesc('event_at')
            ->limit(3)
            ->get();

        // 2. Performance widget data
        $latestPerfMobile = $site->latestPerformanceScanMobile;
        $latestPerfDesktop = $site->latestPerformanceScanDesktop;

        // 3. Security widget data
        $latestSiteCheck = $site->latestSiteCheckScan;
        $latestChecksumScan = $site->latestChecksumScan;

        // 4. Traffic 7-day summary data
        $traffic7d = SiteTrafficDaily::query()
            ->where('site_id', $site->id)
            ->where('date', '>=', now()->subDays(6)->toDateString())
            ->orderBy('date')
            ->get(['date', 'requests', 'visits', 'unique_ips']);

        $traffic7dVisits = (int) $traffic7d->sum('visits');
        $traffic7dRequests = (int) $traffic7d->sum('requests');

        // 5. Contact forms widget data
        $latestFormRun = $site->contactFormTestRuns()
            ->orderByDesc('ran_at')
            ->first();
        $formTestsCount = $site->contactFormTests()->count();

        $dashboardLayout = $site->resolvedDashboardLayout();

        return compact(
            'recentLogs',
            'logCount24h',
            'logCountTotal',
            'topIps24h',
            'recentActivity',
            'uptimePercentage30d',
            'recentUptimeEvents',
            'latestPerfMobile',
            'latestPerfDesktop',
            'latestSiteCheck',
            'latestChecksumScan',
            'traffic7d',
            'traffic7dVisits',
            'traffic7dRequests',
            'latestFormRun',
            'formTestsCount',
            'dashboardLayout'
        );
    }

    /**
     * @return array{trafficData: array<string, mixed>}
     */
    private function loadTrafficTab(Site $site): array
    {
        return ['trafficData' => $this->buildTrafficData($site)];
    }

    /**
     * @return array{bannedIps: LengthAwarePaginator}
     */
    private function loadBansTab(Site $site): array
    {
        // Sites with active fail2ban can accumulate hundreds of banned IPs
        // overnight — paginate so the page stays scannable. ?per_page=
        // overrides the default for ad-hoc "show me everything" usage.
        $perPage = (int) request()->query('per_page', 50);
        $perPage = max(10, min(500, $perPage));

        $bannedIps = $site->blockedIps()
            ->whereNull('unbanned_at')
            ->orderByDesc('banned_at')
            ->paginate($perPage)
            ->withQueryString();

        return compact('bannedIps');
    }

    /**
     * @return array{
     *     latestSiteCheck: ?SiteSecurityScan,
     *     latestChecksumScan: ?SiteSecurityScan,
     *     scanHistory: \Illuminate\Database\Eloquent\Collection
     * }
     */
    private function loadSecurityTab(Site $site): array
    {
        $latestSiteCheck = $site->latestSiteCheckScan;
        $latestChecksumScan = $site->latestChecksumScan;
        $scanHistory = $site->securityScans()
            ->orderByDesc('scanned_at')
            ->limit(20)
            ->get();

        return compact('latestSiteCheck', 'latestChecksumScan', 'scanHistory');
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPerformanceTab(Site $site): array
    {
        $latestPerfMobile = $site->latestPerformanceScanMobile;
        $latestPerfDesktop = $site->latestPerformanceScanDesktop;

        $perfHistory = $site->performanceScans()
            ->orderByDesc('scanned_at')
            ->limit(60)
            ->get();

        // Build a simple trend dataset (last 30 days, both strategies, ascending
        // by date) so the view can hand it straight to Chart.js without
        // additional shaping. One query, two arrays — keeps Larastan happy
        // without the Collection<Model> -> Collection<SitePerformanceScan>
        // narrowing dance.
        /** @var \Illuminate\Database\Eloquent\Collection<int, SitePerformanceScan> $window */
        $window = $site->performanceScans()
            ->where('scanned_at', '>=', now()->subDays(30))
            ->where('status', SitePerformanceScan::STATUS_OK)
            ->orderBy('scanned_at')
            ->get(['scanned_at', 'strategy', 'performance_score', 'lcp_ms']);

        $perfTrend = ['mobile' => [], 'desktop' => []];
        foreach ($window as $row) {
            $point = ['x' => $row->scanned_at->toIso8601String(), 'y' => $row->performance_score];
            if ($row->strategy === SitePerformanceScan::STRATEGY_MOBILE) {
                $perfTrend['mobile'][] = $point;
            } elseif ($row->strategy === SitePerformanceScan::STRATEGY_DESKTOP) {
                $perfTrend['desktop'][] = $point;
            }
        }

        return compact('latestPerfMobile', 'latestPerfDesktop', 'perfHistory', 'perfTrend');
    }

    /**
     * @return array{
     *     formTests: \Illuminate\Database\Eloquent\Collection,
     *     formTestRuns: \Illuminate\Database\Eloquent\Collection,
     *     adminMode: bool
     * }
     */
    private function loadFormsTab(Site $site): array
    {
        $formTests = $site->contactFormTests()->get();

        // Recent runs across all of this site's form-tests, for the history
        // table at the bottom of the tab.
        $formTestRuns = $site->contactFormTestRuns()
            ->orderByDesc('ran_at')
            ->limit(20)
            ->get();

        // ?admin=1 exposes the Daily option in the frequency dropdown.
        // Pure UI flag — the controller accepts daily unconditionally.
        $adminMode = (bool) request()->query('admin');

        return compact('formTests', 'formTestRuns', 'adminMode');
    }

    /**
     * Build the Updates tab data from the cached companion_snapshot. We don't
     * round-trip to the site on tab load — the snapshot job refreshes plugin
     * state daily, and the tab has its own "Refresh inventory" button if the
     * data feels stale.
     *
     * Returns three buckets so the view stays clean:
     *   - updatesAvailable: needs attention
     *   - upToDate: hidden by default
     *   - inactive: hidden by default; mostly delete candidates
     *
     * @return array{
     *     updatesAvailable: array<int, array<string, mixed>>,
     *     upToDate: array<int, array<string, mixed>>,
     *     inactive: array<int, array<string, mixed>>,
     *     pluginCounts: array{total:int, active:int, inactive:int, updates_available:int},
     *     pluginsCheckedAt: ?string,
     *     snapshotAt: ?Carbon,
     * }
     */
    private function loadUpdatesTab(Site $site): array
    {
        $snapshot = is_array($site->companion_snapshot) ? $site->companion_snapshot : [];
        $pluginsPayload = is_array($snapshot['plugins'] ?? null) ? $snapshot['plugins'] : [];
        $plugins = is_array($pluginsPayload['plugins'] ?? null) ? $pluginsPayload['plugins'] : [];

        $updatesAvailable = [];
        $upToDate = [];
        $inactive = [];
        foreach ($plugins as $row) {
            if (! is_array($row) || ! isset($row['slug'])) {
                continue;
            }
            $isActive = (bool) ($row['active'] ?? false);
            $hasUpdate = (bool) ($row['update_available'] ?? false);

            // Update state takes priority over active state. Inactive plugins
            // with pending updates are still real exposure (the files sit on
            // disk and can be exploited via LFI / activation), so they belong
            // in the actionable list, not the "delete candidate" pile. The
            // row's "inactive" badge in the view tells the operator at a
            // glance that updating it doesn't bring it back online.
            if ($hasUpdate) {
                $updatesAvailable[] = $row;
            } elseif (! $isActive) {
                $inactive[] = $row;
            } else {
                $upToDate[] = $row;
            }
        }

        $sortByName = fn ($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        usort($updatesAvailable, $sortByName);
        usort($upToDate, $sortByName);
        usort($inactive, $sortByName);

        $counts = is_array($pluginsPayload['counts'] ?? null) ? $pluginsPayload['counts'] : [];

        return [
            'updatesAvailable' => $updatesAvailable,
            'upToDate' => $upToDate,
            'inactive' => $inactive,
            'pluginCounts' => [
                'total' => (int) ($counts['total'] ?? count($plugins)),
                'active' => (int) ($counts['active'] ?? 0),
                'inactive' => (int) ($counts['inactive'] ?? count($inactive)),
                'updates_available' => (int) ($counts['updates_available'] ?? count($updatesAvailable)),
            ],
            'pluginsCheckedAt' => isset($pluginsPayload['checked_at']) ? (string) $pluginsPayload['checked_at'] : null,
            'snapshotAt' => $site->companion_snapshot_at,
        ];
    }

    /**
     * @return array{
     *     daily: array<int, array<string, mixed>>,
     *     calendar: array<int, array{0: string, 1: int}>,
     *     totals: array{day: int, week: int, month: int, year: int, lifetime: int},
     *     today_partial: bool,
     *     latest_top_paths: array<int, array{path: string, hits: int}>,
     *     has_data: bool
     * }
     */
    private function buildTrafficData(Site $site): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $start90 = $today->subDays(89);
        $start365 = $today->subDays(364);

        $rollups = SiteTrafficDaily::query()
            ->where('site_id', $site->id)
            ->where('date', '>=', $start365)
            ->orderBy('date')
            ->get()
            ->keyBy(fn ($r) => $r->date->toDateString());

        // 90-day daily series with zero-fills for missing days.
        $daily = [];
        for ($d = $start90; $d->lte($today); $d = $d->addDay()) {
            $key = $d->toDateString();
            $row = $rollups->get($key);
            $daily[] = [
                'date' => $key,
                'requests' => $row?->requests ?? 0,
                'status_2xx' => $row?->status_2xx ?? 0,
                'status_3xx' => $row?->status_3xx ?? 0,
                'status_4xx' => $row?->status_4xx ?? 0,
                'status_5xx' => $row?->status_5xx ?? 0,
                'unique_ips' => $row?->unique_ips ?? 0,
                'bytes_sent' => $row?->bytes_sent ?? 0,
            ];
        }

        // 365-day calendar: only emit non-zero days (sparser data → smaller payload).
        $calendar = [];
        foreach ($rollups as $key => $row) {
            if ($row->requests > 0) {
                $calendar[] = [$key, $row->requests];
            }
        }

        // Hero stats use WP-Engine-style visits (DISTINCT IP per UTC day, excluding
        // 403s and static assets) so the numbers line up with the Capacity page and
        // with the user's mental model around WPE-style hosting tiers. The stacked
        // bar chart below still graphs raw requests by status — that's the ops view.
        $totals = [
            'day' => $rollups->get($today->toDateString())?->visits ?? 0,
            'week' => $rollups
                ->filter(fn ($r) => $r->date->gte($today->subDays(6)))
                ->sum('visits'),
            'month' => $rollups
                ->filter(fn ($r) => $r->date->gte($today->subDays(29)))
                ->sum('visits'),
            'year' => $rollups->sum('visits'),
            'lifetime' => SiteTrafficDaily::query()
                ->where('site_id', $site->id)
                ->sum('visits'),
        ];

        // Latest non-empty top_paths.
        $latestWithPaths = $rollups
            ->reverse()
            ->first(fn ($r) => is_array($r->top_paths) && count($r->top_paths) > 0);

        return [
            'daily' => $daily,
            'calendar' => $calendar,
            'totals' => $totals,
            'today_partial' => true,
            'latest_top_paths' => $latestWithPaths?->top_paths ?? [],
            'has_data' => $rollups->isNotEmpty(),
        ];
    }

    public function recheckCert(Site $site, SiteCertRefresher $refresher): JsonResponse
    {
        try {
            $result = $refresher->refresh($site);

            return response()->json([
                'ok' => true,
                'message' => sprintf(
                    'Refreshed from SpinupWP. State: %s%s. Expires %s.',
                    $result['to_state'],
                    $result['from_state'] !== null && $result['from_state'] !== $result['to_state']
                        ? " (was {$result['from_state']})"
                        : '',
                    $result['expires_at'] ?? 'n/a',
                ),
                'state' => $result['to_state'],
                'expires_at' => $result['expires_at'],
                'renews_at' => $result['renews_at'],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Run a single uptime probe on demand. Same probe + state-transition
     * logic as `clockwork:check-site-uptime --site=`, just exposed to the
     * "Refresh status" button so the operator doesn't have to wait up to
     * 5 minutes for the next scheduled tick to clear a stale Down badge.
     */
    public function recheckUptime(Site $site, UptimeProber $prober, UptimeStateUpdater $updater): JsonResponse
    {
        if (! $site->uptime_monitoring_enabled) {
            return response()->json([
                'ok' => false,
                'message' => 'Uptime monitoring is disabled for this site.',
            ], 422);
        }

        try {
            $probe = $prober->probe('https://'.$site->domain.'/');
            $updater->update($site, $probe);
            $site->refresh();

            return response()->json([
                'ok' => true,
                'message' => $probe->succeeded
                    ? "Up (HTTP {$probe->statusCode})"
                    : 'Down ('.($probe->statusCode ? "HTTP {$probe->statusCode}" : ($probe->error ?? 'no response')).')',
                'state' => $site->uptime_state,
                'status_code' => $probe->statusCode,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Re-probe a single site's LLAR + Wordfence active state via SSH+wp-cli.
     * Same logic as `clockwork:detect-wp-plugins`, just scoped to one site so
     * the WP plugins inventory page can refresh a row on demand without
     * waiting for the daily 04:45 scheduled job.
     */
    public function refreshWpPlugins(Site $site, WpPluginDetector $detector): JsonResponse
    {
        try {
            $result = $detector->detect($site);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'result' => WpPluginDetector::RESULT_FAILED,
                'message' => $e->getMessage(),
            ], 500);
        }

        $ok = $result['result'] === WpPluginDetector::RESULT_DETECTED;
        $fresh = $site->fresh();

        return response()->json([
            'ok' => $ok,
            'result' => $result['result'],
            'message' => $result['message'],
            'llar_enabled' => $fresh->llar_enabled,
            'wordfence_enabled' => $fresh->wordfence_enabled,
            'detected_at' => $fresh->wp_plugins_detected_at?->toIso8601String(),
        ], $ok ? 200 : 422);
    }

    public function fetchDbCreds(Site $site, WpConfigExtractor $extractor): RedirectResponse
    {
        try {
            $extractor->extractAndStore($site);

            return back()->with('status', "DB credentials fetched for {$site->domain}.");
        } catch (Throwable $e) {
            return back()->with('status_error', "Failed to fetch DB credentials for {$site->domain}: {$e->getMessage()}");
        }
    }

    public function installLlar(Site $site, LlarInstaller $installer): JsonResponse
    {
        if (! app(ModuleStateResolver::class)->isEnabled('llar')) {
            return response()->json([
                'ok' => false,
                'result' => 'disabled',
                'message' => 'The Limit Login Attempts Reloaded module is currently disabled in Clockwork Control.',
            ], 403);
        }

        try {
            $result = $installer->process($site);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'result' => 'failed',
                'message' => $e->getMessage(),
            ], 500);
        }

        $ok = in_array($result['result'], [
            LlarInstaller::RESULT_INSTALLED,
            LlarInstaller::RESULT_ALREADY_PRESENT,
        ], true);

        return response()->json([
            'ok' => $ok,
            'result' => $result['result'],
            'message' => $result['message'],
            'output' => $result['output'] ?? null,
            'llar_enabled' => $site->fresh()->llar_enabled,
        ], $ok ? 200 : 422);
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $results = Site::query()
            ->with('server:id,name')
            ->where('domain', 'like', '%'.$q.'%')
            ->orderByRaw('CASE WHEN domain LIKE ? THEN 0 ELSE 1 END', [$q.'%'])
            ->orderBy('domain')
            ->limit(15)
            ->get(['id', 'domain', 'server_id', 'is_wordpress'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'domain' => $s->domain,
                'server' => $s->server?->name,
                'is_wordpress' => (bool) $s->is_wordpress,
                'url' => route('sites.show', $s),
            ]);

        return response()->json(['results' => $results]);
    }

    public function unbanIp(Site $site, BlockedIp $blockedIp, Fail2banClient $client): RedirectResponse
    {
        if ($blockedIp->site_id !== $site->id) {
            abort(404);
        }

        if (! $blockedIp->server) {
            return back()->with('status_error', 'Cannot unban — server record missing.');
        }

        $result = $client->unbanIp($blockedIp->server, $blockedIp->ip);

        if (! $result['ok']) {
            return back()->with('status_error', $result['message'].' — '.$result['output']);
        }

        $blockedIp->update(['unbanned_at' => Carbon::now()]);

        return back()->with('status', "Unbanned {$blockedIp->ip}.");
    }

    public function unbanAll(Site $site, Fail2banClient $client): RedirectResponse
    {
        $bans = $site->blockedIps()
            ->whereNull('unbanned_at')
            ->with('server')
            ->get();

        if ($bans->isEmpty()) {
            return back()->with('status', 'No active bans to clear.');
        }

        // Group by server (should normally be just one — the site's server).
        $byServer = $bans->groupBy('server_id');
        $totalOk = 0;
        $totalAttempted = 0;
        $errors = [];
        $now = Carbon::now();

        foreach ($byServer as $serverId => $group) {
            $server = $group->first()->server;
            if (! $server) {
                $errors[] = "skipped {$group->count()} ban(s) — server record missing";

                continue;
            }

            $ips = $group->pluck('ip')->all();
            $totalAttempted += count($ips);
            $result = $client->unbanIps($server, $ips);

            // Collect the IPs that came back ok, then mark them unbanned in a
            // single UPDATE rather than per-row save+update — cleaner SQL and
            // less surface area for the agent's flagged (false-positive)
            // "Collection::where filters wrong" misread.
            $okIps = [];
            foreach ($result['results'] as $ip => $r) {
                if ($r['ok']) {
                    $okIps[] = $ip;
                } else {
                    $errors[] = "{$ip}: ".trim($r['output']);
                }
            }
            if ($okIps !== []) {
                BlockedIp::query()
                    ->where('server_id', $serverId)
                    ->whereIn('ip', $okIps)
                    ->whereNull('unbanned_at')
                    ->update(['unbanned_at' => $now]);
                $totalOk += count($okIps);
            }
        }

        $msg = "Unbanned {$totalOk} of {$totalAttempted} for {$site->domain}.";
        if ($errors !== []) {
            return back()->with('status_error', $msg.' '.implode(' · ', array_slice($errors, 0, 3)));
        }

        return back()->with('status', $msg);
    }

    public function updateCert(Request $request, Site $site): RedirectResponse
    {
        $validated = $request->validate([
            'cert_source' => ['required', 'in:none,spinupwp_le,external,redirect_only'],
            'cert_expires_at' => ['nullable', 'date'],
            'cert_renews_at' => ['nullable', 'date'],
            'cert_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $site->fill($validated)->save();

        // Reset cert_state so the next checker run treats this as a fresh state and
        // doesn't fire a stale transition notification.
        $site->update(['cert_state' => $site->sslState()]);

        return redirect()->route('sites.show', ['site' => $site, 'tab' => 'settings'])
            ->with('status', 'Cert details updated.');
    }

    public function updateNotes(Request $request, Site $site): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $site->update([
            'notes' => $validated['notes'] ?? null,
        ]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'notes' => $site->notes,
                'message' => 'Site notes saved successfully.',
            ]);
        }

        return back()->with('status', 'Site notes saved successfully.');
    }

    public function updateLayout(Request $request, Site $site): JsonResponse|RedirectResponse
    {
        if ($request->boolean('reset')) {
            $site->update(['dashboard_layout' => null]);

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'ok' => true,
                    'layout' => Site::DEFAULT_DASHBOARD_LAYOUT,
                    'message' => 'Dashboard layout reset to default.',
                ]);
            }

            return back()->with('status', 'Dashboard layout reset to default.');
        }

        $validated = $request->validate([
            'layout' => ['required', 'array'],
            'layout.*' => ['string', 'in:'.implode(',', Site::DEFAULT_DASHBOARD_LAYOUT)],
        ]);

        $ordered = array_values(array_unique($validated['layout']));
        $site->update(['dashboard_layout' => $ordered]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'layout' => $site->resolvedDashboardLayout(),
                'message' => 'Dashboard layout updated successfully.',
            ]);
        }

        return back()->with('status', 'Dashboard layout updated.');
    }

    /**
     * Force-refresh both data streams Clockwork pushes/pulls to this site's
     * Companion right now, without waiting for the scheduled jobs:
     *   1. Companion snapshot (plugins + admins + cron + comments) — pulled
     *      from /snapshot, persisted to sites.companion_snapshot.
     *   2. Backups report (SpinupWP config + Spaces history + inferred schedules)
     *      — pushed to /backups-report on the WP side.
     *
     * Returns one combined ok status; partial failures are surfaced per stream.
     */

    /**
     * Pull a fresh Companion snapshot for a single site and persist it.
     * Lightweight alternative to pushCompanionData() — snapshot only, no backups push.
     */
    public function refreshCompanionSnapshot(Site $site): JsonResponse
    {
        if (! $site->companion_installed || ! $site->companion_secret) {
            return response()->json(['ok' => false, 'error' => 'Companion not installed on this site.'], 422);
        }

        $caps = $site->companion_capabilities ?? [];
        if (! in_array('snapshot', $caps, true)) {
            return response()->json(['ok' => false, 'error' => 'Site is running an older Companion version without snapshot support.'], 422);
        }

        try {
            $payload = (new ClockworkCompanionClient($site))->snapshot();
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        $site->forceFill([
            'companion_snapshot' => $payload,
            'companion_snapshot_at' => now(),
            'companion_last_seen_at' => now(),
        ])->save();

        $counts = $payload['plugins']['counts'] ?? [];

        return response()->json([
            'ok' => true,
            'updates' => (int) ($counts['updates_available'] ?? 0),
            'snapshot_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Flush a Pressable site's WordPress object cache (Redis/Memcached) —
     * distinct from the edge-cache purge this app already does internally
     * before every snapshot pull. Object cache flush is an operator tool
     * for after a direct DB write, a restored backup, or anything else
     * that bypasses the normal WordPress write path.
     */
    public function flushPressableObjectCache(Site $site, PressableClient $pressable): JsonResponse
    {
        if (! $site->isPressable() || ! $site->pressable_site_id) {
            return response()->json(['ok' => false, 'error' => 'Not a Pressable site.'], 422);
        }

        try {
            $pressable->flushObjectCache($site->pressable_site_id);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'message' => 'Object cache flush scheduled — takes a few seconds to complete.']);
    }

    /**
     * Live CPU/MySQL usage for a Pressable site over the past day, pulled
     * straight from Pressable's own metrics API (server-side data no
     * SpinupWP-nginx-log equivalent exists for) — read-only, on-demand,
     * not stored anywhere. Two calls because mixing metric families in one
     * request to Pressable silently returns an empty result (confirmed
     * 2026-08-29) — CGroup CPU and MySQL are different families.
     */
    public function pressableResourceMetrics(Site $site, PressableClient $pressable): JsonResponse
    {
        if (! $site->isPressable() || ! $site->pressable_site_id) {
            return response()->json(['ok' => false, 'error' => 'Not a Pressable site.'], 422);
        }

        try {
            $cpu = $pressable->siteMetrics($site->pressable_site_id, ['cgroup_cpu_usage'], ['server'], 'Past 1 day');
            $mysql = $pressable->siteMetrics(
                $site->pressable_site_id,
                ['mysql_cpu_time', 'mysql_busy_time', 'mysql_total_connections'],
                ['server'],
                'Past 1 day',
            );
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'cpu' => $cpu, 'mysql' => $mysql]);
    }

    public function pushCompanionData(
        Site $site,
        SpinupWpClient $spinup,
        SpacesClient $spaces,
    ): JsonResponse {
        if (! $site->companion_installed || ! $site->companion_secret) {
            return response()->json([
                'ok' => false,
                'message' => 'Companion is not installed on this site.',
            ], 422);
        }

        $client = new ClockworkCompanionClient($site);
        $results = ['snapshot' => null, 'backups' => null, 'traffic' => null];

        // 1. Snapshot — only if the plugin advertises the capability.
        $caps = $site->companion_capabilities ?? [];
        if (in_array('snapshot', $caps, true)) {
            try {
                $payload = $client->snapshot();
                $site->forceFill([
                    'companion_snapshot' => $payload,
                    'companion_snapshot_at' => now(),
                    'companion_last_seen_at' => now(),
                ])->save();
                $results['snapshot'] = ['ok' => true, 'plugins' => (int) ($payload['plugins']['counts']['total'] ?? 0)];
            } catch (Throwable $e) {
                $results['snapshot'] = ['ok' => false, 'error' => $e->getMessage()];
            }
        } else {
            $results['snapshot'] = ['ok' => false, 'error' => 'snapshot capability not advertised — older Companion version'];
        }

        // 2. Backups report — only if the site has a SpinupWP id and the
        //    plugin advertises backups-report. Otherwise skip cleanly.
        if (! $site->spinupwp_id) {
            $results['backups'] = ['ok' => false, 'error' => 'site has no SpinupWP id'];
        } elseif (! in_array('backups-report', $caps, true)) {
            $results['backups'] = ['ok' => false, 'error' => 'backups-report capability not advertised'];
        } elseif (! $spinup->isConfigured()) {
            $results['backups'] = ['ok' => false, 'error' => 'CLOCKWORK_SPINUPWP_TOKEN not set'];
        } else {
            try {
                $config = $spinup->siteBackupConfig($site->spinupwp_id);
                $history = [];
                $schedules = [];
                if ($spaces->isConfigured()) {
                    $objects = $spaces->listSiteBackupObjects($site);
                    $history = $spaces->toHistoryRows($objects);
                    $schedules = $spaces->inferSchedules($history);
                }
                $client->pushBackupsReport([
                    'source' => 'spinupwp+spaces',
                    'fetched_at' => now()->toIso8601String(),
                    'config' => $config,
                    'schedules' => $schedules,
                    'history' => $history,
                ]);
                $results['backups'] = ['ok' => true, 'history_runs' => count($history), 'schedules' => count($schedules)];
            } catch (Throwable $e) {
                $results['backups'] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        // 3. Traffic report — only if the plugin advertises traffic-report
        //    (Companion 1.16.0+). Reuses PushCompanionTraffic::buildReport()
        //    so the manual UI-triggered push is bit-for-bit identical to the
        //    nightly scheduled push. No source-of-truth divergence.
        if (! in_array('traffic-report', $caps, true)) {
            $results['traffic'] = ['ok' => false, 'error' => 'traffic-report capability not advertised — Companion 1.16.0+ required'];
        } elseif (! $site->supportsTrafficReport()) {
            try {
                $client->pushTrafficReport([
                    'source' => 'clockwork-monitoring',
                    'supported' => false,
                    'reason' => 'SSH not configured for server',
                    'daily' => [],
                ]);
                $results['traffic'] = [
                    'ok' => true,
                    'supported' => false,
                    'disabled' => true,
                ];
            } catch (Throwable $e) {
                $results['traffic'] = ['ok' => false, 'error' => $e->getMessage()];
            }
        } else {
            try {
                $report = (new PushCompanionTraffic)->buildReport($site);
                if (! $report['has_data']) {
                    $results['traffic'] = ['ok' => false, 'error' => 'no traffic rollup data in the last 30 days'];
                } else {
                    $client->pushTrafficReport($report);
                    $results['traffic'] = [
                        'ok' => true,
                        'supported' => true,
                        'days' => count($report['daily']),
                        'today_visits' => (int) ($report['totals']['today'] ?? 0),
                        'top_paths' => count($report['top_paths']),
                    ];
                }
            } catch (Throwable $e) {
                $results['traffic'] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        $allLegs = ['snapshot', 'backups', 'traffic'];
        $okBoth = ! in_array(false, array_map(fn ($k) => $results[$k]['ok'], $allLegs), true);
        $okEither = in_array(true, array_map(fn ($k) => $results[$k]['ok'], $allLegs), true);

        $bits = [];
        if ($results['snapshot']['ok'] ?? false) {
            $bits[] = "snapshot ✓ ({$results['snapshot']['plugins']} plugins)";
        } else {
            $bits[] = "snapshot ✗ ({$results['snapshot']['error']})";
        }
        if ($results['backups']['ok'] ?? false) {
            $bits[] = "backups ✓ ({$results['backups']['history_runs']} runs, {$results['backups']['schedules']} schedules)";
        } else {
            $bits[] = "backups ✗ ({$results['backups']['error']})";
        }
        if ($results['traffic']['ok']) {
            if (! empty($results['traffic']['disabled'])) {
                $bits[] = 'traffic ✓ (disabled — no SSH)';
            } else {
                $bits[] = "traffic ✓ ({$results['traffic']['days']} days, {$results['traffic']['top_paths']} top paths)";
            }
        } else {
            $bits[] = "traffic ✗ ({$results['traffic']['error']})";
        }

        return response()->json([
            'ok' => $okEither,
            'all_ok' => $okBoth,
            'message' => implode(' · ', $bits),
            'results' => $results,
        ], $okEither ? 200 : 500);
    }

    public function installCompanion(
        Site $site,
        ActionLogger $logger,
    ): JsonResponse {
        // Same result shape from both installers by design — only the
        // transport differs (SSH vs. Pressable's async command API). Which
        // one is which is the hosting provider's business, not this
        // controller's — see HostingProvider::companionInstaller().
        $installer = $site->host()->companionInstaller();
        if ($installer === null) {
            // Nullable by contract — a hosting provider's client in
            // View-Only mode (GridPane/Cloudways/Kinsta/WPEngine default to
            // this until an operator confirms live write access) returns no
            // installer at all, since installing Companion is a write
            // operation. No ActionLog entry — nothing was attempted.
            return response()->json([
                'ok' => false,
                'result' => CompanionInstaller::RESULT_FAILED,
                'message' => "{$site->host()->label()} is in View-Only mode, so Companion can't be installed. Confirm live write access on this provider in /settings/integrations first.",
            ], 422);
        }

        $result = $installer->installOrUpdate($site);
        $ok = in_array($result['result'], [
            CompanionInstaller::RESULT_INSTALLED,
            CompanionInstaller::RESULT_UPDATED,
            CompanionInstaller::RESULT_ALREADY_CURRENT,
        ], true);

        // Distinguish first-install from upgrade so the recent-activity card
        // can render the right verb. ALREADY_CURRENT isn't a meaningful action
        // — skip logging it to avoid noise. FAILED is logged too (previously
        // silently dropped here — see ActionLogger::recordCompanionInstall).
        $logger->recordCompanionInstall($site, $result);

        return response()->json([
            'ok' => $ok,
            'result' => $result['result'],
            'message' => $result['message'],
            'version' => $result['version'] ?? null,
            // Include the underlying wp-cli / SSH output on failure so the
            // operator can see WHY it failed (wrong wp_path, missing site_user,
            // table_prefix mismatch, etc.) without having to crack open the
            // server logs. Only surfaced on errors — successful installs don't
            // need the noise.
            'output' => $ok ? null : ($result['output'] ?? null),
        ], $ok ? 200 : 422);
    }

    /**
     * Mint a Companion SSO magic-link and redirect the operator to it.
     *
     * Picks the WordPress user to log in as (in priority order):
     *   1. ?as= query param (if it matches a known admin login)
     *   2. First admin in the cached companion_snapshot.admins
     *
     * The redirect happens immediately — the magic-link is one-time-use and
     * has a 60s TTL, so we want the browser to consume it without delay.
     * We also use a 302 redirect (default) rather than 303 because the form
     * POSTs to this route, and 302 → GET is what every browser does anyway.
     */
    public function ssoLaunch(Site $site, Request $request, ActionLogger $logger): RedirectResponse
    {
        if (! $site->companion_installed) {
            return back()->with('status_error', 'Companion is not installed on this site.');
        }

        $snapshot = is_array($site->companion_snapshot) ? $site->companion_snapshot : [];
        $admins = is_array($snapshot['admins']['admins'] ?? null) ? $snapshot['admins']['admins'] : [];

        $requested = trim((string) $request->input('as', ''));
        $login = '';
        if ($requested !== '') {
            foreach ($admins as $a) {
                if (is_array($a) && (string) ($a['login'] ?? '') === $requested) {
                    $login = $requested;
                    break;
                }
            }
        }
        if ($login === '' && isset($admins[0]['login'])) {
            $login = (string) $admins[0]['login'];
        }
        if ($login === '') {
            return back()->with('status_error', 'No administrator users found in the Companion snapshot. Click "Push update" to refresh, then try again.');
        }

        try {
            $result = (new ClockworkCompanionClient($site))->generateSsoLink($login);
        } catch (Throwable $e) {
            $logger->record(
                actionType: ActionLog::TYPE_SSO_LOGIN,
                summary: "SSO mint failed for {$login} on {$site->domain}.",
                site: $site,
                target: $login,
                ok: false,
                error: $e->getMessage(),
            );

            return back()->with('status_error', "SSO mint failed: {$e->getMessage()}");
        }

        if ($result['url'] === '') {
            return back()->with('status_error', 'Companion returned an empty URL.');
        }

        $logger->record(
            actionType: ActionLog::TYPE_SSO_LOGIN,
            summary: "Logged in as {$login} on {$site->domain}.",
            site: $site,
            target: $login,
            details: ['expires_at' => $result['expires_at']],
        );

        return redirect()->away($result['url']);
    }

    /**
     * Run a single plugin upgrade via Companion. Called from the Updates tab
     * JS, which loops over selected slugs and calls this once per slug so the
     * user gets per-plugin live progress.
     *
     * Returns Companion's response verbatim (with `ok`, `before_version`,
     * `after_version`, `was_active`, `reactivated`, `messages`, `elapsed_ms`)
     * plus a Clockwork-side `error` if the call itself failed.
     */
    public function updatePlugin(Site $site, Request $request, ActionLogger $logger): JsonResponse
    {
        if (! $site->companion_installed) {
            return response()->json(['ok' => false, 'error' => 'Companion is not installed on this site.'], 422);
        }

        $caps = is_array($site->companion_capabilities) ? $site->companion_capabilities : [];
        if (! in_array('updates', $caps, true)) {
            return response()->json([
                'ok' => false,
                'error' => 'This site\'s Companion is too old (no `updates` capability). Re-install Companion to upgrade.',
            ], 422);
        }

        $slug = trim((string) $request->input('slug', ''));
        if ($slug === '') {
            return response()->json(['ok' => false, 'error' => 'Missing `slug`.'], 422);
        }

        try {
            $result = (new ClockworkCompanionClient($site))->updatePlugin($slug);
        } catch (Throwable $e) {
            $logger->record(
                actionType: ActionLog::TYPE_PLUGIN_UPDATE,
                summary: "Plugin update failed (transport): {$slug} on {$site->domain}.",
                site: $site,
                target: $slug,
                ok: false,
                error: "Transport failure: {$e->getMessage()}",
            );

            return response()->json([
                'ok' => false,
                'slug' => $slug,
                'error' => "Transport failure: {$e->getMessage()}",
            ], 502);
        }

        $summary = $result['ok']
            ? ($result['before_version'] === $result['after_version']
                ? "Plugin already up to date: {$slug} ({$result['before_version']}) on {$site->domain}."
                : "Updated {$slug} {$result['before_version']} → {$result['after_version']} on {$site->domain}.")
            : "Plugin update failed: {$slug} on {$site->domain}.";

        $logger->record(
            actionType: ActionLog::TYPE_PLUGIN_UPDATE,
            summary: $summary,
            site: $site,
            target: $slug,
            details: $result,
            ok: (bool) $result['ok'],
            error: $result['error'] ?? null,
            elapsedMs: $result['elapsed_ms'],
        );

        return response()->json($result);
    }

    /**
     * Toggle the care_plan_enabled flag on a site. Also sets care_plan_override
     * so the daily Bill.com sync respects the manual choice (sync only writes
     * when override is null).
     *
     * Use clearCarePlanOverride() to "let Bill.com decide" — that nulls the
     * override and the next sync re-derives the flag from invoice activity.
     *
     * Returns JSON when the request expects it (Accept: application/json) so
     * fleet-view tables can flip the pill in-place without reloading the
     * page (which would also reorder the table). Falls back to back()
     * redirect for traditional form submits.
     */
    public function toggleCarePlan(Site $site, Request $request, ActionLogger $logger): RedirectResponse|JsonResponse
    {
        $previous = (bool) $site->care_plan_enabled;
        $enabled = $request->boolean('enabled');
        $site->forceFill([
            'care_plan_enabled' => $enabled,
            'care_plan_override' => $enabled,
        ])->save();

        $logger->record(
            actionType: ActionLog::TYPE_CARE_PLAN_TOGGLED,
            summary: $enabled
                ? "Marked {$site->domain} as on a care plan (manual override)."
                : "Marked {$site->domain} as NOT on a care plan (manual override).",
            site: $site,
            target: $enabled ? 'on' : 'off',
            details: ['previous' => $previous, 'now' => $enabled, 'override' => true],
        );

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'care_plan_enabled' => $enabled,
                'domain' => $site->domain,
            ]);
        }

        return back()->with(
            'status',
            $enabled
                ? "Marked {$site->domain} as on a care plan."
                : "Marked {$site->domain} as NOT on a care plan."
        );
    }

    /**
     * Pause / resume nightly auto-updates on a single site. Care plan
     * stays ON either way — this is a separate per-site dial for the case
     * where you want monitoring + scans to continue but plugin updates
     * should be hand-driven (redesigns, post-incident, staging, etc).
     *
     * Honoured by clockwork:run-nightly-plugin-updates which excludes
     * paused sites from its candidate query.
     */
    public function togglePauseAutoUpdates(Site $site, Request $request, ActionLogger $logger): RedirectResponse|JsonResponse
    {
        $previous = (bool) $site->auto_updates_paused;
        $paused = $request->boolean('paused');
        $reason = (string) $request->string('reason')->trim()->limit(250);

        $site->forceFill([
            'auto_updates_paused' => $paused,
            'auto_updates_paused_reason' => $paused ? ($reason ?: null) : null,
        ])->save();

        $logger->record(
            actionType: ActionLog::TYPE_AUTO_UPDATES_TOGGLED,
            summary: $paused
                ? "Paused nightly auto-updates on {$site->domain}".($reason !== '' ? " ({$reason})" : '.')
                : "Resumed nightly auto-updates on {$site->domain}.",
            site: $site,
            target: $paused ? 'paused' : 'resumed',
            details: ['previous' => $previous, 'now' => $paused, 'reason' => $reason ?: null],
        );

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'auto_updates_paused' => $paused,
                'auto_updates_paused_reason' => $site->auto_updates_paused_reason,
                'domain' => $site->domain,
            ]);
        }

        return back()->with(
            'status',
            $paused
                ? "Paused nightly auto-updates on {$site->domain}."
                : "Resumed nightly auto-updates on {$site->domain}."
        );
    }

    /**
     * Clear the manual care_plan_override so the next Bill.com sync run
     * re-derives care_plan_enabled from invoice activity. Doesn't touch
     * care_plan_enabled itself — that stays at its current value until sync
     * decides to flip it.
     */
    public function clearCarePlanOverride(Site $site, ActionLogger $logger): RedirectResponse
    {
        $site->forceFill(['care_plan_override' => null])->save();

        $logger->record(
            actionType: ActionLog::TYPE_CARE_PLAN_TOGGLED,
            summary: "Cleared manual care plan override on {$site->domain}; next Bill.com sync will decide.",
            site: $site,
            target: 'cleared',
            details: ['override' => null],
        );

        return back()->with('status', 'Care plan override cleared. The next Bill.com sync will re-derive the flag.');
    }

    /**
     * Enable / disable the every-5-min HTTP uptime probe for a single site.
     * When disabled, the site is excluded from the probe and the per-site
     * Status card on Overview shows "Monitoring disabled". State columns
     * are not cleared — if you re-enable later, the historical state stays
     * intact (next probe overwrites with fresh data).
     */
    public function toggleUptimeMonitoring(Site $site, Request $request): RedirectResponse
    {
        $enabled = $request->boolean('enabled');
        $site->forceFill(['uptime_monitoring_enabled' => $enabled])->save();

        return back()->with(
            'status',
            $enabled
                ? "Uptime monitoring enabled for {$site->domain}. Next probe runs within 5 min."
                : "Uptime monitoring disabled for {$site->domain}. Probe will skip this site."
        );
    }

    /**
     * Set / clear the uptime-ignore flag on a site. Distinct from
     * `toggleUptimeMonitoring` — ignore keeps the probe running but
     * suppresses Issues / nav badge / Mattermost. Use case: a site is
     * known down indefinitely and we want quiet without losing visibility
     * into eventual recovery.
     */
    public function toggleUptimeIgnore(Site $site, Request $request, ActionLogger $logger): RedirectResponse
    {
        $ignore = $request->boolean('ignore');

        if ($ignore) {
            $reason = trim((string) $request->input('reason', ''));
            $site->forceFill([
                'uptime_ignored_at' => now(),
                'uptime_ignore_reason' => $reason !== '' ? $reason : null,
            ])->save();

            $logger->record(
                actionType: ActionLog::TYPE_UPTIME_IGNORED,
                summary: "Uptime alerts ignored for {$site->domain}".($reason !== '' ? ": {$reason}" : '.'),
                site: $site,
                details: ['reason' => $reason],
                ok: true,
                actor: (string) (Auth::user()->email ?? 'manual'),
            );

            return back()->with('status', "Uptime alerts ignored for {$site->domain}. Probe still runs; alerts and Issues entries are suppressed until you un-ignore.");
        }

        $site->forceFill([
            'uptime_ignored_at' => null,
            'uptime_ignore_reason' => null,
        ])->save();

        $logger->record(
            actionType: ActionLog::TYPE_UPTIME_UNIGNORED,
            summary: "Uptime alerts re-enabled for {$site->domain}.",
            site: $site,
            ok: true,
            actor: (string) (Auth::user()->email ?? 'manual'),
        );

        return back()->with('status', "Uptime alerts re-enabled for {$site->domain}.");
    }

    /**
     * Set / clear the fleet-wide inactive flag. Distinct from `archive()`
     * (which hides a site from every listing entirely) and from
     * `toggleUptimeIgnore` (which only silences uptime): inactive keeps the
     * site fully visible everywhere but excludes it from the Issues page,
     * nav badge, and every site-scoped Mattermost/Slack alert — SSL
     * renewal, plugin updates, 2FA, malware scans, uptime, all of it.
     * Use case: a client migrated away but asked to keep the site reachable
     * a while longer; nobody needs routine health pings for it anymore.
     */
    public function toggleInactive(Site $site, Request $request, ActionLogger $logger): RedirectResponse
    {
        $inactive = $request->boolean('inactive');

        if ($inactive) {
            $reason = trim((string) $request->input('reason', ''));
            $site->forceFill([
                'is_inactive' => true,
                'inactive_reason' => $reason !== '' ? $reason : null,
            ])->save();

            $logger->record(
                actionType: ActionLog::TYPE_SITE_DEACTIVATED,
                summary: "{$site->domain} marked inactive".($reason !== '' ? ": {$reason}" : '.'),
                site: $site,
                details: ['reason' => $reason],
                ok: true,
                actor: (string) (Auth::user()->email ?? 'manual'),
            );

            return back()->with('status', "{$site->domain} marked inactive. It stays visible everywhere, but Issues/nav badge/Mattermost/Slack will stay quiet until you reactivate it.");
        }

        $site->forceFill([
            'is_inactive' => false,
            'inactive_reason' => null,
        ])->save();

        $logger->record(
            actionType: ActionLog::TYPE_SITE_REACTIVATED,
            summary: "{$site->domain} reactivated.",
            site: $site,
            ok: true,
            actor: (string) (Auth::user()->email ?? 'manual'),
        );

        return back()->with('status', "{$site->domain} reactivated — issues and alerts will resume.");
    }

    public function rotateCompanionSecret(Site $site, CompanionInstaller $installer): JsonResponse
    {
        $result = $installer->installOrUpdate($site, rotateSecret: true);
        $ok = in_array($result['result'], [
            CompanionInstaller::RESULT_INSTALLED,
            CompanionInstaller::RESULT_UPDATED,
            CompanionInstaller::RESULT_ALREADY_CURRENT,
        ], true);

        return response()->json([
            'ok' => $ok,
            'message' => $result['message'],
        ], $ok ? 200 : 422);
    }

    /**
     * Soft-remove a site from monitoring. Sets `archived_at`; the Site model's
     * global scope filters archived rows out of every listing (dashboard, issues,
     * monitoring, server site lists). The row stays in the DB so historical
     * scans, bans, and traffic data survive — only the live surfaces hide it.
     *
     * Operator confirms by typing the site domain (matches the destroy-server
     * pattern). The SpinupWP linkage is also cleared so a future re-import
     * doesn't resurrect the row from a stale spinupwp_id match.
     */
    public function archive(Request $request, Site $site, ActionLogger $logger): RedirectResponse
    {
        $validated = $request->validate([
            'confirm_domain' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if (! hash_equals($site->domain, $validated['confirm_domain'])) {
            return back()->with('status_error', 'Confirmation text did not match the site domain.');
        }

        if ($site->archived_at) {
            return back()->with('status', "{$site->domain} is already archived.");
        }

        $site->forceFill([
            'archived_at' => now(),
            'spinupwp_id' => null,
        ])->save();

        $logger->record(
            actionType: 'site.archive',
            summary: 'Site archived (removed from monitoring)',
            site: $site,
            target: $site->domain,
            details: ['reason' => $validated['reason'] ?? null, 'archived_by' => Auth::user()?->email],
            actor: Auth::user()?->email ?? 'manual',
        );

        // No `server` to redirect to for a Pressable site, or a SpinupWP
        // site whose server_id was already nulled before archiving (e.g. a
        // decommissioned-server cleanup that unlinked the site first to
        // avoid a cascade delete) — confirmed live 2026-09-01: this crashed
        // route generation for exactly that case. Fall back to the sites
        // list, same as any other server-less redirect in this app.
        return redirect()
            ->to($site->server ? route('servers.show', $site->server) : route('sites.index'))
            ->with('status', "Archived {$site->domain}. The row is hidden from all listings; historical data is retained.");
    }

    /**
     * Reverse `archive`. Surfaces the row again in dashboard/issues/monitoring.
     * Bypasses the global archived-at scope to find the row, since the route
     * binding would otherwise 404. Linked from the orphan-review page.
     */
    public function unarchive(string $siteId, ActionLogger $logger): RedirectResponse
    {
        $site = Site::query()->withoutGlobalScopes()->findOrFail((int) $siteId);

        if (! $site->archived_at) {
            return back()->with('status', "{$site->domain} is not archived.");
        }

        $site->forceFill(['archived_at' => null])->save();

        $logger->record(
            actionType: 'site.unarchive',
            summary: 'Site unarchived (restored to monitoring)',
            site: $site,
            target: $site->domain,
            details: ['unarchived_by' => Auth::user()?->email],
            actor: Auth::user()?->email ?? 'manual',
        );

        return redirect()
            ->route('sites.show', $site)
            ->with('status', "{$site->domain} restored to monitoring.");
    }

    /**
     * Operator-triggered: email a vulnerability report for a site to a chosen
     * recipient. Body lists every installed plugin matching a known CVE in
     * the wpvulnerability.net mirror, with the patched version and update
     * urgency rationale. Audited; recipient is whatever the operator types
     * in the modal so we can both self-test and (later) send to clients.
     */
    public function emailVulnerabilityReport(
        Request $request,
        Site $site,
        PluginVulnerabilityMatcher $matcher,
        ActionLogger $logger,
    ): JsonResponse {
        $validated = $request->validate([
            'recipient' => ['required', 'email:rfc'],
        ]);

        $vulns = $matcher->forSite($site);
        if ($vulns === []) {
            return response()->json([
                'ok' => false,
                'error' => 'No known vulnerabilities are currently matching installed plugins on this site — nothing to report.',
            ], 422);
        }

        $sender = Auth::user()?->name ?? Auth::user()?->email;

        try {
            Mail::to($validated['recipient'])->send(
                new SiteVulnerabilityReportMail($site, $vulns, $sender)
            );
        } catch (Throwable $e) {
            Log::warning('vuln-report email send failed', [
                'site_id' => $site->id,
                'recipient' => $validated['recipient'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'Mail send failed: '.$e->getMessage(),
            ], 502);
        }

        $logger->record(
            actionType: 'site.email-vuln-report',
            summary: 'Vulnerability report emailed',
            site: $site,
            target: $validated['recipient'],
            details: [
                'vuln_count' => count($vulns),
                'sender' => $sender,
                'mailer' => config('mail.default'),
            ],
            actor: Auth::user()?->email ?? 'manual',
        );

        return response()->json([
            'ok' => true,
            'recipient' => $validated['recipient'],
            'count' => count($vulns),
            'mailer' => config('mail.default'),
        ]);
    }
}
