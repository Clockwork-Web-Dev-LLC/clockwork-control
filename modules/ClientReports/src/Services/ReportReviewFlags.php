<?php

namespace Modules\ClientReports\Services;

use App\Models\SiteBackup;
use App\Models\SiteSecurityScan;
use App\Services\Security\CompanionMalwareScanner;
use Illuminate\Support\Carbon;
use Modules\ClientReports\Support\ReportPhrases;

/**
 * Things in a compiled report that a person should see before the client
 * does. An auto schedule whose report has any of these is held as a draft
 * instead of being emailed; a clean report still sends itself.
 *
 * A plugin vulnerability on its own doesn't hold a report: about a third of
 * sites have one at any time, and holding every one of those reports would
 * turn "review the exceptions" back into "review everything". One that an
 * update waiting at the end of the month would have fixed, or whose updates
 * are paused, means the updates aren't going through, so that does.
 *
 * These are plain functions of the stored sections_data, so anything they
 * need (a plugin's pause, a scan's finding kinds) is captured at compile time.
 */
class ReportReviewFlags
{
    private const BAD_SCAN_STATUSES = ['warning', 'issues_found'];

    /** An outage at least this long holds the report so a person can add context. */
    public const LONG_OUTAGE_MINUTES = 60;

    /**
     * @param  array<string, mixed>  $data  a report's sections_data
     * @return list<string>
     */
    public static function reasons(array $data): array
    {
        $reasons = [];
        $security = (array) ($data['security'] ?? []);

        // A site enrolled part-way through the month, or whose care plan was
        // switched on then, was only looked after from then.
        $coverageStart = ReportPhrases::monitoringBeganOn($data['meta']['coverage_start'] ?? null);
        if ($coverageStart !== null) {
            $began = ($data['meta']['coverage_source'] ?? null) === 'care_plan'
                ? "The care plan was switched on {$coverageStart}"
                : "Monitoring began {$coverageStart}";
            $reasons[] = ReportPhrases::beganAfterPeriod($data['meta']['coverage_start'], $data)
                ? "{$began}, after the end of this report's period, so it covers none of it."
                : "{$began}, so this report covers only part of the month.";
        }
        // Uptime monitoring switched on later than that (or for a site added
        // long before): only the uptime figures are partial.
        $uptimeSince = $data['uptime']['monitored_since'] ?? null;
        if (ReportPhrases::laterDay($uptimeSince, $data['meta']['coverage_start'] ?? null)) {
            $began = ReportPhrases::monitoringBeganOn($uptimeSince);
            $reasons[] = ReportPhrases::monitoringBeganAfterPeriod($data)
                ? "Uptime monitoring began {$began}, after this period, so the Uptime section was left out."
                : "Uptime monitoring began {$began}, so uptime covers only part of the month.";
        }

        array_push($reasons, ...self::malwareReasons($security));
        if (in_array($security['checksum_status'] ?? null, self::BAD_SCAN_STATUSES, true)) {
            $reasons[] = 'WordPress core files differ from the official release.';
        }
        // A host we can check, but no check ran: the client would read "Not
        // checked this month". Hosts with no way to check leave the row out.
        if (($security['checksum_supported'] ?? false) === true && array_key_exists('checksum_status', $security) && $security['checksum_status'] === null) {
            $reasons[] = 'No WordPress core-file check ran this month, although this host supports one, so the client would read "Not checked this month".';
        }
        if (in_array($security['blacklist_status'] ?? null, self::BAD_SCAN_STATUSES, true)) {
            $reasons[] = 'The site is on a spam or malware blocklist.';
        }

        // Lockouts that never became a ban (security.unbanned_lockouts) hide
        // the blocked-visitors count, and the operator preview says why. They
        // don't hold the report: with the count hidden the email is accurate,
        // and a ban backlog is an ops problem that would otherwise hold the
        // same sites every month.

        // A known hole with a fix waiting means an update isn't going through.
        array_push($reasons, ...self::vulnerablePluginReasons($security, (array) ($data['updates'] ?? [])));

        if (isset($data['backups'])) {
            array_push($reasons, ...self::backupReasons((array) $data['backups']));
        }

        // Missing days, a proxy's view or a feed with nothing in it: hidden
        // from the client, so say why. A host we don't collect traffic from
        // has nothing to check.
        $trafficProblem = isset($data['traffic']) && in_array($data['traffic']['status'] ?? null, ['partial', 'suspect', 'no_data'], true)
            ? ReportPhrases::trafficProblem((array) $data['traffic'])
            : null;
        if ($trafficProblem !== null) {
            $reasons[] = ucfirst($trafficProblem).', so the Traffic section was left out.';
        }

        // The client would read "accepted 18 of 19" or "reported sending ... 75%" with no
        // explanation, so a person should look at the failed tests first.
        $forms = (array) ($data['forms'] ?? []);
        $labTests = (int) ($forms['lab_tests'] ?? 0);
        $labAccepted = (int) ($forms['lab_accepted'] ?? 0);
        if ($labTests > $labAccepted) {
            $reasons[] = "Contact-form checks failed: the forms accepted {$labAccepted} of {$labTests} automated check submissions this month.";
        }
        $liveTests = (int) ($forms['live_tests'] ?? 0);
        if ($liveTests > 0 && (float) ($forms['pass_rate'] ?? 0) < 100) {
            $reasons[] = 'Not every live contact-form test reported its email as sent: '.(float) ($forms['pass_rate'] ?? 0)."% of {$liveTests} test submissions did.";
        }

        if (! empty($data['uptime']['probe_stopped'])) {
            $since = $data['uptime']['last_checked_at'] ?? null;
            $reasons[] = 'Uptime monitoring is on, but the site '.($since ? "hasn't been checked since {$since}" : 'has never been checked').', so the Uptime section was left out.';
        }

        if (! empty($data['updates']['snapshot_stale'])) {
            $since = $data['updates']['snapshot_at'] ?? null;
            $reasons[] = 'Companion '.($since ? "hasn't reported since {$since}" : 'has no snapshot date').', so waiting updates and plugin vulnerabilities are out of date.';
        }

        // The client would read "No updates were applied this month" with no
        // explanation; something was waiting, so a person should see why
        // (failing, paused, or a site-wide pause) first.
        $noUpdatesReason = self::noUpdatesAppliedReason((array) ($data['updates'] ?? []));
        if ($noUpdatesReason !== null) {
            $reasons[] = $noUpdatesReason;
        }

        // An update that ran and was then undone (the site is back on the
        // older version) isn't listed; a person should know why, and whether
        // the client saw anything of it.
        $notKeptReason = self::notKeptReason((array) ($data['updates'] ?? []));
        if ($notKeptReason !== null) {
            $reasons[] = $notKeptReason;
        }

        $stillDown = false;
        foreach ((array) ($data['uptime']['outages'] ?? []) as $outage) {
            if (! empty($outage['ongoing'])) {
                $reasons[] = 'The site was still down at the end of the month.';
                $stillDown = true;
                break;
            }
        }

        // A long outage that ended reads to the client as a bare figure; a
        // person should say what happened first (one still open has its
        // own reason above).
        $longOutages = self::longOutagesReason((array) ($data['uptime'] ?? []));
        if ($longOutages !== null) {
            $reasons[] = $longOutages;
        }

        // A host rate-limiting the monitor (HTTP 429) isn't an outage, but
        // nobody could see the site, so a person should check before it goes out.
        $blockedSince = $data['uptime']['probe_blocked_since'] ?? null;
        if (! $stillDown && is_string($blockedSince) && $blockedSince !== '') {
            $reasons[] = 'The uptime monitor was blocked (HTTP 429) from '.ReportPhrases::time($blockedSince).'; the site was not necessarily down.';
        }

        return $reasons;
    }

    /**
     * Backups the client won't see (none, stopped, unchecked, implausibly
     * small, or our relay failing, with its error), and a daily source that
     * backed up on far fewer days than the period had, which the client
     * would read as "backed up on 12 days".
     *
     * @param  array<string, mixed>  $backups
     * @return list<string>
     */
    private static function backupReasons(array $backups): array
    {
        $status = $backups['status'] ?? null;
        $relayFailing = self::relayFailing($backups);

        $reason = match ($status) {
            'none_found' => 'No backups were found this month.',
            'stale' => 'Backups stopped before the last 10 days of the month.'.($relayFailing !== null ? ' '.$relayFailing : ''),
            'failing' => $relayFailing ?? 'Off-site backups were failing at the end of the month.',
            'not_checked' => 'Backups could not be checked.',
            'suspect' => is_numeric($backups['db_backup_bytes'] ?? null) && is_numeric($backups['db_size_bytes'] ?? null)
                ? 'Database backups are '.ReportPhrases::bytes((int) $backups['db_backup_bytes'])
                    .' but the database is '.ReportPhrases::bytes((int) $backups['db_size_bytes'])
                    .', so they look empty or broken and the Backups section was left out.'
                : 'Database backups are far smaller than the database, so they look empty or broken and the Backups section was left out.',
            default => null,
        };
        if ($reason !== null) {
            return [$reason];
        }

        $days = (int) ($backups['days_with_backups'] ?? 0);
        // The days backups were expected (from when the site's coverage
        // began); reports compiled before that was kept use the period's.
        $periodDays = (int) ($backups['expected_days'] ?? $backups['period_days'] ?? 0);

        // Our relay's archives began part-way through the month for a site
        // that was there before: the days before had no off-site backup.
        $began = is_string($backups['archives_began'] ?? null) ? $backups['archives_began'] : null;
        if ($status === 'verified' && ($backups['source'] ?? null) === SiteBackup::SOURCE_S3_ARCHIVE && $began !== null) {
            $beganOn = Carbon::parse($began)->format('M j, Y');

            return ["Off-site backups began on {$beganOn}; none were made before that this month, although the site was enrolled earlier (backed up on {$days} of {$periodDays} days)."];
        }

        if ($status === 'verified' && ($backups['covers_period'] ?? false) === true && ($backups['daily'] ?? false) === true
            && $periodDays > 0 && $days < $periodDays - 3) {
            return ["Backed up on only {$days} of {$periodDays} days this month."];
        }

        return [];
    }

    /**
     * Why nothing was applied although updates were waiting, or null. Counted
     * from the rows the email lists (plugins, themes, core): translations
     * count toward the total but aren't listed, so the email can say "No
     * updates were applied" while the total is above 0. Only updates known
     * to be waiting count (named in the snapshot, or applied after the
     * period): a site with no snapshot says nothing either way, a stale
     * snapshot has its own reason, and an updates section without items
     * (older or partial data) says nothing.
     *
     * @param  array<string, mixed>  $updates
     */
    private static function noUpdatesAppliedReason(array $updates): ?string
    {
        if (! array_key_exists('items', $updates) || ! empty($updates['snapshot_stale'])) {
            return null;
        }

        $applied = (int) ($updates['plugins_count'] ?? 0) + (int) ($updates['themes_count'] ?? 0) + (int) ($updates['core_count'] ?? 0);
        $waiting = array_values(array_filter(array_merge(
            (array) ($updates['pending_plugins'] ?? []),
            (array) ($updates['pending_themes'] ?? []),
            ($updates['pending_core'] ?? false) === true ? ['WordPress core'] : [],
        ), fn ($name) => is_string($name) && trim($name) !== ''));
        $knownWaiting = $waiting !== [] || (int) ($updates['updated_since_period'] ?? 0) > 0;

        if ($applied > 0 || ! $knownWaiting || ! ClientReportCompiler::updatesWereWaiting($updates)) {
            return null;
        }

        $named = array_slice($waiting, 0, 5);
        $list = $waiting === [] ? '' : ': '.implode(', ', $named).(count($waiting) > 5 ? ' and '.(count($waiting) - 5).' more' : '');

        return 'No updates were applied this month although updates were waiting'.$list
            .', so the client would read "No updates were applied this month". Check why they are failing or paused.';
    }

    /**
     * The completed outages of LONG_OUTAGE_MINUTES or more, with when each
     * began and how long it lasted, or null when there were none. Reports
     * compiled before long_outages was kept are read from their (capped)
     * outages list.
     *
     * @param  array<string, mixed>  $uptime
     */
    private static function longOutagesReason(array $uptime): ?string
    {
        $outages = array_key_exists('long_outages', $uptime) ? (array) $uptime['long_outages'] : array_reverse((array) ($uptime['outages'] ?? []));

        $items = [];
        foreach ($outages as $outage) {
            $minutes = is_array($outage) ? (int) ($outage['minutes'] ?? 0) : 0;
            if ($minutes < self::LONG_OUTAGE_MINUTES || ! empty($outage['ongoing']) || ! is_string($outage['started_at'] ?? null)) {
                continue;
            }

            $items[] = preg_replace('/^Down /', '', ReportPhrases::outageStart($outage)).' for '.ReportPhrases::duration($minutes);
        }

        return $items === []
            ? null
            : 'The site was down for an hour or more: '.implode('; ', $items).'. Add context for the client before this goes out.';
    }

    /**
     * The updates left out of the list because they were undone after they
     * ran (updates.not_kept), or null when there were none.
     *
     * @param  array<string, mixed>  $updates
     */
    private static function notKeptReason(array $updates): ?string
    {
        $items = [];
        foreach ((array) ($updates['not_kept'] ?? []) as $item) {
            if (! is_array($item) || trim((string) ($item['label'] ?? '')) === '') {
                continue;
            }

            $when = array_filter([
                is_string($item['date'] ?? null) && $item['date'] !== '' ? Carbon::parse($item['date'])->format('M j') : null,
                trim((string) ($item['installed_version'] ?? '')) !== '' ? 'the site is on '.trim((string) $item['installed_version']).' now' : null,
            ]);
            $items[] = trim((string) $item['label']).($when !== [] ? ' ('.implode('; ', $when).')' : '');
        }

        if ($items === []) {
            return null;
        }

        return (count($items) === 1
            ? 'An update was undone after it ran, so it is left out of the updates list: '
            : 'Updates were undone after they ran, so they are left out of the updates list: ')
            .implode('; ', $items).'. Add context for the client if they noticed.';
    }

    /**
     * "Off-site backups have been failing since Oct 3, 2026: <error>", from
     * the relay's failure streak the compiler kept, or null when there was
     * none by the end of the period. The error was already trimmed and
     * stripped of URL query strings at compile time; it's re-checked here
     * because this text is shown wherever the review reasons are.
     *
     * @param  array<string, mixed>  $backups
     */
    private static function relayFailing(array $backups): ?string
    {
        $since = ReportPhrases::date($backups['relay_failing_since'] ?? null);
        if ($since === null) {
            return null;
        }

        $error = ClientReportCompiler::relayErrorSummary(is_string($backups['relay_error'] ?? null) ? $backups['relay_error'] : null);
        if ($error === null) {
            return "Off-site backups have been failing since {$since}.";
        }

        return "Off-site backups have been failing since {$since}: {$error}".(preg_match('/[.!?…]$/u', $error) ? '' : '.');
    }

    /**
     * Whether finding kinds (kind => count) are all review-tier: ones the
     * scanner grades as "warning" (PHP in uploads, a recently changed
     * wp-config, the dead /e modifier), never a malware signature. The
     * scanner's own grading decides, so the two can't disagree.
     */
    public static function reviewTierKinds(mixed $kinds): bool
    {
        if (! is_array($kinds) || $kinds === []) {
            return false;
        }

        $findings = array_map(fn ($kind) => ['kind' => (string) $kind], array_keys($kinds));

        return CompanionMalwareScanner::statusForFindings($findings) === SiteSecurityScan::STATUS_WARNING;
    }

    /**
     * Whether a person has reviewed the malware findings the report shows:
     * the latest scan is a "warning" with review-tier findings only, and
     * security.malware_reviewed was recorded for that same scan and the
     * same findings. A newer scan, or different findings, needs a new look.
     *
     * @param  array<string, mixed>  $security  sections_data['security']
     */
    public static function malwareReviewed(array $security): bool
    {
        $mark = $security['malware_reviewed'] ?? null;
        $kinds = $security['malware_finding_kinds'] ?? null;

        return is_array($mark)
            && ($security['malware_status'] ?? null) === SiteSecurityScan::STATUS_WARNING
            && isset($security['malware_scan_id'], $mark['scan_id'])
            && (int) $mark['scan_id'] === (int) $security['malware_scan_id']
            && self::reviewTierKinds($kinds)
            && self::sameKinds($mark['finding_kinds'] ?? null, $kinds);
    }

    /**
     * Whether a report's malware findings can be marked reviewed: the latest
     * scan is a "warning" with review-tier findings only (never suspected
     * malware), not already marked.
     *
     * @param  array<string, mixed>  $data  a report's sections_data
     */
    public static function canMarkMalwareReviewed(array $data): bool
    {
        $security = (array) ($data['security'] ?? []);

        return ($security['malware_status'] ?? null) === SiteSecurityScan::STATUS_WARNING
            && isset($security['malware_scan_id'])
            && self::reviewTierKinds($security['malware_finding_kinds'] ?? null)
            && ! self::malwareReviewed($security);
    }

    /**
     * The report's sections_data with its malware findings marked reviewed
     * ($mark: reviewed_by, reviewed_by_id, reviewed_at, note), tied to the
     * scan and findings it shows, and the review reasons that clears taken
     * out of meta.review_reasons; the others (and the first-report reason)
     * stay.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $mark
     * @return array<string, mixed>
     */
    public static function withMalwareReviewed(array $data, array $mark): array
    {
        $before = self::reasons($data);
        $data['security']['malware_reviewed'] = [
            'scan_id' => (int) $data['security']['malware_scan_id'],
            'finding_kinds' => (array) $data['security']['malware_finding_kinds'],
            ...$mark,
        ];
        $cleared = array_diff($before, self::reasons($data));

        // A reason stored under older wording still names the findings to review.
        $data['meta']['review_reasons'] = array_values(array_filter(
            (array) ($data['meta']['review_reasons'] ?? []),
            fn ($reason) => ! in_array($reason, $cleared, true) && ! str_starts_with((string) $reason, 'The malware scan flagged files to review'),
        ));

        return $data;
    }

    /** Whether two kind => count lists are the same, in any order. */
    private static function sameKinds(mixed $a, mixed $b): bool
    {
        if (! is_array($a) || ! is_array($b)) {
            return false;
        }

        $normalize = function (array $kinds): array {
            $kinds = array_map('intval', $kinds);
            ksort($kinds);

            return $kinds;
        };

        return $normalize($a) === $normalize($b);
    }

    /**
     * The latest scan's verdict, with its finding kinds and scan id so the
     * reviewer knows what to open, and any malware an earlier scan in the
     * period found that the latest one no longer does. Review-tier findings
     * a person has marked reviewed (malwareReviewed) no longer hold it.
     *
     * @param  array<string, mixed>  $security
     * @return list<string>
     */
    private static function malwareReasons(array $security): array
    {
        $reasons = [];
        $status = $security['malware_status'] ?? null;
        $scan = isset($security['malware_scan_id']) ? ' (scan #'.(int) $security['malware_scan_id'].')' : '';
        $kinds = self::findingKinds($security['malware_finding_kinds'] ?? null);
        $stoppedEarly = ! empty($security['malware_scan_aborted']) ? ' The scan also stopped before it finished.' : '';

        if ($status === 'warning' && self::malwareReviewed($security)) {
            // A person checked these findings: nothing more to hold for.
        } elseif ($status === 'warning' && $kinds !== '') {
            $reasons[] = "The malware scan flagged files to review: {$kinds}{$scan}.{$stoppedEarly}";
        } elseif ($status === 'issues_found' && $kinds !== '') {
            $reasons[] = "The malware scan found suspected malware: {$kinds}{$scan}.{$stoppedEarly}";
        } elseif (in_array($status, self::BAD_SCAN_STATUSES, true)) {
            $reasons[] = "The malware scan flagged something{$scan}.";
        } elseif ($status === ClientReportCompiler::MALWARE_INCOMPLETE) {
            $reasons[] = self::partialScanReason($security, $scan);
        }

        if (($security['malware_worst_in_period'] ?? null) === 'issues_found' && $status !== 'issues_found') {
            $days = (int) ($security['malware_issue_days'] ?? 0);
            $last = $security['malware_last_issue_at'] ?? null;
            $when = array_filter([
                $days > 0 ? 'on '.$days.' '.($days === 1 ? 'day' : 'days') : null,
                is_string($last) && $last !== '' ? "last on {$last}" : null,
            ]);
            $reasons[] = 'An earlier malware scan this month flagged malware'.($when !== [] ? ' ('.implode(', ', $when).')' : '')
                .', but the latest scan did not: check it was cleaned up.';
        }

        return $reasons;
    }

    /** @param  array<string, mixed>  $security */
    private static function partialScanReason(array $security, string $scan): string
    {
        $outcome = ', so the client sees "Partial scan" instead of an all-clear.';

        if (($security['malware_transport'] ?? null) === 'ssh') {
            $names = fn (string $key) => array_values(array_filter((array) ($security[$key] ?? []), fn ($c) => is_string($c) && $c !== ''));
            $unfinished = $names('malware_incomplete_checks');
            if ($unfinished !== []) {
                return "The latest malware scan{$scan} ran over SSH and some checks didn't finish (".implode(', ', $unfinished).'); they timed out or were cut off, so they can\'t vouch for the files they didn\'t list'.$outcome;
            }

            // A complete SSH scan isn't partial, so one that didn't stop early
            // was recorded before SSH scans proved each check finished.
            if (empty($security['malware_scan_aborted'])) {
                return "The latest malware scan{$scan} ran over SSH before scans recorded whether every check finished; it can't vouch for the whole site{$outcome}";
            }

            $checks = $names('malware_truncated_checks');

            return "The latest malware scan{$scan} ran over SSH and hit its result limit".($checks !== [] ? ' ('.implode(', ', $checks).')' : '')
                .'; files past the limit were not checked'.$outcome;
        }

        $files = (int) ($security['malware_scanned_files'] ?? 0);
        $detail = array_filter([
            is_string($security['malware_abort_reason'] ?? null) && $security['malware_abort_reason'] !== '' ? 'limit: '.$security['malware_abort_reason'] : null,
            $files > 0 ? number_format($files).' files checked' : null,
        ]);

        return "The latest malware scan{$scan} stopped before it finished".($detail !== [] ? ' ('.implode(', ', $detail).')' : '').$outcome;
    }

    /** "php_in_uploads ×4, wp_config_recently_modified ×1", or '' when there are none. */
    private static function findingKinds(mixed $kinds): string
    {
        if (! is_array($kinds)) {
            return '';
        }

        $parts = [];
        foreach ($kinds as $kind => $count) {
            $parts[] = $kind.' ×'.(int) $count;
        }

        return implode(', ', $parts);
    }

    /**
     * Reports compiled with vulnerable_plugin_items join them to the waiting
     * updates by file slug: hold when the update waiting reaches the version
     * that fixes the plugin, or when its updates are paused. Older reports
     * only kept names, so they're matched by name.
     *
     * @param  array<string, mixed>  $security
     * @param  array<string, mixed>  $updates
     * @return list<string>
     */
    private static function vulnerablePluginReasons(array $security, array $updates): array
    {
        if (! array_key_exists('vulnerable_plugin_items', $security)) {
            $vulnerableAndWaiting = array_values(array_unique(array_intersect(
                (array) ($security['vulnerable_plugins'] ?? []),
                (array) ($updates['pending_plugins'] ?? []),
            )));

            return $vulnerableAndWaiting !== []
                ? ['A plugin with a known vulnerability has an update waiting: '.implode(', ', $vulnerableAndWaiting).'.']
                : [];
        }

        // What was waiting at the end of the period, by slug. Without that
        // list (a stale snapshot, or no updates section), use what the
        // plugin's own entry recorded as offered.
        $pending = $updates['pending_plugin_items'] ?? null;
        $offeredBySlug = null;
        if (is_array($pending)) {
            $offeredBySlug = [];
            foreach ($pending as $p) {
                if (is_array($p) && (string) ($p['slug'] ?? '') !== '') {
                    $offeredBySlug[(string) $p['slug']] = $p['new_version'] ?? null;
                }
            }
        }

        $waiting = [];
        $paused = [];
        foreach ((array) $security['vulnerable_plugin_items'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $slug = (string) ($item['slug'] ?? '');
            $label = trim(trim((string) ($item['name'] ?? '')) ?: $slug).(($item['version'] ?? '') !== '' ? ' '.$item['version'] : '');
            $fixedIn = is_string($item['fixed_in'] ?? null) && $item['fixed_in'] !== '' ? $item['fixed_in'] : null;
            // No patched version named, but the affected range is closed:
            // any version after its upper bound is clear.
            $fixedAfter = $fixedIn === null && is_string($item['fixed_after'] ?? null) && $item['fixed_after'] !== '' ? $item['fixed_after'] : null;
            $offered = match (true) {
                $offeredBySlug === null => $item['update_offered'] ?? null,
                array_key_exists($slug, $offeredBySlug) => $offeredBySlug[$slug] ?? $item['update_offered'] ?? null,
                default => null,
            };
            $fix = match (true) {
                $fixedIn !== null => "fixed in {$fixedIn}",
                $fixedAfter !== null => "fixed in the next version after {$fixedAfter}",
                default => 'no fixed version yet',
            };
            $offeredFixes = $offered && (
                ($fixedIn !== null && version_compare((string) $offered, $fixedIn, '>='))
                || ($fixedAfter !== null && version_compare((string) $offered, $fixedAfter, '>'))
            );

            if (is_array($item['paused'] ?? null)) {
                $paused[] = "{$label} ({$fix}; ".self::pause($item['paused']).($offered ? "; {$offered} is waiting" : '').')';
            } elseif ($offeredFixes) {
                $waiting[] = "{$label} → {$offered} ({$fix})";
            }
        }

        $reasons = [];
        if ($waiting !== []) {
            $reasons[] = "A plugin with a known vulnerability has an update waiting that fixes it, but it wasn't applied: ".implode('; ', $waiting).'.';
        }
        if ($paused !== []) {
            $reasons[] = 'Updates are paused for a plugin with a known vulnerability: '.implode('; ', $paused).'.';
        }

        return $reasons;
    }

    /**
     * "auto-paused on 2026-10-30 after 3 failed attempts" or "paused by hand since 2026-09-01".
     *
     * @param  array<string, mixed>  $pause
     */
    private static function pause(array $pause): string
    {
        $since = is_string($pause['since'] ?? null) && $pause['since'] !== '' ? $pause['since'] : null;

        // The update offered is a different package that shares the slug.
        if (($pause['source'] ?? null) === 'identity_mismatch') {
            return 'update held back'.($since ? " since {$since}" : '').': the offered package appears to be a different theme';
        }

        if (($pause['source'] ?? null) === 'auto_failure') {
            $failures = (int) ($pause['failure_count'] ?? 0);

            return 'auto-paused'.($since ? " on {$since}" : '')
                .($failures > 0 ? " after {$failures} failed ".($failures === 1 ? 'attempt' : 'attempts') : '');
        }

        $note = is_string($pause['note'] ?? null) && trim($pause['note']) !== '' ? ': '.trim($pause['note']) : '';

        return 'paused by hand'.($since ? " since {$since}" : '').$note;
    }
}
