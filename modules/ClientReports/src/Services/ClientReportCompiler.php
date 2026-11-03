<?php

namespace Modules\ClientReports\Services;

use App\Models\ActionLog;
use App\Models\BlockedIp;
use App\Models\ContactFormTestRun;
use App\Models\PluginUpdateIgnore;
use App\Models\PluginVulnerability;
use App\Models\ReviewQueueEntry;
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
use App\Support\UpgraderOutput;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\ClientReports\Support\ReportPhrases;
use Modules\Core\Contracts\HostingProvider;
use Throwable;

class ClientReportCompiler
{
    public const SCHEMA_VERSION = 5;

    /**
     * security.malware_status for a clean scan that didn't cover the whole
     * site (stopped at a limit, or an SSH scan cut short or recorded before
     * that could be told). Shown as "Partial scan".
     */
    public const MALWARE_INCOMPLETE = 'incomplete';

    /** How many of the period's final days the backups list shows. */
    public const BACKUP_LIST_DAYS = 10;

    public const UPTIME_OUTAGE_LIST_MAX = 5;

    /** "Too Many Requests": the host rate-limited the uptime probe, so it couldn't see the site. */
    public const PROBE_BLOCKED_STATUS = 429;

    /** A clean read older than this can't vouch for "no backups found". */
    public const BACKUP_CHECK_MAX_AGE_HOURS = 48;

    /**
     * A database backup smaller than this share of the site's database, or
     * under BACKUP_MIN_DB_BYTES of one over BACKUP_SIZEABLE_DB_BYTES, is an
     * empty or failed dump, however many days it ran.
     */
    public const BACKUP_MIN_DB_RATIO = 0.01;

    public const BACKUP_MIN_DB_BYTES = 100 * 1024;

    public const BACKUP_SIZEABLE_DB_BYTES = 10 * 1024 * 1024;

    /** How much of the backup relay's last error a report keeps. */
    public const RELAY_ERROR_MAX_LENGTH = 200;

    /**
     * The period's last day of traffic counts as complete only once its row
     * was written this long after the day ended: a host's feed (Pressable)
     * fills a day in after it's over.
     */
    public const TRAFFIC_LAST_DAY_SETTLE_HOURS = 2;

    /** Above this share of requests from two IPs, access logs see a proxy or gateway, not visitors. */
    public const TRAFFIC_TOP_IP_SHARE_MAX = 0.9;

    /** On a site this busy, fewer visits per request than this means visits weren't counted from real visitors. */
    public const TRAFFIC_BUSY_REQUESTS = 100000;

    public const TRAFFIC_MIN_VISITS_PER_REQUEST = 0.001;

    /** The feed refreshes daily; allow one missed run before calling it stale. */
    public const VULNERABILITY_FEED_MAX_AGE_HOURS = 48;

    /** A Companion snapshot older than this before the period end can't say what was waiting then. */
    public const SNAPSHOT_MAX_AGE_HOURS = 48;

    protected ?bool $vulnerabilityFeedFresh = null;

    public function __construct(
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

        // A site enrolled part-way through the period was only watched from
        // then; null when it was watched for the whole period.
        $coverageStart = $this->coverageStart($site, $start, $end);

        $data = [
            'meta' => [
                'compiled_at' => now()->toIso8601String(),
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                // Carbon 3's diffInDays is a float, and $end is the last
                // microsecond of the day, so round to whole days (31 for October).
                'period_days' => (int) round($start->diffInDays($end)),
                'custom_notes' => $customNotes,
                // When monitoring began, if that was after the period started
                // (ISO 8601). The views say so and the report is held for review.
                'coverage_start' => $coverageStart?->toIso8601String(),
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
            $data['uptime'] = $this->compileUptime($site, $start, $end, $coverageStart);
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

    /**
     * When monitoring of this site began, if that was after the period
     * started, so a site enrolled late in the month isn't reported as watched
     * for all of it. Monitoring begins with the first successful uptime probe,
     * which leaves a seed transition in the action log. A backfilled seed is
     * dated when the backfill ran, not when monitoring began, and a site
     * whose first probes failed gets no seed at all, so those fall back to
     * when the site was added, if that was inside the period.
     *
     * Starting on the period's first day counts as covering the whole period,
     * as it does for backups (covers_period).
     */
    protected function coverageStart(Site $site, Carbon $start, Carbon $end): ?Carbon
    {
        $seed = ActionLog::query()
            ->where('site_id', $site->id)
            ->where('action_type', ActionLog::TYPE_UPTIME_TRANSITION)
            ->where('details->seed', true)
            ->orderBy('ran_at')
            ->orderBy('id')
            ->first(['ran_at', 'details']);

        $began = match (true) {
            $seed !== null && $seed->ran_at !== null && empty($seed->details['backfilled']) => $seed->ran_at,
            $site->created_at !== null && $site->created_at->betweenIncluded($start, $end) => $site->created_at,
            default => null,
        };

        if ($began === null || $began->lessThan($start->copy()->addDay())) {
            return null;
        }

        return $began->copy();
    }

    /**
     * The report's branding, captured at compile time. The support URL falls
     * back to the agency website and never to Control's own URL: Control runs
     * on a private host (often localhost) that clients can't reach. A blank
     * Companion support URL is meaningful there (it hides the support
     * button), so the fallback lives here rather than in the branding manager.
     */
    protected function compileBranding(): array
    {
        $reportsBranding = app(CompanionBrandingManager::class)->getReportsBranding();

        $supportUrl = trim($reportsBranding['support_url']);
        $companyUrl = trim($reportsBranding['company_url']);

        return [
            'company_name' => $reportsBranding['company_name'] ?: config('app.name', 'Clockwork Control'),
            'support_url' => $supportUrl !== '' ? $supportUrl : ($companyUrl !== '' ? $companyUrl : null),
            'support_email' => $reportsBranding['support_email'] ?: config('mail.from.address'),
            'logo_url' => $reportsBranding['logo_url'],
            'primary_color' => $reportsBranding['primary_color'],
            'accent_color' => $reportsBranding['accent_color'],
            'footer_text' => $reportsBranding['footer_text'],
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
            // What was still waiting at the end of the period, so the email
            // never says "already up to date" while something was. null means
            // unknown (no snapshot, or one too old to say). See updatesWereWaiting().
            ...$this->pendingUpdates($site, $end),
        ];
    }

    /**
     * Updates waiting at the end of the period, from the Companion snapshot.
     * The 06:00 UTC run comes before that night's refresh (01:30 ET), so it
     * normally reads the previous day's snapshot: "waiting at the end" means
     * waiting at the last snapshot, typically ~17h before the period end. A
     * snapshot taken well before that (Companion stopped answering) can't
     * say, so everything is unknown. One taken after the period end (a held
     * report recompiled days later) may no longer show an update that was
     * waiting then and has since been applied, so those are counted
     * separately.
     *
     * pending_plugin_items is the same list with each plugin's file slug and
     * the version offered, for the review flags to join on (names repeat).
     *
     * @return array{pending_plugins: ?list<string>, pending_plugin_items: ?list<array{slug: string, name: string, new_version: ?string}>, pending_themes: ?list<string>, pending_core: ?bool, updated_since_period: int, snapshot_at: ?string, snapshot_stale: bool}
     */
    protected function pendingUpdates(Site $site, Carbon $end): array
    {
        $snapshot = (array) $site->companion_snapshot;
        $snapshotAt = $site->companion_snapshot_at;
        $stale = $site->companion_snapshot !== null
            && ($snapshotAt === null || $snapshotAt->lessThan($end->copy()->subHours(self::SNAPSHOT_MAX_AGE_HOURS)));

        $waiting = function (mixed $items, callable $shape): ?array {
            if (! is_array($items)) {
                return null;
            }

            return array_values(array_map(
                $shape,
                array_filter($items, fn ($i) => is_array($i) && ! empty($i['update_available'])),
            ));
        };
        $name = fn (array $i) => (string) ($i['name'] ?? $i['slug'] ?? '');
        $plugins = $snapshot['plugins']['plugins'] ?? null;
        $core = $snapshot['wp_core'] ?? null;

        $updatedSince = ActionLog::query()
            ->where('site_id', $site->id)
            ->whereIn('action_type', [ActionLog::TYPE_PLUGIN_UPDATE, ActionLog::TYPE_THEME_UPDATE, ActionLog::TYPE_CORE_UPDATE])
            ->where('ok', true)
            ->where('ran_at', '>', $end)
            ->get(['summary'])
            ->reject(fn (ActionLog $log) => self::isNoOpUpdate((string) $log->summary))
            ->count();

        return [
            'pending_plugins' => $stale ? null : $waiting($plugins, $name),
            'pending_plugin_items' => $stale ? null : $waiting($plugins, fn (array $i) => [
                'slug' => (string) ($i['slug'] ?? ''),
                'name' => $name($i),
                'new_version' => trim((string) ($i['new_version'] ?? '')) !== '' ? trim((string) $i['new_version']) : null,
            ]),
            'pending_themes' => $stale ? null : $waiting($snapshot['themes']['items'] ?? null, $name),
            'pending_core' => $stale || ! is_array($core) ? null : ! empty($core['update_available']),
            'updated_since_period' => $updatedSince,
            'snapshot_at' => $snapshotAt?->toDateString(),
            'snapshot_stale' => $stale,
        ];
    }

    /**
     * Whether a report's updates section must not say "already up to date":
     * something was waiting at the period end, or we can't tell. Shared by
     * the email, its text version and the preview so they can't drift apart.
     * Reports compiled before waiting updates were recorded keep their old
     * wording.
     *
     * @param  array<string, mixed>  $updates  a report's sections_data['updates']
     */
    public static function updatesWereWaiting(array $updates): bool
    {
        if (! array_key_exists('pending_plugins', $updates)) {
            return false;
        }

        foreach (['pending_plugins', 'pending_themes'] as $key) {
            if (array_key_exists($key, $updates) && ($updates[$key] === null || $updates[$key] !== [])) {
                return true;
            }
        }

        if (array_key_exists('pending_core', $updates) && $updates['pending_core'] !== false) {
            return true;
        }

        return (int) ($updates['updated_since_period'] ?? 0) > 0;
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
     *
     * A site whose monitoring began part-way through the period is measured
     * from then ($coverageStart): the days before weren't watched, so they
     * can't count as up time.
     *
     * A host that rate-limits the monitor answers HTTP 429. That says the
     * probe was blocked, not that visitors couldn't reach the site, so the
     * time it was blocked is kept out of the downtime and the outages and
     * counted separately (probe_blocked_minutes, for the operator). The
     * monitor records that as a `probe_blocked` event, which runs until the
     * next event of any other kind; before it had that state it recorded a
     * `down` with status 429, which is read the same way. A `probe_blocked`
     * event ends an open outage there (the site answers again; the monitor
     * just can't see past the block), and a real `down` while blocked starts
     * an outage. In those older rows a real outage that began while the
     * probe was already blocked left no event, so it can't be told apart.
     */
    protected function compileUptime(Site $site, Carbon $start, Carbon $end, ?Carbon $coverageStart = null): array
    {
        $from = $coverageStart !== null ? $coverageStart->copy()->max($start) : $start->copy();
        // $end is the last microsecond of the day: round, or a 31-day month is 44,639 minutes.
        $totalMinutes = max(1, (int) round($from->diffInMinutes($end)));
        $periodEnd = $end->copy()->min(now());

        $priorEvent = SiteUptimeEvent::query()
            ->where('site_id', $site->id)
            ->where('event_at', '<', $from)
            ->orderByDesc('event_at')
            ->orderByDesc('id')
            ->first();

        $events = SiteUptimeEvent::query()
            ->where('site_id', $site->id)
            ->whereBetween('event_at', [$from, $end])
            ->orderBy('event_at')
            ->orderBy('id')
            ->get();

        $isCountedDown = fn (SiteUptimeEvent $e) => $e->event_type === SiteUptimeEvent::TYPE_DOWN && ! $e->is_sla_exempt;
        // What an event opens: 'blocked' for a probe_blocked event, or a down
        // the monitor recorded for a 429 before it had that state; 'down'
        // for a real outage; null for one that ends whatever was open (up,
        // maintenance, an SLA-exempt outage).
        $opens = fn (SiteUptimeEvent $e): ?string => match (true) {
            $e->event_type === SiteUptimeEvent::TYPE_PROBE_BLOCKED => 'blocked',
            $isCountedDown($e) => (int) $e->status_code === self::PROBE_BLOCKED_STATUS ? 'blocked' : 'down',
            default => null,
        };

        // The interval currently open: where it's counted from, when it really
        // began (before the period, for one carried in), and why.
        $priorOpens = $priorEvent !== null ? $opens($priorEvent) : null;
        $open = $priorOpens !== null ? [
            'from' => $from->copy(),
            'began' => $priorEvent->event_at->copy(),
            'carried_in' => true,
            'blocked' => $priorOpens === 'blocked',
        ] : null;
        $intervals = [];

        foreach ($events as $event) {
            $kind = $opens($event);

            // More of the same (a 429 down carried into a probe_blocked
            // event, say) keeps the interval and when it began.
            if ($kind !== null && $open !== null && $open['blocked'] === ($kind === 'blocked')) {
                continue;
            }

            // Anything else ends what was open where it happened: an outage
            // ends when the site answers again, even if only with a 429, and
            // a blocked stretch ends at a real outage.
            if ($open !== null) {
                $intervals[] = [...$open, 'to' => $event->event_at->copy(), 'ongoing' => false];
                $open = null;
            }

            if ($kind !== null) {
                $open = [
                    'from' => $event->event_at->copy(),
                    'began' => $event->event_at->copy(),
                    'carried_in' => false,
                    'blocked' => $kind === 'blocked',
                ];
            }
        }

        if ($open !== null && $periodEnd->greaterThan($open['from'])) {
            $intervals[] = [...$open, 'to' => $periodEnd->copy(), 'ongoing' => true];
        }

        $seconds = fn (array $i) => max(0, (int) $i['from']->diffInSeconds($i['to']));
        $outageIntervals = array_values(array_filter($intervals, fn (array $i) => ! $i['blocked']));
        $blockedSeconds = array_sum(array_map($seconds, array_filter($intervals, fn (array $i) => $i['blocked'])));

        $outages = count($outageIntervals)
            + ($open !== null && ! $open['blocked'] && ! $periodEnd->greaterThan($open['from']) ? 1 : 0);
        $downtimeSeconds = array_sum(array_map($seconds, $outageIntervals));

        $downtimeMinutes = min($totalMinutes, (int) round($downtimeSeconds / 60));
        $uptimePercentage = max(0.0, min(100.0, round(100 - (($downtimeMinutes / $totalMinutes) * 100), 2)));

        // No events reads as 100%, so the flag alone isn't enough: the probe
        // skips ignored and staging servers and ignore-listed domains (the
        // same query as clockwork:check-site-uptime), and one that stopped
        // checking the site leaves no events either. Monitoring that only
        // began after the period has nothing to say about it.
        $inProbe = $site->uptime_monitoring_enabled
            && Site::query()->whereKey($site->id)->hostMonitored()->notDomainIgnored()->exists();
        $lastChecked = $site->uptime_last_checked_at;
        $checkedRecently = $lastChecked !== null && $lastChecked->greaterThanOrEqualTo($periodEnd->copy()->subDay());

        return [
            'monitored' => $inProbe && $checkedRecently && ($coverageStart === null || $coverageStart->lessThan($periodEnd)),
            // On, in the probe, but not checked lately: a person should look.
            'probe_stopped' => $inProbe && ! $checkedRecently,
            'last_checked_at' => $lastChecked?->toDateString(),
            // When monitoring began, if that was after the period started.
            'monitored_since' => $coverageStart?->toIso8601String(),
            'uptime_percentage' => $uptimePercentage,
            'outages_count' => $outages,
            'downtime_minutes' => $downtimeMinutes,
            'total_period_minutes' => $totalMinutes,
            // Internal: time the monitor was blocked (HTTP 429), not counted as
            // downtime. probe_blocked_since is when a block still in place at
            // the period end began, so the report is held for a person to check.
            'probe_blocked_minutes' => (int) round($blockedSeconds / 60),
            'probe_blocked_since' => $open !== null && $open['blocked'] ? $open['began']->toIso8601String() : null,
            // Newest first, capped: the email lists the most recent outages. An
            // outage carried in from before the period starts at the period
            // start, so it's flagged rather than shown as starting then.
            'outages' => array_slice(array_reverse(array_map(fn (array $i) => [
                'started_at' => $i['from']->toIso8601String(),
                'minutes' => (int) round($seconds($i) / 60),
                'started_before_period' => $i['carried_in'],
                'ongoing' => $i['ongoing'],
            ], $outageIntervals)), 0, self::UPTIME_OUTAGE_LIST_MAX),
        ];
    }

    /**
     * Anything we didn't actually check is reported as null / not-checked —
     * never as a passing default — because this goes to clients verbatim.
     *
     * The malware_* keys after malware_checked_at and vulnerable_plugin_items
     * are internal, for the review flags. Nothing here holds a file path:
     * stored data can reach the preview page, which is also the public view.
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
            ->orderByDesc('id')
            ->get();

        $checksumScan = $isWordPress
            ? $scans->firstWhere('scan_type', SiteSecurityScan::TYPE_CORE_CHECKSUMS)
            : null;

        // Companion's in-WordPress malware probe is the deeper check; SiteCheck
        // (remote, front-end only) stands in when it didn't run. The client
        // sees the latest scan, but one earlier in the month that found
        // malware is still something a person should look at.
        $companionScans = $scans->where('scan_type', SiteSecurityScan::TYPE_COMPANION_MALWARE)->values();
        $malwareScan = $companionScans->first() ?? $scans->firstWhere('scan_type', SiteSecurityScan::TYPE_SITECHECK);
        $malwareDetails = self::malwareDetails($malwareScan);
        $issueScans = $companionScans->where('status', SiteSecurityScan::STATUS_ISSUES_FOUND);
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
        $blocksMeasured = $blockedIpsCount > 0 || $this->lockoutsIngested($site, $start, $end);

        return [
            'total_scans' => $scans->count(),
            'clean_scans' => $scans->where('status', SiteSecurityScan::STATUS_CLEAN)->count(),
            'warn_scans' => $scans->whereIn('status', [SiteSecurityScan::STATUS_WARNING, SiteSecurityScan::STATUS_ISSUES_FOUND])->count(),
            'vulnerabilities_checked' => $vulnerabilitiesChecked,
            'vulnerabilities_as_of' => $vulnerabilitiesChecked ? $site->companion_snapshot_at?->toDateString() : null,
            'active_vulnerabilities' => count($vulnerabilities),
            'vulnerable_plugins' => array_values(array_unique(array_column($vulnerabilities, 'plugin_name'))),
            // Internal: each vulnerable plugin by its file slug, so the review
            // flags can tell whether a waiting update fixes it or its updates
            // are paused. Names aren't unique enough to join on.
            'vulnerable_plugin_items' => $this->vulnerablePluginItems($site, $vulnerabilities),
            'inactive_plugin_vulnerabilities' => count(array_filter($vulnerabilities, fn (array $v) => ! $v['active'])),
            // Only a number when we know lockouts from this site actually reach
            // us; otherwise 0 would read as "no attacks".
            'blocked_threats_count' => $blocksMeasured ? $blockedIpsCount : null,
            // "At the server" only where a fail2ban jail can ban at the server.
            'blocks_at_server' => $blocksMeasured && $this->hasProvisionedJail($site),
            // Whether any transport can run the core-file check on this host.
            // When none can, "Not checked this month" would be true every
            // month, so the row is left out rather than shown.
            'checksum_supported' => $isWordPress && $this->hasChecksumTransport($site),
            'checksum_status' => $checksumScan?->status,
            'checksum_checked_at' => $checksumScan?->scanned_at?->toDateString(),
            'malware_status' => $this->malwareStatus($malwareScan, $malwareDetails),
            'malware_checked_at' => $malwareScan?->scanned_at?->toDateString(),
            'malware_scan_id' => $malwareScan?->id,
            // kind => count, from Companion's findings; null for SiteCheck.
            'malware_finding_kinds' => self::findingKinds($malwareDetails),
            'malware_transport' => $malwareDetails !== null ? ($malwareDetails['transport'] ?? null) : null,
            'malware_scan_aborted' => $malwareDetails !== null && ! empty($malwareDetails['scan_aborted']),
            'malware_abort_reason' => $malwareDetails !== null ? ($malwareDetails['abort_reason'] ?? null) : null,
            'malware_scanned_files' => $malwareDetails !== null && isset($malwareDetails['scanned_files_count']) ? (int) $malwareDetails['scanned_files_count'] : null,
            // The SSH checks that hit their result cap; null when the scan
            // didn't record them (Companion, or an SSH scan from before).
            'malware_truncated_checks' => $malwareDetails !== null && is_array($malwareDetails['truncated_checks'] ?? null)
                ? array_values(array_filter($malwareDetails['truncated_checks'], 'is_string'))
                : null,
            // The worst any Companion scan in the period found, and when one
            // last found malware, so a month that started infected doesn't
            // go out as clean on the strength of its last scan.
            'malware_worst_in_period' => match (true) {
                $issueScans->isNotEmpty() => SiteSecurityScan::STATUS_ISSUES_FOUND,
                $companionScans->contains('status', SiteSecurityScan::STATUS_WARNING) => SiteSecurityScan::STATUS_WARNING,
                $companionScans->isNotEmpty() => SiteSecurityScan::STATUS_CLEAN,
                default => null,
            },
            'malware_last_issue_at' => $issueScans->first()?->scanned_at?->toDateString(),
            'malware_issue_days' => $issueScans->map(fn (SiteSecurityScan $s) => $s->scanned_at->toDateString())->unique()->count(),
            'blacklist_status' => $blacklistScan?->status,
            'blacklist_checked_at' => $blacklistScan?->scanned_at?->toDateString(),
        ];
    }

    /**
     * A Companion malware scan's recorded details, or null for SiteCheck and
     * for rows that never recorded any (whose stored status is all there is).
     *
     * @return ?array<string, mixed>
     */
    protected static function malwareDetails(?SiteSecurityScan $scan): ?array
    {
        if ($scan === null || $scan->scan_type !== SiteSecurityScan::TYPE_COMPANION_MALWARE) {
            return null;
        }

        $details = $scan->details;

        return is_array($details) && is_array($details['findings'] ?? null) ? $details : null;
    }

    /**
     * The scan's status, except that a clean result from a scan that didn't
     * cover the whole site is "incomplete", never "clean": Companion stopped
     * at its file or time limit, or an SSH check hit its result cap
     * (scan_aborted, either way). A partial scan that found something still
     * counts as flagged.
     *
     * An SSH scan never knows how many files it read (scanned_files_count is
     * always 0), so that count says nothing. Its checks do cap their output,
     * and since the scanner learned to notice a cap being hit it stores
     * truncated_checks on every SSH scan (empty when none was). Older SSH
     * rows have no truncated_checks and always said scan_aborted=false
     * whether or not a check was cut short, so a clean one can't vouch for
     * the whole site: those stay "incomplete". That's the safe reading for a
     * report, and it only touches scans recorded before the change.
     *
     * @param  ?array<string, mixed>  $details
     */
    protected function malwareStatus(?SiteSecurityScan $scan, ?array $details): ?string
    {
        if ($scan?->status === SiteSecurityScan::STATUS_CLEAN && $details !== null) {
            $partial = ! empty($details['scan_aborted'])
                || (($details['transport'] ?? null) === 'ssh' && ! array_key_exists('truncated_checks', $details));

            if ($partial) {
                return self::MALWARE_INCOMPLETE;
            }
        }

        return $scan?->status;
    }

    /**
     * How many findings of each kind the scan made, most first. Kinds only:
     * the paths stay on the scan record.
     *
     * @param  ?array<string, mixed>  $details
     * @return ?array<string, int>
     */
    protected static function findingKinds(?array $details): ?array
    {
        if ($details === null) {
            return null;
        }

        $kinds = [];
        foreach ($details['findings'] as $finding) {
            $kind = is_array($finding) ? trim((string) ($finding['kind'] ?? '')) : '';
            $kind = $kind !== '' ? $kind : 'unknown';
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
        }
        arsort($kinds);

        return $kinds;
    }

    /**
     * One entry per installed plugin with a known vulnerability, keyed by its
     * file slug as the snapshot, the updater and update pauses know it:
     * fixed_in is the highest version any of its advisories is patched in,
     * or null when one has no fix yet; update_offered is the version
     * WordPress offered at the snapshot; paused says why updates to it are
     * on hold, read now because a later unpause can't change this report.
     *
     * @param  list<array{plugin_slug: string, plugin_name: string, current_version: string, active: bool, vulnerability: PluginVulnerability, patch_available: bool}>  $vulnerabilities
     * @return list<array{slug: string, name: string, version: string, fixed_in: ?string, update_offered: ?string, paused: ?array{source: string, since: ?string, failure_count: ?int, note: ?string}}>
     */
    protected function vulnerablePluginItems(Site $site, array $vulnerabilities): array
    {
        if ($vulnerabilities === []) {
            return [];
        }

        // The matcher names a plugin by its directory; key its advisories the
        // same way, with the version, to find the snapshot entry they came from.
        $advisories = [];
        foreach ($vulnerabilities as $v) {
            $advisories[$v['plugin_slug']."\0".$v['current_version']][] = $v['vulnerability'];
        }

        $plugins = [];
        foreach ((array) ($site->companion_snapshot['plugins']['plugins'] ?? []) as $plugin) {
            $slug = is_array($plugin) ? (string) ($plugin['slug'] ?? '') : '';
            $key = explode('/', $slug, 2)[0]."\0".(string) ($plugin['version'] ?? '');
            if ($slug !== '' && isset($advisories[$key])) {
                $plugins[$slug] = [$plugin, $advisories[$key]];
            }
        }

        $pauses = $plugins === [] ? collect() : PluginUpdateIgnore::query()
            ->where('site_id', $site->id)
            ->where('target_kind', 'plugin')
            ->whereIn('target_slug', array_keys($plugins))
            ->get()
            ->keyBy('target_slug');

        $items = [];
        foreach ($plugins as $slug => [$plugin, $vulns]) {
            $fixedIn = null;
            foreach ($vulns as $vuln) {
                $patched = trim((string) $vuln->patched_in);
                if ($patched === '') {
                    $fixedIn = null;
                    break;
                }
                if ($fixedIn === null || version_compare($patched, $fixedIn, '>')) {
                    $fixedIn = $patched;
                }
            }

            $offered = trim((string) ($plugin['new_version'] ?? ''));
            $pause = $pauses->get($slug);

            $items[] = [
                'slug' => (string) $slug,
                'name' => (string) ($plugin['name'] ?? $slug),
                'version' => (string) ($plugin['version'] ?? ''),
                'fixed_in' => $fixedIn,
                'update_offered' => ! empty($plugin['update_available']) && $offered !== '' ? $offered : null,
                'paused' => $pause instanceof PluginUpdateIgnore ? [
                    'source' => (string) $pause->source,
                    'since' => $pause->ignored_at?->toDateString(),
                    'failure_count' => $pause->failure_count,
                    'note' => $pause->note !== null && trim($pause->note) !== '' ? Str::limit(trim($pause->note), 120) : null,
                ] : null,
            ];
        }

        return $items;
    }

    /**
     * Whether some transport can run `wp core verify-checksums` for this
     * site, mirroring how WpCoreChecksumVerifier::verify() picks one: a host
     * with server SSH needs a (monitored) server; one without uses Pressable's
     * command API or the host's own command runner. Custom hosts have none.
     */
    protected function hasChecksumTransport(Site $site): bool
    {
        try {
            $host = $site->host();

            if ($host->supports(HostingProvider::CAP_SSH)) {
                return $site->server !== null && ! $site->server->is_ignored;
            }

            return $site->isPressable() || $host->commandRunner() !== null;
        } catch (Throwable) {
            // A host whose module isn't enabled has no transport either.
            return false;
        }
    }

    /**
     * Evidence that lockouts from this site reached Control in the period, so
     * a count of 0 bans is a measurement: lockouts or log hits from it went
     * into the review queue, or it's in the lockout pull (a blocker reports
     * its lockouts), sits on a server with a provisioned fail2ban jail, and
     * that server's lockouts were pulled during or after the period. A
     * capability string alone proves nothing: the blocker can be switched
     * off, or the site left out of every pull.
     */
    protected function lockoutsIngested(Site $site, Carbon $start, Carbon $end): bool
    {
        $queued = ReviewQueueEntry::query()
            ->where('site_id', $site->id)
            ->where(fn ($q) => $q->whereBetween('created_at', [$start, $end])->orWhereBetween('updated_at', [$start, $end]))
            ->exists();

        if ($queued) {
            return true;
        }

        $pulledAt = $site->server?->last_llar_pull_at;

        return (bool) $site->is_wordpress
            && $this->reportsBlocks($site)
            && $this->hasProvisionedJail($site)
            && $pulledAt !== null
            && $pulledAt->greaterThanOrEqualTo($start);
    }

    /** The site's server has Clockwork's fail2ban jail, so bans happen at the server. */
    protected function hasProvisionedJail(Site $site): bool
    {
        $server = $site->server;

        return $server !== null && ! $server->is_ignored && $server->clockwork_jail_provisioned_at !== null;
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
        // A host's own report (Pressable) is dated by when the host ran the
        // test, not when we read it, which can be weeks later.
        $inPeriod = fn () => SitePerformanceScan::query()
            ->where('site_id', $site->id)
            ->where('status', SitePerformanceScan::STATUS_OK)
            ->whereRaw('COALESCE(source_generated_at, scanned_at) BETWEEN ? AND ?', [$start, $end])
            ->orderByRaw('COALESCE(source_generated_at, scanned_at) DESC');

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
                'scanned_at' => ($latestDesktop->source_generated_at ?? $latestDesktop->scanned_at)?->toDateString(),
            ] : null,
            'mobile' => $latestMobile ? [
                'score' => $latestMobile->performance_score,
                'lcp_ms' => $latestMobile->lcp_ms,
                'scanned_at' => ($latestMobile->source_generated_at ?? $latestMobile->scanned_at)?->toDateString(),
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
        // it, and even then only that the site reported the email as sent:
        // nothing watches the inbox. Lab runs suppress the email and just
        // prove the form accepts a submission.
        $live = $runs->where('mode', ContactFormTestRun::MODE_LIVE);
        $lab = $runs->where('mode', ContactFormTestRun::MODE_LAB);
        $reportedSent = $live
            ->where('status', ContactFormTestRun::STATUS_SUCCESS)
            ->where('mail_outcome', ContactFormTestRun::MAIL_SENT)
            ->count();

        return [
            'total_synthetic_tests' => $runs->count(),
            'live_tests' => $live->count(),
            'successful_tests' => $reportedSent,
            'pass_rate' => $live->isNotEmpty() ? round(($reportedSent / $live->count()) * 100, 1) : null,
            'lab_tests' => $lab->count(),
            // Whether the form plugin took the submission. A run's status is
            // Companion answering at all, which it can do while the form refuses.
            'lab_accepted' => $lab->where('accepted', true)->count(),
        ];
    }

    /**
     * The period's traffic, with a status saying whether the figures can go
     * to the client: 'ok', 'partial' (days missing, or the last day not yet
     * complete), 'suspect' (the logs see a proxy, or visits can't be real),
     * 'no_data' (a host we collect traffic from, but nothing for the period)
     * or 'unsupported' (a host we don't collect traffic from). Only 'ok' is
     * shown; the others are hidden and, except 'unsupported', held for review.
     */
    protected function compileTraffic(Site $site, Carbon $start, Carbon $end): array
    {
        // Half-open, so the last day matches however the date was written
        // ("2026-10-31" or "2026-10-31 00:00:00").
        $traffic = SiteTrafficDaily::query()
            ->where('site_id', $site->id)
            ->where('date', '>=', $start->toDateString())
            ->where('date', '<', $end->copy()->addDay()->toDateString())
            ->get();

        $visits = (int) $traffic->sum('visits');
        $requests = (int) $traffic->sum('requests');
        $bytes = (int) $traffic->sum('bytes_sent');

        // A day with no requests and no visits is a placeholder for a day the
        // feed had nothing for, not a measurement.
        $measured = $traffic->filter(fn (SiteTrafficDaily $day) => $day->requests > 0 || $day->visits > 0);
        $periodDays = max(1, (int) round($start->diffInDays($end)));
        $daysCovered = $measured->map(fn (SiteTrafficDaily $day) => $day->date->toDateString())->unique()->count();

        $lastDay = $measured->first(fn (SiteTrafficDaily $day) => $day->date->toDateString() === $end->toDateString());
        $settledAt = $end->copy()->addDay()->startOfDay()->addHours(self::TRAFFIC_LAST_DAY_SETTLE_HOURS);
        $lastDayComplete = $lastDay?->updated_at !== null && $lastDay->updated_at->greaterThanOrEqualTo($settledAt);

        $source = $site->isPressable() ? 'pressable' : 'nginx';
        $topIpShare = self::topIpShare($measured);
        $suspect = ($source === 'nginx' && $topIpShare !== null && $topIpShare > self::TRAFFIC_TOP_IP_SHARE_MAX)
            || ($requests > self::TRAFFIC_BUSY_REQUESTS && $visits < $requests * self::TRAFFIC_MIN_VISITS_PER_REQUEST);

        $status = match (true) {
            // supportsTrafficReport() is the host's state now, so it only
            // names an empty period; rows that exist are always reported on.
            $measured->isEmpty() => $this->collectsTraffic($site) ? 'no_data' : 'unsupported',
            $suspect => 'suspect',
            $daysCovered < $periodDays - 1 || ! $lastDayComplete => 'partial',
            default => 'ok',
        };

        return [
            'has_traffic' => $traffic->isNotEmpty(),
            'total_visits' => $visits,
            'total_requests' => $requests,
            // Some hosts' traffic feeds (Pressable) carry no byte counts; 0 bytes
            // for real requests means "not measured", not "nothing sent".
            'bandwidth_mb' => $bytes > 0 ? round($bytes / (1024 * 1024), 1) : null,
            'status' => $status,
            'source' => $source,
            'days_covered' => $daysCovered,
            'period_days' => $periodDays,
            'last_day_complete' => $lastDayComplete,
            // Internal: the share of requests from the two busiest IPs over the
            // period, where the rows name them (access logs do, Pressable doesn't).
            'top_ip_share' => $topIpShare,
        ];
    }

    /** Whether this site's host is one we collect traffic from (its state now). */
    protected function collectsTraffic(Site $site): bool
    {
        try {
            return $site->supportsTrafficReport();
        } catch (Throwable) {
            // A host whose module isn't enabled isn't read either.
            return false;
        }
    }

    /**
     * The two busiest IPs' share of the requests on the days that list their
     * busiest IPs, or null when none do.
     *
     * @param  iterable<SiteTrafficDaily>  $days
     */
    protected static function topIpShare(iterable $days): ?float
    {
        $hitsByIp = [];
        $requests = 0;

        foreach ($days as $day) {
            $ips = is_array($day->top_ips) ? array_filter($day->top_ips, fn ($row) => is_array($row) && isset($row['ip'])) : [];
            if ($ips === []) {
                continue;
            }

            $requests += $day->requests;
            foreach ($ips as $row) {
                $hitsByIp[(string) $row['ip']] = ($hitsByIp[(string) $row['ip']] ?? 0) + (int) ($row['hits'] ?? 0);
            }
        }

        if ($requests <= 0) {
            return null;
        }

        rsort($hitsByIp);

        return round(min(1.0, array_sum(array_slice($hitsByIp, 0, 2)) / $requests), 3);
    }

    /**
     * Backups come only from site_backups — what each site's real source
     * (Pressable's API, SpinupWP's Spaces bucket, our S3 archives) reported,
     * recorded daily by clockwork:record-site-backups. A backup is only shown
     * if we saw it; "none found" and "couldn't check" are kept apart so the
     * preview can flag them, and neither is shown to the client. Nor are
     * database backups far smaller than the database ('suspect'): a source
     * can list an empty or failed dump every day. A relay site with no
     * archive in the period whose relay was already failing by its end is
     * 'failing', with the relay's error kept for the operator.
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

        // Records reach back only as far as the source still kept backups
        // when we first read it (Pressable keeps about 8 days), so in a month
        // we started recording part-way through, the early days have no
        // record either way. "Backed up on N days this month" would then
        // understate it, so the count is only given for a fully covered month.
        $recordsFrom = $source === null ? null : SiteBackup::query()
            ->where('site_id', $site->id)
            ->where('source', $source)
            ->min('backup_at');
        $coversPeriod = $recordsFrom !== null && Carbon::parse($recordsFrom)->lessThan($start->copy()->addDay());

        $check = $source === null ? null : SiteBackupCheck::query()
            ->where('site_id', $site->id)
            ->where('source', $source)
            ->first();
        $checkIsCurrent = $check !== null && $check->ok
            && $check->checked_at->greaterThanOrEqualTo(now()->subHours(self::BACKUP_CHECK_MAX_AGE_HOURS));

        // A source can report a backup every day that holds next to nothing:
        // compare the newest database backup with the size Companion reports
        // for the site's database.
        $dbSize = $site->companion_snapshot['environment']['db_size_bytes'] ?? null;
        $dbSize = is_numeric($dbSize) && (int) $dbSize > 0 ? (int) $dbSize : null;
        $dbBackup = $inPeriod->first(fn (SiteBackup $b) => $b->bytes !== null
            && in_array($b->kind, [SiteBackup::KIND_DATABASE, SiteBackup::KIND_FULL], true));
        $dbBackupTooSmall = $dbSize !== null && $dbBackup !== null && self::backupTooSmall((int) $dbBackup->bytes, $dbSize);

        // Our own relay keeps its failure streak on the site row: when it was
        // already failing by the end of the period, that's why there's
        // nothing (or nothing recent) to show.
        $relayFailure = $source === SiteBackup::SOURCE_S3_ARCHIVE ? self::relayFailure($site, $end) : null;

        $status = match (true) {
            $source === null => 'no_source',
            // Backups that stopped before the last 10 days are a problem to
            // look at, not something to show the client as fine.
            $recent->isNotEmpty() => $dbBackupTooSmall ? 'suspect' : 'verified',
            $days->isNotEmpty() => 'stale',
            // No archive at all in the period, and the relay says why.
            $relayFailure !== null => 'failing',
            $checkIsCurrent => 'none_found',
            default => 'not_checked',
        };

        // Pressable and our S3 relay back up every day. SpinupWP's schedule is
        // set per site (often weekly), so it counts as daily only when the
        // period has backups on consecutive days. The days the period has had
        // so far, for a report on a month still running.
        $daily = $source !== null && ($source !== SiteBackup::SOURCE_SPINUPWP_SPACES || self::hasConsecutiveDays($days->keys()->all()));
        $periodDays = max(1, (int) round($start->diffInDays($end->copy()->min(now()->endOfDay()))));

        return [
            'enabled' => $source !== null,
            'verified' => $status === 'verified',
            'status' => $status,
            'source' => $source,
            // The whole phrase, preposition included ("by your host, Pressable").
            'destination' => $source !== null ? (ReportPhrases::BACKUP_DESTINATIONS[$source] ?? null) : null,
            'days_with_backups' => $days->count(),
            'covers_period' => $coversPeriod,
            // Internal, for the review flags: whether the source backs up every
            // day, and how many days of the period have passed.
            'daily' => $daily,
            'period_days' => $periodDays,
            'records_from' => $recordsFrom !== null ? Carbon::parse($recordsFrom)->toDateString() : null,
            'last_backup_at' => $inPeriod->first()?->backup_at->toDateString(),
            'list_from' => $windowStart->toDateString(),
            'recent' => $recent->all(),
            'check_error' => $check !== null && ! $check->ok ? $check->error : null,
            'checked_at' => $check?->checked_at->toIso8601String(),
            // Internal: the newest database backup's size against the database's.
            'db_backup_bytes' => $dbBackup?->bytes,
            'db_size_bytes' => $dbSize,
            // Internal: when our relay's current failure streak began, if that
            // was by the end of the period, and its last error (trimmed, URLs
            // without query strings). For the review flags and the preview.
            'relay_failing_since' => $relayFailure['since'] ?? null,
            'relay_error' => $relayFailure['error'] ?? null,
        ];
    }

    /**
     * The backup relay's current failure streak, when it began on or before
     * the period end (a streak that began later says nothing about the
     * period). A successful archive clears it, so this is the state now.
     *
     * @return ?array{since: string, error: ?string}
     */
    protected static function relayFailure(Site $site, Carbon $end): ?array
    {
        $since = $site->backup_relay_failing_since;
        if ($since === null || $since->greaterThan($end)) {
            return null;
        }

        return [
            'since' => $since->toIso8601String(),
            'error' => self::relayErrorSummary($site->backup_relay_last_error),
        ];
    }

    /**
     * A relay error short enough for a review reason. The recorder stores
     * what the archive attempt threw; a signed storage URL can carry
     * credentials in its query string, so query strings and fragments are
     * stripped from every URL (and secrets masked) before it's kept here.
     */
    public static function relayErrorSummary(?string $error): ?string
    {
        $error = trim((string) $error);
        if ($error === '') {
            return null;
        }

        $error = UpgraderOutput::redactText($error);
        // redactText only knows http(s)/ftp URLs; any scheme loses its query.
        $error = preg_replace('~\b([a-z][a-z0-9+.\-]*://[^\s?#]*)[?#]\S*~i', '$1', $error) ?? '';
        $error = trim(preg_replace('/\s+/u', ' ', $error) ?? '');

        return $error === '' ? null : Str::limit($error, self::RELAY_ERROR_MAX_LENGTH);
    }

    /** A database backup this much smaller than the database is empty or broken. */
    public static function backupTooSmall(int $backupBytes, int $dbBytes): bool
    {
        return $backupBytes < $dbBytes * self::BACKUP_MIN_DB_RATIO
            || ($backupBytes < self::BACKUP_MIN_DB_BYTES && $dbBytes > self::BACKUP_SIZEABLE_DB_BYTES);
    }

    /** @param  list<string>  $dates  Y-m-d */
    protected static function hasConsecutiveDays(array $dates): bool
    {
        sort($dates);

        for ($i = 1; $i < count($dates); $i++) {
            if (Carbon::parse($dates[$i - 1])->addDay()->toDateString() === $dates[$i]) {
                return true;
            }
        }

        return false;
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
