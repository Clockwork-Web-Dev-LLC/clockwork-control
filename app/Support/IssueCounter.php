<?php

namespace App\Support;

use App\Models\ContactFormTest;
use App\Models\IgnoredIssue;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;
use App\Models\SiteSecurityScan;
use App\Models\SiteTrafficDaily;
use App\Models\Tag;
use App\Services\Scheduler\SchedulerHeartbeat;
use App\Services\Security\ClosedPluginAuditor;
use App\Services\Security\CoreChecksumAllowlist;
use App\Services\Security\FleetAdminAuditor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

class IssueCounter
{
    /**
     * Cheap fleet-wide issue count for the layout badge.
     *
     * Mirrors what the Issues page shows; if you change one, change the other.
     */
    /**
     * KEEP IN SYNC with App\Http\Controllers\IssuesController::index().
     * Each category contributes to the layout badge AND must render a
     * matching section on the Issues page; drift makes the badge count
     * mismatch what the user sees.
     */
    public function total(): int
    {
        try {
            return $this->calculateTotal();
        } catch (Throwable $e) {
            Log::warning('issue_counter.total_failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    private function calculateTotal(): int
    {
        $unhealthy = Server::query()
            ->where('is_ignored', false)
            ->where('status', Server::STATUS_RED)
            ->count();

        $missingSsh = Server::query()
            ->where('is_ignored', false)
            ->whereNull('last_ssh_ok_at')
            ->count();

        $missingJail = Server::query()
            ->where('is_ignored', false)
            ->whereNotNull('last_ssh_ok_at')
            ->whereNull('clockwork_jail_provisioned_at')
            ->count();

        $missingDb = Site::query()
            ->where('is_wordpress', true)
            ->where('is_inactive', false)
            ->whereNull('db_password')
            ->whereHas('server', fn ($q) => $q->where('is_ignored', false)->whereNotNull('last_ssh_ok_at'))
            ->count();

        // SSL state is computed in PHP (not pure SQL) so we have to evaluate per-site.
        // Loading just the cert columns keeps this cheap.
        $ssl = 0;
        Site::query()
            ->select('id', 'cert_source', 'cert_expires_at', 'cert_renews_at')
            ->where('is_inactive', false)
            ->whereHas('server', fn ($q) => $q->where('is_ignored', false))
            ->whereIn('cert_source', [Site::CERT_SOURCE_SPINUPWP_LE, Site::CERT_SOURCE_EXTERNAL])
            ->whereNotNull('cert_expires_at')
            ->chunk(500, function ($chunk) use (&$ssl) {
                foreach ($chunk as $s) {
                    if (in_array($s->sslState(), [Site::SSL_STATE_YELLOW, Site::SSL_STATE_RED], true)) {
                        $ssl++;
                    }
                }
            });

        // Domain expiration: yellow (<=30 days) and red (<=7 days or redemption/pendingDelete).
        // KEEP IN SYNC with App\Http\Controllers\IssuesController::index().
        $domainExpiration = Site::query()
            ->where('is_inactive', false)
            ->whereHas('server', fn ($q) => $q->where('is_ignored', false))
            ->whereIn('domain_expiration_state', [Site::DOMAIN_EXPIRATION_STATE_YELLOW, Site::DOMAIN_EXPIRATION_STATE_RED])
            ->count();

        // SEO indexability: production sites blocking search engines.
        // Staging-tagged sites are excluded (staging sites are expected to block indexing).
        // Ignored sites are explicitly suppressed by operators.
        // KEEP IN SYNC with App\Http\Controllers\IssuesController::index().
        $seoBlocked = Site::query()
            ->where('is_inactive', false)
            ->where('seo_monitoring_enabled', true)
            ->where('seo_indexable', false)
            ->whereNotIn('id', IgnoredIssue::query()->where('issue_type', IgnoredIssue::TYPE_SEO_INDEXABILITY)->select('site_id'))
            ->hostMonitored()
            ->count();

        $hot = $this->countHotServers();
        $cf = $this->countCloudflareGaps();

        $patches = Server::query()
            ->where('is_ignored', false)
            ->where('upgrade_required', true)
            ->count();

        $reboots = Server::query()
            ->where('is_ignored', false)
            ->where('reboot_required', true)
            ->count();

        $overQuota = $this->countOverQuotaSites();

        // Form testing now lives in contact_form_tests (one row per form).
        // Companion-missing fires when any of a site's enabled form-tests
        // need Companion but Companion is missing/stale on the parent site —
        // counted once per site, not once per form.
        $companionMissing = Site::query()
            ->where('is_inactive', false)
            ->whereHas('contactFormTests', fn ($q) => $q->where('enabled', true))
            ->where(function ($q) {
                $q->where('companion_installed', false)
                    ->orWhereNull('companion_last_seen_at')
                    ->orWhere('companion_last_seen_at', '<', now()->subDays(2));
            })
            ->whereHas('server', fn ($q) => $q->where('is_ignored', false))
            ->count();

        $formsFailing = ContactFormTest::query()
            ->where('enabled', true)
            ->where('state', ContactFormTest::STATE_FAILED)
            ->where('failure_streak', '>=', ContactFormTest::ALERT_STREAK_THRESHOLD)
            ->whereHas('site', function ($q) {
                $q->when(Site::areCarePlansEnabled(), fn ($q) => $q->where('care_plan_enabled', true))
                    ->where('is_inactive', false)
                    ->whereHas('server', fn ($q) => $q->where('is_ignored', false));
            })
            ->count();

        // Sites whose cached Companion snapshot reports plugins.counts.updates_available > 0.
        // JSON path query is fine at fleet scale (~150 sites); we read once per page render.
        $pluginsOutdated = Site::query()
            ->where('is_inactive', false)
            ->whereHas('server', fn ($q) => $q->where('is_ignored', false))
            ->whereNotNull('companion_snapshot')
            ->whereRaw("CAST(JSON_EXTRACT(companion_snapshot, '$.plugins.counts.updates_available') AS UNSIGNED) > 0")
            ->count();

        // Orphaned sites — Site rows lost their SpinupWP linkage and weren't archived.
        // Detected nightly by clockwork:find-orphan-sites; surfaced here so users notice.
        // Scoped to SpinupWP sites only — see IssuesController::index() for why.
        // KEEP IN SYNC with App\Http\Controllers\IssuesController::index().
        $orphans = Site::query()
            ->withoutGlobalScopes()
            ->where('hosting_provider', Site::HOSTING_PROVIDER_SPINUPWP)
            ->whereNull('spinupwp_id')
            ->whereNull('archived_at')
            ->whereHas('server', fn ($q) => $q->where('is_ignored', false))
            ->count();

        // Security scan findings — latest sitecheck/checksum scan per site,
        // gated to care_plan_enabled sites (matches the scheduled scan scope).
        // KEEP IN SYNC with App\Http\Controllers\IssuesController::index().
        $latestSitecheckIds = SiteSecurityScan::latestPerSite(SiteSecurityScan::TYPE_SITECHECK)->pluck('id');
        $latestChecksumIds = SiteSecurityScan::latestPerSite(SiteSecurityScan::TYPE_CORE_CHECKSUMS)->pluck('id');

        // Malware/tampering are NOT gated on is_inactive, deliberately — an
        // inactive site is still live infrastructure Clockwork serves; a
        // real compromise there is still Clockwork's problem regardless of
        // the client relationship. is_inactive suppresses routine
        // maintenance nags (SSL, updates, 2FA), not active-incident alerts.
        $malware = SiteSecurityScan::query()
            ->whereIn('id', $latestSitecheckIds)
            ->where(fn ($q) => $q->where('has_malware_hit', true)->orWhere('blacklist_hit', true))
            ->whereHas('site', fn ($q) => $q->when(Site::areCarePlansEnabled(), fn ($q) => $q->where('care_plan_enabled', true)))
            ->whereHas('site.server', fn ($q) => $q->where('is_ignored', false))
            ->count();

        $tamperingScans = SiteSecurityScan::query()
            ->whereIn('id', $latestChecksumIds)
            ->where('status', SiteSecurityScan::STATUS_ISSUES_FOUND)
            ->whereHas('site', fn ($q) => $q->when(Site::areCarePlansEnabled(), fn ($q) => $q->where('care_plan_enabled', true)))
            ->whereHas('site.server', fn ($q) => $q->where('is_ignored', false))
            ->get();
        $suppressedSiteIds = app(CoreChecksumAllowlist::class)
            ->suppressedSiteIds($tamperingScans);
        $tampering = $tamperingScans->reject(fn ($s) => $suppressedSiteIds->contains($s->site_id))->count();

        // Companion malware scans (PHP-in-uploads + obfuscation signatures,
        // run on the site itself via the Companion plugin). Before 2026-07-15
        // these findings only appeared on the client-facing wp-admin Security
        // page — an operator had to wait for the CLIENT to notice and email
        // (otherclient.example). Latest scan per site, same care-plan gate as above.
        $latestCompanionMalwareIds = SiteSecurityScan::latestPerSite(SiteSecurityScan::TYPE_COMPANION_MALWARE)->pluck('id');
        $companionMalware = SiteSecurityScan::query()
            ->whereIn('id', $latestCompanionMalwareIds)
            ->where('status', SiteSecurityScan::STATUS_ISSUES_FOUND)
            ->whereHas('site', fn ($q) => $q->when(Site::areCarePlansEnabled(), fn ($q) => $q->where('care_plan_enabled', true)))
            ->whereHas('site.server', fn ($q) => $q->where('is_ignored', false))
            ->count();

        // Sites currently in 'down' state (uptime probe). Real outages take
        // priority over SSL warnings on the Issues page.
        $downSites = Site::query()
            ->where('uptime_state', 'down')
            ->where('uptime_monitoring_enabled', true)
            ->whereNull('uptime_ignored_at')
            ->whereHas('server', fn ($q) => $q->where('is_ignored', false))
            ->count();

        // Sites in maintenance mode for longer than 2 hours (likely an abandoned or forgotten window).
        // KEEP IN SYNC with App\Http\Controllers\IssuesController::index().
        $stuckMaintenanceSites = 0;
        try {
            $stuckMaintenanceSites = Site::query()->stuckInMaintenance()->count();
        } catch (Throwable $e) {
            Log::warning('issue_counter.stuck_maintenance_failed', ['error' => $e->getMessage()]);
        }

        $schedulerStale = 0;
        try {
            if (app(SchedulerHeartbeat::class)->isStale()) {
                $schedulerStale = 1;
            }
        } catch (Throwable) {
            // Missing settings table on a half-installed box shouldn't zero the whole badge.
        }

        $flaggedAdmins = 0;
        try {
            $flaggedAdmins = app(FleetAdminAuditor::class)->flaggedSites()->count();
        } catch (Throwable $e) {
            Log::warning('issue_counter.flagged_admins_failed', ['error' => $e->getMessage()]);
        }

        // Active plugins closed or removed on WordPress.org.
        // KEEP IN SYNC with App\Http\Controllers\IssuesController::index().
        $closedPlugins = 0;
        try {
            $closedPlugins = app(ClosedPluginAuditor::class)->flaggedSiteCount();
        } catch (Throwable $e) {
            Log::warning('issue_counter.closed_plugins_failed', ['error' => $e->getMessage()]);
        }

        return $unhealthy + $missingSsh + $missingJail + $missingDb + $ssl + $domainExpiration + $seoBlocked + $hot + $cf + $patches + $reboots + $overQuota + $companionMissing + $formsFailing + $pluginsOutdated + $orphans + $malware + $tampering + $companionMalware + $downSites + $stuckMaintenanceSites + $schedulerStale + $flaggedAdmins + $closedPlugins;
    }

    /**
     * Sites on Shared servers exceeding visit threshold over the rolling window — capacity threshold,
     * not a security issue, but it deserves the same "needs eyes" surface.
     */
    private function countOverQuotaSites(?int $threshold = null): int
    {
        $settings = app(Settings::class);
        $threshold ??= (int) $settings->get('capacity.visit_threshold', 30_000);
        $rollingDays = (int) $settings->get('capacity.rolling_days', 30);

        $sharedTagId = Tag::where('name', 'Shared')->value('id');
        if ($sharedTagId === null) {
            return 0;
        }

        $sharedServerIds = Server::query()
            ->where('is_ignored', false)
            ->whereHas('tags', fn ($q) => $q->where('tags.id', $sharedTagId))
            ->pluck('id');

        if ($sharedServerIds->isEmpty()) {
            return 0;
        }

        $rollingStart = CarbonImmutable::now()->startOfDay()->subDays($rollingDays - 1)->toDateString();

        return SiteTrafficDaily::query()
            ->join('sites', 'sites.id', '=', 'site_traffic_daily.site_id')
            ->whereIn('sites.server_id', $sharedServerIds)
            ->where('sites.is_inactive', false)
            ->where('site_traffic_daily.date', '>=', $rollingStart)
            ->groupBy('site_traffic_daily.site_id')
            ->havingRaw('SUM(site_traffic_daily.visits) > ?', [$threshold])
            ->select('site_traffic_daily.site_id')
            ->get()
            ->count();
    }

    private function countCloudflareGaps(): int
    {
        // Only flag DNS-only (proxy off) — these are configuration mistakes.
        // "Not on Cloudflare" is informational only; the user can't always force a flip.
        return Site::query()
            ->where('is_inactive', false)
            ->where('cloudflare_state', Site::CF_DNS_ONLY)
            ->whereHas('server', fn ($q) => $q->where('is_ignored', false))
            ->count();
    }

    private function countHotServers(): int
    {
        $settings = app(Settings::class);
        $cpuYellow = (float) $settings->get('capacity.cpu_threshold', config('clockwork.monitoring.cpu_yellow_threshold', 70));
        $diskYellow = (float) $settings->get('capacity.disk_threshold', config('clockwork.monitoring.disk_yellow_threshold', 85));
        $memYellow = (float) $settings->get('capacity.memory_threshold', config('clockwork.monitoring.memory_yellow_threshold', 80));

        $serverIds = Server::query()->where('is_ignored', false)->pluck('id');
        if ($serverIds->isEmpty()) {
            return 0;
        }

        $avgs = ServerMetric::query()
            ->whereIn('server_id', $serverIds)
            ->where('recorded_at', '>=', now()->subDay())
            ->selectRaw('AVG(cpu_pct) AS avg_cpu, AVG(memory_pct) AS avg_memory, AVG(disk_pct) AS avg_disk')
            ->groupBy('server_id')
            ->get();

        return $avgs->filter(fn ($a) => ($a->avg_cpu !== null && $a->avg_cpu >= $cpuYellow)
            || ($a->avg_memory !== null && $a->avg_memory >= $memYellow)
            || ($a->avg_disk !== null && $a->avg_disk >= $diskYellow)
        )->count();
    }
}
