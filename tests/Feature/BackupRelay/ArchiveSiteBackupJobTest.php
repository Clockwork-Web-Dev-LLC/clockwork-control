<?php

namespace Tests\Feature\BackupRelay;

use App\Models\BackupRelayRun;
use App\Models\Site;
use App\Models\SiteBackup;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\BackupRelay\Jobs\ArchiveSiteBackupJob;
use Modules\BackupRelay\Services\GlacierUploader;
use Modules\Core\Contracts\BackupRef;
use Modules\Core\Contracts\BackupRelayAdapter;
use Modules\Core\Contracts\DirectS3BackupRelayAdapter;
use Modules\Core\Contracts\HostingProvider;
use Tests\TestCase;

class ArchiveSiteBackupJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_job_uploads_backup_to_s3_and_updates_site(): void
    {
        Storage::fake('s3-backup-relay');

        $site = Site::query()->create([
            'domain' => 'my-archive-site.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '999',
            'backup_relay_enabled' => true,
            'is_wordpress' => true,
        ]);

        $fakeStream = fopen('data://text/plain,fake-tar-gz-content', 'r');
        $fakeRef = new BackupRef(
            provider: Site::HOSTING_PROVIDER_PRESSABLE,
            externalId: 'backup_12345',
            createdAt: Carbon::parse('2026-09-01 04:00:00'),
            sizeBytes: 1024,
            metadata: ['id' => 'backup_12345'],
        );

        $fakeAdapter = new class($fakeRef, $fakeStream) implements BackupRelayAdapter
        {
            public function __construct(
                private readonly BackupRef $ref,
                private $stream,
            ) {}

            public function latestBackupRef(Site $site): ?BackupRef
            {
                return $this->ref;
            }

            public function openBackupStream(Site $site, BackupRef $ref)
            {
                return $this->stream;
            }
        };

        $mockHost = \Mockery::mock(HostingProvider::class);
        $mockHost->shouldReceive('id')->andReturn('pressable');
        $mockHost->shouldReceive('supports')->with(HostingProvider::CAP_BACKUP_RELAY)->andReturn(true);
        $mockHost->shouldReceive('backupRelayAdapter')->andReturn($fakeAdapter);

        // Bind mock to host registry or partial mock site
        $site = \Mockery::mock($site)->makePartial();
        $site->shouldReceive('host')->andReturn($mockHost);

        $uploader = new GlacierUploader('s3-backup-relay');
        $job = new ArchiveSiteBackupJob($site);

        $result = $job->handle($uploader);

        $this->assertSame('archived', $result);
        $expectedKey = "archives/{$site->domain}/2026-09-01_backup_12345.archive";
        Storage::disk('s3-backup-relay')->assertExists($expectedKey);
        $this->assertSame('fake-tar-gz-content', Storage::disk('s3-backup-relay')->get($expectedKey));

        $this->assertNotNull($site->fresh()->backup_relay_last_archived_at);
    }

    public function test_archive_job_skips_when_destination_already_exists(): void
    {
        Storage::fake('s3-backup-relay');

        $site = Site::query()->create([
            'domain' => 'already-done.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '888',
            'backup_relay_enabled' => true,
            'is_wordpress' => true,
        ]);

        $expectedKey = "archives/{$site->domain}/2026-09-01_backup_exists.archive";
        Storage::disk('s3-backup-relay')->put($expectedKey, 'pre-existing');

        $fakeRef = new BackupRef(
            provider: Site::HOSTING_PROVIDER_PRESSABLE,
            externalId: 'backup_exists',
            createdAt: Carbon::parse('2026-09-01 04:00:00'),
            sizeBytes: 1024,
        );

        $fakeAdapter = new class($fakeRef) implements BackupRelayAdapter
        {
            public function __construct(private readonly BackupRef $ref) {}

            public function latestBackupRef(Site $site): ?BackupRef
            {
                return $this->ref;
            }

            public function openBackupStream(Site $site, BackupRef $ref)
            {
                return fopen('data://text/plain,new-content', 'r');
            }
        };

        $mockHost = \Mockery::mock(HostingProvider::class);
        $mockHost->shouldReceive('id')->andReturn('pressable');
        $mockHost->shouldReceive('supports')->with(HostingProvider::CAP_BACKUP_RELAY)->andReturn(true);
        $mockHost->shouldReceive('backupRelayAdapter')->andReturn($fakeAdapter);

        $site = \Mockery::mock($site)->makePartial();
        $site->shouldReceive('host')->andReturn($mockHost);

        $uploader = new GlacierUploader('s3-backup-relay');
        $job = new ArchiveSiteBackupJob($site);

        $result = $job->handle($uploader);

        $this->assertSame('skipped', $result);
        $this->assertSame('pre-existing', Storage::disk('s3-backup-relay')->get($expectedKey));
    }

    public function test_console_command_runs_and_records_relay_run_row(): void
    {
        Storage::fake('s3-backup-relay');

        Site::query()->create([
            'domain' => 'target1.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '1001',
            'backup_relay_enabled' => true,
            'is_wordpress' => true,
        ]);

        Site::query()->create([
            'domain' => 'target2-unsupported.example.com',
            'hosting_provider' => 'unsupported_custom',
            'backup_relay_enabled' => true,
            'is_wordpress' => true,
        ]);

        $this->artisan('clockwork:backup-relay-run')->assertSuccessful();

        $this->assertSame(1, BackupRelayRun::query()->count());
        $run = BackupRelayRun::query()->first();
        $this->assertSame(1, $run->sites_total);
    }

    public function test_archive_job_skips_same_calendar_day_for_daily_sites(): void
    {
        Storage::fake('s3-backup-relay');
        Carbon::setTestNow(Carbon::parse('2026-09-11 15:00:00'));

        $site = Site::query()->create([
            'domain' => 'daily-skip.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '777',
            'backup_relay_enabled' => true,
            'backup_relay_frequency' => 'daily',
            'backup_relay_last_archived_at' => Carbon::parse('2026-09-11 04:58:00'),
            'is_wordpress' => true,
        ]);

        $fakeRef = new BackupRef(
            provider: Site::HOSTING_PROVIDER_PRESSABLE,
            externalId: 'backup_today',
            createdAt: Carbon::parse('2026-09-11 12:00:00'),
            sizeBytes: 1024,
        );

        $fakeAdapter = new class($fakeRef) implements BackupRelayAdapter
        {
            public function __construct(private readonly BackupRef $ref) {}

            public function latestBackupRef(Site $site): ?BackupRef
            {
                return $this->ref;
            }

            public function openBackupStream(Site $site, BackupRef $ref)
            {
                return fopen('data://text/plain,should-not-upload', 'r');
            }
        };

        $mockHost = \Mockery::mock(HostingProvider::class);
        $mockHost->shouldReceive('id')->andReturn('pressable');
        $mockHost->shouldReceive('supports')->with(HostingProvider::CAP_BACKUP_RELAY)->andReturn(true);
        $mockHost->shouldReceive('backupRelayAdapter')->andReturn($fakeAdapter);

        $site = \Mockery::mock($site)->makePartial();
        $site->shouldReceive('host')->andReturn($mockHost);

        $uploader = new GlacierUploader('s3-backup-relay');
        $job = new ArchiveSiteBackupJob($site);

        $this->assertSame('skipped', $job->handle($uploader));

        Carbon::setTestNow();
    }

    public function test_direct_archive_job_writes_manifest_sidecar_and_saves_site_backup(): void
    {
        Storage::fake('s3-backup-relay');
        Carbon::setTestNow(Carbon::parse('2026-10-28 22:00:00'));

        $site = Site::query()->create([
            'domain' => 'direct-manifest.example.com',
            'hosting_provider' => 'custom',
            'backup_relay_enabled' => true,
            'is_wordpress' => true,
        ]);

        $manifestPayload = [
            'version' => '1.0',
            'created_at' => '2026-10-28T22:00:00Z',
            'site' => ['domain' => 'direct-manifest.example.com'],
            'summary' => ['total_files' => 150, 'total_uncompressed_bytes' => 5000000],
            'database' => ['tables_count' => 12, 'total_rows' => 450],
            'files' => ['uploads' => ['file_count' => 120, 'bytes' => 4000000]],
        ];

        $fakeDirectAdapter = new class($manifestPayload) implements BackupRelayAdapter, DirectS3BackupRelayAdapter
        {
            public function __construct(private readonly array $manifest) {}

            public function latestBackupRef(Site $site): ?BackupRef
            {
                return new BackupRef(
                    provider: 'companion',
                    externalId: 'direct',
                    createdAt: Carbon::parse('2026-10-28 22:00:00'),
                );
            }

            public function openBackupStream(Site $site, BackupRef $ref)
            {
                return null;
            }

            public function uploadDirectToS3(Site $site, string $destinationKey, array $presigned): array
            {
                return [
                    'ok' => true,
                    'size_bytes' => 1234567,
                    'sha256' => 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
                    'manifest' => $this->manifest,
                ];
            }
        };

        $mockHost = \Mockery::mock(HostingProvider::class);
        $mockHost->shouldReceive('id')->andReturn('custom');
        $mockHost->shouldReceive('supports')->with(HostingProvider::CAP_BACKUP_RELAY)->andReturn(true);
        $mockHost->shouldReceive('backupRelayAdapter')->andReturn($fakeDirectAdapter);

        $site = \Mockery::mock($site)->makePartial();
        $site->shouldReceive('host')->andReturn($mockHost);

        $uploader = new GlacierUploader('s3-backup-relay');
        $job = new ArchiveSiteBackupJob($site);

        $result = $job->handle($uploader);

        $this->assertSame('archived', $result);

        $expectedArchiveKey = "archives/{$site->domain}/2026-10-28.zip";
        $expectedManifestKey = "{$expectedArchiveKey}.manifest.json";
        $expectedShaKey = "{$expectedArchiveKey}.sha256.json";

        // Sidecars exist in S3
        Storage::disk('s3-backup-relay')->assertExists($expectedManifestKey);
        Storage::disk('s3-backup-relay')->assertExists($expectedShaKey);

        $sidecarJson = json_decode(Storage::disk('s3-backup-relay')->get($expectedManifestKey), true);
        $this->assertSame('1.0', $sidecarJson['version']);
        $this->assertSame(12, $sidecarJson['database']['tables_count']);

        // Site has manifest and sha
        $freshSite = $site->fresh();
        $this->assertSame($manifestPayload, $freshSite->backup_relay_last_manifest);
        $this->assertSame('abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789', $freshSite->backup_relay_last_sha256);

        // SiteBackup record was created
        $backupRecord = SiteBackup::query()->where('site_id', $site->id)->first();
        $this->assertNotNull($backupRecord);
        $this->assertSame(SiteBackup::SOURCE_S3_ARCHIVE, $backupRecord->source);
        $this->assertSame(1234567, $backupRecord->bytes);
        $this->assertSame($manifestPayload, $backupRecord->manifest);

        Carbon::setTestNow();
    }
}
