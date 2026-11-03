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
