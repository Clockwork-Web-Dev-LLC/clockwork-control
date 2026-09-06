<?php

namespace Tests\Feature\Backups;

use App\Console\Commands\PullBackupRelayReport;
use App\Models\BackupRelayRun;
use App\Services\Chat\ChatNotifier;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery;

/*
|--------------------------------------------------------------------------
| PullBackupRelayReport::checkStaleness() call-site coverage
|--------------------------------------------------------------------------
|
| This is the "state + timestamp, alert only on transition" gate described
| in the command's own docblock (lines ~134-160): backupRelayStale() fires
| once on the not-stale -> stale transition, backupRelayRecovered() fires
| once on the reverse, and neither fires on a fresh environment, a repeat
| still-stale run, or a repeat still-healthy run. Not re-testing
| ChatNotifierDispatcher's is_inactive gating (this command's alerts aren't
| site-scoped anyway — see ChatNotifierGatingTest) or pullReport()'s S3/
| validation logic (BackupRelayCommandsTest already covers that end-to-end
| against a real notifier); this file only exercises whether checkStaleness()
| calls the right ChatNotifier method, with the right arguments, under each
| of its gating conditions.
|
| Storage::fake('s3') is left with no last-report.json object in every case
| below, so pullReport() takes its "no report found yet" early-return path
| without touching BackupRelayRun or Settings at all — checkStaleness() is
| reached purely off whatever BackupRelayRun/Settings state each test seeds
| directly, independent of the pull side.
*/

describe('PullBackupRelayReport staleness alerting', function () {
    beforeEach(function () {
        Storage::fake('s3');
        app(Settings::class)->put('backup_relay.frequency', 'twice_weekly');
    });

    it('fires backupRelayStale once on the not-stale -> stale transition, with the days-since and last-run-at it computed', function () {
        $lastRun = BackupRelayRun::factory()->create([
            'started_at' => now()->subDays(8),
            'finished_at' => now()->subDays(8),
        ]);

        $this->mock(ChatNotifier::class, function ($mock) use ($lastRun) {
            $mock->shouldReceive('backupRelayStale')
                ->once()
                ->with(8, Mockery::on(fn ($lastRunAt) => $lastRunAt instanceof Carbon
                    && $lastRunAt->equalTo($lastRun->finished_at)))
                ->andReturn(true);
            $mock->shouldNotReceive('backupRelayRecovered');
        });

        $this->artisan('clockwork:pull-backup-relay-report')->assertSuccessful();

        expect((string) app(Settings::class)->get('backup_relay.stale_alert_since', ''))->not->toBe('');
    });

    it('does not re-fire backupRelayStale when already stale on a previous run', function () {
        BackupRelayRun::factory()->create([
            'started_at' => now()->subDays(10),
            'finished_at' => now()->subDays(10),
        ]);
        app(Settings::class)->put('backup_relay.stale_alert_since', now()->subDay()->toIso8601String());

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldNotReceive('backupRelayStale');
            $mock->shouldNotReceive('backupRelayRecovered');
        });

        $this->artisan('clockwork:pull-backup-relay-report')->assertSuccessful();
    });

    it('fires backupRelayRecovered once on the stale -> not-stale transition and clears the alert flag', function () {
        BackupRelayRun::factory()->create([
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        app(Settings::class)->put('backup_relay.stale_alert_since', now()->subDays(9)->toIso8601String());

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('backupRelayRecovered')->once()->andReturn(true);
            $mock->shouldNotReceive('backupRelayStale');
        });

        $this->artisan('clockwork:pull-backup-relay-report')->assertSuccessful();

        expect((string) app(Settings::class)->get('backup_relay.stale_alert_since', ''))->toBe('');
    });

    it('does not fire anything on a fresh environment with no BackupRelayRun row at all', function () {
        expect(BackupRelayRun::query()->count())->toBe(0);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldNotReceive('backupRelayStale');
            $mock->shouldNotReceive('backupRelayRecovered');
        });

        $this->artisan('clockwork:pull-backup-relay-report')->assertSuccessful();
    });

    it('does not fire anything when the last run is recent and nothing was ever flagged stale', function () {
        BackupRelayRun::factory()->create([
            'started_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldNotReceive('backupRelayStale');
            $mock->shouldNotReceive('backupRelayRecovered');
        });

        $this->artisan('clockwork:pull-backup-relay-report')->assertSuccessful();

        expect((string) app(Settings::class)->get('backup_relay.stale_alert_since', ''))->toBe('');
    });

    it('uses the real STALE_DAYS threshold (6) as the boundary the transition test above relies on', function () {
        expect(PullBackupRelayReport::STALE_DAYS)->toBe(6);
    });
});
