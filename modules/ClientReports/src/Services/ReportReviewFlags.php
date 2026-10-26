<?php

namespace Modules\ClientReports\Services;

/**
 * Things in a compiled report that a person should see before the client
 * does. An auto schedule whose report has any of these is held as a draft
 * instead of being emailed; a clean report still sends itself.
 *
 * Plugin vulnerabilities are deliberately not here: about a third of sites
 * have one at any time, and holding every one of those reports would turn
 * "review the exceptions" back into "review everything".
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

        if (in_array($security['malware_status'] ?? null, self::BAD_SCAN_STATUSES, true)) {
            $reasons[] = 'The malware scan flagged something.';
        }
        if (in_array($security['checksum_status'] ?? null, self::BAD_SCAN_STATUSES, true)) {
            $reasons[] = 'WordPress core files differ from the official release.';
        }
        if (in_array($security['blacklist_status'] ?? null, self::BAD_SCAN_STATUSES, true)) {
            $reasons[] = 'The site is on a spam or malware blocklist.';
        }

        // A known hole with a fix waiting means an update isn't going through.
        $vulnerableAndWaiting = array_values(array_intersect(
            (array) ($security['vulnerable_plugins'] ?? []),
            (array) ($data['updates']['pending_plugins'] ?? []),
        ));
        if ($vulnerableAndWaiting !== []) {
            $reasons[] = 'A plugin with a known vulnerability has an update waiting: '.implode(', ', array_unique($vulnerableAndWaiting)).'.';
        }

        $backupStatus = $data['backups']['status'] ?? null;
        if (isset($data['backups']) && in_array($backupStatus, ['none_found', 'stale', 'not_checked'], true)) {
            $reasons[] = match ($backupStatus) {
                'none_found' => 'No backups were found this month.',
                'stale' => 'Backups stopped before the last 10 days of the month.',
                default => 'Backups could not be checked.',
            };
        }

        if (! empty($data['uptime']['probe_stopped'])) {
            $since = $data['uptime']['last_checked_at'] ?? null;
            $reasons[] = 'Uptime monitoring is on, but the site '.($since ? "hasn't been checked since {$since}" : 'has never been checked').', so the Uptime section was left out.';
        }

        if (! empty($data['updates']['snapshot_stale'])) {
            $since = $data['updates']['snapshot_at'] ?? null;
            $reasons[] = 'Companion '.($since ? "hasn't reported since {$since}" : 'has no snapshot date').', so waiting updates and plugin vulnerabilities are out of date.';
        }

        foreach ((array) ($data['uptime']['outages'] ?? []) as $outage) {
            if (! empty($outage['ongoing'])) {
                $reasons[] = 'The site was still down at the end of the month.';
                break;
            }
        }

        return $reasons;
    }
}
