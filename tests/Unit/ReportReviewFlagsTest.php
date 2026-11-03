<?php

use Modules\ClientReports\Services\ReportReviewFlags;

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

it('says the monitor was blocked, not that the site was down, when only a 429 is still open', function () {
    $reasons = ReportReviewFlags::reasons([
        'uptime' => ['outages' => [['ongoing' => false]], 'probe_blocked_since' => '2026-10-30T14:05:00+00:00'],
    ]);

    // The time is shown in the report timezone, which needs the app config; the wording doesn't.
    expect($reasons)->toHaveCount(1)
        ->and($reasons[0])->toStartWith('The uptime monitor was blocked (HTTP 429) from Oct 30, ')
        ->toEndWith('; the site was not necessarily down.');
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
            ]]))->toBe(['The latest malware scan (scan #6) ran over SSH, which can\'t say how many files it covered, so the client sees "Partial scan" instead of an all-clear.']);
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

    it('uses the version the plugin recorded as offered when there is no list of waiting updates', function () {
        expect(ReportReviewFlags::reasons([
            'security' => ['vulnerable_plugin_items' => [vulnerableItem(['update_offered' => '1.2'])]],
            'updates' => ['pending_plugins' => null, 'pending_plugin_items' => null],
        ]))->toBe(["A plugin with a known vulnerability has an update waiting that fixes it, but it wasn't applied: Example Forms 1.0 → 1.2 (fixed in 1.2)."])
            ->and(ReportReviewFlags::reasons(['security' => ['vulnerable_plugin_items' => [vulnerableItem(['update_offered' => '1.2'])]]]))
            ->toHaveCount(1);
    });
});
