<?php

namespace Tests\Feature\BackupRelay;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\BackupRelay\Jobs\ArchiveSiteBackupJob;
use Modules\BackupRelay\Services\CompanionBackupRelayAdapter;
use Modules\BackupRelay\Services\GlacierUploader;
use Modules\Core\Contracts\DirectS3BackupRelayAdapter;
use Modules\Core\Contracts\HostingProvider;

describe('Companion Backup Relay (S3 Glacier Instant Retrieval)', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Storage::fake('s3-backup-relay');
        Carbon::setTestNow(Carbon::parse('2026-09-11 15:00:00'));
    });

    afterEach(function () {
        Carbon::setTestNow();
    });

    it('confirms CustomHostingProvider supports CAP_BACKUP_RELAY and provides CompanionBackupRelayAdapter', function () {
        $site = Site::factory()->custom()->create();
        $host = $site->host();

        expect($host->supports(HostingProvider::CAP_BACKUP_RELAY))->toBeTrue()
            ->and($host->backupRelayAdapter())->toBeInstanceOf(CompanionBackupRelayAdapter::class)
            ->and($host->backupRelayAdapter())->toBeInstanceOf(DirectS3BackupRelayAdapter::class);
    });

    it('generates presigned upload URL with GLACIER_IR header for Companion to send', function () {
        $uploader = new GlacierUploader('s3-backup-relay');
        $uploadData = $uploader->presignedUploadUrl('archives/example.com/2026-09-11.zip');

        expect($uploadData)->toBeArray()
            ->and($uploadData)->toHaveKey('url')
            ->and($uploadData['url'])->toContain('archives/example.com/2026-09-11.zip')
            ->and($uploadData['headers'])->toHaveKey('x-amz-storage-class')
            ->and($uploadData['headers']['x-amz-storage-class'])->toBe('GLACIER_IR');
    });

    it('creates a daily backup reference for companion site', function () {
        $site = Site::factory()->custom()->withCompanionInstalled()->create();
        $adapter = new CompanionBackupRelayAdapter;

        $ref = $adapter->latestBackupRef($site);
        expect($ref)->not->toBeNull()
            ->and($ref->externalId)->toBe('cw_companion_20260911')
            ->and($ref->createdAt->toDateString())->toBe('2026-09-11')
            ->and($ref->provider)->toBe(Site::HOSTING_PROVIDER_CUSTOM);
    });

    it('successfully executes direct S3 Glacier backup via Companion and persists size and sha256', function () {
        $site = Site::factory()->custom()->withCompanionInstalled()->create([
            'domain' => 'wpengine-client.com',
            'backup_relay_enabled' => true,
            'backup_relay_last_archived_at' => null,
        ]);

        Http::fake([
            'https://wpengine-client.com/wp-json/clockwork/v1/backup/create*' => Http::response([
                'ok' => true,
                'size_bytes' => 52428800,
                'sha256' => 'abc123def456',
                'duration_ms' => 4500,
            ], 200),
        ]);

        $uploader = new GlacierUploader('s3-backup-relay');
        $job = new ArchiveSiteBackupJob($site);

        $result = $job->handle($uploader);

        expect($result)->toBe('archived');

        $site->refresh();
        expect($site->backup_relay_last_archived_at)->not->toBeNull()
            ->and($site->backup_relay_last_size_bytes)->toBe(52428800)
            ->and($site->backup_relay_last_sha256)->toBe('abc123def456');

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), '/backup/create')
                && ($data['headers']['x-amz-storage-class'] ?? null) === 'GLACIER_IR'
                && str_contains((string) ($data['destination_key'] ?? ''), 'archives/wpengine-client.com/2026-09-11.zip');
        });

        Storage::disk('s3-backup-relay')->assertExists('archives/wpengine-client.com/2026-09-11.zip.sha256.json');
        $sidecar = json_decode((string) Storage::disk('s3-backup-relay')->get('archives/wpengine-client.com/2026-09-11.zip.sha256.json'), true);
        expect($sidecar['sha256'])->toBe('abc123def456')
            ->and($sidecar['size_bytes'])->toBe(52428800);

        $secondResult = $job->handle($uploader);
        expect($secondResult)->toBe('skipped');
    });

    it('skips a second scheduled daily run but Backup Now writes a timestamped zip', function () {
        $site = Site::factory()->custom()->withCompanionInstalled()->create([
            'domain' => 'daily-custom.com',
            'backup_relay_enabled' => true,
            'backup_relay_frequency' => 'daily',
            'backup_relay_last_archived_at' => null,
        ]);

        Http::fake([
            'https://daily-custom.com/wp-json/clockwork/v1/backup/create*' => Http::response([
                'ok' => true,
                'size_bytes' => 1024,
                'sha256' => 'deadbeef',
            ], 200),
        ]);

        $uploader = new GlacierUploader('s3-backup-relay');

        expect((new ArchiveSiteBackupJob($site))->handle($uploader))->toBe('archived');
        expect((new ArchiveSiteBackupJob($site->fresh()))->handle($uploader))->toBe('skipped');

        $forced = new ArchiveSiteBackupJob($site->fresh(), force: true);
        expect($forced->handle($uploader))->toBe('archived');

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), '/backup/create')
                && str_contains((string) ($data['destination_key'] ?? ''), 'archives/daily-custom.com/2026-09-11_15-00-00.zip');
        });
    });

    it('treats an existing daily zip as archived when Companion timed out after the PUT', function () {
        $site = Site::factory()->custom()->withCompanionInstalled()->create([
            'domain' => 'wpengine-timeout.com',
            'backup_relay_enabled' => true,
            'backup_relay_last_archived_at' => null,
        ]);

        Storage::disk('s3-backup-relay')->put(
            'archives/wpengine-timeout.com/2026-09-11.zip',
            str_repeat('a', 2048),
        );

        Http::fake();

        $uploader = new GlacierUploader('s3-backup-relay');
        $job = new ArchiveSiteBackupJob($site);

        expect($job->handle($uploader))->toBe('archived');

        $site->refresh();
        expect($site->backup_relay_last_archived_at)->not->toBeNull()
            ->and($site->backup_relay_last_size_bytes)->toBe(2048);

        Http::assertNothingSent();
    });

    it('handles companion backup generation failures gracefully', function () {
        $site = Site::factory()->custom()->withCompanionInstalled()->create([
            'domain' => 'wpengine-failed.com',
            'backup_relay_enabled' => true,
        ]);

        Http::fake([
            'https://wpengine-failed.com/wp-json/clockwork/v1/backup/create*' => Http::response([
                'ok' => false,
                'error' => 'Disk space insufficient on host',
            ], 500),
        ]);

        $uploader = new GlacierUploader('s3-backup-relay');
        $job = new ArchiveSiteBackupJob($site);

        expect(fn () => $job->handle($uploader))->toThrow(\RuntimeException::class, 'Disk space insufficient');
    });
});
