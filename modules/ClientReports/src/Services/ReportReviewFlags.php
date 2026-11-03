<?php

namespace Modules\ClientReports\Services;

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

    /**
     * @param  array<string, mixed>  $data  a report's sections_data
     * @return list<string>
     */
    public static function reasons(array $data): array
    {
        $reasons = [];
        $security = (array) ($data['security'] ?? []);

        // A site enrolled part-way through the month was only watched from then.
        $coverageStart = ReportPhrases::monitoringBeganOn($data['meta']['coverage_start'] ?? null);
        if ($coverageStart !== null) {
            $reasons[] = ReportPhrases::beganAfterPeriod($data['meta']['coverage_start'], $data)
                ? "Monitoring began {$coverageStart}, after the end of this report's period, so it covers none of it."
                : "Monitoring began {$coverageStart}, so this report covers only part of the month.";
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

        $stillDown = false;
        foreach ((array) ($data['uptime']['outages'] ?? []) as $outage) {
            if (! empty($outage['ongoing'])) {
                $reasons[] = 'The site was still down at the end of the month.';
                $stillDown = true;
                break;
            }
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
        $periodDays = (int) ($backups['period_days'] ?? 0);
        if ($status === 'verified' && ($backups['covers_period'] ?? false) === true && ($backups['daily'] ?? false) === true
            && $periodDays > 0 && $days < $periodDays - 3) {
            return ["Backed up on only {$days} of {$periodDays} days this month."];
        }

        return [];
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
     * The latest scan's verdict, with its finding kinds and scan id so the
     * reviewer knows what to open, and any malware an earlier scan in the
     * period found that the latest one no longer does.
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

        if ($status === 'warning' && $kinds !== '') {
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
            // A complete SSH scan isn't partial, so one that didn't stop early
            // was recorded before SSH scans noticed being cut short.
            if (empty($security['malware_scan_aborted'])) {
                return "The latest malware scan{$scan} ran over SSH before scans recorded whether they were cut short; it can't vouch for the whole site{$outcome}";
            }

            $checks = array_values(array_filter((array) ($security['malware_truncated_checks'] ?? []), fn ($c) => is_string($c) && $c !== ''));

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
            $offered = match (true) {
                $offeredBySlug === null => $item['update_offered'] ?? null,
                array_key_exists($slug, $offeredBySlug) => $offeredBySlug[$slug] ?? $item['update_offered'] ?? null,
                default => null,
            };
            $fix = $fixedIn !== null ? "fixed in {$fixedIn}" : 'no fixed version yet';

            if (is_array($item['paused'] ?? null)) {
                $paused[] = "{$label} ({$fix}; ".self::pause($item['paused']).($offered ? "; {$offered} is waiting" : '').')';
            } elseif ($fixedIn !== null && $offered && version_compare((string) $offered, $fixedIn, '>=')) {
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

        if (($pause['source'] ?? null) === 'auto_failure') {
            $failures = (int) ($pause['failure_count'] ?? 0);

            return 'auto-paused'.($since ? " on {$since}" : '')
                .($failures > 0 ? " after {$failures} failed ".($failures === 1 ? 'attempt' : 'attempts') : '');
        }

        $note = is_string($pause['note'] ?? null) && trim($pause['note']) !== '' ? ': '.trim($pause['note']) : '';

        return 'paused by hand'.($since ? " since {$since}" : '').$note;
    }
}
