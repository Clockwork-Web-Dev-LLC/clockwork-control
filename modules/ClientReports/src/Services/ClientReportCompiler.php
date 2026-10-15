<?php

namespace Modules\ClientReports\Services;

use App\Models\ActionLog;
use App\Models\BlockedIp;
use App\Models\ContactFormTestRun;
use App\Models\PluginVulnerability;
use App\Models\Site;
use App\Models\SiteBackup;
use App\Models\SiteBackupCheck;
use App\Models\SiteFieldMetric;
use App\Models\SitePerformanceScan;
use App\Models\SiteSecurityScan;
use App\Models\SiteTrafficDaily;
use App\Models\SiteUptimeEvent;
use App\Models\SiteWorkLog;
use App\Services\Backups\SiteBackupRecorder;
use App\Services\Companion\CompanionBrandingManager;
use App\Services\Security\PluginVulnerabilityMatcher;
use App\Support\Settings;
use Illuminate\Support\Carbon;

class ClientReportCompiler
{
    public const SCHEMA_VERSION = 3;

    /** How many of the period's final days the backups list shows. */
    public const BACKUP_LIST_DAYS = 10;

    public const UPTIME_OUTAGE_LIST_MAX = 5;

    /** A clean read older than this can't vouch for "no backups found". */
    public const BACKUP_CHECK_MAX_AGE_HOURS = 48;

    /** The feed refreshes daily; allow one missed run before calling it stale. */
    public const VULNERABILITY_FEED_MAX_AGE_HOURS = 48;

    protected ?bool $vulnerabilityFeedFresh = null;

    public function __construct(
        protected Settings $settings,
        protected PluginVulnerabilityMatcher $vulnerabilities,
        protected SiteBackupRecorder $backups,
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
                'wp_version' => $site->companion_snapshot['wp_core']['current_version'] ?? null,
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
            ->get()
            ->reject(fn (ActionLog $log) => self::isNoOpUpdate((string) $log->summary));

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
     * A run that changed nothing isn't an update to report: one that ends on
     * the version it started from ("1.5.3 → 1.5.3"), a plugin that was already
     * current, or a translations run with no packages.
     */
    public static function isNoOpUpdate(string $summary): bool
    {
        return (bool) preg_match('/(?<![\w.\-])(\d[\w.\-]*)\s*→\s*\1(?![\w.\-])/u', $summary)
            || (bool) preg_match('/^\s*(plugin|theme)s?\s+already\s+up\s+to\s+date\b/i', $summary)
            || (bool) preg_match('/^\s*translations\s+updated:\s*0\s+package/i', $summary);
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

        $intervals = [];
        $downSince = $priorEvent !== null && $isCountedDown($priorEvent) ? $start->copy() : null;

        foreach ($events as $event) {
            if ($isCountedDown($event)) {
                $downSince ??= $event->event_at->copy();

                continue;
            }

            if ($downSince !== null) {
                $intervals[] = [$downSince, $event->event_at->copy()];
                $downSince = null;
            }
        }

        $stillDown = $downSince !== null;
        if ($stillDown && $periodEnd->greaterThan($downSince)) {
            $intervals[] = [$downSince, $periodEnd->copy()];
        }

        $outages = count($intervals) + ($stillDown && ! $periodEnd->greaterThan($downSince) ? 1 : 0);
        $downtimeSeconds = array_sum(array_map(fn (array $i) => max(0, (int) $i[0]->diffInSeconds($i[1])), $intervals));

        $downtimeMinutes = min($totalMinutes, (int) round($downtimeSeconds / 60));
        $uptimePercentage = max(0.0, min(100.0, round(100 - (($downtimeMinutes / $totalMinutes) * 100), 2)));

        return [
            'monitored' => (bool) $site->uptime_monitoring_enabled,
            'uptime_percentage' => $uptimePercentage,
            'outages_count' => $outages,
            'downtime_minutes' => $downtimeMinutes,
            'total_period_minutes' => $totalMinutes,
            // Newest first, capped: the email lists the most recent outages. An
            // outage carried in from before the period starts at the period
            // start, so it's flagged rather than shown as starting then.
            'outages' => array_slice(array_reverse(array_map(fn (array $i) => [
                'started_at' => $i[0]->toIso8601String(),
                'minutes' => (int) round($i[0]->diffInSeconds($i[1]) / 60),
                'started_before_period' => $i[0]->equalTo($start) && $priorEvent !== null && $isCountedDown($priorEvent),
                'ongoing' => $stillDown && $i[1]->equalTo($periodEnd),
            ], $intervals)), 0, self::UPTIME_OUTAGE_LIST_MAX),
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
        $blacklistScan = $scans->firstWhere('scan_type', SiteSecurityScan::TYPE_BLACKLIST);

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
            // Only a number when something on the site reports its blocks to us;
            // with no blocker reporting, 0 would read as "no attacks".
            'blocked_threats_count' => $blockedIpsCount > 0 || $this->reportsBlocks($site) ? $blockedIpsCount : null,
            'checksum_status' => $checksumScan?->status,
            'checksum_checked_at' => $checksumScan?->scanned_at?->toDateString(),
            'malware_status' => $malwareScan?->status,
            'malware_checked_at' => $malwareScan?->scanned_at?->toDateString(),
            'blacklist_status' => $blacklistScan?->status,
            'blacklist_checked_at' => $blacklistScan?->scanned_at?->toDateString(),
        ];
    }

    /** Gatekeeper (Companion) or Limit Login Attempts Reloaded sends this site's lockouts to Control. */
    protected function reportsBlocks(Site $site): bool
    {
        if (in_array('gatekeeper', (array) $site->companion_capabilities, true)) {
            return true;
        }

        foreach ((array) ($site->companion_snapshot['plugins']['plugins'] ?? []) as $plugin) {
            $file = (string) ($plugin['slug'] ?? $plugin['file'] ?? '');
            if (str_starts_with($file, 'limit-login-attempts-reloaded/') && ! empty($plugin['active'])) {
                return true;
            }
        }

        return false;
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
        // GTmetrix on our plan has no phone emulation: its scans run desktop
        // Chrome whatever strategy they were filed under, so they're reported
        // as desktop. Pressable's and Google's "mobile" results really are.
        $inPeriod = fn () => SitePerformanceScan::query()
            ->where('site_id', $site->id)
            ->where('status', SitePerformanceScan::STATUS_OK)
            ->whereBetween('scanned_at', [$start, $end])
            ->orderByDesc('scanned_at');

        $latestMobile = $inPeriod()
            ->where('strategy', SitePerformanceScan::STRATEGY_MOBILE)
            ->where('engine', '!=', SitePerformanceScan::ENGINE_GTMETRIX)
            ->first();

        $latestDesktop = $inPeriod()
            ->where(fn ($q) => $q->where('strategy', SitePerformanceScan::STRATEGY_DESKTOP)
                ->orWhere('engine', SitePerformanceScan::ENGINE_GTMETRIX))
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
            // Some hosts' traffic feeds (Pressable) carry no byte counts; 0 bytes
            // for real requests means "not measured", not "nothing sent".
            'bandwidth_mb' => $bytes > 0 ? round($bytes / (1024 * 1024), 1) : null,
        ];
    }

    /**
     * Backups come only from site_backups — what each site's real source
     * (Pressable's API, SpinupWP's Spaces bucket, our S3 archives) reported,
     * recorded daily by clockwork:record-site-backups. A backup is only shown
     * if we saw it; "none found" and "couldn't check" are kept apart so the
     * preview can flag them, and neither is shown to the client.
     */
    protected function compileBackups(Site $site, Carbon $start, Carbon $end): array
    {
        $source = $this->backups->sourceFor($site);
        $windowStart = $end->copy()->subDays(self::BACKUP_LIST_DAYS - 1)->startOfDay()->max($start);

        $inPeriod = $source === null ? collect() : SiteBackup::query()
            ->where('site_id', $site->id)
            ->where('source', $source)
            ->whereBetween('backup_at', [$start, $end])
            ->orderByDesc('backup_at')
            ->get();

        // One row per day: a Pressable day has a files and a database backup.
        // The size counts one backup of each kind (the newest scheduled one),
        // so an extra on-demand or hourly backup doesn't inflate it.
        $days = $inPeriod
            ->groupBy(fn (SiteBackup $b) => $b->backup_at->toDateString())
            ->map(function ($group, string $day) {
                $perKind = $group->groupBy('kind')->map(fn ($rows) => $rows
                    ->sortBy([['is_manual', 'asc'], ['backup_at', 'desc']])
                    ->first());

                // A full backup already holds the files and database, so a
                // separate same-day database run (SpinupWP) isn't added on top.
                $sized = $perKind->has(SiteBackup::KIND_FULL)
                    ? $perKind->filter(fn (SiteBackup $b, string $kind) => $kind === SiteBackup::KIND_FULL)
                    : $perKind;

                return [
                    'date' => $day,
                    'kinds' => $perKind->keys()->sort()->values()->all(),
                    'bytes' => $sized->contains(fn (SiteBackup $b) => $b->bytes === null) ? null : (int) $sized->sum('bytes'),
                    'manual' => $group->every(fn (SiteBackup $b) => $b->is_manual),
                ];
            });
        $recent = $days->filter(fn (array $d) => $d['date'] >= $windowStart->toDateString())->values();

        $check = $source === null ? null : SiteBackupCheck::query()
            ->where('site_id', $site->id)
            ->where('source', $source)
            ->first();
        $checkIsCurrent = $check !== null && $check->ok
            && $check->checked_at->greaterThanOrEqualTo(now()->subHours(self::BACKUP_CHECK_MAX_AGE_HOURS));

        $status = match (true) {
            $source === null => 'no_source',
            // Backups that stopped before the last 10 days are a problem to
            // look at, not something to show the client as fine.
            $recent->isNotEmpty() => 'verified',
            $days->isNotEmpty() => 'stale',
            $checkIsCurrent => 'none_found',
            default => 'not_checked',
        };

        return [
            'enabled' => $source !== null,
            'verified' => $status === 'verified',
            'status' => $status,
            'source' => $source,
            'destination' => match ($source) {
                SiteBackup::SOURCE_PRESSABLE => 'your host, Pressable',
                SiteBackup::SOURCE_SPINUPWP_SPACES => 'SpinupWP, stored in DigitalOcean Spaces',
                SiteBackup::SOURCE_S3_ARCHIVE => 'independent off-site storage (Amazon S3)',
                default => null,
            },
            'days_with_backups' => $days->count(),
            'last_backup_at' => $inPeriod->first()?->backup_at->toDateString(),
            'list_from' => $windowStart->toDateString(),
            'recent' => $recent->all(),
            'check_error' => $check !== null && ! $check->ok ? $check->error : null,
            'checked_at' => $check?->checked_at->toIso8601String(),
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
