<?php

use Modules\ClientReports\Services\ReportReviewFlags;
use Modules\ClientReports\Support\ReportPhrases;

it('flags what a person should see before the client does', function () {
    expect(ReportReviewFlags::reasons([
        'security' => ['malware_status' => 'warning', 'checksum_status' => 'issues_found', 'blacklist_status' => 'issues_found'],
        'backups' => ['status' => 'stale'],
        'uptime' => ['outages' => [['ongoing' => false], ['ongoing' => true]]],
    ]))->toBe([
        'The malware scan flagged something.',
        'WordPress core files differ from the official release.',
        'The site is on a spam or malware blocklist.',
        'Backups stopped before the last 10 days of the month.',
        'The site was still down at the end of the month.',
    ]);
});

it('lets a clean report, unchecked scans and known vulnerabilities through', function () {
    expect(ReportReviewFlags::reasons([
        'security' => ['malware_status' => 'clean', 'checksum_status' => null, 'blacklist_status' => null, 'active_vulnerabilities' => 3],
        'backups' => ['status' => 'no_source'],
        'uptime' => ['outages' => [['ongoing' => false]]],
    ]))->toBe([]);
});

it('flags a vulnerable plugin whose update is still waiting', function () {
    expect(ReportReviewFlags::reasons([
        'security' => ['vulnerable_plugins' => ['WP Rocket', 'Akismet']],
        'updates' => ['pending_plugins' => ['WP Rocket', 'Hello Dolly']],
    ]))->toBe(['A plugin with a known vulnerability has an update waiting: WP Rocket.']);
});

it('flags an uptime probe that stopped and a Companion that stopped reporting', function () {
    expect(ReportReviewFlags::reasons([
        'uptime' => ['monitored' => false, 'probe_stopped' => true, 'last_checked_at' => '2026-09-12'],
        'updates' => ['pending_plugins' => null, 'snapshot_stale' => true, 'snapshot_at' => '2026-08-20'],
    ]))->toBe([
        "Uptime monitoring is on, but the site hasn't been checked since 2026-09-12, so the Uptime section was left out.",
        "Companion hasn't reported since 2026-08-20, so waiting updates and plugin vulnerabilities are out of date.",
    ]);
});

it('flags a report that covers only part of the month', function () {
    expect(ReportReviewFlags::reasons([
        'meta' => ['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'coverage_start' => '2026-10-19T16:30:00+00:00'],
        'uptime' => ['monitored_since' => '2026-10-19T16:30:00+00:00', 'outages' => []],
    ]))->toBe(['Monitoring began Oct 19, so this report covers only part of the month.']);
});

it('flags a report whose monitoring began only after its period', function () {
    expect(ReportReviewFlags::reasons([
        'meta' => ['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'coverage_start' => '2026-10-05T16:00:00+00:00'],
    ]))->toBe(["Monitoring began Oct 5, after the end of this report's period, so it covers none of it."]);
});

it('says only the uptime is partial when uptime monitoring began after the site was added', function () {
    expect(ReportReviewFlags::reasons([
        'meta' => ['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'coverage_start' => null],
        'uptime' => ['monitored_since' => '2026-10-17T14:00:00+00:00', 'outages' => []],
    ]))->toBe(['Uptime monitoring began Oct 17, so uptime covers only part of the month.'])
        ->and(ReportReviewFlags::reasons([
            'meta' => ['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'coverage_start' => '2026-10-10T12:00:00+00:00'],
            'uptime' => ['monitored_since' => '2026-10-17T14:00:00+00:00', 'outages' => []],
        ]))->toBe([
            'Monitoring began Oct 10, so this report covers only part of the month.',
            'Uptime monitoring began Oct 17, so uptime covers only part of the month.',
        ])
        ->and(ReportReviewFlags::reasons([
            'meta' => ['period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'coverage_start' => null],
            'uptime' => ['monitored_since' => '2026-10-05T16:00:00+00:00', 'outages' => []],
        ]))->toBe(['Uptime monitoring began Oct 5, after this period, so the Uptime section was left out.']);
});

it('says the monitor was blocked, not that the site was down, when only a 429 is still open', function () {
    $reasons = ReportReviewFlags::reasons([
        'uptime' => ['outages' => [['ongoing' => false]], 'probe_blocked_since' => '2026-10-30T14:05:00+00:00'],
    ]);

    // The time is shown in the report timezone, which needs the app config; the wording doesn't.
    expect($reasons)->toHaveCount(1)
        ->and($reasons[0])->toStartWith('The uptime monitor was blocked (HTTP 429) from Oct 30, ')
        ->toEndWith('; the site was not necessarily down.');
});

it('holds completed outages of an hour or more, read from the outages list on reports compiled before long_outages', function () {
    $long = ['started_at' => '2026-10-01T06:10:00+00:00', 'minutes' => 381, 'started_before_period' => false, 'ongoing' => false];
    $carried = ['started_at' => '2026-10-01T04:00:00+00:00', 'minutes' => 60, 'started_before_period' => true, 'ongoing' => false];
    $short = ['started_at' => '2026-10-09T10:00:00+00:00', 'minutes' => 59, 'started_before_period' => false, 'ongoing' => false];

    // The time is shown in the report timezone, which needs the app config; the wording doesn't.
    $when = fn (array $o) => preg_replace('/^Down /', '', ReportPhrases::outageStart($o));
    $expected = 'The site was down for an hour or more: '.$when($carried).' for 1h 0m; '.$when($long).' for 6h 21m. Add context for the client before this goes out.';

    expect(ReportReviewFlags::reasons(['uptime' => ['outages' => [$short, $long, $carried]]]))->toBe([$expected])
        ->and(ReportReviewFlags::reasons(['uptime' => ['outages' => [$short], 'long_outages' => [$carried, $long]]]))->toBe([$expected])
        ->and(ReportReviewFlags::reasons(['uptime' => ['outages' => [$long], 'long_outages' => []]]))->toBe([]);
});

it('still says the site was down when a real outage is open at the end of the month', function () {
    expect(ReportReviewFlags::reasons([
        'uptime' => ['outages' => [['ongoing' => true]], 'probe_blocked_since' => null],
    ]))->toBe(['The site was still down at the end of the month.']);
});

it('adds nothing for a full month or a report compiled before coverage was recorded', function () {
    expect(ReportReviewFlags::reasons([
        'meta' => ['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'coverage_start' => null],
        'uptime' => ['monitored_since' => null, 'probe_blocked_minutes' => 30, 'probe_blocked_since' => null, 'outages' => []],
    ]))->toBe([])
        ->and(ReportReviewFlags::reasons(['meta' => ['period_end' => '2026-10-31'], 'uptime' => ['outages' => []]]))->toBe([]);
});

describe('malware', function () {
    it('names the finding kinds and the scan, and says when a flagged scan stopped early', function () {
        expect(ReportReviewFlags::reasons(['security' => [
            'malware_status' => 'warning', 'malware_scan_id' => 123, 'malware_finding_kinds' => ['php_in_uploads' => 4],
        ]]))->toBe(['The malware scan flagged files to review: php_in_uploads ×4 (scan #123).'])
            ->and(ReportReviewFlags::reasons(['security' => [
                'malware_status' => 'issues_found', 'malware_scan_id' => 7, 'malware_scan_aborted' => true,
                'malware_finding_kinds' => ['eval_base64' => 2, 'php_in_uploads' => 1],
            ]]))->toBe(['The malware scan found suspected malware: eval_base64 ×2, php_in_uploads ×1 (scan #7). The scan also stopped before it finished.'])
            // A SiteCheck result has no finding kinds.
            ->and(ReportReviewFlags::reasons(['security' => ['malware_status' => 'issues_found', 'malware_scan_id' => 9, 'malware_finding_kinds' => null]]))
            ->toBe(['The malware scan flagged something (scan #9).']);
    });

    it('holds a scan that did not cover the whole site', function () {
        expect(ReportReviewFlags::reasons(['security' => [
            'malware_status' => 'incomplete', 'malware_scan_id' => 5, 'malware_transport' => 'companion',
            'malware_scan_aborted' => true, 'malware_abort_reason' => 'max_wall_seconds', 'malware_scanned_files' => 1200,
        ]]))->toBe(['The latest malware scan (scan #5) stopped before it finished (limit: max_wall_seconds, 1,200 files checked), so the client sees "Partial scan" instead of an all-clear.'])
            ->and(ReportReviewFlags::reasons(['security' => [
                'malware_status' => 'incomplete', 'malware_scan_id' => 6, 'malware_transport' => 'ssh', 'malware_scan_aborted' => false,
            ]]))->toBe(['The latest malware scan (scan #6) ran over SSH before scans recorded whether every check finished; it can\'t vouch for the whole site, so the client sees "Partial scan" instead of an all-clear.'])
            ->and(ReportReviewFlags::reasons(['security' => [
                'malware_status' => 'incomplete', 'malware_scan_id' => 7, 'malware_transport' => 'ssh', 'malware_scan_aborted' => true,
                'malware_abort_reason' => 'ssh_check_incomplete', 'malware_incomplete_checks' => ['shell_wso'],
            ]]))->toBe(['The latest malware scan (scan #7) ran over SSH and some checks didn\'t finish (shell_wso); they timed out or were cut off, so they can\'t vouch for the files they didn\'t list, so the client sees "Partial scan" instead of an all-clear.'])
            ->and(ReportReviewFlags::reasons(['security' => [
                'malware_status' => 'incomplete', 'malware_scan_id' => 8, 'malware_transport' => 'ssh', 'malware_scan_aborted' => true,
                'malware_abort_reason' => 'ssh_result_limit', 'malware_truncated_checks' => ['php_in_uploads', 'shell_c99'],
            ]]))->toBe(['The latest malware scan (scan #8) ran over SSH and hit its result limit (php_in_uploads, shell_c99); files past the limit were not checked, so the client sees "Partial scan" instead of an all-clear.']);
    });

    it('lets a clean SSH scan through, whatever its file count says', function () {
        expect(ReportReviewFlags::reasons(['security' => [
            'malware_status' => 'clean', 'malware_transport' => 'ssh', 'malware_scan_aborted' => false, 'malware_scanned_files' => 0, 'malware_truncated_checks' => [],
        ]]))->toBe([]);
    });

    it('flags malware an earlier scan found when the latest scan is clean, and not twice when it still finds it', function () {
        $earlier = ['malware_worst_in_period' => 'issues_found', 'malware_last_issue_at' => '2026-10-26', 'malware_issue_days' => 1];

        expect(ReportReviewFlags::reasons(['security' => ['malware_status' => 'clean', ...$earlier]]))
            ->toBe(['An earlier malware scan this month flagged malware (on 1 day, last on 2026-10-26), but the latest scan did not: check it was cleaned up.'])
            ->and(ReportReviewFlags::reasons(['security' => ['malware_status' => 'issues_found', ...$earlier]]))
            ->toBe(['The malware scan flagged something.'])
            ->and(ReportReviewFlags::reasons(['security' => ['malware_status' => 'clean', 'malware_worst_in_period' => 'warning']]))->toBe([]);
    });
});

describe('WordPress core files', function () {
    it('flags a missing check only where the host can run one', function () {
        expect(ReportReviewFlags::reasons(['security' => ['checksum_supported' => true, 'checksum_status' => null]]))
            ->toBe(['No WordPress core-file check ran this month, although this host supports one, so the client would read "Not checked this month".'])
            ->and(ReportReviewFlags::reasons(['security' => ['checksum_supported' => true, 'checksum_status' => 'clean']]))->toBe([])
            ->and(ReportReviewFlags::reasons(['security' => ['checksum_supported' => false, 'checksum_status' => null]]))->toBe([])
            // Older reports didn't record it.
            ->and(ReportReviewFlags::reasons(['security' => ['checksum_status' => null]]))->toBe([]);
    });
});

describe('vulnerable plugins joined to waiting updates by slug', function () {
    function vulnerableItem(array $overrides = []): array
    {
        return array_replace([
            'slug' => 'example-forms/example-forms.php', 'name' => 'Example Forms', 'version' => '1.0',
            'fixed_in' => '1.2', 'update_offered' => null, 'paused' => null,
        ], $overrides);
    }

    it('holds when the update waiting reaches the fixed version', function () {
        expect(ReportReviewFlags::reasons([
            'security' => ['vulnerable_plugins' => ['Example Forms'], 'vulnerable_plugin_items' => [vulnerableItem(['update_offered' => '1.3'])]],
            'updates' => ['pending_plugins' => ['Example Forms'], 'pending_plugin_items' => [['slug' => 'example-forms/example-forms.php', 'name' => 'Example Forms', 'new_version' => '1.3']]],
        ]))->toBe(["A plugin with a known vulnerability has an update waiting that fixes it, but it wasn't applied: Example Forms 1.0 → 1.3 (fixed in 1.2)."]);
    });

    it('lets through an update that falls short of the fix, one with no fix yet, and a same-named plugin\'s update', function () {
        expect(ReportReviewFlags::reasons([
            'security' => ['vulnerable_plugin_items' => [
                vulnerableItem(['update_offered' => '1.1']),
                vulnerableItem(['slug' => 'example-gallery/example-gallery.php', 'name' => 'Example Gallery', 'fixed_in' => null, 'update_offered' => '9.0']),
                vulnerableItem(['slug' => 'example-seo/example-seo.php', 'name' => 'Example SEO']),
            ]],
            'updates' => ['pending_plugins' => ['Example Forms', 'Example Gallery', 'Example SEO'], 'pending_plugin_items' => [
                ['slug' => 'example-forms/example-forms.php', 'name' => 'Example Forms', 'new_version' => '1.1'],
                ['slug' => 'example-gallery/example-gallery.php', 'name' => 'Example Gallery', 'new_version' => '9.0'],
                // A copy in another folder, with the same name and a fixing version.
                ['slug' => 'example-seo-backup/example-seo.php', 'name' => 'Example SEO', 'new_version' => '5.0'],
            ]],
        ]))->toBe([]);
    });

    it('holds a vulnerable plugin whose updates are paused, naming the fix and why', function () {
        expect(ReportReviewFlags::reasons([
            'security' => ['vulnerable_plugin_items' => [
                vulnerableItem(['paused' => ['source' => 'auto_failure', 'since' => '2026-10-30', 'failure_count' => 9, 'note' => null]]),
                vulnerableItem(['slug' => 'example-gallery/example-gallery.php', 'name' => 'Example Gallery', 'version' => '3.0', 'fixed_in' => null,
                    'paused' => ['source' => 'manual', 'since' => '2026-09-01', 'failure_count' => null, 'note' => 'Licence expired']]),
            ]],
            'updates' => ['pending_plugin_items' => []],
        ]))->toBe(['Updates are paused for a plugin with a known vulnerability: Example Forms 1.0 (fixed in 1.2; auto-paused on 2026-10-30 after 9 failed attempts); Example Gallery 3.0 (no fixed version yet; paused by hand since 2026-09-01: Licence expired).']);
    });

    it('reads a fix only known as "after" a closed bound, and holds only an update past it', function () {
        $item = fn (string $offered) => vulnerableItem(['fixed_in' => null, 'fixed_after' => '5.2.4', 'update_offered' => $offered]);

        expect(ReportReviewFlags::reasons(['security' => ['vulnerable_plugin_items' => [$item('5.2.5')]]]))
            ->toBe(["A plugin with a known vulnerability has an update waiting that fixes it, but it wasn't applied: Example Forms 1.0 → 5.2.5 (fixed in the next version after 5.2.4)."])
            ->and(ReportReviewFlags::reasons(['security' => ['vulnerable_plugin_items' => [$item('5.2.4')]]]))->toBe([])
            ->and(ReportReviewFlags::reasons(['security' => ['vulnerable_plugin_items' => [
                vulnerableItem(['fixed_in' => null, 'fixed_after' => '5.2.4', 'paused' => ['source' => 'manual', 'since' => null, 'failure_count' => null, 'note' => null]]),
            ]]]))->toBe(['Updates are paused for a plugin with a known vulnerability: Example Forms 1.0 (fixed in the next version after 5.2.4; paused by hand).']);
    });

    it('says an update held back as a different package is held back, not paused by hand', function () {
        expect(ReportReviewFlags::reasons(['security' => ['vulnerable_plugin_items' => [
            vulnerableItem(['paused' => ['source' => 'identity_mismatch', 'since' => '2026-10-01', 'failure_count' => null, 'note' => 'Different package']]),
        ]]]))->toBe(['Updates are paused for a plugin with a known vulnerability: Example Forms 1.0 (fixed in 1.2; update held back since 2026-10-01: the offered package appears to be a different theme).']);
    });

    it('uses the version the plugin recorded as offered when there is no list of waiting updates', function () {
        expect(ReportReviewFlags::reasons([
            'security' => ['vulnerable_plugin_items' => [vulnerableItem(['update_offered' => '1.2'])]],
            'updates' => ['pending_plugins' => null, 'pending_plugin_items' => null],
        ]))->toBe(["A plugin with a known vulnerability has an update waiting that fixes it, but it wasn't applied: Example Forms 1.0 → 1.2 (fixed in 1.2)."])
            ->and(ReportReviewFlags::reasons(['security' => ['vulnerable_plugin_items' => [vulnerableItem(['update_offered' => '1.2'])]]]))
            ->toHaveCount(1);
    });
});

describe('backups', function () {
    it('holds database backups far smaller than the database, with both sizes', function () {
        expect(ReportReviewFlags::reasons(['backups' => ['status' => 'suspect', 'db_backup_bytes' => 9200, 'db_size_bytes' => 3 * 1024 ** 3]]))
            ->toBe(['Database backups are 9 KB but the database is 3.0 GB, so they look empty or broken and the Backups section was left out.'])
            ->and(ReportReviewFlags::reasons(['backups' => ['status' => 'suspect']]))
            ->toBe(['Database backups are far smaller than the database, so they look empty or broken and the Backups section was left out.']);
    });

    it('counts only the days backups were expected, from when the site\'s coverage began', function () {
        $relay = ['status' => 'verified', 'verified' => true, 'source' => 's3_archive', 'covers_period' => false, 'daily' => true,
            'days_with_backups' => 1, 'period_days' => 31, 'expected_days' => 4, 'archives_began' => '2026-10-31', 'expected_from' => '2026-10-28'];
        $daily = ['status' => 'verified', 'verified' => true, 'covers_period' => true, 'daily' => true, 'days_with_backups' => 5, 'period_days' => 31, 'expected_days' => 12];

        expect(ReportReviewFlags::reasons(['backups' => $relay]))
            ->toBe(['Off-site backups began on Oct 31, 2026; none were made before that this month, although the site was enrolled earlier (backed up on 1 of 4 days).'])
            ->and(ReportReviewFlags::reasons(['backups' => $daily]))->toBe(['Backed up on only 5 of 12 days this month.'])
            ->and(ReportReviewFlags::reasons(['backups' => ['days_with_backups' => 10] + $daily]))->toBe([]);
    });

    it('holds a daily source that backed up on far fewer days than the period had', function () {
        $backups = ['status' => 'verified', 'verified' => true, 'covers_period' => true, 'daily' => true, 'days_with_backups' => 12, 'period_days' => 31];

        expect(ReportReviewFlags::reasons(['backups' => $backups]))->toBe(['Backed up on only 12 of 31 days this month.'])
            // A few days short is normal.
            ->and(ReportReviewFlags::reasons(['backups' => ['days_with_backups' => 28] + $backups]))->toBe([])
            // A weekly schedule, records that start part-way through, and older reports say nothing.
            ->and(ReportReviewFlags::reasons(['backups' => ['daily' => false] + $backups]))->toBe([])
            ->and(ReportReviewFlags::reasons(['backups' => ['covers_period' => false] + $backups]))->toBe([])
            ->and(ReportReviewFlags::reasons(['backups' => ['status' => 'verified', 'covers_period' => true, 'days_with_backups' => 3]]))->toBe([]);
    });

    it('holds relay archives that began part-way through the month for a site enrolled before it', function () {
        $backups = ['status' => 'verified', 'verified' => true, 'source' => 's3_archive', 'covers_period' => false, 'daily' => true,
            'days_with_backups' => 3, 'period_days' => 31, 'archives_began' => '2026-10-29', 'expected_from' => '2026-10-01'];

        expect(ReportReviewFlags::reasons(['backups' => $backups]))
            ->toBe(['Off-site backups began on Oct 29, 2026; none were made before that this month, although the site was enrolled earlier (backed up on 3 of 31 days).'])
            // Without the marker (older reports, other sources) nothing changes.
            ->and(ReportReviewFlags::reasons(['backups' => ['archives_began' => null] + $backups]))->toBe([])
            ->and(ReportReviewFlags::reasons(['backups' => ['source' => 'pressable'] + $backups]))->toBe([]);
    });

    it('holds off-site backups that have been failing, with when it began and the error', function () {
        $failing = ['status' => 'failing', 'verified' => false, 'relay_failing_since' => '2026-10-03T12:00:00+00:00'];

        expect(ReportReviewFlags::reasons(['backups' => $failing + ['relay_error' => 'Upload to storage failed with HTTP 403']]))
            ->toBe(['Off-site backups have been failing since Oct 3, 2026: Upload to storage failed with HTTP 403.'])
            ->and(ReportReviewFlags::reasons(['backups' => $failing + ['relay_error' => null]]))
            ->toBe(['Off-site backups have been failing since Oct 3, 2026.'])
            ->and(ReportReviewFlags::reasons(['backups' => ['status' => 'failing']]))
            ->toBe(['Off-site backups were failing at the end of the month.'])
            // Archives that stopped part-way: the stale reason says why.
            ->and(ReportReviewFlags::reasons(['backups' => ['status' => 'stale', 'relay_failing_since' => '2026-10-03T12:00:00+00:00', 'relay_error' => 'Example timeout.']]))
            ->toBe(['Backups stopped before the last 10 days of the month. Off-site backups have been failing since Oct 3, 2026: Example timeout.']);
    });

    it('never repeats a URL query string from the relay error', function () {
        $reasons = ReportReviewFlags::reasons(['backups' => [
            'status' => 'failing', 'relay_failing_since' => '2026-10-03T12:00:00+00:00',
            'relay_error' => 'PUT s3://example-bucket/site.tar.gz?X-Amz-Signature=abc123 and https://storage.example.com/a.zip?token=def456#frag failed',
        ]]);

        expect($reasons)->toBe(['Off-site backups have been failing since Oct 3, 2026: PUT s3://example-bucket/site.tar.gz and https://storage.example.com/a.zip failed.'])
            ->and(implode(' ', $reasons))->not->toContain('abc123')->not->toContain('def456');
    });
});

describe('traffic', function () {
    it('holds traffic the client won\'t see, saying why', function (array $traffic, string $reason) {
        expect(ReportReviewFlags::reasons(['traffic' => $traffic]))->toBe([$reason]);
    })->with([
        'days missing' => [
            ['status' => 'partial', 'source' => 'nginx', 'days_covered' => 12, 'period_days' => 31, 'last_day_complete' => true],
            'Traffic was recorded on only 12 of 31 days, so the Traffic section was left out.',
        ],
        'last day unfinished' => [
            ['status' => 'partial', 'source' => 'pressable', 'days_covered' => 31, 'period_days' => 31, 'last_day_complete' => false],
            "The period's last day of traffic wasn't complete when the report was compiled, so the Traffic section was left out.",
        ],
        'a proxy in front of the site' => [
            ['status' => 'suspect', 'source' => 'nginx', 'days_covered' => 12, 'period_days' => 31, 'top_ip_share' => 0.998, 'total_visits' => 40, 'total_requests' => 1500000],
            '100% of requests came from two IP addresses, which looks like a proxy or gateway in front of the site rather than visitors; also, traffic was recorded on only 12 of 31 days, so the Traffic section was left out.',
        ],
        'a proxy seen as one address' => [
            ['status' => 'suspect', 'source' => 'nginx', 'days_covered' => 31, 'period_days' => 31, 'top_ip_share' => 1.0, 'top_ip_count' => 1, 'total_visits' => 31, 'total_requests' => 90000],
            '100% of requests came from one IP address, which looks like a proxy or gateway in front of the site rather than visitors, so the Traffic section was left out.',
        ],
        'visits that cannot be real' => [
            ['status' => 'suspect', 'source' => 'pressable', 'days_covered' => 31, 'period_days' => 31, 'top_ip_share' => 0.95, 'total_visits' => 33, 'total_requests' => 410000],
            "33 visits for 410,000 requests doesn't look like real visitors, so the Traffic section was left out.",
        ],
        'nothing from a host we collect from' => [
            ['status' => 'no_data', 'has_traffic' => false],
            "No traffic was recorded for this period, although this host's traffic is collected, so the Traffic section was left out.",
        ],
    ]);

    it('lets complete traffic, hosts we don\'t collect from and older reports through', function () {
        expect(ReportReviewFlags::reasons(['traffic' => ['status' => 'ok', 'days_covered' => 31, 'period_days' => 31]]))->toBe([])
            ->and(ReportReviewFlags::reasons(['traffic' => ['status' => 'unsupported', 'has_traffic' => false]]))->toBe([])
            ->and(ReportReviewFlags::reasons(['traffic' => ['has_traffic' => true, 'total_visits' => 10, 'total_requests' => 900000]]))->toBe([]);
    });
});

describe('contact forms', function () {
    it('holds a report whose form checks or delivery tests failed', function () {
        expect(ReportReviewFlags::reasons(['forms' => ['live_tests' => 0, 'pass_rate' => null, 'lab_tests' => 19, 'lab_accepted' => 18]]))
            ->toBe(['Contact-form checks failed: the forms accepted 18 of 19 automated check submissions this month.'])
            ->and(ReportReviewFlags::reasons(['forms' => ['live_tests' => 4, 'pass_rate' => 75.0, 'lab_tests' => 0, 'lab_accepted' => 0]]))
            ->toBe(['Not every live contact-form test reported its email as sent: 75% of 4 test submissions did.']);
    });

    it('lets passing and untested forms through', function () {
        expect(ReportReviewFlags::reasons(['forms' => ['live_tests' => 2, 'pass_rate' => 100.0, 'lab_tests' => 5, 'lab_accepted' => 5]]))->toBe([])
            ->and(ReportReviewFlags::reasons(['forms' => ['live_tests' => 0, 'pass_rate' => null, 'lab_tests' => 0, 'lab_accepted' => 0]]))->toBe([]);
    });
});

describe('nothing applied while updates were waiting', function () {
    $updates = fn (array $over = []) => ['updates' => array_replace([
        'total' => 0, 'plugins_count' => 0, 'themes_count' => 0, 'core_count' => 0, 'translations_count' => 0,
        'items' => ['plugins' => [], 'themes' => [], 'core' => []],
        'pending_plugins' => [], 'pending_themes' => [], 'pending_core' => false,
        'updated_since_period' => 0, 'snapshot_at' => '2026-10-31', 'snapshot_stale' => false,
    ], $over)];
    $reason = 'No updates were applied this month although updates were waiting';

    it('holds a report that applied nothing while plugins were waiting', function () use ($updates, $reason) {
        expect(ReportReviewFlags::reasons($updates(['pending_plugins' => ['Example Forms', 'Example SEO']])))
            ->toBe([$reason.': Example Forms, Example SEO, so the client would read "No updates were applied this month". Check why they are failing or paused.']);
    });

    it('holds a report when the only sign is an update applied after the period', function () use ($updates, $reason) {
        expect(ReportReviewFlags::reasons($updates(['updated_since_period' => 2])))
            ->toBe([$reason.', so the client would read "No updates were applied this month". Check why they are failing or paused.']);
    });

    it('names at most five and counts the rest', function () use ($updates) {
        $names = array_map(fn ($i) => "Example {$i}", range(1, 7));

        expect(ReportReviewFlags::reasons($updates(['pending_plugins' => $names]))[0])->toContain('Example 5 and 2 more,');
    });

    it('stays quiet when an update was applied, nothing was waiting, the snapshot is stale or the section is older', function () use ($updates, $reason) {
        // A translations run counts toward the total but isn't a listed row, so it doesn't count as applied.
        expect(ReportReviewFlags::reasons($updates(['pending_plugins' => ['Example Forms'], 'total' => 1, 'translations_count' => 1])))->toHaveCount(1)
            ->and(ReportReviewFlags::reasons($updates(['pending_plugins' => ['Example Forms'], 'total' => 1, 'plugins_count' => 1])))->toBe([])
            ->and(ReportReviewFlags::reasons($updates()))->toBe([])
            ->and(ReportReviewFlags::reasons($updates(['pending_plugins' => null, 'pending_themes' => null, 'pending_core' => null])))->toBe([])
            ->and(implode(' ', ReportReviewFlags::reasons($updates(['pending_plugins' => null, 'snapshot_stale' => true]))))->not->toContain($reason)
            ->and(ReportReviewFlags::reasons(['updates' => ['total' => 0, 'pending_plugins' => ['Example Forms']]]))->toBe([]);
    });
});

describe('malware findings marked reviewed', function () {
    function reviewedSecurity(array $overrides = [], array $mark = []): array
    {
        return array_replace([
            'malware_status' => 'warning', 'malware_scan_id' => 41, 'malware_finding_kinds' => ['php_in_uploads' => 2, 'wp_config_recently_modified' => 1],
            'malware_reviewed' => array_replace(['scan_id' => 41, 'finding_kinds' => ['wp_config_recently_modified' => 1, 'php_in_uploads' => 2],
                'reviewed_by' => 'Example Operator', 'reviewed_at' => '2026-11-03T10:00:00+00:00', 'note' => 'Checked'], $mark),
        ], $overrides);
    }

    it('reads the mark only for the same scan and the same findings, in any order', function () {
        expect(ReportReviewFlags::malwareReviewed(reviewedSecurity()))->toBeTrue()
            ->and(ReportReviewFlags::malwareReviewed(reviewedSecurity(mark: ['scan_id' => 40])))->toBeFalse()
            ->and(ReportReviewFlags::malwareReviewed(reviewedSecurity(mark: ['finding_kinds' => ['php_in_uploads' => 2]])))->toBeFalse()
            ->and(ReportReviewFlags::malwareReviewed(reviewedSecurity(['malware_finding_kinds' => ['php_in_uploads' => 3, 'wp_config_recently_modified' => 1]])))->toBeFalse()
            ->and(ReportReviewFlags::malwareReviewed(reviewedSecurity(['malware_reviewed' => null])))->toBeFalse();
    });

    it('never applies to suspected malware, even with a matching mark', function () {
        $kinds = ['php_in_uploads' => 1, 'eval_base64' => 1];
        $security = reviewedSecurity(['malware_status' => 'issues_found', 'malware_finding_kinds' => $kinds], ['finding_kinds' => $kinds]);

        expect(ReportReviewFlags::malwareReviewed($security))->toBeFalse()
            ->and(ReportReviewFlags::malwareReviewed(['malware_status' => 'warning'] + $security))->toBeFalse()
            ->and(ReportReviewFlags::canMarkMalwareReviewed(['security' => $security]))->toBeFalse()
            ->and(ReportReviewFlags::reasons(['security' => $security]))->toBe(['The malware scan found suspected malware: php_in_uploads ×1, eval_base64 ×1 (scan #41).']);
    });

    it('drops the malware reason once reviewed, keeping the others', function () {
        $data = ['security' => reviewedSecurity(['malware_reviewed' => null]), 'backups' => ['status' => 'stale']];
        $data['meta']['review_reasons'] = ['First report.', ...ReportReviewFlags::reasons($data)];

        $marked = ReportReviewFlags::withMalwareReviewed($data, ['reviewed_by' => 'Example Operator', 'note' => 'Checked']);

        expect(ReportReviewFlags::canMarkMalwareReviewed($data))->toBeTrue()
            ->and($data['meta']['review_reasons'])->toHaveCount(3)
            ->and($marked['meta']['review_reasons'])->toBe(['First report.', 'Backups stopped before the last 10 days of the month.'])
            ->and($marked['security']['malware_reviewed'])->toMatchArray(['scan_id' => 41, 'reviewed_by' => 'Example Operator'])
            ->and(ReportReviewFlags::canMarkMalwareReviewed($marked))->toBeFalse();
    });
});

describe('rows the client would read as "Not checked"', function () {
    it('holds a missing malware or blocklist result only when that scan was switched on', function () {
        $missing = ['malware_status' => null, 'blacklist_status' => null];

        expect(ReportReviewFlags::reasons(['security' => [...$missing, 'malware_expected' => true, 'blacklist_expected' => true]]))->toBe([
            'No malware scan finished this month, so the client would read "Not checked this month".',
            'No blocklist check finished this month, so the client would read "Not checked this month".',
        ])
            ->and(ReportReviewFlags::reasons(['security' => [...$missing, 'malware_expected' => false, 'blacklist_expected' => false]]))->toBe([])
            // Reports compiled before these keys were kept say nothing.
            ->and(ReportReviewFlags::reasons(['security' => $missing]))->toBe([]);
    });

    it('holds a long rate-limited stretch that counts as up, but not a short one', function () {
        expect(ReportReviewFlags::reasons(['uptime' => ['probe_blocked_minutes' => 125]]))
            ->toBe(['The uptime monitor was blocked (HTTP 429) for 2h 5m this month. That time counts as up in the uptime figure, but nobody could see the site.'])
            ->and(ReportReviewFlags::reasons(['uptime' => ['probe_blocked_minutes' => 59]]))->toBe([]);
    });
});
