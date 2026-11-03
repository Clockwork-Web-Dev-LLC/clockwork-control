<?php

use App\Models\ActionLog;
use App\Models\BlockedIp;
use App\Models\ContactFormTestRun;
use App\Models\PluginUpdateIgnore;
use App\Models\PluginVulnerability;
use App\Models\ReviewQueueEntry;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBackup;
use App\Models\SiteBackupCheck;
use App\Models\SitePerformanceScan;
use App\Models\SiteSecurityScan;
use App\Models\SiteUptimeEvent;
use App\Services\ActionLog\ActionLogger;
use App\Services\Chat\ChatNotifier;
use App\Services\Security\CompanionMalwareScanner;
use App\Services\Security\SecurityScanRecorder;
use App\Services\Ssh\SshClient;
use App\Services\Uptime\UptimeProbeResult;
use App\Services\Uptime\UptimeStateUpdater;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Services\ClientReportCompiler;
use Modules\ClientReports\Services\ReportReviewFlags;
use Modules\ClientReports\Support\ReportPhrases;
use Modules\Core\Contracts\SmsNotifier;

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

/** @return array{data: array<string, mixed>, html: string, text: string, preview: string, public: string, reasons: list<string>} */
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
        'preview' => view('client-reports::reports.show', ['report' => $report, 'isOperatorPreview' => true])->render(),
        'public' => view('client-reports::reports.show', ['report' => $report, 'isOperatorPreview' => false])->render(),
        'reasons' => ReportReviewFlags::reasons($data),
    ];
}

/**
 * A Companion malware scan with the details the scanner records.
 *
 * @param  list<array{kind: string, path: string, evidence: string}>  $findings
 * @param  array<string, mixed>  $details
 */
function companionMalwareScan(Site $site, string $at, string $status, array $findings = [], array $details = []): SiteSecurityScan
{
    return SiteSecurityScan::factory()->create([
        'site_id' => $site->id,
        'scan_type' => SiteSecurityScan::TYPE_COMPANION_MALWARE,
        'scanned_at' => Carbon::parse($at),
        'status' => $status,
        'details' => array_replace([
            'transport' => 'companion', 'findings' => $findings, 'scanned_files_count' => 12000,
            'scan_aborted' => false, 'abort_reason' => null,
        ], $details),
    ]);
}

/** A snapshot with Limit Login Attempts Reloaded active. */
function llarSnapshot(): array
{
    return ['plugins' => ['plugins' => [
        ['slug' => 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php', 'active' => true],
    ]]];
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
        // The date is when the plugin list was checked (the snapshot date), so
        // it says "checked", and the client is told which plugin it is.
        expect($report['html'])->toContain('1 found in 1 plugin')->toContain('(checked Oct 1)')->not->toContain('as of')
            ->toContain('Plugin affected: Leaky Forms')->not->toContain('None found');
        expect($report['text'])->toContain("Known plugin vulnerabilities (checked Oct 1): 1 found in 1 plugin\nPlugin affected: Leaky Forms\n");
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

    it('records each vulnerable plugin by its file slug, with the version that fixes it, the update offered and any pause', function () {
        PluginVulnerability::factory()->create(['slug' => 'example-forms', 'to_version' => '2.0.9', 'patched_in' => '2.1.0']);
        PluginVulnerability::factory()->create(['slug' => 'example-forms', 'to_version' => '1.9.0', 'patched_in' => '1.9.1']);
        PluginVulnerability::factory()->create(['slug' => 'example-gallery', 'to_version' => '*', 'patched_in' => '2.0.0']);
        PluginVulnerability::factory()->create(['slug' => 'example-gallery', 'to_version' => '*', 'patched_in' => null]);
        $site = accuracySite(['companion_snapshot' => ['plugins' => ['plugins' => [
            ['slug' => 'example-forms/example-forms.php', 'name' => 'Example Forms', 'version' => '1.5.0', 'active' => true, 'update_available' => false],
            // A backup copy with the same name and an update waiting: not the vulnerable plugin.
            ['slug' => 'example-forms-old/example-forms.php', 'name' => 'Example Forms', 'version' => '1.0.0', 'active' => false, 'update_available' => true, 'new_version' => '2.1.0'],
            ['slug' => 'example-gallery/example-gallery.php', 'name' => 'Example Gallery', 'version' => '3.0', 'active' => true, 'update_available' => true, 'new_version' => '3.1'],
        ]]]]);
        PluginUpdateIgnore::factory()->create([
            'site_id' => $site->id,
            'target_slug' => 'example-gallery/example-gallery.php',
            'source' => PluginUpdateIgnore::SOURCE_AUTO_FAILURE,
            'failure_count' => 3,
            'ignored_at' => Carbon::parse('2026-09-25 04:00'),
        ]);

        $report = compileSeptember($site);

        expect($report['data']['security']['vulnerable_plugin_items'])->toBe([
            ['slug' => 'example-forms/example-forms.php', 'name' => 'Example Forms', 'version' => '1.5.0', 'fixed_in' => '2.1.0', 'update_offered' => null, 'paused' => null],
            ['slug' => 'example-gallery/example-gallery.php', 'name' => 'Example Gallery', 'version' => '3.0', 'fixed_in' => null, 'update_offered' => '3.1', 'paused' => [
                'source' => 'auto_failure', 'since' => '2026-09-25', 'failure_count' => 3, 'note' => null,
            ]],
        ])
            ->and($report['data']['updates']['pending_plugin_items'])->toBe([
                ['slug' => 'example-forms-old/example-forms.php', 'name' => 'Example Forms', 'new_version' => '2.1.0'],
                ['slug' => 'example-gallery/example-gallery.php', 'name' => 'Example Gallery', 'new_version' => '3.1'],
            ])
            // Matching by name would have said the vulnerable Example Forms had an update waiting.
            ->and($report['reasons'])->toContain('Updates are paused for a plugin with a known vulnerability: Example Gallery 3.0 (no fixed version yet; auto-paused on 2026-09-25 after 3 failed attempts; 3.1 is waiting).')
            ->and(implode(' ', $report['reasons']))->not->toContain('Example Forms');
    });

    it('holds the report when a waiting update reaches the fixed version, but not when it falls short', function () {
        PluginVulnerability::factory()->create(['slug' => 'example-forms', 'to_version' => '1.1.9', 'patched_in' => '1.2.0']);
        PluginVulnerability::factory()->create(['slug' => 'example-slider', 'to_version' => '2.0.0', 'to_inclusive' => false, 'patched_in' => '2.0.1']);
        $site = accuracySite(['companion_snapshot' => ['plugins' => ['plugins' => [
            ['slug' => 'example-forms/example-forms.php', 'name' => 'Example Forms', 'version' => '1.0.0', 'active' => true, 'update_available' => true, 'new_version' => '1.2.0'],
            ['slug' => 'example-slider/example-slider.php', 'name' => 'Example Slider', 'version' => '1.4.0', 'active' => true, 'update_available' => true, 'new_version' => '1.5.0'],
        ]]]]);

        $reasons = compileSeptember($site)['reasons'];

        expect($reasons)->toContain("A plugin with a known vulnerability has an update waiting that fixes it, but it wasn't applied: Example Forms 1.0.0 → 1.2.0 (fixed in 1.2.0).")
            ->and(implode(' ', $reasons))->not->toContain('Example Slider');
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
            ->and($report['data']['backups']['destination'])->toBe('by SpinupWP to DigitalOcean Spaces')
            ->and($report['html'])->toContain('by SpinupWP to DigitalOcean Spaces')->toContain('Files &amp; database')->toContain('500 MB');
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

    it('holds database backups far smaller than the database instead of showing them to the client', function () {
        $site = accuracySite([
            'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP,
            'companion_snapshot' => ['plugins' => ['plugins' => []], 'environment' => ['db_size_bytes' => 3 * 1024 ** 3]],
        ]);
        foreach (range(1, 30) as $day) {
            SiteBackup::create([
                'site_id' => $site->id, 'source' => SiteBackup::SOURCE_SPINUPWP_SPACES, 'kind' => SiteBackup::KIND_DATABASE,
                'external_ref' => "db-{$day}", 'backup_at' => Carbon::parse(sprintf('2026-09-%02d 07:00', $day)), 'bytes' => 9200,
                'is_manual' => false, 'first_seen_at' => now(), 'last_seen_at' => now(),
            ]);
        }
        SiteBackupCheck::create(['site_id' => $site->id, 'source' => SiteBackup::SOURCE_SPINUPWP_SPACES, 'checked_at' => now(), 'ok' => true]);

        $report = compileSeptember($site);

        expect($report['data']['backups'])->toMatchArray([
            'status' => 'suspect', 'verified' => false, 'days_with_backups' => 30, 'db_backup_bytes' => 9200, 'db_size_bytes' => 3 * 1024 ** 3,
        ])
            ->and($report['html'])->not->toContain('backed up')
            ->and($report['text'])->not->toContain('BACKUPS')
            ->and($report['preview'])->toContain('Backups hidden from the client: the newest database backup is far smaller than the database (9 KB for 3.0 GB).')
            ->and($report['reasons'])->toContain('Database backups are 9 KB but the database is 3.0 GB, so they look empty or broken and the Backups section was left out.');
    });

    it('keeps database backups of a plausible size, and a small database\'s small backups', function (int $dbBytes, int $backupBytes) {
        $site = accuracySite([
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE, 'pressable_site_id' => (string) random_int(1000, 999999),
            'companion_snapshot' => ['plugins' => ['plugins' => []], 'environment' => ['db_size_bytes' => $dbBytes]],
        ]);
        foreach (range(1, 30) as $day) {
            recordBackup($site, SiteBackup::KIND_DATABASE, sprintf('2026-09-%02d 23:00:00', $day), $backupBytes);
        }
        recordCheck($site, true);

        expect(compileSeptember($site)['data']['backups']['status'])->toBe('verified');
    })->with([
        'compressed to a few percent' => [100 * 1024 ** 2, 3 * 1024 ** 2],
        'a small database' => [5 * 1024 ** 2, 60 * 1024],
    ]);

    it('draws the line at about 1% of the database', function () {
        expect(ClientReportCompiler::backupTooSmall(9 * 1024, 3 * 1024 ** 3))->toBeTrue()
            ->and(ClientReportCompiler::backupTooSmall(500 * 1024, 100 * 1024 ** 2))->toBeTrue()
            ->and(ClientReportCompiler::backupTooSmall(90 * 1024, 11 * 1024 ** 2))->toBeTrue()
            ->and(ClientReportCompiler::backupTooSmall(2 * 1024 ** 2, 100 * 1024 ** 2))->toBeFalse()
            // Under 100 KB is fine for a database that small.
            ->and(ClientReportCompiler::backupTooSmall(90 * 1024, 8 * 1024 ** 2))->toBeFalse();
    });

    it('holds a month a daily source backed up on far fewer days than it had', function () {
        $site = pressableBackupSite();
        recordBackup($site, SiteBackup::KIND_FILES, '2026-08-20 00:00:00'); // records go back before the period
        foreach (range(15, 30) as $day) {
            recordBackup($site, SiteBackup::KIND_FILES, sprintf('2026-09-%02d 00:00:00', $day));
        }
        recordCheck($site, true);

        $report = compileSeptember($site);

        expect($report['data']['backups'])->toMatchArray(['status' => 'verified', 'covers_period' => true, 'daily' => true, 'days_with_backups' => 16, 'period_days' => 30])
            ->and($report['reasons'])->toContain('Backed up on only 16 of 30 days this month.')
            // The client still reads the plain count, never a promise of daily backups.
            ->and($report['html'])->toContain('backed up on <strong>16 days</strong> this month')->not->toContain('daily');
    });

    it('does not hold a weekly SpinupWP schedule or a month missing only a few days', function () {
        $weekly = accuracySite(['hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP]);
        foreach (['2026-08-30', '2026-09-06', '2026-09-13', '2026-09-20', '2026-09-27'] as $at) {
            SiteBackup::create([
                'site_id' => $weekly->id, 'source' => SiteBackup::SOURCE_SPINUPWP_SPACES, 'kind' => SiteBackup::KIND_FULL,
                'external_ref' => $at, 'backup_at' => Carbon::parse($at.' 08:00'), 'bytes' => 500 * 1024 ** 2,
                'is_manual' => false, 'first_seen_at' => now(), 'last_seen_at' => now(),
            ]);
        }
        SiteBackupCheck::create(['site_id' => $weekly->id, 'source' => SiteBackup::SOURCE_SPINUPWP_SPACES, 'checked_at' => now(), 'ok' => true]);

        $nearlyEveryDay = pressableBackupSite();
        foreach (range(1, 30) as $day) {
            if (! in_array($day, [5, 12, 19], true)) {
                recordBackup($nearlyEveryDay, SiteBackup::KIND_FILES, sprintf('2026-09-%02d 00:00:00', $day));
            }
        }
        recordCheck($nearlyEveryDay, true);

        $weeklyReport = compileSeptember($weekly);
        expect($weeklyReport['data']['backups'])->toMatchArray(['status' => 'verified', 'daily' => false, 'days_with_backups' => 4])
            ->and(implode(' ', $weeklyReport['reasons']))->not->toContain('Backed up on only')
            ->and(implode(' ', compileSeptember($nearlyEveryDay)['reasons']))->not->toContain('Backed up on only');
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

describe('backups from our relay that keeps failing', function () {
    /** A standalone site whose off-site backups come from our relay (S3 archives). */
    function failingRelaySite(array $attributes = []): Site
    {
        return accuracySite(array_merge([
            'hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM, 'server_id' => null, 'backup_relay_enabled' => true,
        ], $attributes));
    }

    function failingRelayArchive(Site $site, string $at): void
    {
        SiteBackup::create([
            'site_id' => $site->id, 'source' => SiteBackup::SOURCE_S3_ARCHIVE, 'kind' => SiteBackup::KIND_FULL,
            'external_ref' => 'archive-'.$at, 'backup_at' => Carbon::parse($at), 'bytes' => 80 * 1024 ** 2,
            'is_manual' => false, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    it('reports "failing" with the relay\'s error, hidden from the client and held for review', function () {
        $site = failingRelaySite([
            'backup_relay_failing_since' => Carbon::parse('2026-09-20 05:10:00'),
            'backup_relay_last_attempt_at' => Carbon::parse('2026-10-02 05:10:00'),
            'backup_relay_last_error' => "BackupRelay: example.com: upload to https://storage.example.com/bucket/site.tar.gz?X-Amz-Signature=abc123&X-Amz-Credential=key failed\n with HTTP 403",
        ]);
        SiteBackupCheck::create(['site_id' => $site->id, 'source' => SiteBackup::SOURCE_S3_ARCHIVE, 'checked_at' => now(), 'ok' => true]);

        $report = compileSeptember($site);
        $backups = $report['data']['backups'];

        expect($backups)->toMatchArray([
            'source' => SiteBackup::SOURCE_S3_ARCHIVE, 'status' => 'failing', 'verified' => false, 'days_with_backups' => 0,
            'relay_failing_since' => '2026-09-20T05:10:00+00:00',
            'relay_error' => 'BackupRelay: example.com: upload to https://storage.example.com/bucket/site.tar.gz failed with HTTP 403',
        ])
            ->and(json_encode($report['data']))->not->toContain('X-Amz')->not->toContain('abc123')
            ->and($report['reasons'])->toContain('Off-site backups have been failing since Sep 20, 2026: BackupRelay: example.com: upload to https://storage.example.com/bucket/site.tar.gz failed with HTTP 403.')
            ->and($report['html'])->not->toContain('backed up')->not->toContain('BackupRelay')
            ->and($report['text'])->not->toContain('BACKUPS')->not->toContain('BackupRelay')
            ->and($report['preview'])->toContain('Backups hidden from the client: no off-site archive this period; the backup relay has been failing since Sep 20, 2026.')
            ->toContain('>Failing</span>')
            ->toContain('Last relay error: BackupRelay: example.com')
            ->and($report['public'])->not->toContain('Failing')->not->toContain('BackupRelay')->not->toContain('Backups');
    });

    it('keeps no more than about 200 characters of the error', function () {
        $site = failingRelaySite(['backup_relay_failing_since' => Carbon::parse('2026-09-01 05:00:00'), 'backup_relay_last_error' => str_repeat('Connection reset by peer. ', 40)]);

        $error = compileSeptember($site)['data']['backups']['relay_error'];

        expect(mb_strlen($error))->toBeLessThanOrEqual(ClientReportCompiler::RELAY_ERROR_MAX_LENGTH + 3)
            ->and($error)->toStartWith('Connection reset by peer.')->toEndWith('...');
    });

    it('says nothing about a relay that only started failing after the period', function () {
        $site = failingRelaySite(['backup_relay_failing_since' => Carbon::parse('2026-10-02 05:00:00'), 'backup_relay_last_error' => 'Example timeout']);
        SiteBackupCheck::create(['site_id' => $site->id, 'source' => SiteBackup::SOURCE_S3_ARCHIVE, 'checked_at' => now(), 'ok' => true]);

        $report = compileSeptember($site);

        expect($report['data']['backups'])->toMatchArray(['status' => 'none_found', 'relay_failing_since' => null, 'relay_error' => null])
            ->and(implode(' ', $report['reasons']))->not->toContain('failing');
    });

    it('names the failure when relay archives stopped part-way through the period', function () {
        $site = failingRelaySite(['backup_relay_failing_since' => Carbon::parse('2026-09-12 05:00:00'), 'backup_relay_last_error' => 'Example lacks the backup-create capability.']);
        foreach (range(1, 11) as $day) {
            failingRelayArchive($site, sprintf('2026-09-%02d 05:00:00', $day));
        }

        $report = compileSeptember($site);

        expect($report['data']['backups']['status'])->toBe('stale')
            ->and($report['reasons'])->toContain('Backups stopped before the last 10 days of the month. Off-site backups have been failing since Sep 12, 2026: Example lacks the backup-create capability.');
    });

    it('still shows archives made in the last days of the period, even if the relay fails now', function () {
        $site = failingRelaySite(['backup_relay_failing_since' => Carbon::parse('2026-09-30 05:00:00'), 'backup_relay_last_error' => 'Example timeout']);
        foreach (range(1, 29) as $day) {
            failingRelayArchive($site, sprintf('2026-09-%02d 05:00:00', $day));
        }
        failingRelayArchive($site, '2026-08-31 05:00:00');

        $report = compileSeptember($site);

        expect($report['data']['backups'])->toMatchArray(['status' => 'verified', 'verified' => true, 'days_with_backups' => 29])
            ->and(implode(' ', $report['reasons']))->not->toContain('failing');
    });

    it('ignores relay state on a site whose backups come from its host', function () {
        $site = pressableBackupSite();
        $site->update(['backup_relay_failing_since' => Carbon::parse('2026-09-05'), 'backup_relay_last_error' => 'Example timeout']);
        recordCheck($site, true);

        expect(compileSeptember($site->fresh())['data']['backups'])->toMatchArray(['status' => 'none_found', 'relay_failing_since' => null]);
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

    it('leaves the row out of both emails on a host with no way to run the check, and says why only in the preview', function () {
        // A custom host: no server SSH, no Pressable API, no command runner.
        $site = accuracySite(['hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM, 'server_id' => null, 'spinupwp_id' => null]);

        $report = compileSeptember($site);

        expect($report['data']['security'])->toMatchArray(['checksum_supported' => false, 'checksum_status' => null])
            ->and($report['html'])->not->toContain('WordPress core files')
            ->and($report['text'])->not->toContain('WordPress core files')
            ->and($report['preview'])->toContain('Not checked (no checksum path for this host) — hidden from the client')
            ->and($report['public'])->not->toContain('WordPress Core Files')->not->toContain('no checksum path')
            ->and(implode(' ', $report['reasons']))->not->toContain('core-file');
    });

    it('holds the report when the host can run the check but none ran this month', function () {
        $report = compileSeptember(accuracySite());

        expect($report['data']['security']['checksum_supported'])->toBeTrue()
            ->and($report['html'])->toContain('WordPress core files')->toContain('Not checked this month')
            ->and($report['reasons'])->toContain('No WordPress core-file check ran this month, although this host supports one, so the client would read "Not checked this month".');
    });

    it('knows which hosts can run the check', function (Closure $makeSite, bool $supported) {
        expect(compileSeptember($makeSite())['data']['security']['checksum_supported'])->toBe($supported);
    })->with([
        'server with SSH' => [fn () => accuracySite(), true],
        'Pressable' => [fn () => Site::factory()->pressable()->create(['is_wordpress' => true, 'uptime_last_checked_at' => now()]), true],
        'SSH host with no server' => [fn () => accuracySite(['server_id' => null]), false],
        'SSH host on an ignored server' => [fn () => accuracySite(['server_id' => Server::factory()->create(['is_ignored' => true])->id]), false],
        'custom host' => [fn () => accuracySite(['hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM, 'server_id' => null]), false],
        'not WordPress' => [fn () => accuracySite(['is_wordpress' => false, 'companion_snapshot' => null]), false],
    ]);

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

    it('says "Partial scan", never "No malware found", when the latest clean scan stopped at its limit', function () {
        $site = accuracySite();
        $scan = companionMalwareScan($site, '2026-09-28', SiteSecurityScan::STATUS_CLEAN, [], ['scan_aborted' => true, 'abort_reason' => 'max_files_visited', 'scanned_files_count' => 75000]);

        $report = compileSeptember($site);

        expect($report['data']['security'])->toMatchArray(['malware_status' => 'incomplete', 'malware_scan_id' => $scan->id, 'malware_scan_aborted' => true])
            ->and($report['html'])->toContain('Partial scan')->not->toContain('No malware found')
            ->and($report['text'])->toContain('Malware scan: Partial scan')
            ->and($report['preview'])->toContain('Partial scan')->not->toContain('No malware found')
            ->and($report['reasons'])->toContain("The latest malware scan (scan #{$scan->id}) stopped before it finished (limit: max_files_visited, 75,000 files checked), so the client sees \"Partial scan\" instead of an all-clear.");
    });

    it('counts a clean SSH scan that wasn\'t cut short as complete, although SSH never counts files', function () {
        $site = accuracySite();
        companionMalwareScan($site, '2026-09-28', SiteSecurityScan::STATUS_CLEAN, [], ['transport' => 'ssh', 'scanned_files_count' => 0, 'truncated_checks' => []]);

        $report = compileSeptember($site);

        expect($report['data']['security'])->toMatchArray(['malware_status' => 'clean', 'malware_transport' => 'ssh', 'malware_truncated_checks' => []])
            ->and($report['html'])->toContain('No malware found')
            ->and($report['text'])->not->toContain('Partial scan')
            ->and(implode(' ', $report['reasons']))->not->toContain('malware');
    });

    it('says "Partial scan" for a clean SSH scan that hit its result limit', function () {
        $site = accuracySite();
        $scan = companionMalwareScan($site, '2026-09-28', SiteSecurityScan::STATUS_CLEAN, [], [
            'transport' => 'ssh', 'scanned_files_count' => 0, 'scan_aborted' => true, 'abort_reason' => 'ssh_result_limit', 'truncated_checks' => ['php_in_uploads'],
        ]);

        $report = compileSeptember($site);

        expect($report['data']['security'])->toMatchArray(['malware_status' => 'incomplete', 'malware_truncated_checks' => ['php_in_uploads']])
            ->and($report['text'])->toContain('Malware scan: Partial scan')
            ->and($report['reasons'])->toContain("The latest malware scan (scan #{$scan->id}) ran over SSH and hit its result limit (php_in_uploads); files past the limit were not checked, so the client sees \"Partial scan\" instead of an all-clear.");
    });

    it('keeps an SSH scan recorded before cut-short scans were noticed as partial', function () {
        // Those rows always said scan_aborted=false and have no truncated_checks.
        $site = accuracySite();
        $scan = companionMalwareScan($site, '2026-09-28', SiteSecurityScan::STATUS_CLEAN, [], ['transport' => 'ssh', 'scanned_files_count' => 0]);

        $report = compileSeptember($site);

        expect($report['data']['security'])->toMatchArray(['malware_status' => 'incomplete', 'malware_truncated_checks' => null])
            ->and($report['text'])->toContain('Malware scan: Partial scan')
            ->and($report['reasons'])->toContain("The latest malware scan (scan #{$scan->id}) ran over SSH before scans recorded whether they were cut short; it can't vouch for the whole site, so the client sees \"Partial scan\" instead of an all-clear.");
    });

    it('reads a real SSH scan of a SpinupWP-style server as complete in the next report', function () {
        // End to end: the scanner's own SSH result, recorded, then compiled.
        $server = Server::factory()->create(['ssh_password' => 'not-empty']);
        $site = accuracySite(['server_id' => $server->id, 'companion_installed' => false, 'companion_secret' => null, 'wp_path' => '/sites/ssh.example.com/files']);
        $this->mock(SshClient::class, fn ($mock) => $mock->shouldReceive('exec')->andReturn(''));
        $this->travelTo(Carbon::parse('2026-09-28 06:00:00'));
        app(SecurityScanRecorder::class)->record(app(CompanionMalwareScanner::class)->scanSite($site));
        $this->travelBack();

        $scan = SiteSecurityScan::query()->where('site_id', $site->id)->where('scan_type', SiteSecurityScan::TYPE_COMPANION_MALWARE)->sole();
        expect($scan->details)->toMatchArray(['transport' => 'ssh', 'scan_aborted' => false, 'truncated_checks' => []]);

        expect(compileSeptember($site)['data']['security']['malware_status'])->toBe('clean');
    });

    it('keeps a finished clean scan as "No malware found", and a partial scan that flagged files as flagged', function () {
        $clean = accuracySite();
        companionMalwareScan($clean, '2026-09-28', SiteSecurityScan::STATUS_CLEAN);
        $flagged = accuracySite();
        companionMalwareScan($flagged, '2026-09-28', SiteSecurityScan::STATUS_WARNING, [['kind' => 'php_in_uploads', 'path' => 'wp-content/uploads/example/a.php', 'evidence' => 'x']], ['scan_aborted' => true, 'abort_reason' => 'max_files_visited']);

        $cleanReport = compileSeptember($clean);
        $flaggedReport = compileSeptember($flagged);

        expect($cleanReport['data']['security']['malware_status'])->toBe('clean')
            ->and($cleanReport['html'])->toContain('No malware found')
            ->and(implode(' ', $cleanReport['reasons']))->not->toContain('malware')
            ->and($flaggedReport['data']['security']['malware_status'])->toBe('warning')
            ->and($flaggedReport['html'])->toContain('Issues flagged');
    });

    it('keeps the stored status of a SiteCheck scan standing in for Companion', function () {
        $site = accuracySite();
        SiteSecurityScan::factory()->create(['site_id' => $site->id, 'scan_type' => SiteSecurityScan::TYPE_SITECHECK, 'scanned_at' => Carbon::parse('2026-09-18'), 'status' => SiteSecurityScan::STATUS_CLEAN, 'details' => ['scan' => [], 'site' => []]]);

        $security = compileSeptember($site)['data']['security'];

        expect($security)->toMatchArray(['malware_status' => 'clean', 'malware_finding_kinds' => null, 'malware_worst_in_period' => null]);
    });

    it('names the finding kinds and the scan for the reviewer, and never stores the paths', function () {
        $site = accuracySite();
        $findings = [
            ...array_map(fn (int $n) => ['kind' => 'php_in_uploads', 'path' => "wp-content/uploads/example-cache/file-{$n}.php", 'evidence' => 'PHP file under uploads'], range(1, 4)),
            ['kind' => 'wp_config_recently_modified', 'path' => 'wp-config.php', 'evidence' => 'mtime within 30 days'],
        ];
        $scan = companionMalwareScan($site, '2026-09-28', SiteSecurityScan::STATUS_WARNING, $findings);

        $report = compileSeptember($site);

        expect($report['data']['security'])->toMatchArray([
            'malware_status' => 'warning',
            'malware_scan_id' => $scan->id,
            'malware_finding_kinds' => ['php_in_uploads' => 4, 'wp_config_recently_modified' => 1],
        ])
            ->and(json_encode($report['data']))->not->toContain('example-cache')->not->toContain('wp-config.php')
            ->and($report['reasons'])->toContain("The malware scan flagged files to review: php_in_uploads ×4, wp_config_recently_modified ×1 (scan #{$scan->id}).")
            // The client wording is unchanged.
            ->and($report['text'])->toContain('Malware scan: Issues flagged');
    });

    it('holds a report whose earlier scans found malware even though the latest one is clean', function () {
        $site = accuracySite();
        $signature = [['kind' => 'eval_base64', 'path' => 'wp-content/themes/example/x.php', 'evidence' => 'Substring match']];
        companionMalwareScan($site, '2026-09-05 03:00', SiteSecurityScan::STATUS_ISSUES_FOUND, $signature);
        companionMalwareScan($site, '2026-09-06 03:00', SiteSecurityScan::STATUS_ISSUES_FOUND, $signature, ['scan_aborted' => true, 'abort_reason' => 'max_files_visited']);
        companionMalwareScan($site, '2026-09-06 15:00', SiteSecurityScan::STATUS_ISSUES_FOUND, $signature);
        companionMalwareScan($site, '2026-09-20 03:00', SiteSecurityScan::STATUS_CLEAN);

        $report = compileSeptember($site);

        expect($report['data']['security'])->toMatchArray([
            'malware_status' => 'clean',
            'malware_worst_in_period' => 'issues_found',
            'malware_last_issue_at' => '2026-09-06',
            'malware_issue_days' => 2,
        ])
            ->and($report['reasons'])->toContain('An earlier malware scan this month flagged malware (on 2 days, last on 2026-09-06), but the latest scan did not: check it was cleaned up.');
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

    // Was "shows a real 0 when a blocker reports to Control": an active blocker
    // alone isn't evidence that its lockouts reach us (it can be switched off,
    // or the site left out of every pull), so it no longer yields a 0.
    it('leaves the count out when a blocker is installed but nothing shows its lockouts reach Control', function () {
        $report = compileSeptember(accuracySite(['companion_snapshot' => llarSnapshot()]));

        expect($report['data']['security']['blocked_threats_count'])->toBeNull()
            ->and($report['html'])->not->toContain('Threats blocked')->not->toContain('Malicious visitors blocked')
            ->not->toContain('block attackers')
            ->and($report['text'])->not->toContain('Malicious visitors blocked')
            ->and($report['preview'])->toContain('Not measured (no blocker reports to Control for this site this period)');
    });

    it('shows a real 0, blocked at the server, when the server has a jail and its lockouts were pulled', function () {
        $server = Server::factory()->create(['clockwork_jail_provisioned_at' => Carbon::parse('2026-08-01'), 'last_llar_pull_at' => now()]);
        $report = compileSeptember(accuracySite(['server_id' => $server->id, 'companion_snapshot' => llarSnapshot()]));

        expect($report['data']['security'])->toMatchArray(['blocked_threats_count' => 0, 'blocks_at_server' => true])
            ->and($report['html'])->toContain('block attackers at the server')
            ->and($report['text'])->toContain('Malicious visitors blocked: 0');
    });

    it('does not count a jail whose lockouts were last pulled before the period', function () {
        $server = Server::factory()->create(['clockwork_jail_provisioned_at' => Carbon::parse('2026-08-01'), 'last_llar_pull_at' => Carbon::parse('2026-08-20')]);

        expect(compileSeptember(accuracySite(['server_id' => $server->id, 'companion_snapshot' => llarSnapshot()]))['data']['security']['blocked_threats_count'])->toBeNull();
    });

    it('shows a real 0 without claiming the server when lockouts from a serverless site reached the review queue', function () {
        $site = accuracySite(['hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM, 'server_id' => null, 'companion_snapshot' => llarSnapshot()]);
        ReviewQueueEntry::factory()->create(['site_id' => $site->id, 'source' => ReviewQueueEntry::SOURCE_LLAR, 'created_at' => Carbon::parse('2026-09-14'), 'updated_at' => Carbon::parse('2026-09-14')]);

        $report = compileSeptember($site);

        expect($report['data']['security'])->toMatchArray(['blocked_threats_count' => 0, 'blocks_at_server' => false])
            ->and($report['html'])->toContain('block malicious visitors')->not->toContain('at the server')
            ->and($report['text'])->toContain('Malicious visitors blocked: 0');
    });

    it('counts real bans in the period whatever else is known', function () {
        $site = accuracySite();
        BlockedIp::factory()->count(2)->create(['site_id' => $site->id, 'banned_at' => Carbon::parse('2026-09-10')]);
        BlockedIp::factory()->create(['site_id' => $site->id, 'banned_at' => Carbon::parse('2026-08-10')]);

        expect(compileSeptember($site)['data']['security'])->toMatchArray(['blocked_threats_count' => 2, 'blocks_at_server' => false]);
    });
});

describe('traffic', function () {
    /**
     * One row per day of September from $first to $last, each written early
     * on the day after it (as the hourly rollup does).
     *
     * @param  array<string, mixed>  $row
     */
    function recordTraffic(Site $site, int $first = 1, int $last = 30, array $row = []): void
    {
        foreach (range($first, $last) as $day) {
            $date = Carbon::parse(sprintf('2026-09-%02d', $day));
            DB::table('site_traffic_daily')->insert(array_replace([
                'site_id' => $site->id, 'date' => $date->toDateString(), 'requests' => 5000, 'visits' => 80, 'bytes_sent' => 0,
                'top_ips' => null, 'created_at' => $date->copy()->addDay()->setTime(5, 0), 'updated_at' => $date->copy()->addDay()->setTime(5, 0),
            ], $row));
        }
    }

    /** A site whose server we can read access logs from. */
    function trafficSite(): Site
    {
        return accuracySite(['server_id' => Server::factory()->create(['last_ssh_ok_at' => now()])->id]);
    }

    it('leaves out data transferred when the host reports no byte counts', function () {
        $site = accuracySite();
        recordTraffic($site);

        $report = compileSeptember($site);

        expect($report['data']['traffic'])->toMatchArray([
            'has_traffic' => true, 'total_requests' => 150000, 'bandwidth_mb' => null,
            'status' => 'ok', 'source' => 'nginx', 'days_covered' => 30, 'period_days' => 30, 'last_day_complete' => true,
        ])
            ->and($report['html'])->toContain('Visits')->not->toContain('Data transferred')
            ->and($report['preview'])->toContain('Recorded on 30 of 30 days, from the server&#039;s access logs.')
            ->and($report['public'])->not->toContain('Recorded on 30 of 30 days')
            ->and(implode(' ', $report['reasons']))->not->toContain('Traffic');
    });

    it('hides traffic recorded on only some days of the month, and holds the report', function () {
        $site = accuracySite();
        recordTraffic($site, 10, 10);

        $report = compileSeptember($site);

        expect($report['data']['traffic'])->toMatchArray(['has_traffic' => true, 'total_requests' => 5000, 'status' => 'partial', 'days_covered' => 1, 'period_days' => 30])
            ->and($report['html'])->not->toContain('Visits')
            ->and($report['text'])->not->toContain('TRAFFIC')
            ->and($report['preview'])->not->toContain('Traffic Analytics')
            ->toContain('Traffic hidden from the client: traffic was recorded on only 1 of 30 days.')
            ->and($report['reasons'])->toContain('Traffic was recorded on only 1 of 30 days, so the Traffic section was left out.');
    });

    it('allows one missing day, but not a missing or unfinished last day', function () {
        $missingMidMonth = accuracySite();
        recordTraffic($missingMidMonth, 1, 14);
        recordTraffic($missingMidMonth, 16, 30);

        $missingLastDay = accuracySite();
        recordTraffic($missingLastDay, 1, 29);

        // The host's feed wrote the last day before it was over.
        $unfinishedLastDay = accuracySite();
        recordTraffic($unfinishedLastDay, 1, 29);
        recordTraffic($unfinishedLastDay, 30, 30, ['updated_at' => Carbon::parse('2026-09-30 06:37')]);

        expect(compileSeptember($missingMidMonth)['data']['traffic'])->toMatchArray(['status' => 'ok', 'days_covered' => 29]);

        foreach ([$missingLastDay, $unfinishedLastDay] as $site) {
            $report = compileSeptember($site);
            expect($report['data']['traffic'])->toMatchArray(['status' => 'partial', 'last_day_complete' => false])
                ->and($report['reasons'])->toContain("The period's last day of traffic wasn't complete when the report was compiled, so the Traffic section was left out.");
        }
    });

    it('does not count days the feed had nothing for', function () {
        $site = accuracySite();
        recordTraffic($site, 1, 15);
        recordTraffic($site, 16, 30, ['requests' => 0, 'visits' => 0]);

        expect(compileSeptember($site)['data']['traffic'])->toMatchArray(['status' => 'partial', 'days_covered' => 15]);
    });

    it('treats access logs that see two IP addresses as a proxy, not visitors', function () {
        $site = accuracySite();
        recordTraffic($site, row: ['requests' => 1000, 'visits' => 2, 'top_ips' => json_encode([
            ['ip' => '10.0.0.4', 'hits' => 480], ['ip' => '10.0.0.5', 'hits' => 470], ['ip' => '203.0.113.9', 'hits' => 50],
        ])]);

        $report = compileSeptember($site);

        expect($report['data']['traffic'])->toMatchArray(['status' => 'suspect', 'top_ip_share' => 0.95])
            ->and($report['html'])->not->toContain('Visits')
            ->and($report['reasons'])->toContain('95% of requests came from two IP addresses, which looks like a proxy or gateway in front of the site rather than visitors, so the Traffic section was left out.');
    });

    it('does not judge a host feed by its IP list, and keeps real spread-out visitors', function () {
        $ips = json_encode([['ip' => '198.51.100.1', 'hits' => 900], ['ip' => '198.51.100.2', 'hits' => 90]]);
        $pressable = accuracySite(['hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE, 'pressable_site_id' => '4242', 'server_id' => null]);
        recordTraffic($pressable, row: ['requests' => 1000, 'top_ips' => $ips]);
        $spread = accuracySite();
        recordTraffic($spread, row: ['requests' => 1000, 'top_ips' => json_encode([['ip' => '198.51.100.1', 'hits' => 300], ['ip' => '198.51.100.2', 'hits' => 200]])]);

        expect(compileSeptember($pressable)['data']['traffic'])->toMatchArray(['status' => 'ok', 'source' => 'pressable', 'top_ip_share' => 0.99])
            ->and(compileSeptember($spread)['data']['traffic'])->toMatchArray(['status' => 'ok', 'top_ip_share' => 0.5]);
    });

    it('treats a busy site with almost no visits as suspect', function () {
        $site = accuracySite();
        recordTraffic($site, row: ['requests' => 70000, 'visits' => 1]);

        $report = compileSeptember($site);

        expect($report['data']['traffic'])->toMatchArray(['status' => 'suspect', 'top_ip_share' => null])
            ->and($report['reasons'])->toContain("30 visits for 2,100,000 requests doesn't look like real visitors, so the Traffic section was left out.");
    });

    it('holds a report with no traffic from a host we collect it from, but not from one we don\'t', function () {
        $collected = compileSeptember(trafficSite());
        $notCollected = compileSeptember(accuracySite(['hosting_provider' => Site::HOSTING_PROVIDER_CUSTOM, 'server_id' => null]));

        expect($collected['data']['traffic'])->toMatchArray(['has_traffic' => false, 'status' => 'no_data', 'days_covered' => 0])
            ->and($collected['reasons'])->toContain("No traffic was recorded for this period, although this host's traffic is collected, so the Traffic section was left out.")
            ->and($notCollected['data']['traffic'])->toMatchArray(['has_traffic' => false, 'status' => 'unsupported'])
            ->and(implode(' ', $notCollected['reasons']))->not->toContain('Traffic')
            ->and($notCollected['preview'])->toContain('Traffic hidden from the client: traffic isn&#039;t collected for this host.');
    });

    it('reports traffic that exists even when the host can no longer be read', function () {
        // The factory server has no SSH credentials, so the host reads as unsupported now.
        $site = accuracySite();
        recordTraffic($site);

        expect($site->supportsTrafficReport())->toBeFalse()
            ->and(compileSeptember($site)['data']['traffic']['status'])->toBe('ok');
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
    it('counts runs recorded as "success" as reported sent', function () {
        // Regression: the compiler looked for status "ok", which is never
        // written, so every tested site was told "0% delivered". Only the
        // site's own "sent" is measured, so the wording claims no more.
        $site = accuracySite();
        ContactFormTestRun::factory()->count(3)->create(['site_id' => $site->id, 'ran_at' => Carbon::parse('2026-09-05'), 'status' => ContactFormTestRun::STATUS_SUCCESS]);
        ContactFormTestRun::factory()->create(['site_id' => $site->id, 'ran_at' => Carbon::parse('2026-09-06'), 'status' => ContactFormTestRun::STATUS_FAILED]);

        $report = compileSeptember($site);

        expect($report['data']['forms'])->toMatchArray(['total_synthetic_tests' => 4, 'successful_tests' => 3, 'pass_rate' => 75.0])
            ->and($report['html'])->toContain('your site reported sending the notification email for')->toContain('75%</strong> of them.')
            ->not->toContain('% delivered')
            ->and($report['text'])->toContain('4 live test submissions, notification email reported sent for 75% (inbox delivery not checked)');
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

    it('counts a lab run as accepted only when the form took the submission, and holds the report when one did not', function () {
        $site = accuracySite();
        $lab = ['site_id' => $site->id, 'ran_at' => Carbon::parse('2026-09-07'), 'mode' => ContactFormTestRun::MODE_LAB, 'mail_outcome' => ContactFormTestRun::MAIL_SUPPRESSED];
        ContactFormTestRun::factory()->count(2)->create($lab + ['accepted' => true, 'status' => ContactFormTestRun::STATUS_SUCCESS]);
        // Companion answered, but the form refused the submission.
        ContactFormTestRun::factory()->create($lab + ['accepted' => false, 'status' => ContactFormTestRun::STATUS_SUCCESS]);

        $report = compileSeptember($site);

        expect($report['data']['forms'])->toMatchArray(['lab_tests' => 3, 'lab_accepted' => 2])
            ->and($report['html'])->toContain('accepted <strong>2 of 3</strong>')
            ->and($report['reasons'])->toContain('Contact-form checks failed: the forms accepted 2 of 3 automated check submissions this month.');
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
    function uptimeEvent(Site $site, string $type, string $at, bool $exempt = false, ?int $status = null): void
    {
        SiteUptimeEvent::create(['site_id' => $site->id, 'event_type' => $type, 'event_at' => Carbon::parse($at), 'is_sla_exempt' => $exempt, 'status_code' => $status]);
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
        // Shown in the report timezone (clockwork.reports.timezone), not UTC.
        expect(compileSeptember($site)['html'])->toContain('Down Sep 10, 6:00 AM EDT')->toContain('4h 0m')->not->toContain('UTC');
    });

    it('counts an outage that started before the period from the start of the period', function () {
        // The period is September's days in the report timezone (EDT): Sep 1
        // begins at 04:00 UTC.
        $site = accuracySite(['uptime_monitoring_enabled' => true]);
        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-01 03:00:00');
        uptimeEvent($site, SiteUptimeEvent::TYPE_UP, '2026-09-01 05:30:00');

        $report = compileSeptember($site);

        expect($report['data']['uptime'])->toMatchArray(['outages_count' => 1, 'downtime_minutes' => 90])
            ->and($report['data']['uptime']['outages'][0]['started_before_period'])->toBeTrue()
            // Its start is clamped to the period start, which isn't when it began, so no time is given.
            ->and($report['html'])->toContain('Down since before Sep 1<')->not->toContain('12:00 AM')
            ->and($report['text'])->toContain('- Down since before Sep 1 for 90 min');
    });

    it('runs an outage still open at the end of the period to the end', function () {
        // Sep 30 ends at midnight EDT, 04:00 UTC on Oct 1.
        $site = accuracySite(['uptime_monitoring_enabled' => true]);
        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-10-01 02:00:00');

        $report = compileSeptember($site);

        expect($report['data']['uptime']['downtime_minutes'])->toBe(120)
            ->and($report['data']['uptime']['outages'][0]['ongoing'])->toBeTrue()
            ->and($report['text'])->toContain('- Down Sep 30, 10:00 PM EDT for 120 min (still down at period end)');
    });

    it('holds the report when a real outage is still open at the end of the period', function () {
        $site = accuracySite(['uptime_monitoring_enabled' => true]);
        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-10-01 02:00:00', status: 503);

        $report = compileSeptember($site);

        expect($report['data']['uptime'])->toMatchArray(['outages_count' => 1, 'downtime_minutes' => 120, 'probe_blocked_minutes' => 0, 'probe_blocked_since' => null])
            ->and(ReportReviewFlags::reasons($report['data']))->toContain('The site was still down at the end of the month.')
            ->and(implode(' ', ReportReviewFlags::reasons($report['data'])))->not->toContain('HTTP 429');
    });

    it('does not count time the monitor was rate-limited (HTTP 429) as downtime', function () {
        // A host that rate-limits the monitor answers 429: the probe was
        // blocked, which says nothing about whether visitors reached the site.
        $site = accuracySite(['uptime_monitoring_enabled' => true]);
        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-12 08:00:00', status: 429);
        uptimeEvent($site, SiteUptimeEvent::TYPE_UP, '2026-09-12 20:00:00', status: 200);
        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-20 10:00:00', status: 502);
        uptimeEvent($site, SiteUptimeEvent::TYPE_UP, '2026-09-20 10:30:00', status: 200);

        $report = compileSeptember($site);
        $uptime = $report['data']['uptime'];

        expect($uptime)->toMatchArray([
            'outages_count' => 1,
            'downtime_minutes' => 30,
            'uptime_percentage' => 99.93,
            'probe_blocked_minutes' => 720,
            'probe_blocked_since' => null,
        ])
            ->and($uptime['outages'])->toHaveCount(1)
            ->and($uptime['outages'][0])->toMatchArray(['started_at' => '2026-09-20T10:00:00+00:00', 'minutes' => 30])
            ->and($report['html'])->not->toContain('Down Sep 12')->not->toContain('12h 0m')
            ->and($report['text'])->not->toContain('Sep 12')
            ->and(ReportReviewFlags::reasons(['meta' => $report['data']['meta'], 'uptime' => $uptime]))->toBe([]);
    });

    it('says the monitor was blocked, not that the site was still down, when a 429 is open at the end of the period', function () {
        $site = accuracySite(['uptime_monitoring_enabled' => true]);
        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-30 23:40:00', status: 429);

        $report = compileSeptember($site);
        $preview = view('client-reports::reports.show', ['report' => ClientReport::latest('id')->first(), 'isOperatorPreview' => true])->render();
        $public = view('client-reports::reports.show', ['report' => ClientReport::latest('id')->first(), 'isOperatorPreview' => false])->render();

        expect($report['data']['uptime'])->toMatchArray([
            'outages_count' => 0,
            'downtime_minutes' => 0,
            'uptime_percentage' => 100,
            'outages' => [],
            'probe_blocked_minutes' => 260,
            'probe_blocked_since' => '2026-09-30T23:40:00+00:00',
        ])
            ->and($report['html'])->not->toContain('still down')
            ->and($report['text'])->not->toContain('still down')
            ->and(ReportReviewFlags::reasons(['meta' => $report['data']['meta'], 'uptime' => $report['data']['uptime']]))->toBe([
                'The uptime monitor was blocked (HTTP 429) from Sep 30, 7:40 PM EDT; the site was not necessarily down.',
            ])
            // The blocked time is for the operator only.
            ->and($preview)->toContain('The uptime monitor was blocked (HTTP 429) for 260 min this period.')
            ->and($public)->not->toContain('HTTP 429');
    });

    it('treats a 429 block carried in from before the period as blocked, not down', function () {
        $site = accuracySite(['uptime_monitoring_enabled' => true]);
        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-01 02:00:00', status: 429);
        uptimeEvent($site, SiteUptimeEvent::TYPE_UP, '2026-09-01 07:00:00', status: 200);

        expect(compileSeptember($site)['data']['uptime'])->toMatchArray([
            'outages_count' => 0, 'downtime_minutes' => 0, 'outages' => [], 'probe_blocked_minutes' => 180, 'probe_blocked_since' => null,
        ]);
    });

    describe('the monitor\'s probe_blocked events', function () {
        it('never counts a probe_blocked stretch as downtime', function () {
            $site = accuracySite(['uptime_monitoring_enabled' => true]);
            uptimeEvent($site, SiteUptimeEvent::TYPE_PROBE_BLOCKED, '2026-09-12 08:00:00', status: 429);
            uptimeEvent($site, SiteUptimeEvent::TYPE_UP, '2026-09-12 20:00:00', status: 200);

            $report = compileSeptember($site);

            expect($report['data']['uptime'])->toMatchArray([
                'outages_count' => 0, 'downtime_minutes' => 0, 'uptime_percentage' => 100, 'outages' => [],
                'probe_blocked_minutes' => 720, 'probe_blocked_since' => null,
            ])
                ->and($report['html'])->not->toContain('Down Sep 12')
                ->and(implode(' ', $report['reasons']))->not->toContain('down')->not->toContain('HTTP 429');
        });

        it('ends an outage where the site starts answering 429, and counts the block apart (down → probe_blocked → up)', function () {
            $site = accuracySite(['uptime_monitoring_enabled' => true]);
            uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-15 10:00:00', status: 502);
            uptimeEvent($site, SiteUptimeEvent::TYPE_PROBE_BLOCKED, '2026-09-15 10:40:00', status: 429);
            uptimeEvent($site, SiteUptimeEvent::TYPE_UP, '2026-09-15 14:40:00', status: 200);

            $uptime = compileSeptember($site)['data']['uptime'];

            expect($uptime)->toMatchArray(['outages_count' => 1, 'downtime_minutes' => 40, 'probe_blocked_minutes' => 240, 'probe_blocked_since' => null])
                ->and($uptime['outages'])->toBe([['started_at' => '2026-09-15T10:00:00+00:00', 'minutes' => 40, 'started_before_period' => false, 'ongoing' => false]]);
        });

        it('reads the same from events the real uptime state machine wrote', function () {
            $site = accuracySite(['uptime_monitoring_enabled' => true, 'uptime_state' => 'down', 'uptime_consecutive_failures' => 2, 'uptime_down_since' => Carbon::parse('2026-09-15 10:00:00')]);
            uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-15 10:00:00', status: 502);
            $chat = $this->mock(ChatNotifier::class);
            $chat->shouldNotReceive('siteWentUp');
            $chat->shouldNotReceive('siteWentDown');
            $chat->shouldReceive('uptimeProbeBlocked')->once()->andReturn(true);
            $this->mock(SmsNotifier::class)->shouldNotReceive('siteWentUp');

            // A down site that then answers only 429 for the configured six
            // hours: the outage is closed at the first 429.
            $blocked = UptimeProbeResult::blocked(429, 150, 'HTTP 429 (rate-limited — probe blocked)');
            for ($at = Carbon::parse('2026-09-15 10:40:00'); $at->lessThanOrEqualTo(Carbon::parse('2026-09-15 16:40:00')); $at->addMinutes(30)) {
                $this->travelTo($at);
                app(UptimeStateUpdater::class)->update($site->fresh(), $blocked);
            }
            $this->travelTo(Carbon::parse('2026-09-15 17:40:00'));
            app(UptimeStateUpdater::class)->update($site->fresh(), UptimeProbeResult::success(200, 120));
            $this->travelBack();
            $site = $site->fresh();
            $site->forceFill(['uptime_last_checked_at' => now()])->save();

            expect(SiteUptimeEvent::where('site_id', $site->id)->orderBy('event_at')->get()->map(fn ($e) => [$e->event_type, $e->event_at->format('H:i')])->all())
                ->toBe([[SiteUptimeEvent::TYPE_DOWN, '10:00'], [SiteUptimeEvent::TYPE_PROBE_BLOCKED, '10:40'], [SiteUptimeEvent::TYPE_UP, '17:40']]);
            expect(compileSeptember($site)['data']['uptime'])
                ->toMatchArray(['outages_count' => 1, 'downtime_minutes' => 40, 'probe_blocked_minutes' => 420, 'probe_blocked_since' => null]);
        });

        it('leaves a single 429 on the last probe of the month out of the report', function () {
            // 03:55 UTC on Oct 1 is 11:55 PM EDT on Sep 30, the month's last probe.
            $site = accuracySite(['uptime_monitoring_enabled' => true, 'uptime_state' => 'up']);
            $chat = $this->mock(ChatNotifier::class);
            $chat->shouldNotReceive('uptimeProbeBlocked');
            $chat->shouldNotReceive('siteWentUp');

            $this->travelTo(Carbon::parse('2026-10-01 03:55:00'));
            app(UptimeStateUpdater::class)->update($site->fresh(), UptimeProbeResult::blocked(429, 150, 'HTTP 429 (rate-limited — probe blocked)'));
            $this->travelTo(Carbon::parse('2026-10-01 04:00:00'));
            app(UptimeStateUpdater::class)->update($site->fresh(), UptimeProbeResult::success(200, 120));
            $this->travelBack();
            $site = $site->fresh();
            $site->forceFill(['uptime_last_checked_at' => now()])->save();

            $report = compileSeptember($site);

            expect(SiteUptimeEvent::where('site_id', $site->id)->count())->toBe(0)
                ->and(ActionLog::where('site_id', $site->id)->where('action_type', ActionLog::TYPE_UPTIME_TRANSITION)->count())->toBe(0)
                ->and($report['data']['uptime'])->toMatchArray(['probe_blocked_since' => null, 'probe_blocked_minutes' => 0])
                ->and(implode(' ', $report['reasons']))->not->toContain('HTTP 429');
        });

        it('says the monitor was blocked, not that the site was down, when a probe_blocked stretch is open at the end of the month', function () {
            $site = accuracySite(['uptime_monitoring_enabled' => true]);
            uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-30 22:00:00', status: 503);
            uptimeEvent($site, SiteUptimeEvent::TYPE_PROBE_BLOCKED, '2026-09-30 23:40:00', status: 429);

            $report = compileSeptember($site);

            expect($report['data']['uptime'])->toMatchArray([
                'outages_count' => 1, 'downtime_minutes' => 100, 'probe_blocked_minutes' => 260, 'probe_blocked_since' => '2026-09-30T23:40:00+00:00',
            ])
                ->and($report['data']['uptime']['outages'][0]['ongoing'])->toBeFalse()
                ->and($report['text'])->not->toContain('still down')
                ->and($report['reasons'])->toContain('The uptime monitor was blocked (HTTP 429) from Sep 30, 7:40 PM EDT; the site was not necessarily down.')
                ->not->toContain('The site was still down at the end of the month.');
        });

        it('starts a real outage when a blocked site goes down', function () {
            $site = accuracySite(['uptime_monitoring_enabled' => true]);
            uptimeEvent($site, SiteUptimeEvent::TYPE_PROBE_BLOCKED, '2026-09-10 08:00:00', status: 429);
            uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-10 09:00:00', status: 502);
            uptimeEvent($site, SiteUptimeEvent::TYPE_UP, '2026-09-10 10:00:00', status: 200);

            $uptime = compileSeptember($site)['data']['uptime'];

            expect($uptime)->toMatchArray(['outages_count' => 1, 'downtime_minutes' => 60, 'probe_blocked_minutes' => 60])
                ->and($uptime['outages'][0])->toMatchArray(['started_at' => '2026-09-10T09:00:00+00:00', 'minutes' => 60]);
        });

        it('carries a probe_blocked stretch in from before the period as blocked', function () {
            $site = accuracySite(['uptime_monitoring_enabled' => true]);
            uptimeEvent($site, SiteUptimeEvent::TYPE_PROBE_BLOCKED, '2026-09-01 02:00:00', status: 429);
            uptimeEvent($site, SiteUptimeEvent::TYPE_UP, '2026-09-01 07:00:00', status: 200);

            expect(compileSeptember($site)['data']['uptime'])->toMatchArray([
                'outages_count' => 0, 'downtime_minutes' => 0, 'outages' => [], 'probe_blocked_minutes' => 180, 'probe_blocked_since' => null,
            ]);
        });

        it('joins a 429 down recorded before the monitor had a blocked state to the probe_blocked event after it', function () {
            // Deployed mid-block: the old row (down, 429) and the new one
            // (probe_blocked) are one stretch, which began with the old row.
            $site = accuracySite(['uptime_monitoring_enabled' => true]);
            uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-29 10:00:00', status: 429);
            uptimeEvent($site, SiteUptimeEvent::TYPE_PROBE_BLOCKED, '2026-09-30 10:00:00', status: 429);

            $report = compileSeptember($site);

            expect($report['data']['uptime'])->toMatchArray([
                'outages_count' => 0, 'downtime_minutes' => 0, 'outages' => [],
                'probe_blocked_minutes' => 42 * 60, 'probe_blocked_since' => '2026-09-29T10:00:00+00:00',
            ])
                ->and($report['reasons'])->toContain('The uptime monitor was blocked (HTTP 429) from Sep 29, 6:00 AM EDT; the site was not necessarily down.');
        });
    });

    it('leaves uptime out for a site the probe skips, instead of claiming 100%', function () {
        // CheckSiteUptime skips ignored and staging servers, so there are no events to read.
        $site = accuracySite(['uptime_monitoring_enabled' => true, 'server_id' => Server::factory()->ignored()]);

        $report = compileSeptember($site);
        $preview = view('client-reports::reports.show', ['report' => ClientReport::latest('id')->first(), 'isOperatorPreview' => true])->render();

        expect($report['data']['uptime'])->toMatchArray(['monitored' => false, 'probe_stopped' => false])
            ->and($report['html'])->not->toContain('around the clock')->not->toContain('Uptime')
            ->and($report['text'])->not->toContain('UPTIME')
            ->and($preview)->not->toContain('Uptime & Availability')
            ->toContain('Uptime hidden from the client: the uptime probe doesn&#039;t check this site.')
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

describe('the period in the report timezone', function () {
    it('keeps an outage at 01:00 UTC on the 1st in the month before, where its local time falls', function () {
        // 01:00 UTC on Sep 1 is Aug 31, 9:00 PM EDT; 01:00 UTC on Oct 1 is Sep 30.
        $site = accuracySite(['uptime_monitoring_enabled' => true]);
        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-01 01:00:00', status: 502);
        uptimeEvent($site, SiteUptimeEvent::TYPE_UP, '2026-09-01 01:30:00', status: 200);
        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-10-01 01:00:00', status: 502);
        uptimeEvent($site, SiteUptimeEvent::TYPE_UP, '2026-10-01 01:30:00', status: 200);

        $report = compileSeptember($site);

        expect($report['data']['uptime'])->toMatchArray(['outages_count' => 1, 'downtime_minutes' => 30, 'total_period_minutes' => 30 * 24 * 60])
            ->and($report['data']['uptime']['outages'][0])->toMatchArray(['started_at' => '2026-10-01T01:00:00+00:00', 'started_before_period' => false])
            ->and($report['text'])->toContain('- Down Sep 30, 9:00 PM EDT for 30 min')->not->toContain('Aug 31')
            ->and($report['html'])->toContain('Down Sep 30, 9:00 PM EDT')->not->toContain('Aug 31');
    });

    it('counts uptime monitoring that began late on the first day, local time, as the whole month', function () {
        // 01:30 UTC on Sep 2 is still Sep 1 in New York.
        $site = accuracySite(['uptime_monitoring_enabled' => true, 'created_at' => Carbon::parse('2026-09-02 01:00:00')]);
        uptimeSeed($site, '2026-09-02 01:30:00');

        $report = compileSeptember($site);

        expect($report['data']['meta']['coverage_start'])->toBeNull()
            ->and($report['data']['uptime'])->toMatchArray(['monitored_since' => null, 'total_period_minutes' => 30 * 24 * 60])
            ->and($report['text'])->not->toContain('onitoring began')
            ->and(implode(' ', $report['reasons']))->not->toContain('onitoring began');
    });

    it('reads a site added late on the last day, local time, as inside the period, not after it', function () {
        // 02:00 UTC on Oct 1 is Sep 30, 10:00 PM EDT.
        $site = accuracySite(['uptime_monitoring_enabled' => true, 'created_at' => Carbon::parse('2026-10-01 02:00:00')]);
        uptimeSeed($site, '2026-10-01 02:00:00');

        $report = compileSeptember($site);
        $note = 'Monitoring began Sep 30, so this report covers Sep 30 – Sep 30.';

        expect($report['data']['meta']['coverage_start'])->toBe('2026-10-01T02:00:00+00:00')
            ->and($report['data']['uptime'])->toMatchArray(['monitored' => true, 'monitored_since' => '2026-10-01T02:00:00+00:00', 'total_period_minutes' => 120])
            ->and($report['text'])->toContain($note)->not->toContain('after the end')
            ->and($report['reasons'])->toContain('Monitoring began Sep 30, so this report covers only part of the month.')
            ->and(implode(' ', $report['reasons']))->not->toContain('after the end')->not->toContain('Uptime monitoring began');
    });

    it('shows an outage carried in from before the period as since before the local first day', function () {
        expect(ReportPhrases::outageStart(['started_at' => '2026-09-01T04:00:00+00:00', 'started_before_period' => true]))
            ->toBe('Down since before Sep 1')
            // Reports compiled before the period was measured locally clamped to midnight UTC.
            ->and(ReportPhrases::outageStart(['started_at' => '2026-09-01T00:00:00+00:00', 'started_before_period' => true]))
            ->toBe('Down since before Sep 1');
    });
});

describe('partial-month coverage', function () {
    /** The seed transition the uptime probe (or the one-off backfill) writes. */
    function uptimeSeed(Site $site, string $at, bool $backfilled = false): void
    {
        app(ActionLogger::class)->record(
            actionType: ActionLog::TYPE_UPTIME_TRANSITION,
            summary: "{$site->domain} monitoring started — confirmed up",
            site: $site,
            target: 'up',
            details: ['status_code' => 200, 'seed' => true] + ($backfilled ? ['backfilled' => true] : []),
            ok: true,
            actor: $backfilled ? 'backfill' : 'scheduled',
            ranAt: Carbon::parse($at),
        );
    }

    it('measures uptime from when monitoring began for a site enrolled part-way through the month', function () {
        $site = accuracySite(['uptime_monitoring_enabled' => true, 'uptime_state' => 'unknown', 'created_at' => Carbon::parse('2026-09-26 18:10:00')]);

        // The first successful probe seeds the start of monitoring, exactly as the probe does.
        $this->mock(ChatNotifier::class)->shouldNotReceive('siteWentUp');
        $this->mock(SmsNotifier::class)->shouldNotReceive('siteWentUp');
        $this->travelTo(Carbon::parse('2026-09-26 18:20:00'));
        app(UptimeStateUpdater::class)->update($site, UptimeProbeResult::success(200, 150));
        $this->travelBack();
        $site->forceFill(['uptime_last_checked_at' => now()])->save();

        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-27 10:00:00', status: 503);
        uptimeEvent($site, SiteUptimeEvent::TYPE_UP, '2026-09-27 11:42:00', status: 200);

        $report = compileSeptember($site);
        $preview = view('client-reports::reports.show', ['report' => ClientReport::latest('id')->first(), 'isOperatorPreview' => true])->render();
        $note = 'Monitoring began Sep 26, so this report covers Sep 26 – Sep 30.';

        // 102 minutes down out of the 6,340 it was watched (to midnight EDT
        // on Sep 30), not out of the whole month's 43,200.
        expect($report['data']['meta']['coverage_start'])->toBe('2026-09-26T18:10:00+00:00')
            ->and($report['data']['uptime'])->toMatchArray([
                'monitored' => true,
                'monitored_since' => '2026-09-26T18:20:00+00:00',
                'total_period_minutes' => 6340,
                'downtime_minutes' => 102,
                'uptime_percentage' => 98.39,
                'outages_count' => 1,
            ])
            ->and($report['html'])->toContain($note)->toContain('(monitoring began Sep 26)')->toContain('98.39%')
            ->and($report['text'])->toContain($note)->toContain('Availability: 98.39% (monitoring began Sep 26)')
            ->and($preview)->toContain($note)->toContain('Monitoring began')->not->toContain('24 / 7 / 365')
            ->and(ReportReviewFlags::reasons($report['data']))->toContain('Monitoring began Sep 26, so this report covers only part of the month.')
            // Uptime began the same day the site was added: one reason, not two.
            ->and(implode(' ', ReportReviewFlags::reasons($report['data'])))->not->toContain('Uptime monitoring began');
    });

    it('falls back to when the site was added when its monitoring has no seed', function () {
        // A site whose first probes failed goes straight to down and never gets a seed.
        $site = accuracySite(['uptime_monitoring_enabled' => true, 'created_at' => Carbon::parse('2026-09-20 15:00:00')]);
        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-20 15:10:00', status: 500);
        uptimeEvent($site, SiteUptimeEvent::TYPE_UP, '2026-09-20 16:10:00', status: 200);

        $data = compileSeptember($site)['data'];

        expect($data['meta']['coverage_start'])->toBe('2026-09-20T15:00:00+00:00')
            ->and($data['uptime'])->toMatchArray([
                'monitored_since' => '2026-09-20T15:00:00+00:00',
                'total_period_minutes' => 15180,
                'downtime_minutes' => 60,
                'uptime_percentage' => 99.6,
            ])
            ->and(ReportReviewFlags::reasons($data))->toContain('Monitoring began Sep 20, so this report covers only part of the month.');
    });

    it('leaves a site watched for the whole month unchanged', function () {
        $site = accuracySite(['uptime_monitoring_enabled' => true, 'created_at' => Carbon::parse('2026-05-01 12:00:00')]);
        uptimeSeed($site, '2026-06-01 09:00:00');
        uptimeEvent($site, SiteUptimeEvent::TYPE_DOWN, '2026-09-10 10:00:00', status: 503);
        uptimeEvent($site, SiteUptimeEvent::TYPE_UP, '2026-09-10 14:00:00', status: 200);

        $report = compileSeptember($site);

        expect($report['data']['meta']['coverage_start'])->toBeNull()
            ->and($report['data']['uptime'])->toMatchArray([
                'monitored' => true,
                'monitored_since' => null,
                'total_period_minutes' => 30 * 24 * 60,
                'downtime_minutes' => 240,
                'uptime_percentage' => 99.44,
            ])
            ->and($report['html'])->not->toContain('Monitoring began')->not->toContain('monitoring began')
            ->and($report['text'])->not->toContain('onitoring began')
            ->and(implode(' ', ReportReviewFlags::reasons($report['data'])))->not->toContain('Monitoring began');
    });

    it('counts monitoring that began on the first day as the whole month', function () {
        $site = accuracySite(['uptime_monitoring_enabled' => true]);
        uptimeSeed($site, '2026-09-01 14:00:00');

        $data = compileSeptember($site)['data'];

        expect($data['meta']['coverage_start'])->toBeNull()
            ->and($data['uptime'])->toMatchArray(['monitored_since' => null, 'total_period_minutes' => 30 * 24 * 60])
            ->and(ReportReviewFlags::reasons(['meta' => $data['meta'], 'uptime' => $data['uptime']]))->toBe([]);
    });

    it('ignores a backfilled seed, which is dated when the backfill ran rather than when monitoring began', function () {
        $site = accuracySite(['uptime_monitoring_enabled' => true, 'created_at' => Carbon::parse('2026-05-01 12:00:00')]);
        uptimeSeed($site, '2026-09-15 12:00:00', backfilled: true);

        $data = compileSeptember($site)['data'];

        expect($data['meta']['coverage_start'])->toBeNull()
            ->and($data['uptime'])->toMatchArray(['monitored_since' => null, 'total_period_minutes' => 30 * 24 * 60]);
    });

    it('leaves uptime out and says so when monitoring began only after the period', function () {
        $site = accuracySite(['uptime_monitoring_enabled' => true, 'created_at' => Carbon::parse('2026-05-01 12:00:00')]);
        uptimeSeed($site, '2026-10-05 16:00:00');

        $report = compileSeptember($site);
        $preview = view('client-reports::reports.show', ['report' => ClientReport::latest('id')->first(), 'isOperatorPreview' => true])->render();

        // The site was there all month, so only the uptime section is left
        // out; nothing says the report covers none of the month.
        expect($report['data']['meta']['coverage_start'])->toBeNull()
            ->and($report['data']['uptime'])->toMatchArray(['monitored' => false, 'probe_stopped' => false, 'monitored_since' => '2026-10-05T16:00:00+00:00'])
            ->and($report['html'])->not->toContain('Uptime &amp; availability')->not->toContain('Monitoring began')
            ->and($report['text'])->not->toContain('UPTIME')->not->toContain('Monitoring began')
            ->and($preview)->toContain('Uptime hidden from the client: uptime monitoring began Oct 5, after this period.')
            ->and(ReportReviewFlags::reasons($report['data']))
            ->toContain('Uptime monitoring began Oct 5, after this period, so the Uptime section was left out.')
            ->and(implode(' ', ReportReviewFlags::reasons($report['data'])))->not->toContain('covers none of it');
    });

    it('says only the uptime is partial when monitoring is switched on mid-month for a site added long before', function () {
        // Added months ago with uptime monitoring off; an update ran on the
        // 5th, then monitoring was switched on and first probed on the 17th.
        $site = accuracySite([
            'created_at' => Carbon::parse('2026-04-10 12:00:00'),
            'uptime_monitoring_enabled' => false,
            'uptime_state' => 'unknown',
        ]);
        ActionLog::create(['site_id' => $site->id, 'action_type' => ActionLog::TYPE_PLUGIN_UPDATE, 'target' => 'example-forms/example-forms.php',
            'summary' => 'Plugin updated: Example Forms 1.0 → 1.1', 'ok' => true, 'ran_at' => Carbon::parse('2026-09-05 14:00'), 'actor' => 'test']);

        $site->forceFill(['uptime_monitoring_enabled' => true])->save();
        $this->mock(ChatNotifier::class)->shouldNotReceive('siteWentUp');
        $this->mock(SmsNotifier::class)->shouldNotReceive('siteWentUp');
        $this->travelTo(Carbon::parse('2026-09-17 14:00:00'));
        app(UptimeStateUpdater::class)->update($site->fresh(), UptimeProbeResult::success(200, 150));
        $this->travelBack();
        $site = $site->fresh();
        $site->forceFill(['uptime_last_checked_at' => now()])->save();

        $report = compileSeptember($site);

        expect($report['data']['meta']['coverage_start'])->toBeNull()
            ->and($report['data']['uptime']['monitored_since'])->toBe('2026-09-17T14:00:00+00:00')
            ->and($report['data']['updates']['plugins_count'])->toBe(1)
            ->and($report['html'])->not->toContain('so this report covers')->toContain('(monitoring began Sep 17)')->toContain('Example Forms')
            ->and($report['text'])->not->toContain('so this report covers')->toContain('(monitoring began Sep 17)')
            ->and($report['preview'])->not->toContain('so this report covers')
            ->and($report['reasons'])->toContain('Uptime monitoring began Sep 17, so uptime covers only part of the month.')
            ->and(implode(' ', $report['reasons']))->not->toContain('this report covers only part');
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
