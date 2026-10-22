<?php

use App\Models\ActionLog;
use App\Models\ContactFormTestRun;
use App\Models\PluginVulnerability;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBackup;
use App\Models\SiteBackupCheck;
use App\Models\SitePerformanceScan;
use App\Models\SiteSecurityScan;
use App\Models\SiteUptimeEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Services\ClientReportCompiler;
use Modules\ClientReports\Services\ReportReviewFlags;

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
        'uptime_last_checked_at' => now(),
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
    function pressableBackupSite(): Site
    {
        return accuracySite(['hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE, 'pressable_site_id' => (string) random_int(1000, 999999)]);
    }

    function recordBackup(Site $site, string $kind, string $at, ?int $bytes = 50 * 1024 ** 2): void
    {
        SiteBackup::create([
            'site_id' => $site->id, 'source' => SiteBackup::SOURCE_PRESSABLE, 'kind' => $kind,
            'external_ref' => $kind.$at, 'backup_at' => Carbon::parse($at), 'bytes' => $bytes,
            'is_manual' => false, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    function recordCheck(Site $site, bool $ok, ?Carbon $at = null): void
    {
        SiteBackupCheck::create(['site_id' => $site->id, 'source' => SiteBackup::SOURCE_PRESSABLE, 'checked_at' => $at ?? now(), 'ok' => $ok, 'error' => $ok ? null : 'Pressable API returned 503']);
    }

    it('lists the last 10 days of recorded backups, one row per day', function () {
        $site = pressableBackupSite();
        foreach (range(1, 30) as $day) {
            recordBackup($site, SiteBackup::KIND_FILES, sprintf('2026-09-%02d 00:00:00', $day));
            recordBackup($site, SiteBackup::KIND_DATABASE, sprintf('2026-09-%02d 23:00:00', $day), 10 * 1024 ** 2);
        }
        recordBackup($site, SiteBackup::KIND_FILES, '2026-10-01 00:00:00'); // after the period
        recordCheck($site, true);

        $report = compileSeptember($site);
        $backups = $report['data']['backups'];

        expect($backups)->toMatchArray(['status' => 'verified', 'verified' => true, 'days_with_backups' => 30, 'list_from' => '2026-09-21', 'last_backup_at' => '2026-09-30'])
            ->and($backups['recent'])->toHaveCount(10)
            ->and($backups['recent'][0])->toMatchArray(['date' => '2026-09-30', 'kinds' => ['db', 'fs'], 'bytes' => 60 * 1024 ** 2]);
        expect($report['html'])->toContain('backed up on <strong>30 days</strong> this month by your host, Pressable')
            ->toContain('Wed, Sep 30')->toContain('Files &amp; database')->toContain('60 MB')
            ->not->toContain('Sep 20');
        expect($report['text'])->toContain('Backed up on 30 days this month by your host, Pressable');
    });

    it('does not claim backups from a care plan flag alone', function () {
        $report = compileSeptember(accuracySite(['care_plan_enabled' => true, 'hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM, 'backup_relay_enabled' => false]));

        expect($report['data']['backups'])->toMatchArray(['status' => 'no_source', 'verified' => false])
            ->and($report['html'])->not->toContain('backed up')
            ->and($report['text'])->not->toContain('BACKUPS');
    });

    it('hides the section when backups were checked and none were found', function () {
        $site = pressableBackupSite();
        recordBackup($site, SiteBackup::KIND_FILES, '2026-08-28 00:00:00'); // before the period
        recordCheck($site, true);

        $report = compileSeptember($site);

        expect($report['data']['backups']['status'])->toBe('none_found')
            ->and($report['html'])->not->toContain('backed up');
    });

    it('counts one backup of each kind per day in the size, preferring the scheduled one', function () {
        $site = pressableBackupSite();
        recordBackup($site, SiteBackup::KIND_FILES, '2026-09-30 00:00:00', 50 * 1024 ** 2);
        recordBackup($site, SiteBackup::KIND_DATABASE, '2026-09-30 05:00:00', 9 * 1024 ** 2);
        recordBackup($site, SiteBackup::KIND_DATABASE, '2026-09-30 23:00:00', 10 * 1024 ** 2);
        SiteBackup::create([
            'site_id' => $site->id, 'source' => SiteBackup::SOURCE_PRESSABLE, 'kind' => SiteBackup::KIND_FILES,
            'external_ref' => 'manual', 'backup_at' => Carbon::parse('2026-09-30 15:00:00'), 'bytes' => 70 * 1024 ** 2,
            'is_manual' => true, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        recordCheck($site, true);

        expect(compileSeptember($site)['data']['backups']['recent'][0])
            ->toMatchArray(['date' => '2026-09-30', 'kinds' => ['db', 'fs'], 'bytes' => 60 * 1024 ** 2, 'manual' => false]);
    });

    it('sizes a day with a full backup by the full backup alone', function () {
        $site = accuracySite(['hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP]);
        foreach ([[SiteBackup::KIND_FULL, '2026-09-30 00:01:00', 500], [SiteBackup::KIND_DATABASE, '2026-09-30 06:00:00', 20]] as [$kind, $at, $mb]) {
            SiteBackup::create([
                'site_id' => $site->id, 'source' => SiteBackup::SOURCE_SPINUPWP_SPACES, 'kind' => $kind,
                'external_ref' => $at, 'backup_at' => Carbon::parse($at), 'bytes' => $mb * 1024 ** 2,
                'is_manual' => false, 'first_seen_at' => now(), 'last_seen_at' => now(),
            ]);
        }
        SiteBackupCheck::create(['site_id' => $site->id, 'source' => SiteBackup::SOURCE_SPINUPWP_SPACES, 'checked_at' => now(), 'ok' => true]);

        $report = compileSeptember($site);

        expect($report['data']['backups']['recent'][0])->toMatchArray(['kinds' => ['db', 'full'], 'bytes' => 500 * 1024 ** 2])
            ->and($report['html'])->toContain('by SpinupWP, stored in DigitalOcean Spaces')->toContain('Files &amp; database')->toContain('500 MB');
    });

    it('flags backups that stopped before the last 10 days instead of showing them to the client', function () {
        $site = pressableBackupSite();
        recordBackup($site, SiteBackup::KIND_FILES, '2026-09-05 00:00:00');
        recordCheck($site, true);

        $report = compileSeptember($site);

        expect($report['data']['backups'])->toMatchArray(['status' => 'stale', 'verified' => false, 'days_with_backups' => 1, 'recent' => []])
            ->and($report['html'])->not->toContain('backed up')->not->toContain('independent off-site storage')
            ->and($report['text'])->not->toContain('BACKUPS');
    });

    it('leaves out the month\'s count when our records start part-way through the month', function () {
        // Pressable keeps about 8 days, so the first recorder run can't see
        // the start of the month: "backed up on 9 days" would understate it.
        $site = pressableBackupSite();
        foreach (range(22, 30) as $day) {
            recordBackup($site, SiteBackup::KIND_FILES, sprintf('2026-09-%02d 00:00:00', $day));
        }
        recordCheck($site, true);

        $report = compileSeptember($site);

        expect($report['data']['backups'])->toMatchArray(['status' => 'verified', 'covers_period' => false, 'records_from' => '2026-09-22', 'days_with_backups' => 9])
            ->and($report['html'])->toContain('Your site is backed up by your host, Pressable, so it can be restored')
            ->not->toContain('backed up on')->toContain('Wed, Sep 30')
            ->and($report['text'])->toContain('Backed up by your host, Pressable. Most recent:')
            ->not->toContain('Backed up on');
    });

    it('treats a failed or stale check as "could not check", not as no backups', function () {
        $failing = pressableBackupSite();
        recordCheck($failing, false);
        $stale = pressableBackupSite();
        recordCheck($stale, true, now()->subDays(5));

        expect(compileSeptember($failing)['data']['backups'])->toMatchArray(['status' => 'not_checked', 'check_error' => 'Pressable API returned 503'])
            ->and(compileSeptember($stale)['data']['backups']['status'])->toBe('not_checked');
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

describe('threats blocked', function () {
    it('leaves the count out when nothing on the site reports blocks', function () {
        $report = compileSeptember(accuracySite());

        expect($report['data']['security']['blocked_threats_count'])->toBeNull()
            ->and($report['html'])->not->toContain('Malicious visitors blocked')->not->toContain('Threats blocked')
            ->not->toContain('block attackers')
            ->and($report['text'])->not->toContain('Malicious visitors blocked');
    });

    it('shows a real 0 when a blocker reports to Control', function () {
        $site = accuracySite(['companion_snapshot' => ['plugins' => ['plugins' => [
            ['slug' => 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php', 'active' => true],
        ]]]]);

        $report = compileSeptember($site);

        expect($report['data']['security']['blocked_threats_count'])->toBe(0)
            ->and($report['html'])->toContain('block attackers at the server')
            ->and($report['text'])->toContain('Malicious visitors blocked: 0');
    });
});

describe('traffic', function () {
    it('leaves out data transferred when the host reports no byte counts', function () {
        $site = accuracySite();
        DB::table('site_traffic_daily')->insert(['site_id' => $site->id, 'date' => '2026-09-10', 'requests' => 5000, 'visits' => 80, 'bytes_sent' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $report = compileSeptember($site);

        expect($report['data']['traffic'])->toMatchArray(['has_traffic' => true, 'total_requests' => 5000, 'bandwidth_mb' => null])
            ->and($report['html'])->toContain('Visits')->not->toContain('Data transferred');
    });
});

describe('blacklist check', function () {
    it('reports blocklist status from the latest in-period blacklist scan', function () {
        $site = accuracySite();
        SiteSecurityScan::factory()->create(['site_id' => $site->id, 'scan_type' => SiteSecurityScan::TYPE_BLACKLIST, 'scanned_at' => Carbon::parse('2026-09-20'), 'status' => SiteSecurityScan::STATUS_CLEAN]);

        $report = compileSeptember($site);

        expect($report['data']['security']['blacklist_status'])->toBe('clean')
            ->and($report['html'])->toContain('Spam &amp; malware blocklists')->toContain('Not listed')
            ->and(compileSeptember(accuracySite())['text'])->toContain('Spam & malware blocklists: Not checked this month');
    });

    it('shows the blocklist row in both emails for non-WordPress sites', function () {
        $site = accuracySite(['is_wordpress' => false, 'companion_snapshot' => null]);
        SiteSecurityScan::factory()->create(['site_id' => $site->id, 'scan_type' => SiteSecurityScan::TYPE_BLACKLIST, 'scanned_at' => Carbon::parse('2026-09-20'), 'status' => SiteSecurityScan::STATUS_CLEAN]);

        $report = compileSeptember($site);

        expect($report['html'])->toContain('Spam &amp; malware blocklists')->not->toContain('WordPress core files')
            ->and($report['text'])->toContain('Spam & malware blocklists: Not listed')->not->toContain('WordPress core files');
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
        SitePerformanceScan::factory()->create(['site_id' => $site->id, 'scanned_at' => Carbon::parse('2026-09-14'), 'strategy' => SitePerformanceScan::STRATEGY_MOBILE, 'engine' => SitePerformanceScan::ENGINE_PRESSABLE, 'performance_score' => 88]);

        $report = compileSeptember($site);

        expect($report['data']['performance']['mobile'])->toMatchArray(['score' => 88, 'scanned_at' => '2026-09-14'])
            ->and($report['html'])->toContain('Tested Sep 14, 2026');
    });

    it('dates a host report by when the host ran the test, not when we read it', function () {
        $site = accuracySite();
        // Pressable ran this on Aug 28; we only read it on Sep 5. It isn't September's.
        SitePerformanceScan::factory()->create(['site_id' => $site->id, 'scanned_at' => Carbon::parse('2026-09-05'), 'source_generated_at' => Carbon::parse('2026-08-28'), 'strategy' => SitePerformanceScan::STRATEGY_MOBILE, 'engine' => SitePerformanceScan::ENGINE_PRESSABLE, 'performance_score' => 60]);
        // Ran Sep 20, read Oct 2: it is September's, labelled Sep 20.
        SitePerformanceScan::factory()->create(['site_id' => $site->id, 'scanned_at' => Carbon::parse('2026-10-02'), 'source_generated_at' => Carbon::parse('2026-09-20'), 'strategy' => SitePerformanceScan::STRATEGY_DESKTOP, 'engine' => SitePerformanceScan::ENGINE_PRESSABLE, 'performance_score' => 82]);

        $perf = compileSeptember($site)['data']['performance'];

        expect($perf['mobile'])->toBeNull()
            ->and($perf['desktop'])->toMatchArray(['score' => 82, 'scanned_at' => '2026-09-20']);
    });

    it('reports GTmetrix scans as desktop, since our plan has no phone emulation', function () {
        $site = accuracySite();
        SitePerformanceScan::factory()->create(['site_id' => $site->id, 'scanned_at' => Carbon::parse('2026-09-14'), 'strategy' => SitePerformanceScan::STRATEGY_MOBILE, 'engine' => SitePerformanceScan::ENGINE_GTMETRIX, 'performance_score' => 91]);

        $perf = compileSeptember($site)['data']['performance'];

        expect($perf['mobile'])->toBeNull()
            ->and($perf['desktop'])->toMatchArray(['score' => 91]);
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

        expect($uptime)->toMatchArray(['outages_count' => 1, 'downtime_minutes' => 240])
            ->and($uptime['outages'])->toHaveCount(1)
            ->and($uptime['outages'][0])->toBe(['started_at' => '2026-09-10T10:00:00+00:00', 'minutes' => 240, 'started_before_period' => false, 'ongoing' => false]);
        expect(compileSeptember($site)['html'])->toContain('Down Sep 10, 10:00 AM UTC')->toContain('4h 0m');
    });

    it('counts an outage that started before the period from the start of the period', function () {
        $site = accuracySite(['uptime_monitoring_enabled' => true]);
        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-08-31 23:00:00');
        uptimeEvent($site, SiteUptimeEvent::TYPE_UP, '2026-09-01 01:30:00');

        $report = compileSeptember($site);

        expect($report['data']['uptime'])->toMatchArray(['outages_count' => 1, 'downtime_minutes' => 90])
            ->and($report['data']['uptime']['outages'][0]['started_before_period'])->toBeTrue()
            ->and($report['html'])->toContain('Down since before Sep 1, 12:00 AM UTC');
    });

    it('runs an outage still open at the end of the period to the end', function () {
        $site = accuracySite(['uptime_monitoring_enabled' => true]);
        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-30 22:00:00');

        $report = compileSeptember($site);

        expect($report['data']['uptime']['downtime_minutes'])->toBe(120)
            ->and($report['data']['uptime']['outages'][0]['ongoing'])->toBeTrue()
            ->and($report['text'])->toContain('(still down at period end)');
    });

    it('leaves uptime out for a site the probe skips, instead of claiming 100%', function () {
        // CheckSiteUptime skips ignored and staging servers, so there are no events to read.
        $site = accuracySite(['uptime_monitoring_enabled' => true, 'server_id' => Server::factory()->ignored()]);

        $report = compileSeptember($site);
        $preview = view('client-reports::reports.show', ['report' => ClientReport::latest('id')->first(), 'isOperatorPreview' => true])->render();

        expect($report['data']['uptime'])->toMatchArray(['monitored' => false, 'probe_stopped' => false])
            ->and($report['html'])->not->toContain('around the clock')->not->toContain('Uptime')
            ->and($report['text'])->not->toContain('UPTIME')
            ->and($preview)->not->toContain('Uptime &amp; Availability')
            ->and(implode(' ', ReportReviewFlags::reasons($report['data'])))->not->toContain('Uptime');
    });

    it('holds the report when the probe stopped checking a site it should check', function () {
        $site = accuracySite(['uptime_monitoring_enabled' => true, 'uptime_last_checked_at' => Carbon::parse('2026-09-12 08:00')]);

        $report = compileSeptember($site);

        expect($report['data']['uptime'])->toMatchArray(['monitored' => false, 'probe_stopped' => true, 'last_checked_at' => '2026-09-12'])
            ->and($report['html'])->not->toContain('around the clock')
            ->and(ReportReviewFlags::reasons($report['data']))
            ->toContain("Uptime monitoring is on, but the site hasn't been checked since 2026-09-12, so the Uptime section was left out.");
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

describe('updates and site facts', function () {
    it('leaves out "updates" that ended on the version they started from', function () {
        $site = accuracySite();
        foreach (['Plugin updated: Beaver Themer 1.5.3 → 1.5.3', 'Plugin updated: Yoast SEO 26.1 → 26.2', 'Plugin updated: Thing 1.5 → 1.5.1'] as $i => $summary) {
            ActionLog::create([
                'site_id' => $site->id, 'action_type' => ActionLog::TYPE_PLUGIN_UPDATE, 'target' => "p{$i}/p.php",
                'summary' => $summary, 'ok' => true, 'ran_at' => Carbon::parse('2026-09-1'.$i), 'actor' => 'test',
            ]);
        }

        $updates = compileSeptember($site)['data']['updates'];

        expect($updates['total'])->toBe(2)
            ->and(array_column($updates['items']['plugins'], 'summary'))->not->toContain('Plugin updated: Beaver Themer 1.5.3 → 1.5.3');
    });

    it('leaves out "already up to date" and empty translation runs, but keeps real updates that look similar', function () {
        $site = accuracySite();
        $rows = [
            [ActionLog::TYPE_PLUGIN_UPDATE, 'Plugin already up to date: acf/acf.php (6.8.10) on example.com'],
            [ActionLog::TYPE_TRANSLATIONS_UPDATE, 'Translations updated: 0 package(s)'],
            [ActionLog::TYPE_TRANSLATIONS_UPDATE, 'Translations updated: 3 package(s)'],
            // The new version is the tail of the old one; this is a real update.
            [ActionLog::TYPE_PLUGIN_UPDATE, 'Plugin updated: Thing 3.3.4 → 3.4'],
        ];
        foreach ($rows as $i => [$type, $summary]) {
            ActionLog::create([
                'site_id' => $site->id, 'action_type' => $type, 'target' => "t{$i}",
                'summary' => $summary, 'ok' => true, 'ran_at' => Carbon::parse('2026-09-1'.$i), 'actor' => 'test',
            ]);
        }

        $updates = compileSeptember($site)['data']['updates'];

        expect($updates['total'])->toBe(2)
            ->and($updates['translations_count'])->toBe(1)
            ->and(array_column($updates['items']['plugins'], 'summary'))->toBe(['Plugin updated: Thing 3.3.4 → 3.4']);
    });

    it('does not say "already up to date" while plugin updates are waiting', function () {
        $waiting = accuracySite(['companion_snapshot' => ['plugins' => ['plugins' => [['name' => 'WP Rocket', 'active' => true, 'update_available' => true]]]]]);
        $current = accuracySite(['companion_snapshot' => [
            'plugins' => ['plugins' => [['name' => 'Akismet', 'active' => true, 'update_available' => false]]],
            'themes' => ['items' => [['name' => 'Astra', 'slug' => 'astra', 'update_available' => false]]],
            'wp_core' => ['current_version' => '6.8.3', 'update_available' => false],
        ]]);

        $w = compileSeptember($waiting);
        $preview = fn () => view('client-reports::reports.show', ['report' => ClientReport::latest('id')->first(), 'isOperatorPreview' => true])->render();
        $wPreview = $preview();
        $c = compileSeptember($current);
        $cPreview = $preview();

        expect($w['data']['updates']['pending_plugins'])->toBe(['WP Rocket'])
            ->and($w['html'])->toContain('No updates were applied this month.')->not->toContain('already up to date')
            ->and($w['text'])->toContain('No updates were applied this month.')
            ->and($c['html'])->toContain('Everything was already up to date');
        // The preview (what prints as the PDF) follows the same rule.
        expect($wPreview)->toContain('No updates were applied this period.')->not->toContain('up-to-date throughout')
            ->and($cPreview)->toContain('up-to-date throughout this period');
    });

    it('does not say "already up to date" while a theme or WordPress update is waiting', function () {
        $snapshot = fn (bool $theme, bool $core) => ['companion_snapshot' => [
            'plugins' => ['plugins' => [['name' => 'Akismet', 'active' => true, 'update_available' => false]]],
            'themes' => ['items' => [['name' => 'Divi', 'slug' => 'Divi', 'update_available' => $theme]]],
            'wp_core' => ['current_version' => '6.8.2', 'update_available' => $core],
        ]];

        $theme = compileSeptember(accuracySite($snapshot(true, false)));
        $core = compileSeptember(accuracySite($snapshot(false, true)));

        expect($theme['data']['updates'])->toMatchArray(['pending_themes' => ['Divi'], 'pending_core' => false])
            ->and($theme['html'])->toContain('No updates were applied this month.')->not->toContain('already up to date')
            ->and($theme['text'])->toContain('No updates were applied this month.')
            ->and($core['data']['updates']['pending_core'])->toBeTrue()
            ->and($core['html'])->toContain('No updates were applied this month.');
    });

    it('does not say "already up to date" from a stale snapshot, and holds the report', function () {
        // Companion stopped answering in August: its snapshot can't say what was waiting at the end of September.
        $site = accuracySite(['companion_snapshot_at' => Carbon::parse('2026-08-20 01:30'), 'companion_snapshot' => [
            'plugins' => ['plugins' => []], 'themes' => ['items' => []], 'wp_core' => ['update_available' => false],
        ]]);

        $report = compileSeptember($site);

        expect($report['data']['updates'])->toMatchArray(['pending_plugins' => null, 'pending_themes' => null, 'pending_core' => null, 'snapshot_stale' => true])
            ->and($report['html'])->toContain('No updates were applied this month.')
            ->and(ReportReviewFlags::reasons($report['data']))
            ->toContain("Companion hasn't reported since 2026-08-20, so waiting updates and plugin vulnerabilities are out of date.");
    });

    it('counts an update applied after the period as waiting at its end', function () {
        // A held report recompiled after the month, or a manual update applied on the 1st.
        $site = accuracySite(['companion_snapshot' => [
            'plugins' => ['plugins' => [['name' => 'WP Rocket', 'active' => true, 'update_available' => false]]],
            'themes' => ['items' => []], 'wp_core' => ['update_available' => false],
        ]]);
        ActionLog::create(['site_id' => $site->id, 'action_type' => ActionLog::TYPE_PLUGIN_UPDATE, 'target' => 'wp-rocket/wp-rocket.php',
            'summary' => 'Plugin updated: WP Rocket 3.1 → 3.2', 'ok' => true, 'ran_at' => Carbon::parse('2026-10-01 01:40'), 'actor' => 'test']);

        $report = compileSeptember($site);

        expect($report['data']['updates'])->toMatchArray(['total' => 0, 'updated_since_period' => 1])
            ->and($report['html'])->toContain('No updates were applied this month.')->not->toContain('already up to date');
    });

    it('reads the WordPress version from the Companion snapshot', function () {
        $site = accuracySite(['companion_snapshot' => ['wp_core' => ['current_version' => '6.8.3'], 'plugins' => ['plugins' => []]]]);

        expect(compileSeptember($site)['data']['site']['wp_version'])->toBe('6.8.3');
    });
});
