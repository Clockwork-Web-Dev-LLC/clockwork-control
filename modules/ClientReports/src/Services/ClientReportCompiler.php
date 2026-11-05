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
use App\Services\Security\CompanionMalwareScanner;
use App\Services\Security\PluginVulnerabilityMatcher;
use App\Support\UpgraderOutput;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Support\ReportPhrases;
use Modules\Core\Contracts\HostingProvider;
use Throwable;

class ClientReportCompiler
{
    public const SCHEMA_VERSION = 6;

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

    /** Review-queue states of a lockout that hasn't become a ban (and may never). */
    public const UNBANNED_LOCKOUT_STATUSES = [
        ReviewQueueEntry::STATUS_PENDING,
        ReviewQueueEntry::STATUS_QUEUED_FOR_BAN,
        ReviewQueueEntry::STATUS_FAILED,
    ];

    /** Above this share of requests from two IPs, access logs see a proxy or gateway, not visitors. */
    public const TRAFFIC_TOP_IP_SHARE_MAX = 0.9;

    /** On a site this busy, fewer visits per request than this means visits weren't counted from real visitors. */
    public const TRAFFIC_BUSY_REQUESTS = 100000;

    public const TRAFFIC_MIN_VISITS_PER_REQUEST = 0.001;

    /** The feed refreshes daily; allow one missed run before calling it stale. */
    public const VULNERABILITY_FEED_MAX_AGE_HOURS = 48;

    /** A Companion snapshot older than this before the period end can't say what was waiting then. */
    public const SNAPSHOT_MAX_AGE_HOURS = 48;

    /**
     * The agency's own connector plugins, by directory. Updating them is
     * our tooling, not the client's software, so they're never listed or
     * counted as the client's updates.
     */
    public const AGENCY_PLUGIN_DIRS = ['clockwork-companion', 'clockwork-renegade'];

    /**
     * Version changes something other than Control made (WordPress's own
     * auto-updates, the host, someone in wp-admin), recorded when Control
     * sees them between snapshots, and the update kind each one is listed
     * as. Their details: slug, name, from_version, to_version, observed_at.
     */
    public const OBSERVED_UPDATE_TYPES = [
        'plugin_update_observed' => ActionLog::TYPE_PLUGIN_UPDATE,
        'theme_update_observed' => ActionLog::TYPE_THEME_UPDATE,
        'core_update_observed' => ActionLog::TYPE_CORE_UPDATE,
    ];

    /**
     * Logged after an update to say it was undone or stopped: the nightly
     * run pausing the plugin or theme, or a backup restored over the site.
     */
    protected const UNDO_LOG_TYPES = [
        ActionLog::TYPE_PLUGIN_UPDATE_AUTO_IGNORED,
        ActionLog::TYPE_THEME_UPDATE_AUTO_IGNORED,
        ActionLog::TYPE_BACKUP_RESTORE_APPLIED,
    ];

    /** A ban lifted this soon after it was made was a mistake, not a malicious visitor stopped. */
    public const BAN_FALSE_POSITIVE_MINUTES = 60;

    /** Where lockouts reach Control from (the pull of Gatekeeper / LLAR / Wordfence lockouts). */
    public const LOCKOUT_SOURCES = [BlockedIp::SOURCE_LLAR, BlockedIp::SOURCE_GATEKEEPER, BlockedIp::SOURCE_WORDFENCE];

    /**
     * Lockouts reach Control from across the fleet every day; none for this
     * long before the period end means the feed stopped, so a count can't
     * be the month's total.
     */
    public const LOCKOUT_INGEST_MAX_GAP_HOURS = 72;

    protected ?bool $vulnerabilityFeedFresh = null;

    /** @var array<string, ?Carbon> lastIngestedLockout() by period end */
    protected array $lastIngestedLockout = [];

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

        // The same calendar days in the report timezone, as UTC instants. The
        // uptime and updates sections and the coverage dates show times and
        // dates in that zone, so they're measured over it: an outage at 01:00
        // UTC on the 1st reads "Sep 30, 9:00 PM EDT" and belongs to the month
        // before, and so does an update run then. The other sections list
        // whole days (UTC, as their sources record them).
        $zone = ReportPhrases::timezone();
        $localStart = Carbon::parse($periodStart->toDateString(), $zone)->startOfDay()->utc();
        $localEnd = Carbon::parse($periodEnd->toDateString(), $zone)->endOfDay()->utc();

        $shouldInclude = fn (string $key) => $sections === null || in_array($key, $sections, true);

        // Some sites are flagged non-WordPress but report a WordPress core via
        // Companion; trust the snapshot so their core-file row isn't dropped.
        $isWordPress = (bool) $site->is_wordpress || isset($site->companion_snapshot['wp_core']);

        // A site added part-way through the period, or whose care plan was
        // switched on part-way through it, was only looked after from then,
        // which the whole report says; null when it was looked after for the
        // whole period. Uptime monitoring can begin later still (switched on
        // mid-month for a site added long before), which only the uptime
        // section measures from.
        [$joinedAt, $coverageSource] = $this->coverageStart($site, $localStart, $localEnd);
        $uptimeFrom = $this->uptimeMonitoringStart($site, $localStart, $localEnd);

        $data = [
            'meta' => [
                'compiled_at' => now()->toIso8601String(),
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                // Carbon 3's diffInDays is a float, and $end is the last
                // microsecond of the day, so round to whole days (31 for October).
                'period_days' => (int) round($start->diffInDays($end)),
                'custom_notes' => $customNotes,
                // When the site was added or its care plan switched on, if that
                // was after the period's first day in the report timezone (ISO
                // 8601), and which it was ('site_added' or 'care_plan'). The
                // views say so and the report is held for review.
                'coverage_start' => $joinedAt?->toIso8601String(),
                'coverage_source' => $coverageSource,
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
            $data['updates'] = $this->compileUpdates($site, $localStart, $localEnd);
        }
        if ($shouldInclude('uptime')) {
            $data['uptime'] = $this->compileUptime($site, $localStart, $localEnd, $uptimeFrom);
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
            $data['backups'] = $this->compileBackups($site, $start, $end, $joinedAt);
        }
        if ($shouldInclude('work_log')) {
            $data['work_log'] = $this->compileWorkLog($site, $start, $end);
        }

        return $data;
    }

    /**
     * When the site was added, if that was inside the period but after its
     * first day, so a site enrolled late in the month isn't reported as
     * looked after for all of it. $start and $end bound the period's days in
     * the report timezone, so "after the first day" is the day the client
     * reads. Being added on the first day counts as the whole period, as it
     * does for backups (covers_period).
     */
    protected function joinedAt(Site $site, Carbon $start, Carbon $end): ?Carbon
    {
        $created = $site->created_at;
        if ($created === null || ! $created->betweenIncluded($start, $end) || $created->lessThan($start->copy()->addDay())) {
            return null;
        }

        return $created->copy();
    }

    /**
     * Where the report's coverage begins, if after the period's first day:
     * when the site was added (joinedAt) or when its care plan was switched
     * on (carePlanStartedAt), whichever is later, with which it was.
     *
     * @return array{0: ?Carbon, 1: ?string}
     */
    protected function coverageStart(Site $site, Carbon $start, Carbon $end): array
    {
        $added = $this->joinedAt($site, $start, $end);
        $carePlan = $this->carePlanStartedAt($site, $start, $end);

        return match (true) {
            $carePlan !== null && ($added === null || $carePlan->greaterThan($added)) => [$carePlan, 'care_plan'],
            $added !== null => [$added, 'site_added'],
            default => [null, null],
        };
    }

    /**
     * When the site's care plan was last switched on inside the period, if
     * that was after its first day (in the report timezone): the period
     * before wasn't a care-plan period, so the report covers only the rest.
     * A switch-on is a care_plan_toggled row that turned the plan on from
     * off: details.now (the site page's toggle) or details.enabled (a
     * one-off command-line run) true, with details.previous false. Rows
     * that re-sent the flag, cleared an override or found the plan already
     * on aren't. With care plans switched off fleet-wide every site counts
     * as on, so toggles say nothing.
     */
    protected function carePlanStartedAt(Site $site, Carbon $start, Carbon $end): ?Carbon
    {
        if (! Site::areCarePlansEnabled()) {
            return null;
        }

        $switchedOn = ActionLog::query()
            ->where('site_id', $site->id)
            ->where('action_type', ActionLog::TYPE_CARE_PLAN_TOGGLED)
            ->whereBetween('ran_at', [$start, $end])
            ->orderByDesc('ran_at')
            ->orderByDesc('id')
            ->get(['ran_at', 'details'])
            ->first(function (ActionLog $log) {
                $details = is_array($log->details) ? $log->details : [];

                return ($details['now'] ?? $details['enabled'] ?? null) === true
                    && array_key_exists('previous', $details) && $details['previous'] === false;
            });

        $at = $switchedOn?->ran_at;
        if ($at === null || $at->lessThan($start->copy()->addDay())) {
            return null;
        }

        return $at->copy();
    }

    /**
     * When uptime monitoring of this site began, if that was after the
     * period's first day (in the report timezone), so the uptime figures are
     * measured from then. Monitoring begins with the first successful uptime
     * probe, which leaves a seed transition in the action log; that can come
     * long after the site was added, when monitoring is switched on
     * mid-month. A backfilled seed is dated when the backfill ran, not when
     * monitoring began, and a site whose first probes failed gets no seed at
     * all, so those fall back to when the site was added, if that was inside
     * the period. A seed after the period is kept: the uptime section is then
     * left out, since monitoring had nothing to say about the period.
     */
    protected function uptimeMonitoringStart(Site $site, Carbon $start, Carbon $end): ?Carbon
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

    /**
     * The updates applied in the period: Control's own update runs, and
     * version changes something else made that Control saw between
     * snapshots (OBSERVED_UPDATE_TYPES), each listed once. $start and $end
     * bound the period's days in the report timezone, and each update is
     * dated by its day there, so an update run at 9:30 PM on the 28th
     * (01:30 UTC on the 29th) reads "Oct 28", as an outage then would.
     *
     * Left out: runs that changed nothing, the agency's own connector
     * plugins (AGENCY_PLUGIN_DIRS), and updates that were undone after they
     * ran (see undoneUpdates()), which are kept apart as not_kept for the
     * review flags.
     */
    protected function compileUpdates(Site $site, Carbon $start, Carbon $end): array
    {
        $zone = ReportPhrases::timezone();
        $logs = ActionLog::query()
            ->where('site_id', $site->id)
            ->whereIn('action_type', ActionLog::UPDATE_TYPES)
            ->where('ok', true)
            ->whereBetween('ran_at', [$start, $end])
            ->orderByDesc('ran_at')
            ->orderByDesc('id')
            ->get()
            ->reject(fn (ActionLog $log) => self::isNoOpUpdate((string) $log->summary) || self::isAgencyPluginUpdate($log))
            ->values();

        $undone = $this->undoneUpdates($site, $logs);
        $logs = $logs->reject(fn (ActionLog $log) => isset($undone[$log->id]))->values();

        $items = $logs->map(fn (ActionLog $log) => [
            'type' => $log->action_type,
            'at' => $log->ran_at,
            'item' => [
                'target' => $log->target,
                'summary' => $log->summary,
                'date' => $log->ran_at?->copy()->setTimezone($zone)->toDateString(),
                'observed' => false,
            ],
        ])->all();

        foreach ($this->observedUpdates($site, $start, $end) as $observed) {
            $items[] = $observed;
        }

        // Newest first, as the rows were read.
        usort($items, fn (array $a, array $b) => ($b['at']?->getTimestamp() ?? 0) <=> ($a['at']?->getTimestamp() ?? 0));

        $byType = fn (string $type) => array_values(array_map(
            fn (array $i) => $i['item'],
            array_filter($items, fn (array $i) => $i['type'] === $type),
        ));
        $pluginUpdates = $byType(ActionLog::TYPE_PLUGIN_UPDATE);
        $themeUpdates = $byType(ActionLog::TYPE_THEME_UPDATE);
        $coreUpdates = $byType(ActionLog::TYPE_CORE_UPDATE);
        $translationUpdates = $byType(ActionLog::TYPE_TRANSLATIONS_UPDATE);

        return [
            'total' => count($items),
            'plugins_count' => count($pluginUpdates),
            'themes_count' => count($themeUpdates),
            'core_count' => count($coreUpdates),
            'translations_count' => count($translationUpdates),
            'items' => [
                'plugins' => $pluginUpdates,
                'themes' => $themeUpdates,
                'core' => $coreUpdates,
            ],
            // Internal: updates that ran in the period but were undone after
            // (the site is on an older version now, and the update was paused
            // or a backup restored), so they aren't listed. For the review flags.
            'not_kept' => array_values($undone),
            // What was still waiting at the end of the period, so the email
            // never says "up to date" while something was. null means
            // unknown (no snapshot, or one too old to say). See updatesWereWaiting().
            ...$this->pendingUpdates($site, $end),
        ];
    }

    /** Whether an update log is for one of the agency's own connector plugins. */
    protected static function isAgencyPluginUpdate(ActionLog $log): bool
    {
        $type = self::OBSERVED_UPDATE_TYPES[$log->action_type] ?? $log->action_type;
        if ($type !== ActionLog::TYPE_PLUGIN_UPDATE) {
            return false;
        }

        $details = is_array($log->details) ? $log->details : [];

        return self::isAgencyPlugin((string) ($details['slug'] ?? $log->target ?? ''));
    }

    /** Whether a plugin slug ("clockwork-companion/clockwork-companion.php") is one of the agency's own. */
    public static function isAgencyPlugin(string $slug): bool
    {
        return in_array(strtolower(explode('/', trim($slug), 2)[0]), self::AGENCY_PLUGIN_DIRS, true);
    }

    /**
     * Version changes something other than Control made, seen in the period,
     * as update items. Each is dated by when it was seen (observed_at, else
     * when it was logged) in the report timezone. One that Control's own
     * update run made (the same plugin, theme or core going to the same
     * version) is already listed, so it's left out; so are the agency's own
     * plugins and changes that ended on the version they started from.
     *
     * @return list<array{type: string, at: Carbon, item: array{target: ?string, summary: string, date: string, observed: bool}}>
     */
    protected function observedUpdates(Site $site, Carbon $start, Carbon $end): array
    {
        $zone = ReportPhrases::timezone();
        $rows = $this->observedUpdateRows($site, $start)
            ->filter(fn (array $row) => $row['at']->betweenIncluded($start, $end))
            ->reject(fn (array $row) => $row['to'] === '' || $row['to'] === $row['from'])
            ->values();
        if ($rows->isEmpty()) {
            return [];
        }

        // Control's own runs to the same version, a little either side of
        // the period: a snapshot can see a change a day after Control made it.
        $applied = [];
        ActionLog::query()
            ->where('site_id', $site->id)
            ->whereIn('action_type', [ActionLog::TYPE_PLUGIN_UPDATE, ActionLog::TYPE_THEME_UPDATE, ActionLog::TYPE_CORE_UPDATE])
            ->where('ok', true)
            ->whereBetween('ran_at', [$start->copy()->subDays(31), $end->copy()->addDays(31)])
            ->get(['action_type', 'target', 'summary', 'details'])
            ->each(function (ActionLog $log) use (&$applied) {
                $details = is_array($log->details) ? $log->details : [];
                $to = trim((string) ($details['after_version'] ?? ''));
                if ($to === '' && preg_match('/→\s*(\S+)\s*(?:\[|$)/u', (string) $log->summary, $m)) {
                    $to = $m[1];
                }
                $applied[self::updateKey($log->action_type, (string) ($details['slug'] ?? $log->target ?? ''), $to)] = true;
            });

        $prefixes = [
            ActionLog::TYPE_PLUGIN_UPDATE => 'Plugin updated: ',
            ActionLog::TYPE_THEME_UPDATE => 'Theme updated: ',
            ActionLog::TYPE_CORE_UPDATE => 'WordPress core updated: ',
        ];

        $items = [];
        foreach ($rows as $row) {
            if (isset($applied[self::updateKey($row['type'], $row['slug'], $row['to'])])) {
                continue;
            }

            $name = $row['type'] === ActionLog::TYPE_CORE_UPDATE ? '' : $row['name'];
            $versions = ($row['from'] !== '' ? $row['from'].' → ' : '→ ').$row['to'];
            $items[] = [
                'type' => $row['type'],
                'at' => $row['at'],
                'item' => [
                    'target' => $row['slug'] !== '' ? $row['slug'] : null,
                    'summary' => $prefixes[$row['type']].trim($name.' '.$versions),
                    'date' => $row['at']->copy()->setTimezone($zone)->toDateString(),
                    'observed' => true,
                ],
            ];
        }

        return $items;
    }

    /**
     * The site's observed version changes from $since on, read into one
     * shape, without the agency's own plugins.
     *
     * @return Collection<int, array{type: string, slug: string, name: string, from: string, to: string, at: Carbon}>
     */
    protected function observedUpdateRows(Site $site, Carbon $since): Collection
    {
        return ActionLog::query()
            ->where('site_id', $site->id)
            ->whereIn('action_type', array_keys(self::OBSERVED_UPDATE_TYPES))
            ->where('ok', true)
            // observed_at can come before the row was written, never after.
            ->where('ran_at', '>=', $since)
            ->get()
            ->reject(fn (ActionLog $log) => self::isAgencyPluginUpdate($log))
            ->map(function (ActionLog $log) {
                $details = is_array($log->details) ? $log->details : [];
                $slug = trim((string) ($details['slug'] ?? $log->target ?? ''));

                try {
                    $at = is_string($details['observed_at'] ?? null) && trim($details['observed_at']) !== ''
                        ? Carbon::parse($details['observed_at'])
                        : $log->ran_at;
                } catch (Throwable) {
                    $at = $log->ran_at;
                }

                return [
                    'type' => self::OBSERVED_UPDATE_TYPES[$log->action_type],
                    'slug' => $slug,
                    'name' => trim((string) ($details['name'] ?? '')) ?: ($slug !== '' ? Str::headline(explode('/', $slug, 2)[0]) : 'WordPress'),
                    'from' => trim((string) ($details['from_version'] ?? '')),
                    'to' => trim((string) ($details['to_version'] ?? '')),
                    'at' => $at?->copy()->utc(),
                ];
            })
            ->filter(fn (array $row) => $row['at'] !== null)
            ->values();
    }

    /** "plugin_update|example-forms|2.1": an update by kind, plugin or theme directory and the version it reached. */
    protected static function updateKey(string $type, string $slug, string $version): string
    {
        $dir = $type === ActionLog::TYPE_CORE_UPDATE ? '' : strtolower(explode('/', trim($slug), 2)[0]);

        return $type.'|'.$dir.'|'.trim($version);
    }

    /**
     * Plugin and theme updates that ran but didn't stick: the site's
     * snapshot, taken after the update, shows a lower version than the
     * update reached, and since the update the plugin or theme was paused
     * (an update ignore, or the nightly run's auto-pause) or a backup was
     * restored over the site. A lower version alone isn't enough (the
     * snapshot may list the plugin under another slug, or a person may have
     * rolled it back for a reason the client was told about), so without
     * one of those the update is still listed.
     *
     * @param  Collection<int, ActionLog>  $logs
     * @return array<int, array{type: string, label: string, date: ?string, installed_version: string}> keyed by log id
     */
    protected function undoneUpdates(Site $site, Collection $logs): array
    {
        $snapshotAt = $site->companion_snapshot_at;
        $snapshot = (array) $site->companion_snapshot;
        if ($snapshotAt === null || $snapshot === []) {
            return [];
        }

        $installed = [];
        foreach (['plugin' => $snapshot['plugins']['plugins'] ?? null, 'theme' => $snapshot['themes']['items'] ?? null] as $kind => $entries) {
            foreach ((array) $entries as $entry) {
                $slug = is_array($entry) ? trim((string) ($entry['slug'] ?? '')) : '';
                $version = is_array($entry) ? trim((string) ($entry['version'] ?? '')) : '';
                if ($slug !== '' && $version !== '') {
                    $installed[$kind][$slug] = $version;
                    $installed[$kind][strtolower(explode('/', $slug, 2)[0])] ??= $version;
                }
            }
        }

        $candidates = [];
        foreach ($logs as $log) {
            $kind = match ($log->action_type) {
                ActionLog::TYPE_PLUGIN_UPDATE => 'plugin',
                ActionLog::TYPE_THEME_UPDATE => 'theme',
                default => null,
            };
            $details = is_array($log->details) ? $log->details : [];
            $slug = trim((string) ($details['slug'] ?? $log->target ?? ''));
            $after = trim((string) ($details['after_version'] ?? ''));
            $now = $kind !== null ? ($installed[$kind][$slug] ?? $installed[$kind][strtolower(explode('/', $slug, 2)[0])] ?? null) : null;

            if ($now !== null && $after !== '' && $log->ran_at !== null && $snapshotAt->greaterThan($log->ran_at)
                && version_compare($now, $after, '<')) {
                $candidates[$log->id] = [$log, $kind, $slug, $now];
            }
        }
        if ($candidates === []) {
            return [];
        }

        $earliest = collect($candidates)->min(fn (array $c) => $c[0]->ran_at);
        $pauses = PluginUpdateIgnore::query()
            ->where('site_id', $site->id)
            ->whereIn('target_slug', array_unique(array_column($candidates, 2)))
            ->get(['target_kind', 'target_slug', 'ignored_at']);
        $undoLogs = ActionLog::query()
            ->where('site_id', $site->id)
            ->whereIn('action_type', self::UNDO_LOG_TYPES)
            ->where('ran_at', '>=', $earliest)
            ->get(['action_type', 'target', 'ran_at']);

        $undone = [];
        foreach ($candidates as $id => [$log, $kind, $slug, $now]) {
            $paused = $pauses->contains(fn (PluginUpdateIgnore $p) => $p->target_kind === $kind && $p->target_slug === $slug
                && $p->ignored_at !== null && $p->ignored_at->greaterThanOrEqualTo($log->ran_at));
            $rolledBack = $undoLogs->contains(fn (ActionLog $u) => $u->ran_at !== null && $u->ran_at->greaterThanOrEqualTo($log->ran_at)
                && ($u->action_type === ActionLog::TYPE_BACKUP_RESTORE_APPLIED || $u->target === $slug));

            if ($paused || $rolledBack) {
                $undone[$id] = [
                    'type' => $kind,
                    'label' => ClientReportMail::cleanUpdateSummary((string) $log->summary, $slug),
                    'date' => $log->ran_at->copy()->setTimezone(ReportPhrases::timezone())->toDateString(),
                    'installed_version' => $now,
                ];
            }
        }

        return $undone;
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
     * The agency's own connector plugins are left out, as they are from the
     * updates list.
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
        if (is_array($plugins)) {
            $plugins = array_values(array_filter($plugins, fn ($p) => ! (is_array($p) && self::isAgencyPlugin((string) ($p['slug'] ?? '')))));
        }
        $core = $snapshot['wp_core'] ?? null;

        // Updates applied after the period (by Control, or seen changing
        // since) were waiting at its end.
        $updatedSince = ActionLog::query()
            ->where('site_id', $site->id)
            ->whereIn('action_type', [ActionLog::TYPE_PLUGIN_UPDATE, ActionLog::TYPE_THEME_UPDATE, ActionLog::TYPE_CORE_UPDATE])
            ->where('ok', true)
            ->where('ran_at', '>', $end)
            ->get(['action_type', 'target', 'summary', 'details'])
            ->reject(fn (ActionLog $log) => self::isNoOpUpdate((string) $log->summary) || self::isAgencyPluginUpdate($log))
            ->count()
            + $this->observedUpdateRows($site, $end)
                ->filter(fn (array $row) => $row['at']->greaterThan($end) && $row['to'] !== '' && $row['to'] !== $row['from'])
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
     * $start and $end bound the period's days in the report timezone, the
     * zone the outage times are shown in. A site whose uptime monitoring
     * began part-way through the period is measured from then
     * ($monitoringFrom): the days before weren't watched, so they can't
     * count as up time.
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
    protected function compileUptime(Site $site, Carbon $start, Carbon $end, ?Carbon $monitoringFrom = null): array
    {
        $from = $monitoringFrom !== null ? $monitoringFrom->copy()->max($start) : $start->copy();
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
        $outageRow = fn (array $i) => [
            'started_at' => $i['from']->toIso8601String(),
            'minutes' => (int) round($seconds($i) / 60),
            'started_before_period' => $i['carried_in'],
            'ongoing' => $i['ongoing'],
        ];
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
            'monitored' => $inProbe && $checkedRecently && ($monitoringFrom === null || $monitoringFrom->lessThan($periodEnd)),
            // On, in the probe, but not checked lately: a person should look.
            'probe_stopped' => $inProbe && ! $checkedRecently,
            'last_checked_at' => $lastChecked?->toDateString(),
            // When uptime monitoring began, if that was after the period's first day.
            'monitored_since' => $monitoringFrom?->toIso8601String(),
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
            'outages' => array_slice(array_reverse(array_map($outageRow, $outageIntervals)), 0, self::UPTIME_OUTAGE_LIST_MAX),
            // Internal: every outage of ReportReviewFlags::LONG_OUTAGE_MINUTES
            // or more, oldest first and uncapped, so one that dropped off the
            // client's list still holds the report for a person to explain.
            'long_outages' => array_values(array_filter(
                array_map($outageRow, $outageIntervals),
                fn (array $o) => $o['minutes'] >= ReportReviewFlags::LONG_OUTAGE_MINUTES,
            )),
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
        // Each Companion scan read with the scanner's own-source exemption
        // applied again, so a scan stored before the scanner learned to skip
        // its own signature list doesn't count as having found malware.
        $normalize = CompanionMalwareScanner::findingPathNormalizer($site);
        $effectiveStatus = fn (SiteSecurityScan $scan): ?string => self::statusWithoutOwnScanner($scan, $normalize)['status'];
        $malwareDetails = self::malwareDetails($malwareScan, $normalize);
        $issueScans = $companionScans->filter(fn (SiteSecurityScan $scan) => $effectiveStatus($scan) === SiteSecurityScan::STATUS_ISSUES_FOUND);
        $blacklistScan = $scans->firstWhere('scan_type', SiteSecurityScan::TYPE_BLACKLIST);

        // The plugin list is the site's current Companion snapshot, so this is
        // the state on the snapshot date, not a history over the period.
        // An empty or stale vulnerability feed matches nothing, which would
        // read as "None found" — so a feed that hasn't refreshed recently
        // counts as not checked.
        $snapshotPlugins = $site->companion_snapshot['plugins']['plugins'] ?? null;
        $vulnerabilitiesChecked = is_array($snapshotPlugins) && $this->vulnerabilityFeedIsFresh();
        $vulnerabilities = $vulnerabilitiesChecked ? $this->vulnerabilities->forSite($site) : [];

        // Bans lifted as false positives (a rule that misfired, or a ban
        // undone within the hour) blocked nobody malicious.
        $blockedIpsCount = BlockedIp::query()
            ->where('site_id', $site->id)
            ->whereBetween('banned_at', [$start, $end])
            ->get(['banned_at', 'unbanned_at', 'decided_by'])
            ->reject(fn (BlockedIp $ban) => self::liftedAsFalsePositive($ban))
            ->count();
        // Lockouts from the period that never became a ban: waiting for
        // review, or failed (no server to ban at, fail2ban not provisioned).
        // The ban count leaves those visitors out, so it can't be shown.
        $unbannedLockouts = ReviewQueueEntry::query()
            ->where('site_id', $site->id)
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('status', self::UNBANNED_LOCKOUT_STATUSES)
            ->count();
        // A count is a monthly total only when lockouts reached us all
        // month: bans in the period prove some did, not that all did. The
        // site must report its lockouts now (a Companion that stopped
        // advertising its blocker mid-month doesn't), and lockouts must
        // still have been arriving near the period end.
        $lastIngested = $this->lastIngestedLockout($end);
        $blocksUnmeasured = match (true) {
            $unbannedLockouts > 0 => 'unbanned_lockouts',
            ! $this->lockoutsIngested($site, $start, $end) => 'no_lockout_feed',
            $lastIngested !== null && $lastIngested->lessThan($end->copy()->subHours(self::LOCKOUT_INGEST_MAX_GAP_HOURS)) => 'ingestion_stopped',
            default => null,
        };
        $blocksMeasured = $blocksUnmeasured === null;

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
            // us and were all turned into bans; otherwise 0 would read as "no
            // attacks" (or a low count as all of them).
            'blocked_threats_count' => $blocksMeasured ? $blockedIpsCount : null,
            // Internal: lockouts in the period that never became a ban.
            'unbanned_lockouts' => $unbannedLockouts,
            // Internal: why the count is hidden (unbanned_lockouts,
            // no_lockout_feed, ingestion_stopped), or null when it's shown,
            // and when any site's lockout last reached us by the period end.
            // For the operator note (ReportPhrases::blockedVisitorsNote).
            'blocks_unmeasured' => $blocksUnmeasured,
            'lockouts_last_ingested_at' => $lastIngested?->toIso8601String(),
            // "At the server" only where a fail2ban jail can ban at the server.
            'blocks_at_server' => $blocksMeasured && $this->hasProvisionedJail($site),
            // Whether any transport can run the core-file check on this host.
            // When none can, "Not checked this month" would be true every
            // month, so the row is left out rather than shown.
            'checksum_supported' => $isWordPress && $this->hasChecksumTransport($site),
            'checksum_status' => $checksumScan?->status,
            'checksum_checked_at' => $checksumScan?->scanned_at?->toDateString(),
            'malware_status' => $this->malwareStatus($malwareScan, $malwareDetails, $malwareScan !== null ? $effectiveStatus($malwareScan) : null),
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
            // The SSH checks that didn't finish (timed out or cut off), and
            // whether every check proved it finished; null when not recorded.
            'malware_incomplete_checks' => $malwareDetails !== null && is_array($malwareDetails['incomplete_checks'] ?? null)
                ? array_values(array_filter($malwareDetails['incomplete_checks'], 'is_string'))
                : null,
            'malware_ssh_checks_verified' => $malwareDetails !== null && array_key_exists('ssh_checks_verified', $malwareDetails)
                ? (bool) $malwareDetails['ssh_checks_verified']
                : null,
            // The worst any Companion scan in the period found, and when one
            // last found malware, so a month that started infected doesn't
            // go out as clean on the strength of its last scan.
            'malware_worst_in_period' => match (true) {
                $issueScans->isNotEmpty() => SiteSecurityScan::STATUS_ISSUES_FOUND,
                $companionScans->contains(fn (SiteSecurityScan $scan) => $effectiveStatus($scan) === SiteSecurityScan::STATUS_WARNING) => SiteSecurityScan::STATUS_WARNING,
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
     * Findings in the scanner's own source are left out, as the scanner now
     * does when it records a scan.
     *
     * @param  ?\Closure(string): string  $normalize
     * @return ?array<string, mixed>
     */
    protected static function malwareDetails(?SiteSecurityScan $scan, ?\Closure $normalize = null): ?array
    {
        if ($scan === null || $scan->scan_type !== SiteSecurityScan::TYPE_COMPANION_MALWARE) {
            return null;
        }

        $details = $scan->details;
        if (! is_array($details) || ! is_array($details['findings'] ?? null)) {
            return null;
        }

        $details['findings'] = self::statusWithoutOwnScanner($scan, $normalize ?? CompanionMalwareScanner::findingPathNormalizer())['findings'];

        return $details;
    }

    /**
     * A Companion scan's status and findings with the scanner's own source
     * left out (CompanionMalwareScanner::isOwnScannerSource). Scans recorded
     * before that exemption worked flagged the scanner's signature list as
     * malware; this only re-applies the exemption the scanner makes today.
     * A scan whose stored findings are all kept (or that has none recorded,
     * like factory or SiteCheck rows) keeps its stored status.
     *
     * @param  \Closure(string): string  $normalize
     * @return array{status: ?string, findings: ?array<int, mixed>}
     */
    protected static function statusWithoutOwnScanner(SiteSecurityScan $scan, \Closure $normalize): array
    {
        $findings = is_array($scan->details) && is_array($scan->details['findings'] ?? null) ? $scan->details['findings'] : null;
        if ($scan->scan_type !== SiteSecurityScan::TYPE_COMPANION_MALWARE || $findings === null || $findings === []) {
            return ['status' => $scan->status, 'findings' => $findings];
        }

        $kept = array_values(array_filter($findings, fn ($f) => ! (is_array($f) && is_string($f['path'] ?? null)
            && CompanionMalwareScanner::isOwnScannerSource($normalize($f['path'])))));

        return count($kept) === count($findings)
            ? ['status' => $scan->status, 'findings' => $findings]
            : ['status' => CompanionMalwareScanner::statusForFindings($kept), 'findings' => $kept];
    }

    /**
     * The scan's status, except that a clean result from a scan that didn't
     * cover the whole site is "incomplete", never "clean": Companion stopped
     * at its file or time limit, or an SSH check hit its result cap
     * (scan_aborted, either way). A partial scan that found something still
     * counts as flagged.
     *
     * An SSH scan never knows how many files it read (scanned_files_count is
     * always 0), so that count says nothing. Its checks can also run out of
     * time, or stop at an odd file name, and still come back looking clean.
     * Since the scanner makes every check prove it finished, it stores
     * ssh_checks_verified on SSH scans; only a clean SSH scan with that set
     * is complete. Older SSH rows (with or without truncated_checks) can't
     * vouch for the whole site: those stay "incomplete". That's the safe
     * reading for a report, and it only touches scans recorded before the
     * change.
     *
     * @param  ?array<string, mixed>  $details
     */
    protected function malwareStatus(?SiteSecurityScan $scan, ?array $details, ?string $status = null): ?string
    {
        $status ??= $scan?->status;
        if ($status === SiteSecurityScan::STATUS_CLEAN && $details !== null) {
            $partial = ! empty($details['scan_aborted'])
                || (($details['transport'] ?? null) === 'ssh' && empty($details['ssh_checks_verified']));

            if ($partial) {
                return self::MALWARE_INCOMPLETE;
            }
        }

        return $status;
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
     * Evidence that lockouts from this site were turned into bans through the
     * whole period, so a count of 0 bans is a measurement: it's in the
     * lockout pull (a blocker reports its lockouts), sits on a server with a
     * provisioned fail2ban jail, and that server's lockouts were pulled in
     * the period's last day or after, so a pull that stopped mid-month can't
     * vouch for the rest of it. A capability string alone proves nothing:
     * the blocker can be switched off, or the site left out of every pull.
     * Review-queue rows are no evidence either: they are the lockouts that
     * did not become bans (see UNBANNED_LOCKOUT_STATUSES).
     */
    protected function lockoutsIngested(Site $site, Carbon $start, Carbon $end): bool
    {
        $pulledAt = $site->server?->last_llar_pull_at;

        return (bool) $site->is_wordpress
            && $this->reportsBlocks($site)
            && $this->hasProvisionedJail($site)
            && $pulledAt !== null
            && $pulledAt->greaterThanOrEqualTo($end->copy()->subDay());
    }

    /**
     * A ban that blocked nobody malicious: reversed as a false positive
     * (decided_by false_positive_*), or lifted within
     * BAN_FALSE_POSITIVE_MINUTES of being made.
     */
    protected static function liftedAsFalsePositive(BlockedIp $ban): bool
    {
        if (str_starts_with((string) $ban->decided_by, 'false_positive')) {
            return true;
        }

        return $ban->banned_at !== null && $ban->unbanned_at !== null
            && $ban->unbanned_at->lessThanOrEqualTo($ban->banned_at->copy()->addMinutes(self::BAN_FALSE_POSITIVE_MINUTES));
    }

    /**
     * When a lockout from any site last reached Control (a ban from a
     * lockout source) by $end, or null when none ever has. Lockouts arrive
     * from across the fleet every day, so none for days means the feed
     * stopped, even while the pull itself keeps running and stamping
     * last_llar_pull_at.
     */
    protected function lastIngestedLockout(Carbon $end): ?Carbon
    {
        $key = $end->toIso8601String();
        if (! array_key_exists($key, $this->lastIngestedLockout)) {
            $this->lastIngestedLockout[$key] = BlockedIp::query()
                ->whereIn('source', self::LOCKOUT_SOURCES)
                ->where('banned_at', '<=', $end)
                ->orderByDesc('banned_at')
                ->first(['banned_at'])
                ?->banned_at
                ?->copy();
        }

        return $this->lastIngestedLockout[$key];
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
    protected function compileBackups(Site $site, Carbon $start, Carbon $end, ?Carbon $joinedAt = null): array
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

        // Our relay's S3 listing is the whole history (90-day retention), so
        // its oldest archive is when archiving actually began, not where our
        // records start. A relay site that was there from the period start
        // (or from when it joined) had no off-site backup on the days before
        // that: a gap for a person to see, not "records start here".
        // Enrolment turns the relay on, so when the site joined stands in for
        // when protection was expected; if the relay was switched on later,
        // the report is held for nothing worse than a look.
        $archivesBegan = null;
        $expectedFrom = null;
        if ($source === SiteBackup::SOURCE_S3_ARCHIVE && $site->backup_relay_enabled && $recordsFrom !== null) {
            $expectedFrom = ($joinedAt ?? $start)->copy()->startOfDay()->max($start);
            $first = Carbon::parse($recordsFrom);
            if ($first->greaterThanOrEqualTo($expectedFrom->copy()->addDays(2)) && $first->lessThanOrEqualTo($end)) {
                $archivesBegan = $first->toDateString();
            }
        }

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
            // Internal: when a relay site's off-site archives first began,
            // inside the period, and when they should have (the period start,
            // or when the site joined); null when there's no such gap.
            'archives_began' => $archivesBegan,
            'expected_from' => $archivesBegan !== null ? $expectedFrom?->toDateString() : null,
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
