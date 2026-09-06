<?php

namespace Tests\Feature\Console;

use App\Models\BackupRelayRun;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Phase 6 console-command coverage: clockwork:pull-backup-relay-report
|--------------------------------------------------------------------------
|
| The report-processing happy path (valid payload -> BackupRelayRun row +
| Settings update, dedup on repeat finished_at, no-object-yet no-op) is
| already covered end-to-end by tests/Feature/BackupRelayCommandsTest.php,
| and the staleness-alerting gate (checkStaleness()) is already covered
| call-site-by-call-site by
| tests/Feature/Backups/PullBackupRelayReportNotificationTest.php. What
| neither file exercises is pullReport()'s two FAILURE paths — malformed
| JSON at the S3 object, and a well-formed-but-invalid payload — so that's
| all this file adds.
*/

function pbrrKey(): string
{
    return rtrim((string) config('clockwork.backup_relay.s3_prefix'), '/').'/last-report.json';
}

describe('clockwork:pull-backup-relay-report — malformed/invalid payload handling', function () {
    it('exits FAILURE and records nothing when the S3 object is not valid JSON', function () {
        Storage::fake('s3');
        Storage::disk('s3')->put(pbrrKey(), 'not valid json{{{');

        $this->artisan('clockwork:pull-backup-relay-report')->assertFailed();

        expect(BackupRelayRun::query()->count())->toBe(0);
    });

    it('exits FAILURE and records nothing when the report fails field validation', function () {
        Storage::fake('s3');
        Storage::disk('s3')->put(pbrrKey(), json_encode([
            'sites_total' => 10,
            'sites_archived' => 8,
            // sites_skipped / sites_failed missing — required.
            'started_at' => '2026-08-30T05:00:00+00:00',
            'finished_at' => '2026-08-30T05:14:00+00:00',
        ]));

        $this->artisan('clockwork:pull-backup-relay-report')->assertFailed();

        expect(BackupRelayRun::query()->count())->toBe(0);
    });

    it('exits FAILURE when a failures[] entry is missing its required domain/error fields', function () {
        Storage::fake('s3');
        Storage::disk('s3')->put(pbrrKey(), json_encode([
            'sites_total' => 5,
            'sites_archived' => 4,
            'sites_skipped' => 0,
            'sites_failed' => 1,
            'failures' => [['domain' => 'broken.example.com']], // missing 'error'
            'started_at' => '2026-08-30T05:00:00+00:00',
            'finished_at' => '2026-08-30T05:14:00+00:00',
        ]));

        $this->artisan('clockwork:pull-backup-relay-report')->assertFailed();

        expect(BackupRelayRun::query()->count())->toBe(0);
    });
});
