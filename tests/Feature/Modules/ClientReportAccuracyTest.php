<?php

use App\Models\ContactFormTestRun;
use App\Models\PluginVulnerability;
use App\Models\Site;
use App\Models\SitePerformanceScan;
use App\Models\SiteSecurityScan;
use App\Models\SiteUptimeEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Services\ClientReportCompiler;

/*
|--------------------------------------------------------------------------
| Client report accuracy
|--------------------------------------------------------------------------
|
| The report goes to clients verbatim, so anything that wasn't actually
| checked in the period must read as "not checked", never as a pass. Each
| test here pins a case where the compiler used to fill a gap with a
| reassuring default (or read the wrong status value).
*/

beforeEach(function () {
    Http::fake();
});

function accuracySite(array $attributes = []): Site
{
    return Site::factory()->create(array_merge([
        'is_wordpress' => true,
        'care_plan_enabled' => true,
        'backup_relay_enabled' => false,
        'backup_relay_last_archived_at' => null,
        'companion_snapshot' => ['plugins' => ['plugins' => []]],
        'companion_snapshot_at' => Carbon::parse('2026-10-01 06:00'),
    ], $attributes));
}

/** @return array{data: array<string, mixed>, html: string, text: string} */
function compileSeptember(Site $site): array
{
    $start = Carbon::parse('2026-09-01');
    $end = Carbon::parse('2026-09-30');
    $data = app(ClientReportCompiler::class)->compile($site, $start, $end);

    $report = ClientReport::create([
        'site_id' => $site->id,
        'title' => 'Accuracy test',
        'period_start' => $start,
        'period_end' => $end,
        'sections_data' => $data,
        'status' => 'generated',
    ]);

    $mail = new ClientReportMail($report);
    $content = $mail->content();

    return [
        'data' => $data,
        'html' => $mail->render(),
        'text' => view($content->text, $content->with)->render(),
    ];
}

describe('known plugin vulnerabilities (#8)', function () {
    it('counts real matches from the vulnerability feed instead of always reporting none', function () {
        PluginVulnerability::factory()->create(['slug' => 'leaky-forms', 'to_version' => '2.0.0']);
        $site = accuracySite(['companion_snapshot' => ['plugins' => ['plugins' => [
            ['slug' => 'leaky-forms/leaky-forms.php', 'name' => 'Leaky Forms', 'version' => '1.5.0', 'active' => true],
            ['slug' => 'safe-plugin/safe-plugin.php', 'name' => 'Safe Plugin', 'version' => '3.0.0', 'active' => true],
        ]]]]);

        $report = compileSeptember($site);

        expect($report['data']['security'])
            ->toMatchArray([
                'vulnerabilities_checked' => true,
                'active_vulnerabilities' => 1,
                'vulnerable_plugins' => ['Leaky Forms'],
                'vulnerabilities_as_of' => '2026-10-01',
            ]);
        expect($report['html'])->toContain('1 found in 1 plugin')->toContain('(as of Oct 1)')->not->toContain('None found');
        expect($report['text'])->toContain('Known plugin vulnerabilities (as of Oct 1): 1 found in 1 plugin');
    });

    it('says "None found" only when the plugins were actually checked', function () {
        PluginVulnerability::factory()->create(['slug' => 'something-not-installed']);
        $report = compileSeptember(accuracySite());

        expect($report['data']['security']['vulnerabilities_checked'])->toBeTrue()
            ->and($report['html'])->toContain('None found');
    });

    it('says "Not checked" when there is no plugin list to check', function () {
        $report = compileSeptember(accuracySite(['companion_snapshot' => null, 'companion_snapshot_at' => null]));

        expect($report['data']['security']['vulnerabilities_checked'])->toBeFalse()
            ->and($report['html'])->toContain('Not checked')->not->toContain('None found')
            ->and($report['text'])->toContain('Known plugin vulnerabilities: Not checked');
    });

    it('says "Not checked" when the vulnerability feed is stale or empty, instead of "None found"', function () {
        // A failed overnight feed refresh can leave the table empty or old;
        // matching against it would wrongly read as "None found".
        PluginVulnerability::factory()->create(['slug' => 'leaky-forms', 'feed_updated_at' => now()->subDays(5)]);
        $site = accuracySite(['companion_snapshot' => ['plugins' => ['plugins' => [
            ['slug' => 'leaky-forms/leaky-forms.php', 'name' => 'Leaky Forms', 'version' => '1.0.0', 'active' => true],
        ]]]]);

        $report = compileSeptember($site);

        expect($report['data']['security']['vulnerabilities_checked'])->toBeFalse()
            ->and($report['html'])->not->toContain('None found');
    });

    it('notes how many matches are in inactive plugins', function () {
        PluginVulnerability::factory()->create(['slug' => 'dormant', 'to_version' => '2.0.0']);
        $site = accuracySite(['companion_snapshot' => ['plugins' => ['plugins' => [
            ['slug' => 'dormant/dormant.php', 'name' => 'Dormant', 'version' => '1.0.0', 'active' => false],
        ]]]]);

        expect(compileSeptember($site)['html'])->toContain('1 found in 1 plugin (1 in inactive plugins)');
    });
});

describe('backups (#6)', function () {
    it('claims a backup only when the relay archived one inside the period', function () {
        $site = accuracySite([
            'backup_relay_enabled' => true,
            'backup_relay_last_archived_at' => Carbon::parse('2026-09-20 03:00'),
        ]);

        $report = compileSeptember($site);

        expect($report['data']['backups'])->toMatchArray(['verified' => true, 'destination' => 'Independent AWS S3 / Glacier']);
        expect($report['html'])->toContain('were backed up to independent off-site storage this month')
            ->toContain('Sep 20, 2026');
        expect($report['text'])->toContain('BACKUPS');
    });

    it('does not claim backups from a care plan flag alone', function () {
        $report = compileSeptember(accuracySite(['care_plan_enabled' => true, 'backup_relay_enabled' => false]));

        expect($report['data']['backups'])->toMatchArray(['enabled' => true, 'verified' => false, 'destination' => null]);
        expect($report['html'])->not->toContain('backed up')->not->toContain('Host Automated Snapshots');
        expect($report['text'])->not->toContain('BACKUPS');
    });

    it('does not claim backups when the last archive is from before the period', function () {
        $report = compileSeptember(accuracySite([
            'backup_relay_enabled' => true,
            'backup_relay_last_archived_at' => Carbon::parse('2026-08-28 03:00'),
        ]));

        expect($report['data']['backups']['verified'])->toBeFalse()
            ->and($report['html'])->not->toContain('backed up');
    });
});

describe('WordPress core file check (#5)', function () {
    it('reports "Not checked" instead of "Ok" when no checksum scan ran in the period', function () {
        $site = accuracySite();
        // Outside the period, so it must not count.
        SiteSecurityScan::factory()->create([
            'site_id' => $site->id,
            'scan_type' => SiteSecurityScan::TYPE_CORE_CHECKSUMS,
            'scanned_at' => Carbon::parse('2026-08-15'),
        ]);

        $report = compileSeptember($site);

        expect($report['data']['security']['checksum_status'])->toBeNull()
            ->and($report['html'])->toContain('Not checked this month')->not->toMatch('/>\s*Ok\s*</')
            ->and($report['text'])->toContain('WordPress core files: Not checked this month');
    });

    it('uses the latest completed checksum scan inside the period', function () {
        $site = accuracySite();
        SiteSecurityScan::factory()->create(['site_id' => $site->id, 'scan_type' => SiteSecurityScan::TYPE_CORE_CHECKSUMS, 'scanned_at' => Carbon::parse('2026-09-10'), 'status' => SiteSecurityScan::STATUS_ISSUES_FOUND]);
        SiteSecurityScan::factory()->create(['site_id' => $site->id, 'scan_type' => SiteSecurityScan::TYPE_CORE_CHECKSUMS, 'scanned_at' => Carbon::parse('2026-09-20'), 'status' => SiteSecurityScan::STATUS_CLEAN]);
        // A later scan that never completed is not evidence either way.
        SiteSecurityScan::factory()->create(['site_id' => $site->id, 'scan_type' => SiteSecurityScan::TYPE_CORE_CHECKSUMS, 'scanned_at' => Carbon::parse('2026-09-25'), 'status' => SiteSecurityScan::STATUS_FAILED]);

        $report = compileSeptember($site);

        expect($report['data']['security'])->toMatchArray(['checksum_status' => 'clean', 'checksum_checked_at' => '2026-09-20'])
            ->and($report['html'])->toContain('Verified clean');
    });

    it('leaves the WordPress row out entirely for non-WordPress sites', function () {
        $report = compileSeptember(accuracySite(['is_wordpress' => false, 'companion_snapshot' => null]));

        expect($report['data']['security']['checksum_status'])->toBeNull()
            ->and($report['html'])->not->toContain('WordPress core files')
            ->and($report['text'])->not->toContain('WordPress core files');
    });
});

describe('WordPress detection', function () {
    it('treats a site as WordPress when Companion reports a core, even if the flag is off', function () {
        $report = compileSeptember(accuracySite(['is_wordpress' => false, 'companion_snapshot' => ['wp_core' => ['version' => '6.8'], 'plugins' => ['plugins' => []]]]));

        expect($report['data']['site']['is_wordpress'])->toBeTrue()
            ->and($report['html'])->toContain('WordPress core files')->toContain('Not checked this month');
    });
});

describe('malware scan', function () {
    it('shows flagged malware findings instead of an all-clear', function () {
        $site = accuracySite();
        SiteSecurityScan::factory()->create(['site_id' => $site->id, 'scan_type' => SiteSecurityScan::TYPE_COMPANION_MALWARE, 'scanned_at' => Carbon::parse('2026-09-18'), 'status' => SiteSecurityScan::STATUS_WARNING]);

        $report = compileSeptember($site);

        expect($report['data']['security']['malware_status'])->toBe('warning')
            ->and($report['html'])->toContain('Malware scan')->toContain('Issues flagged')
            ->and($report['text'])->toContain('Malware scan: Issues flagged');
    });

    it('says "Not checked this month" when no malware scan completed', function () {
        $report = compileSeptember(accuracySite());

        expect($report['data']['security']['malware_status'])->toBeNull()
            ->and($report['text'])->toContain('Malware scan: Not checked this month');
    });
});

describe('security scan counts', function () {
    it('counts warning and issues-found scans, and does not count failed scans as run', function () {
        $site = accuracySite();
        foreach ([SiteSecurityScan::STATUS_CLEAN, SiteSecurityScan::STATUS_WARNING, SiteSecurityScan::STATUS_ISSUES_FOUND, SiteSecurityScan::STATUS_FAILED] as $status) {
            SiteSecurityScan::factory()->create(['site_id' => $site->id, 'scanned_at' => Carbon::parse('2026-09-12'), 'status' => $status]);
        }

        $security = compileSeptember($site)['data']['security'];

        expect($security)->toMatchArray(['total_scans' => 3, 'clean_scans' => 1, 'warn_scans' => 2]);
    });
});

describe('contact form tests (#4)', function () {
    it('counts runs recorded as "success" as delivered', function () {
        // Regression: the compiler looked for status "ok", which is never
        // written, so every tested site was told "0% delivered".
        $site = accuracySite();
        ContactFormTestRun::factory()->count(3)->create(['site_id' => $site->id, 'ran_at' => Carbon::parse('2026-09-05'), 'status' => ContactFormTestRun::STATUS_SUCCESS]);
        ContactFormTestRun::factory()->create(['site_id' => $site->id, 'ran_at' => Carbon::parse('2026-09-06'), 'status' => ContactFormTestRun::STATUS_FAILED]);

        $report = compileSeptember($site);

        expect($report['data']['forms'])->toMatchArray(['total_synthetic_tests' => 4, 'successful_tests' => 3, 'pass_rate' => 75.0])
            ->and($report['html'])->toContain('75% delivered')
            ->and($report['text'])->toContain('4 test submissions, 75% delivered');
    });

    it('never claims delivery from lab runs, which suppress the email', function () {
        $site = accuracySite();
        ContactFormTestRun::factory()->count(5)->create([
            'site_id' => $site->id,
            'ran_at' => Carbon::parse('2026-09-07'),
            'mode' => ContactFormTestRun::MODE_LAB,
            'mail_outcome' => ContactFormTestRun::MAIL_SUPPRESSED,
            'status' => ContactFormTestRun::STATUS_SUCCESS,
        ]);

        $report = compileSeptember($site);

        expect($report['data']['forms'])->toMatchArray(['live_tests' => 0, 'pass_rate' => null, 'lab_tests' => 5, 'lab_accepted' => 5])
            ->and($report['html'])->toContain('accepted <strong>5 of 5</strong>')->not->toContain('% delivered')
            ->and($report['text'])->not->toContain('% delivered');
    });

    it('does not count a live run whose email failed as delivered', function () {
        $site = accuracySite();
        ContactFormTestRun::factory()->create(['site_id' => $site->id, 'ran_at' => Carbon::parse('2026-09-07'), 'mail_outcome' => ContactFormTestRun::MAIL_FAILED]);
        ContactFormTestRun::factory()->create(['site_id' => $site->id, 'ran_at' => Carbon::parse('2026-09-08')]);

        expect(compileSeptember($site)['data']['forms'])->toMatchArray(['live_tests' => 2, 'successful_tests' => 1, 'pass_rate' => 50.0]);
    });

    it('has no pass rate at all when nothing was tested', function () {
        $report = compileSeptember(accuracySite());

        expect($report['data']['forms']['pass_rate'])->toBeNull()
            ->and($report['html'])->not->toContain('Contact forms');
    });
});

describe('performance (#7)', function () {
    it('ignores scans from before the period instead of showing them as this month', function () {
        $site = accuracySite();
        SitePerformanceScan::factory()->create(['site_id' => $site->id, 'scanned_at' => Carbon::parse('2026-06-30'), 'strategy' => SitePerformanceScan::STRATEGY_DESKTOP]);

        $report = compileSeptember($site);

        expect($report['data']['performance'])->toMatchArray(['has_performance' => false, 'desktop' => null])
            ->and($report['html'])->not->toContain('Speed &amp; performance');
    });

    it('shows scans that ran inside the period', function () {
        $site = accuracySite();
        SitePerformanceScan::factory()->create(['site_id' => $site->id, 'scanned_at' => Carbon::parse('2026-09-14'), 'strategy' => SitePerformanceScan::STRATEGY_MOBILE, 'performance_score' => 88]);

        $report = compileSeptember($site);

        expect($report['data']['performance']['mobile'])->toMatchArray(['score' => 88, 'scanned_at' => '2026-09-14'])
            ->and($report['html'])->toContain('Tested Sep 14, 2026');
    });
});

describe('uptime', function () {
    function uptimeEvent(Site $site, string $type, string $at, bool $exempt = false): void
    {
        SiteUptimeEvent::create(['site_id' => $site->id, 'event_type' => $type, 'event_at' => Carbon::parse($at), 'is_sla_exempt' => $exempt]);
    }

    it('measures real outage length from down to up instead of 5 minutes per outage', function () {
        // Regression: the compiler read a non-existent duration_seconds column
        // and counted every outage as 5 minutes.
        $site = accuracySite(['uptime_monitoring_enabled' => true]);
        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-10 10:00:00');
        uptimeEvent($site, SiteUptimeEvent::TYPE_UP, '2026-09-10 14:00:00');

        $uptime = compileSeptember($site)['data']['uptime'];

        expect($uptime)->toMatchArray(['outages_count' => 1, 'downtime_minutes' => 240]);
    });

    it('counts an outage that started before the period from the start of the period', function () {
        $site = accuracySite(['uptime_monitoring_enabled' => true]);
        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-08-31 23:00:00');
        uptimeEvent($site, SiteUptimeEvent::TYPE_UP, '2026-09-01 01:30:00');

        expect(compileSeptember($site)['data']['uptime'])->toMatchArray(['outages_count' => 1, 'downtime_minutes' => 90]);
    });

    it('runs an outage still open at the end of the period to the end', function () {
        $site = accuracySite(['uptime_monitoring_enabled' => true]);
        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-30 22:00:00');

        expect(compileSeptember($site)['data']['uptime']['downtime_minutes'])->toBe(120);
    });

    it('ignores SLA-exempt outages and treats maintenance as not down', function () {
        $site = accuracySite(['uptime_monitoring_enabled' => true]);
        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-05 10:00:00', exempt: true);
        uptimeEvent($site, SiteUptimeEvent::TYPE_UP, '2026-09-05 12:00:00');
        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-06 10:00:00');
        uptimeEvent($site, SiteUptimeEvent::TYPE_MAINTENANCE, '2026-09-06 10:30:00');

        expect(compileSeptember($site)['data']['uptime'])->toMatchArray(['outages_count' => 1, 'downtime_minutes' => 30]);
    });
});

describe('reports compiled before these fixes', function () {
    it('does not re-show their broken forms rate or their unchecked "None" vulnerabilities', function () {
        $site = accuracySite();
        $report = ClientReport::create([
            'site_id' => $site->id,
            'title' => 'Old-format report',
            'period_start' => Carbon::parse('2026-08-01'),
            'period_end' => Carbon::parse('2026-08-31'),
            'status' => 'generated',
            'sections_data' => [
                'meta' => ['custom_notes' => null],
                'site' => ['is_wordpress' => true],
                'security' => ['total_scans' => 3, 'active_vulnerabilities' => 0, 'checksum_status' => 'clean', 'blocked_threats_count' => 0],
                'forms' => ['pass_rate' => 0, 'successful_tests' => 0, 'total_synthetic_tests' => 12],
            ],
        ]);

        $html = (new ClientReportMail($report))->render();

        expect($html)->not->toContain('% delivered')->not->toContain('None found')->toContain('Not checked');
    });
});
