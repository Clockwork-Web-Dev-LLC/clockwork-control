<?php

namespace App\Http\Controllers;

use App\Models\ContactFormTest;
use App\Models\IgnoredIssue;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;
use App\Models\SiteSecurityScan;
use App\Services\Security\CoreChecksumAllowlist;
use App\Services\Security\PluginVulnerabilityMatcher;
use App\Services\Sites\WpConfigExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;
use Throwable;

class IssuesController extends Controller
{
    public function index(): View
    {
        // Match the rest of the fleet automation: staging-tagged servers are
        // explicitly excluded from monitoring loops, so they shouldn't appear
        // on /issues either. Without this, SpinupWP's nightly import flips
        // upgrade_required=true on staging boxes and they sit as ghost rows
        // we can't poll-and-clear (PollSystemUpdates respects the same scope).
        $servers = Server::query()
            ->monitored()
            ->orderBy('name')
            ->get();

        // Hot-resource roll-up: 24h *average* exceeds threshold. Average tolerates short spikes
        // like nightly backups; sustained pressure still rises above the line.
        $cpuYellow = (float) config('clockwork.monitoring.cpu_yellow_threshold', 70);
        $diskYellow = (float) config('clockwork.monitoring.disk_yellow_threshold', 85);
        $memYellow = (float) config('clockwork.monitoring.memory_yellow_threshold', 80);

        $avgs = ServerMetric::query()
            ->whereIn('server_id', $servers->pluck('id'))
            ->where('recorded_at', '>=', now()->subDay())
            ->selectRaw('
                server_id,
                AVG(cpu_pct) AS avg_cpu,
                AVG(memory_pct) AS avg_memory,
                AVG(disk_pct) AS avg_disk,
                AVG(load_1) AS avg_load,
                COUNT(*) AS samples,
                MAX(recorded_at) AS last_recorded
            ')
            ->groupBy('server_id')
            ->get()
            ->keyBy('server_id');

        $hotServers = $servers
            ->map(function ($s) use ($avgs, $cpuYellow, $diskYellow, $memYellow) {
                $a = $avgs->get($s->id);
                if (! $a) {
                    return null;
                }
                $reasons = [];
                if ($a->avg_cpu !== null && $a->avg_cpu >= $cpuYellow) {
                    $reasons[] = 'CPU avg '.number_format((float) $a->avg_cpu, 0).'%';
                }
                if ($a->avg_memory !== null && $a->avg_memory >= $memYellow) {
                    $reasons[] = 'memory avg '.number_format((float) $a->avg_memory, 0).'%';
                }
                if ($a->avg_disk !== null && $a->avg_disk >= $diskYellow) {
                    $reasons[] = 'disk avg '.number_format((float) $a->avg_disk, 0).'%';
                }
                if ($reasons === []) {
                    return null;
                }
                $s->setAttribute('_avg', $a);
                $s->setAttribute('_reasons', $reasons);

                return $s;
            })
            ->filter()
            ->values();

        // SSL issues (and the several downstream .filter()s below that share
        // this same base collection: missingDbCreds, cfMisconfigured,
        // companionMissing, pluginsOutdated, twoFactorAtRisk — is_inactive
        // sites are excluded from all of them at once here).
        $sites = Site::query()
            ->with('server:id,name,is_ignored,last_ssh_ok_at')
            ->where('is_inactive', false)
            ->whereHas('server', fn ($q) => $q->where('is_ignored', false))
            ->orderBy('cert_expires_at')
            ->get();

        $sslIssues = $sites
            ->filter(fn (Site $s) => in_array($s->sslState(), ['yellow', 'red'], true))
            ->sortBy(fn (Site $s) => $s->cert_expires_at?->getTimestamp() ?? PHP_INT_MAX)
            ->values();

        // Domain expiration issues (yellow/red states).
        // KEEP IN SYNC with App\Support\IssueCounter::total().
        $domainExpirationIssues = Site::query()
            ->with('server:id,name,is_ignored')
            ->where('is_inactive', false)
            ->whereHas('server', fn ($q) => $q->where('is_ignored', false))
            ->whereIn('domain_expiration_state', [Site::DOMAIN_EXPIRATION_STATE_YELLOW, Site::DOMAIN_EXPIRATION_STATE_RED])
            ->orderBy('domain_expires_at')
            ->get();

        // Ignored SEO indexability records
        $ignoredSeoIssues = IgnoredIssue::query()
            ->where('issue_type', IgnoredIssue::TYPE_SEO_INDEXABILITY)
            ->with(['site.server', 'user'])
            ->latest()
            ->get();

        $ignoredSeoSiteIds = $ignoredSeoIssues->pluck('site_id')->filter();

        // SEO indexability issues (production blocking + staging protected).
        // KEEP IN SYNC with App\Support\IssueCounter::total().
        $allSeoIssues = Site::query()
            ->with(['server:id,name,is_ignored', 'server.tags:id,name,slug'])
            ->where('is_inactive', false)
            ->where('seo_monitoring_enabled', true)
            ->where('seo_indexable', false)
            ->whereHas('server', fn ($q) => $q->where('is_ignored', false))
            ->orderBy('seo_checked_at', 'desc')
            ->get();

        $seoIssues = $allSeoIssues->reject(fn (Site $s) => $ignoredSeoSiteIds->contains($s->id))->values();
        $seoBlockedCount = $seoIssues->reject(fn (Site $s) => (bool) $s->server?->isStaging())->count();

        // Server health: status=red
        $unhealthyServers = $servers
            ->where('status', Server::STATUS_RED)
            ->values();

        // Provisioning gaps
        $missingSsh = $servers->filter(fn (Server $s) => ! $s->last_ssh_ok_at)->values();
        $missingJail = $servers
            ->filter(fn (Server $s) => $s->last_ssh_ok_at && ! $s->clockwork_jail_provisioned_at)
            ->values();

        // WP DB credential gaps — only sites whose server has working SSH (otherwise not actionable yet).
        $missingDbCreds = $sites
            ->filter(fn (Site $s) => $s->is_wordpress
                && ! $s->db_password
                && $s->server?->last_ssh_ok_at)
            ->values();

        // Cloudflare misconfig: DNS-only is the only actionable Issue. "Not on Cloudflare"
        // is shown on the site row but not flagged here — the user can't always force a flip.
        $cfMisconfigured = $sites
            ->where('cloudflare_state', Site::CF_DNS_ONLY)
            ->values();

        $patchesAvailable = $servers->where('upgrade_required', true)->values();
        $rebootRequired = $servers->where('reboot_required', true)->values();

        // Contact form testing (care-plan benefit) — sourced from the per-form
        // contact_form_tests table. KEEP IN SYNC with App\Support\IssueCounter::total().
        // Drift here will cause the nav badge count to mismatch the page render —
        // the bug we already fixed once for missingDbCreds.
        $companionStaleAfter = now()->subDays(2);
        $sitesWithEnabledFormTests = ContactFormTest::query()
            ->where('enabled', true)
            ->whereHas('site', fn ($q) => $q->where('care_plan_enabled', true)->where('is_inactive', false))
            ->pluck('site_id')
            ->unique();
        $companionMissing = $sites
            ->filter(fn (Site $s) => $sitesWithEnabledFormTests->contains($s->id)
                && (! $s->companion_installed
                    || ! $s->companion_last_seen_at
                    || $s->companion_last_seen_at->lt($companionStaleAfter)))
            ->values();
        $failedFormTests = ContactFormTest::query()
            ->with(['site.server'])
            ->where('enabled', true)
            ->where('state', ContactFormTest::STATE_FAILED)
            ->where('failure_streak', '>=', ContactFormTest::ALERT_STREAK_THRESHOLD)
            ->whereHas('site', fn ($q) => $q->where('care_plan_enabled', true)->where('is_inactive', false))
            ->get();

        // Outdated WP plugins — derived from the cached Companion snapshot. Only sites
        // with the snapshot capability + a recent refresh contribute. KEEP IN SYNC with
        // App\Support\IssueCounter::total().
        $pluginsOutdated = $sites
            ->filter(fn (Site $s) => is_array($s->companion_snapshot)
                && (int) ($s->companion_snapshot['plugins']['counts']['updates_available'] ?? 0) > 0)
            ->sortByDesc(fn (Site $s) => (int) ($s->companion_snapshot['plugins']['counts']['updates_available'] ?? 0))
            ->values();

        // Security overlay: which of those sites have AT LEAST ONE plugin
        // version that matches a known CVE in our wpvulnerability.net mirror.
        // The matcher caches the full vuln set in-memory for this request,
        // so this is a single SQL query + N in-memory range checks. Returned
        // as a map [site_id => [vuln_finding, ...]] for the view to render
        // counts and tooltips per row.
        $matcher = app(PluginVulnerabilityMatcher::class);
        $vulnsBySiteId = $matcher->forSites($pluginsOutdated);

        // 2FA at risk — Companion's two_factor snapshot block (1.28.0+).
        // Flags sites where users' 2FA still lives in Wordfence Login
        // Security (being discontinued — worse, when WFLS is inactive those
        // users have NO login gate despite thinking they do), where the
        // CLOCKWORK_2FA_DISABLE rescue hatch was left on, or where every
        // WFLS setup has migrated and the plugin is now safe to remove
        // (1.29.0+ reports wfls_ready_to_remove). "No 2FA at all" is
        // deliberately NOT an issue yet — it would flag the whole fleet on
        // day one. KEEP IN SYNC with App\Support\IssueCounter::total().
        $twoFactorAtRisk = $sites
            ->filter(function (Site $s) {
                $tf = $s->companion_snapshot['two_factor'] ?? null;
                if (! is_array($tf)) {
                    return false;
                }

                return (int) ($tf['wfls_unmigrated_total'] ?? $tf['counts']['wfls_only'] ?? 0) > 0
                    || ! empty($tf['gate_disabled'])
                    || ! empty($tf['wfls_ready_to_remove']);
            })
            ->sortBy(fn (Site $s) => ($s->companion_snapshot['two_factor']['wfls_active'] ?? true) ? 1 : 0)
            ->values();

        // Orphaned sites — local Site rows whose SpinupWP linkage was lost.
        // Source: clockwork:find-orphan-sites populates consolidated_into_site_id
        // when a parent is detected. Both kinds (consolidated + unknown) flag here.
        // Scoped to SpinupWP sites only — GridPane/Cloudways/custom-VPS sites
        // always have spinupwp_id = null and are not orphans.
        // KEEP IN SYNC with App\Support\IssueCounter::total().
        $orphanSites = Site::query()
            ->withoutGlobalScopes()
            ->where('hosting_provider', Site::HOSTING_PROVIDER_SPINUPWP)
            ->whereNull('spinupwp_id')
            ->whereNull('archived_at')
            ->whereHas('server', fn ($q) => $q->where('is_ignored', false))
            ->orderBy('domain')
            ->get();
        $orphanParents = Site::whereIn('id', $orphanSites->pluck('consolidated_into_site_id')->filter())
            ->get()
            ->keyBy('id');

        // Security scan findings — derived from latest site_security_scans row
        // per (site, scan_type). Sucuri SiteCheck flags malware/blacklist hits;
        // wp core verify-checksums flags modified/missing/unexpected core files.
        // Restricted to care_plan_enabled sites to match the scheduled scan
        // scope — non-care-plan sites can still have stale scans from manual
        // `--site=X` runs, but we don't surface them as fleet issues since
        // they're not part of the recurring deliverable.
        // KEEP IN SYNC with App\Support\IssueCounter::total().
        $latestSiteCheckIds = SiteSecurityScan::latestPerSite(SiteSecurityScan::TYPE_SITECHECK)->pluck('id');
        $latestChecksumIds = SiteSecurityScan::latestPerSite(SiteSecurityScan::TYPE_CORE_CHECKSUMS)->pluck('id');

        $malwareHits = SiteSecurityScan::query()
            ->whereIn('id', $latestSiteCheckIds)
            ->where(fn ($q) => $q->where('has_malware_hit', true)->orWhere('blacklist_hit', true))
            ->with(['site:id,domain,server_id,care_plan_enabled', 'site.server:id,name,is_ignored'])
            ->whereHas('site', fn ($q) => $q->where('care_plan_enabled', true))
            ->whereHas('site.server', fn ($q) => $q->where('is_ignored', false))
            ->get();

        $checksumTampering = SiteSecurityScan::query()
            ->whereIn('id', $latestChecksumIds)
            ->where('status', SiteSecurityScan::STATUS_ISSUES_FOUND)
            ->with(['site:id,domain,server_id,care_plan_enabled', 'site.server:id,name,is_ignored'])
            ->whereHas('site', fn ($q) => $q->where('care_plan_enabled', true))
            ->whereHas('site.server', fn ($q) => $q->where('is_ignored', false))
            ->get();

        // Drop rows where every flagged file is allowlisted — those don't need
        // operator attention. Allowlisting happens per-site on the file viewer.
        $suppressedSiteIds = app(CoreChecksumAllowlist::class)
            ->suppressedSiteIds($checksumTampering);
        if ($suppressedSiteIds->isNotEmpty()) {
            $checksumTampering = $checksumTampering->reject(fn ($row) => $suppressedSiteIds->contains($row->site_id))->values();
        }

        // Companion malware scans — PHP-in-uploads + obfuscation-signature
        // findings from the scanner running on the site itself. Surfaced here
        // since 2026-07-15 so the operator sees ISSUES FOUND before the client
        // does (otherclient.example's findings sat client-visible-only until the client
        // screenshotted them).
        // KEEP IN SYNC with App\Support\IssueCounter::total().
        $latestCompanionMalwareIds = SiteSecurityScan::latestPerSite(SiteSecurityScan::TYPE_COMPANION_MALWARE)->pluck('id');
        $companionMalwareFindings = SiteSecurityScan::query()
            ->whereIn('id', $latestCompanionMalwareIds)
            ->where('status', SiteSecurityScan::STATUS_ISSUES_FOUND)
            ->with(['site:id,domain,server_id,care_plan_enabled', 'site.server:id,name,is_ignored'])
            ->whereHas('site', fn ($q) => $q->where('care_plan_enabled', true))
            ->whereHas('site.server', fn ($q) => $q->where('is_ignored', false))
            ->get();

        // Sites currently in 'down' state per the uptime probe — surfaced
        // prominently because real outages outrank everything else on this page.
        // Mirrors the count gate in IssueCounter::total().
        $downSites = Site::query()
            ->where('uptime_state', 'down')
            ->where('uptime_monitoring_enabled', true)
            ->whereNull('uptime_ignored_at')
            ->whereHas('server', fn ($q) => $q->where('is_ignored', false))
            ->with('server:id,name')
            ->orderBy('uptime_down_since')
            ->get();

        // KEEP IN SYNC with App\Support\IssueCounter::total().
        $stuckMaintenanceSites = Site::query()
            ->stuckInMaintenance()
            ->with('server:id,name')
            ->orderBy('uptime_maintenance_since')
            ->get();

        $totals = [
            'ssl' => $sslIssues->count(),
            'domain_expiration' => $domainExpirationIssues->count(),
            'seo_blocked' => $seoBlockedCount,
            'health' => $unhealthyServers->count(),
            'hot' => $hotServers->count(),
            'cf' => $cfMisconfigured->count(),
            'no_ssh' => $missingSsh->count(),
            'no_jail' => $missingJail->count(),
            'no_db' => $missingDbCreds->count(),
            'patches' => $patchesAvailable->count(),
            'reboot' => $rebootRequired->count(),
            'no_companion' => $companionMissing->count(),
            'forms_failing' => $failedFormTests->count(),
            'plugins_outdated' => $pluginsOutdated->count(),
            'two_factor' => $twoFactorAtRisk->count(),
            'orphans' => $orphanSites->count(),
            'malware' => $malwareHits->count(),
            'tampering' => $checksumTampering->count(),
            'companion_malware' => $companionMalwareFindings->count(),
            'down_sites' => $downSites->count(),
            'stuck_maintenance' => $stuckMaintenanceSites->count(),
        ];
        $totals['all'] = array_sum($totals);
        $totals['domain-expiration'] = $totals['domain_expiration'];
        $totals['seo-indexability'] = $totals['seo_blocked'];

        return view('dashboard.issues', compact(
            'sslIssues',
            'domainExpirationIssues',
            'seoIssues',
            'ignoredSeoIssues',
            'unhealthyServers',
            'hotServers',
            'cfMisconfigured',
            'missingSsh',
            'missingJail',
            'missingDbCreds',
            'patchesAvailable',
            'rebootRequired',
            'companionMissing',
            'failedFormTests',
            'pluginsOutdated',
            'twoFactorAtRisk',
            'vulnsBySiteId',
            'orphanSites',
            'orphanParents',
            'malwareHits',
            'checksumTampering',
            'companionMalwareFindings',
            'downSites',
            'stuckMaintenanceSites',
            'totals',
        ));
    }

    public function destroyOrphan(string $siteId): RedirectResponse
    {
        $site = Site::withoutGlobalScopes()->findOrFail($siteId);

        abort_unless($site->isSpinupWp() && $site->spinupwp_id === null, 422, 'Site is not orphaned.');
        abort_unless($site->archived_at === null, 422, 'Site is already archived.');

        $site->forceFill(['archived_at' => now()])->save();

        return back()->with('status', "{$site->domain} removed from monitoring.");
    }

    public function fetchAllDbCreds(WpConfigExtractor $extractor): RedirectResponse
    {
        $sites = Site::query()
            ->with('server')
            ->where('is_wordpress', true)
            ->whereNull('db_password')
            ->whereHas('server', fn ($q) => $q->monitored()->whereNotNull('last_ssh_ok_at'))
            ->get();

        if ($sites->isEmpty()) {
            return back()->with('status', 'No sites with missing DB credentials found.');
        }

        $ok = 0;
        $failed = [];

        foreach ($sites as $site) {
            try {
                $extractor->extractAndStore($site);
                $ok++;
            } catch (Throwable $e) {
                $failed[] = "{$site->domain}: {$e->getMessage()}";
            }
        }

        if ($failed) {
            $msg = "Fetched {$ok}, failed ".count($failed).': '.implode('; ', array_slice($failed, 0, 3));

            return back()->with('status_error', $msg);
        }

        return back()->with('status', "DB credentials fetched for {$ok} site(s).");
    }

    public function pollServers(): JsonResponse
    {
        try {
            Artisan::call('clockwork:poll-servers');
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        $unhealthy = Server::query()->monitored()->where('status', 'red')->count();

        return response()->json(['ok' => true, 'unhealthy' => $unhealthy]);
    }

    public function ignore(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'issue_type' => ['required', 'string', 'in:seo_indexability'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $ignoredIssue = IgnoredIssue::updateOrCreate(
            [
                'issue_type' => $validated['issue_type'],
                'site_id' => $validated['site_id'] ?? null,
            ],
            [
                'server_id' => $validated['server_id'] ?? null,
                'reason' => $validated['reason'] ?? null,
                'ignored_by_user_id' => $request->user()?->id,
            ]
        );

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Issue ignored successfully.',
                'ignored_issue' => $ignoredIssue->load(['site.server', 'user']),
            ]);
        }

        return back()->with('status', 'Issue ignored successfully.');
    }

    public function unignore(IgnoredIssue $ignoredIssue, Request $request): JsonResponse|RedirectResponse
    {
        $domain = $ignoredIssue->site?->domain ?? 'Target';
        $ignoredIssue->delete();

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => "Restored {$domain} to active monitoring.",
            ]);
        }

        return back()->with('status', "Restored {$domain} to active monitoring.");
    }
}
