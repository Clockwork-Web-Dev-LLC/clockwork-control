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
