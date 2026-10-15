<?php

namespace Modules\ClientReports\Services;

use App\Models\ActionLog;
use App\Models\BlockedIp;
use App\Models\ContactFormTestRun;
use App\Models\PluginVulnerability;
use App\Models\Site;
use App\Models\SiteFieldMetric;
use App\Models\SitePerformanceScan;
use App\Models\SiteSecurityScan;
use App\Models\SiteTrafficDaily;
use App\Models\SiteUptimeEvent;
use App\Models\SiteWorkLog;
use App\Services\Companion\CompanionBrandingManager;
use App\Services\Security\PluginVulnerabilityMatcher;
use App\Support\Settings;
use Illuminate\Support\Carbon;

class ClientReportCompiler
{
    public const SCHEMA_VERSION = 2;

    /** The feed refreshes daily; allow one missed run before calling it stale. */
    public const VULNERABILITY_FEED_MAX_AGE_HOURS = 48;

    protected ?bool $vulnerabilityFeedFresh = null;

    public function __construct(
        protected Settings $settings,
        protected PluginVulnerabilityMatcher $vulnerabilities,
    ) {}

    /**
     * Compile report sections for a site over the specified date window.
     *
     * @param  ?array<string>  $sections  Optional subset of section keys to compile. If null, all sections are compiled.
     * @return array<string, mixed>
     */
    public function compile(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?string $customNotes = null,
        ?array $sections = null,
    ): array {
        $start = $periodStart->copy()->startOfDay();
        $end = $periodEnd->copy()->endOfDay();

        $shouldInclude = fn (string $key) => $sections === null || in_array($key, $sections, true);

        // Some sites are flagged non-WordPress but report a WordPress core via
        // Companion; trust the snapshot so their core-file row isn't dropped.
        $isWordPress = (bool) $site->is_wordpress || isset($site->companion_snapshot['wp_core']);

        $data = [
            'meta' => [
                'compiled_at' => now()->toIso8601String(),
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'period_days' => $start->diffInDays($end) + 1,
                'custom_notes' => $customNotes,
                // Bumped when a section's meaning changes, so the views can
                // tell reports compiled under the old (overstating) rules apart.
                'schema_version' => self::SCHEMA_VERSION,
            ],
            'branding' => $this->compileBranding(),
            'site' => [
                'id' => $site->id,
                'domain' => $site->domain,
                'is_wordpress' => $isWordPress,
                'wp_version' => $site->companion_snapshot['wp_core']['version'] ?? null,
                'php_version' => $site->companion_snapshot['environment']['php_version'] ?? null,
                'care_plan_enabled' => (bool) $site->care_plan_enabled,
            ],
        ];

        if ($shouldInclude('updates')) {
            $data['updates'] = $this->compileUpdates($site, $start, $end);
        }
        if ($shouldInclude('uptime')) {
            $data['uptime'] = $this->compileUptime($site, $start, $end);
        }
        if ($shouldInclude('security')) {
            $data['security'] = $this->compileSecurity($site, $start, $end, $isWordPress);
        }
        if ($shouldInclude('performance')) {
            $data['performance'] = $this->compilePerformance($site, $start, $end);
        }
        if ($shouldInclude('forms')) {
            $data['forms'] = $this->compileForms($site, $start, $end);
        }
        if ($shouldInclude('traffic')) {
            $data['traffic'] = $this->compileTraffic($site, $start, $end);
        }
        if ($shouldInclude('backups')) {
            $data['backups'] = $this->compileBackups($site, $start, $end);
        }
        if ($shouldInclude('work_log')) {
            $data['work_log'] = $this->compileWorkLog($site, $start, $end);
        }

        return $data;
    }

    protected function compileBranding(): array
    {
        if (class_exists(CompanionBrandingManager::class)) {
            $reportsBranding = app(CompanionBrandingManager::class)->getReportsBranding();

            return [
                'company_name' => $reportsBranding['company_name'] ?: config('app.name', 'Clockwork Control'),
                'support_url' => $reportsBranding['support_url'] ?: config('app.url'),
                'support_email' => $reportsBranding['support_email'] ?: config('mail.from.address'),
                'logo_url' => $reportsBranding['logo_url'],
                'primary_color' => $reportsBranding['primary_color'],
                'accent_color' => $reportsBranding['accent_color'],
                'footer_text' => $reportsBranding['footer_text'],
            ];
        }

        return [
            'company_name' => $this->settings->get('companion.branding.company_name') ?: ($this->settings->get('companion.company_name') ?: config('app.name', 'Clockwork Control')),
            'support_url' => $this->settings->get('companion.branding.support_url') ?: ($this->settings->get('companion.support_url') ?: config('app.url')),
            'support_email' => $this->settings->get('companion.branding.support_email') ?: ($this->settings->get('companion.support_email') ?: config('mail.from.address')),
            'logo_url' => $this->settings->get('companion.branding.logo_url') ?: $this->settings->get('companion.logo_url'),
            'primary_color' => '#2D2062',
            'accent_color' => '#7EFF83',
            'footer_text' => '',
        ];
    }

    protected function compileUpdates(Site $site, Carbon $start, Carbon $end): array
    {
        $logs = ActionLog::query()
            ->where('site_id', $site->id)
            ->whereIn('action_type', ActionLog::UPDATE_TYPES)
            ->where('ok', true)
            ->whereBetween('ran_at', [$start, $end])
            ->orderByDesc('ran_at')
            ->get();

        $pluginUpdates = [];
        $themeUpdates = [];
        $coreUpdates = [];
        $translationUpdates = [];

        foreach ($logs as $log) {
            $item = [
                'target' => $log->target,
                'summary' => $log->summary,
                'date' => $log->ran_at?->toDateString(),
            ];

            match ($log->action_type) {
                ActionLog::TYPE_PLUGIN_UPDATE => $pluginUpdates[] = $item,
                ActionLog::TYPE_THEME_UPDATE => $themeUpdates[] = $item,
                ActionLog::TYPE_CORE_UPDATE => $coreUpdates[] = $item,
                ActionLog::TYPE_TRANSLATIONS_UPDATE => $translationUpdates[] = $item,
                default => null,
            };
        }

        return [
            'total' => $logs->count(),
            'plugins_count' => count($pluginUpdates),
            'themes_count' => count($themeUpdates),
            'core_count' => count($coreUpdates),
            'translations_count' => count($translationUpdates),
            'items' => [
                'plugins' => $pluginUpdates,
                'themes' => $themeUpdates,
                'core' => $coreUpdates,
            ],
        ];
    }

    /**
     * Downtime is measured from each `down` event to the next `up` (or
     * `maintenance`) event, clamped to the period. Events only record state
     * changes, so the last event before the period tells us whether the site
     * was already down when it began. SLA-exempt outages don't count.
     */
    protected function compileUptime(Site $site, Carbon $start, Carbon $end): array
    {
        $totalMinutes = max(1, (int) $start->diffInMinutes($end));
        $periodEnd = $end->copy()->min(now());

        $priorEvent = SiteUptimeEvent::query()
            ->where('site_id', $site->id)
            ->where('event_at', '<', $start)
            ->orderByDesc('event_at')
            ->orderByDesc('id')
            ->first();

        $events = SiteUptimeEvent::query()
            ->where('site_id', $site->id)
            ->whereBetween('event_at', [$start, $end])
            ->orderBy('event_at')
            ->orderBy('id')
            ->get();

        $isCountedDown = fn (SiteUptimeEvent $e) => $e->event_type === SiteUptimeEvent::TYPE_DOWN && ! $e->is_sla_exempt;

        $outages = 0;
        $downtimeSeconds = 0;
        $downSince = $priorEvent !== null && $isCountedDown($priorEvent) ? $start->copy() : null;
        if ($downSince !== null) {
            $outages++;
        }

        foreach ($events as $event) {
            if ($isCountedDown($event)) {
                if ($downSince === null) {
                    $downSince = $event->event_at->copy();
                    $outages++;
                }

                continue;
            }

            if ($downSince !== null) {
                $downtimeSeconds += max(0, (int) $downSince->diffInSeconds($event->event_at));
                $downSince = null;
            }
        }

        if ($downSince !== null && $periodEnd->greaterThan($downSince)) {
            $downtimeSeconds += (int) $downSince->diffInSeconds($periodEnd);
        }

        $downtimeMinutes = min($totalMinutes, (int) round($downtimeSeconds / 60));
        $uptimePercentage = max(0.0, min(100.0, round(100 - (($downtimeMinutes / $totalMinutes) * 100), 2)));

        return [
            'monitored' => (bool) $site->uptime_monitoring_enabled,
            'uptime_percentage' => $uptimePercentage,
            'outages_count' => $outages,
            'downtime_minutes' => $downtimeMinutes,
            'total_period_minutes' => $totalMinutes,
        ];
    }

    /**
     * Anything we didn't actually check is reported as null / not-checked —
     * never as a passing default — because this goes to clients verbatim.
     */
    protected function compileSecurity(Site $site, Carbon $start, Carbon $end, bool $isWordPress): array
    {
        // A failed scan never ran to completion, so it can't count as a scan
        // "run" or as evidence of anything.
        $scans = SiteSecurityScan::query()
            ->where('site_id', $site->id)
            ->whereBetween('scanned_at', [$start, $end])
            ->where('status', '!=', SiteSecurityScan::STATUS_FAILED)
            ->orderByDesc('scanned_at')
            ->get();

        $checksumScan = $isWordPress
            ? $scans->firstWhere('scan_type', SiteSecurityScan::TYPE_CORE_CHECKSUMS)
            : null;

        // Companion's in-WordPress malware probe is the deeper check; SiteCheck
        // (remote, front-end only) stands in when it didn't run.
        $malwareScan = $scans->firstWhere('scan_type', SiteSecurityScan::TYPE_COMPANION_MALWARE)
            ?? $scans->firstWhere('scan_type', SiteSecurityScan::TYPE_SITECHECK);

        // The plugin list is the site's current Companion snapshot, so this is
        // the state on the snapshot date, not a history over the period.
        // An empty or stale vulnerability feed matches nothing, which would
        // read as "None found" — so a feed that hasn't refreshed recently
        // counts as not checked.
        $snapshotPlugins = $site->companion_snapshot['plugins']['plugins'] ?? null;
        $vulnerabilitiesChecked = is_array($snapshotPlugins) && $this->vulnerabilityFeedIsFresh();
        $vulnerabilities = $vulnerabilitiesChecked ? $this->vulnerabilities->forSite($site) : [];

        $blockedIpsCount = BlockedIp::query()
            ->where('site_id', $site->id)
            ->whereBetween('banned_at', [$start, $end])
            ->count();

        return [
            'total_scans' => $scans->count(),
            'clean_scans' => $scans->where('status', SiteSecurityScan::STATUS_CLEAN)->count(),
            'warn_scans' => $scans->whereIn('status', [SiteSecurityScan::STATUS_WARNING, SiteSecurityScan::STATUS_ISSUES_FOUND])->count(),
            'vulnerabilities_checked' => $vulnerabilitiesChecked,
            'vulnerabilities_as_of' => $vulnerabilitiesChecked ? $site->companion_snapshot_at?->toDateString() : null,
            'active_vulnerabilities' => count($vulnerabilities),
            'vulnerable_plugins' => array_values(array_unique(array_column($vulnerabilities, 'plugin_name'))),
            'inactive_plugin_vulnerabilities' => count(array_filter($vulnerabilities, fn (array $v) => ! $v['active'])),
            'blocked_threats_count' => $blockedIpsCount,
            'checksum_status' => $checksumScan?->status,
            'checksum_checked_at' => $checksumScan?->scanned_at?->toDateString(),
            'malware_status' => $malwareScan?->status,
            'malware_checked_at' => $malwareScan?->scanned_at?->toDateString(),
        ];
    }

    protected function vulnerabilityFeedIsFresh(): bool
    {
        if ($this->vulnerabilityFeedFresh === null) {
            $latest = PluginVulnerability::query()->max('feed_updated_at');
            $this->vulnerabilityFeedFresh = $latest !== null
                && Carbon::parse($latest)->greaterThanOrEqualTo(now()->subHours(self::VULNERABILITY_FEED_MAX_AGE_HOURS));
        }

        return $this->vulnerabilityFeedFresh;
    }

    protected function compilePerformance(Site $site, Carbon $start, Carbon $end): array
    {
        // Only tests run inside the period: an old score shown under this
        // month's heading reads as this month's result.
        $latestMobile = SitePerformanceScan::query()
            ->where('site_id', $site->id)
            ->where('strategy', SitePerformanceScan::STRATEGY_MOBILE)
            ->where('status', SitePerformanceScan::STATUS_OK)
            ->whereBetween('scanned_at', [$start, $end])
            ->orderByDesc('scanned_at')
            ->first();

        $latestDesktop = SitePerformanceScan::query()
            ->where('site_id', $site->id)
            ->where('strategy', SitePerformanceScan::STRATEGY_DESKTOP)
            ->where('status', SitePerformanceScan::STATUS_OK)
            ->whereBetween('scanned_at', [$start, $end])
            ->orderByDesc('scanned_at')
            ->first();

        $latestField = SiteFieldMetric::query()
            ->where('site_id', $site->id)
            ->where('form_factor', SiteFieldMetric::FORM_FACTOR_PHONE)
            ->where('status', SiteFieldMetric::STATUS_OK)
            ->whereBetween('collected_at', [$start, $end])
            ->orderByDesc('collected_at')
            ->first();

        return [
            'has_performance' => $latestMobile !== null || $latestDesktop !== null,
            'desktop' => $latestDesktop ? [
                'score' => $latestDesktop->performance_score,
                'lcp_ms' => $latestDesktop->lcp_ms,
                'scanned_at' => $latestDesktop->scanned_at?->toDateString(),
            ] : null,
            'mobile' => $latestMobile ? [
                'score' => $latestMobile->performance_score,
                'lcp_ms' => $latestMobile->lcp_ms,
                'scanned_at' => $latestMobile->scanned_at?->toDateString(),
            ] : null,
            'field_data' => $latestField ? [
                'cwv_pass' => $latestField->cwv_pass,
                'lcp_ms' => $latestField->lcp_p75_ms,
                'inp_ms' => $latestField->inp_p75_ms,
                'cls_x1000' => $latestField->cls_p75_x1000,
                'ttfb_ms' => $latestField->ttfb_p75_ms,
                'period_start' => $latestField->period_start?->toDateString(),
                'period_end' => $latestField->period_end?->toDateString(),
            ] : null,
        ];
    }

    protected function compileForms(Site $site, Carbon $start, Carbon $end): array
    {
        $runs = ContactFormTestRun::query()
            ->where('site_id', $site->id)
            ->whereBetween('ran_at', [$start, $end])
            ->get();

        // Only live runs actually send email, so only they say anything about
        // delivery. Lab runs suppress the email and just prove the form accepts
        // a submission.
        $live = $runs->where('mode', ContactFormTestRun::MODE_LIVE);
        $lab = $runs->where('mode', ContactFormTestRun::MODE_LAB);
        $delivered = $live
            ->where('status', ContactFormTestRun::STATUS_SUCCESS)
            ->where('mail_outcome', ContactFormTestRun::MAIL_SENT)
            ->count();

        return [
            'total_synthetic_tests' => $runs->count(),
            'live_tests' => $live->count(),
            'successful_tests' => $delivered,
            'pass_rate' => $live->isNotEmpty() ? round(($delivered / $live->count()) * 100, 1) : null,
            'lab_tests' => $lab->count(),
            'lab_accepted' => $lab->where('status', ContactFormTestRun::STATUS_SUCCESS)->count(),
        ];
    }

    protected function compileTraffic(Site $site, Carbon $start, Carbon $end): array
    {
        $traffic = SiteTrafficDaily::query()
            ->where('site_id', $site->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $visits = (int) $traffic->sum('visits');
        $requests = (int) $traffic->sum('requests');
        $bytes = (int) $traffic->sum('bytes_sent');

        return [
            'has_traffic' => $traffic->isNotEmpty(),
            'total_visits' => $visits,
            'total_requests' => $requests,
            'bandwidth_mb' => round($bytes / (1024 * 1024), 1),
        ];
    }

    protected function compileBackups(Site $site, Carbon $start, Carbon $end): array
    {
        // A backup only counts if the relay actually archived one inside the
        // period. Being switched on (or being on a care plan, or a host that
        // "usually" snapshots) isn't evidence, and clients read this as a
        // promise that a restore is possible.
        $hasBackupRelay = (bool) ($site->backup_relay_enabled ?? false);
        $lastArchived = $site->backup_relay_last_archived_at;
        $verified = $hasBackupRelay && $lastArchived !== null && $lastArchived->between($start, $end);

        return [
            'enabled' => $hasBackupRelay || $site->care_plan_enabled,
            'verified' => $verified,
            'last_backup_at' => $lastArchived?->toDateString(),
            'destination' => $verified ? 'Independent AWS S3 / Glacier' : null,
        ];
    }

    protected function compileWorkLog(Site $site, Carbon $start, Carbon $end): array
    {
        $entries = SiteWorkLog::query()
            ->with('user:id,name')
            ->where('site_id', $site->id)
            ->whereBetween('worked_on', [$start->toDateString(), $end->toDateString()])
            ->orderBy('worked_on')
            ->get();

        return [
            'total_hours' => round((float) $entries->sum('hours'), 2),
            'entries' => $entries->map(fn (SiteWorkLog $log) => [
                'worked_on' => $log->worked_on?->toDateString(),
                'hours' => (float) $log->hours,
                'description' => $log->description,
                'user' => $log->user?->name,
            ])->all(),
        ];
    }
}
