<?php

namespace Tests\Feature\BackupRelay;

use App\Models\Site;
use App\Services\Chat\ChatNotifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\BackupRelay\Jobs\ArchiveSiteBackupJob;
use Modules\BackupRelay\Services\BackupArchiveEnumerator;
use Modules\BackupRelay\Services\GlacierUploader;
use RuntimeException;

/*
| Each site keeps its last relay attempt (time, error, start of the failure
| streak) so a failing site is visible without reading the application log,
| and the stored archive hash never outlives the archive it describes.
*/

function directRelaySite(array $attributes = []): Site
{
    return Site::factory()->custom()->withCompanionInstalled()->create(array_merge([
        'domain' => 'relay-'.uniqid().'.example.com',
        'companion_capabilities' => ['backup-create'],
        'backup_relay_enabled' => true,
        'backup_relay_frequency' => 'daily',
    ], $attributes));
}

describe('backup relay attempt state', function () {
    beforeEach(function () {
        Storage::fake('s3-backup-relay');
        Carbon::setTestNow(Carbon::parse('2026-10-28 04:30:00'));
    });

    afterEach(function () {
        Carbon::setTestNow();
    });

    it('records each failure, keeps the start of the streak, and clears it all on the next archive', function () {
        $site = directRelaySite(['domain' => 'streak.example.com']);

        Http::fake([
            'https://streak.example.com/wp-json/clockwork/v1/backup/create*' => Http::sequence()
                ->push(['ok' => false, 'error' => 'Database dump failed on host'], 500)
                ->push(['ok' => false, 'error' => 'Upload to storage was refused'], 500)
                ->push(['ok' => true, 'size_bytes' => 4096, 'sha256' => str_repeat('c', 64)], 200),
        ]);

        $uploader = new GlacierUploader('s3-backup-relay');

        expect(fn () => (new ArchiveSiteBackupJob($site))->handle($uploader))
            ->toThrow(RuntimeException::class, 'Database dump failed on host');

        $site->refresh();
        expect($site->backup_relay_last_error)->toContain('Database dump failed on host')
            ->and($site->backup_relay_failing_since?->toDateTimeString())->toBe('2026-10-28 04:30:00')
            ->and($site->backup_relay_last_attempt_at?->toDateTimeString())->toBe('2026-10-28 04:30:00')
            ->and($site->backup_relay_last_archived_at)->toBeNull();

        Carbon::setTestNow(Carbon::parse('2026-10-29 04:30:00'));

        expect(fn () => (new ArchiveSiteBackupJob($site->fresh()))->handle($uploader))
            ->toThrow(RuntimeException::class, 'Upload to storage was refused');

        $site->refresh();
        expect($site->backup_relay_last_error)->toContain('Upload to storage was refused')
            ->and($site->backup_relay_failing_since?->toDateTimeString())->toBe('2026-10-28 04:30:00')
            ->and($site->backup_relay_last_attempt_at?->toDateTimeString())->toBe('2026-10-29 04:30:00');

        Carbon::setTestNow(Carbon::parse('2026-10-30 04:30:00'));

        expect((new ArchiveSiteBackupJob($site->fresh()))->handle($uploader))->toBe('archived');

        $site->refresh();
        expect($site->backup_relay_last_error)->toBeNull()
            ->and($site->backup_relay_failing_since)->toBeNull()
            ->and($site->backup_relay_last_attempt_at?->toDateTimeString())->toBe('2026-10-30 04:30:00')
            ->and($site->backup_relay_last_archived_at?->toDateTimeString())->toBe('2026-10-30 04:30:00')
            ->and($site->backup_relay_last_sha256)->toBe(str_repeat('c', 64));
    });

    it('leaves attempt state alone when the run skips the site', function () {
        $site = directRelaySite([
            'backup_relay_last_archived_at' => now()->subHour(),
            'backup_relay_last_attempt_at' => now()->subHour(),
        ]);

        Http::fake();

        expect((new ArchiveSiteBackupJob($site))->handle(new GlacierUploader('s3-backup-relay')))->toBe('skipped');

        expect($site->fresh()->backup_relay_last_attempt_at?->toDateTimeString())->toBe('2026-10-28 03:30:00');
        Http::assertNothingSent();
    });

    it('persists the failure from a scheduled direct-only run so it is visible without log access', function () {
        directRelaySite([
            'domain' => 'old-plugin.example.com',
            'companion_version' => '1.30.3',
            'companion_capabilities' => ['snapshot', 'updates'],
        ]);

        Http::fake();
        $this->mock(ChatNotifier::class, fn ($mock) => $mock->shouldReceive('send')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'old-plugin.example.com')));

        $this->artisan('clockwork:backup-relay-run --direct-only')
            ->expectsOutputToContain('lacks the backup-create capability')
            ->assertExitCode(1);

        $site = Site::query()->where('domain', 'old-plugin.example.com')->firstOrFail();
        expect($site->backup_relay_last_error)
            ->toContain('Companion 1.30.3 lacks the backup-create capability; update Companion or switch to Renegade.')
            ->and($site->backup_relay_failing_since)->not->toBeNull()
            ->and($site->backup_relay_last_attempt_at)->not->toBeNull();

        Http::assertNothingSent();
    });
});

describe('backup relay stored archive hash', function () {
    beforeEach(function () {
        Storage::fake('s3-backup-relay');
        Carbon::setTestNow(Carbon::parse('2026-10-31 04:45:00'));
    });

    afterEach(function () {
        Carbon::setTestNow();
    });

    it('clears the previous hash when an archive already in storage is adopted without one', function () {
        $site = directRelaySite([
            'domain' => 'adopted.example.com',
            'backup_relay_last_archived_at' => Carbon::parse('2026-10-30 04:45:00'),
            'backup_relay_last_sha256' => str_repeat('a', 64),
        ]);

        $previousKey = 'archives/adopted.example.com/2026-10-30.zip';
        $newKey = 'archives/adopted.example.com/2026-10-31.zip';
        Storage::disk('s3-backup-relay')->put($previousKey, str_repeat('p', 128));
        Storage::disk('s3-backup-relay')->put($newKey, str_repeat('n', 256));

        Http::fake();

        expect((new ArchiveSiteBackupJob($site))->handle(new GlacierUploader('s3-backup-relay')))->toBe('archived');

        $site->refresh();
        expect($site->backup_relay_last_sha256)->toBeNull()
            ->and($site->backup_relay_last_size_bytes)->toBe(256)
            ->and(app(BackupArchiveEnumerator::class)->resolveArchiveSha256($site, $newKey))->toBeNull();

        Http::assertNothingSent();
    });

    it('clears the previous hash when the plugin reports a failure but the archive landed anyway', function () {
        $site = directRelaySite([
            'domain' => 'landed.example.com',
            'backup_relay_last_archived_at' => Carbon::parse('2026-10-30 04:45:00'),
            'backup_relay_last_sha256' => str_repeat('a', 64),
            'backup_relay_last_error' => 'HTTP 502 error code: 502',
            'backup_relay_failing_since' => Carbon::parse('2026-10-29 04:45:00'),
        ]);

        $newKey = 'archives/landed.example.com/2026-10-31.zip';

        // The upload completes on the host while the gateway answers 502.
        Http::fake(function (Request $request) use ($newKey) {
            if (str_contains($request->url(), '/backup/create')) {
                Storage::disk('s3-backup-relay')->put($newKey, str_repeat('n', 512));

                return Http::response('error code: 502', 502);
            }

            return Http::response([], 404);
        });

        expect((new ArchiveSiteBackupJob($site))->handle(new GlacierUploader('s3-backup-relay')))->toBe('archived');

        $site->refresh();
        expect($site->backup_relay_last_sha256)->toBeNull()
            ->and($site->backup_relay_last_archived_at?->toDateTimeString())->toBe('2026-10-31 04:45:00')
            ->and($site->backup_relay_last_error)->toBeNull()
            ->and($site->backup_relay_failing_since)->toBeNull()
            ->and(app(BackupArchiveEnumerator::class)->resolveArchiveSha256($site, $newKey))->toBeNull();

        Storage::disk('s3-backup-relay')->assertMissing("{$newKey}.sha256.json");
    });
});
