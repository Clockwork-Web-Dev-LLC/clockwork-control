<?php

namespace Tests\Feature\BackupRelay;

use App\Console\Commands\BackupRestoreCommand;
use App\Models\ActionLog;
use App\Models\Site;
use App\Models\User;
use App\Services\Process\BackgroundArtisan;
use App\Services\Process\BackgroundArtisanResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\BackupRelay\Services\BackupArchiveEnumerator;
use Tests\TestCase;

class BackupRelayRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Storage::fake('s3-backup-relay');
        BackupRestoreCommand::$pollIntervalSeconds = 0;
    }

    protected function tearDown(): void
    {
        BackupRestoreCommand::$pollIntervalSeconds = BackupRestoreCommand::POLL_INTERVAL_SECONDS;
        parent::tearDown();
    }

    private function createCustomSite(array $attributes = []): Site
    {
        return Site::factory()->custom()->withCompanionInstalled()->create(array_merge([
            'domain' => 'restore-test.example.com',
            'backup_relay_enabled' => true,
            'companion_capabilities' => ['backup-relay', 'backup-restore'],
        ], $attributes));
    }

    public function test_guest_is_redirected_to_login_for_restore_endpoints(): void
    {
        $site = $this->createCustomSite();

        $this->post(route('sites.backup-relay.restore.stage', ['site' => $site->id]))
            ->assertRedirect(route('login'));

        $this->post(route('sites.backup-relay.restore.apply', ['site' => $site->id]))
            ->assertRedirect(route('login'));

        $this->get(route('sites.backup-relay.restore.status', ['site' => $site->id]))
            ->assertRedirect(route('login'));
    }

    public function test_restore_endpoints_reject_non_custom_sites(): void
    {
        $pressableSite = Site::query()->create([
            'domain' => 'pressable.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '123',
            'backup_relay_enabled' => true,
            'is_wordpress' => true,
        ]);

        $this->actingAs($this->user)
            ->postJson(route('sites.backup-relay.restore.stage', ['site' => $pressableSite->id]), [
                'archive_key' => 'archives/pressable.example.com/2026-09-11.zip',
                'confirm_domain' => 'pressable.example.com',
            ])
            ->assertStatus(403);

        $this->actingAs($this->user)
            ->postJson(route('sites.backup-relay.restore.apply', ['site' => $pressableSite->id]))
            ->assertStatus(403);

        $this->actingAs($this->user)
            ->getJson(route('sites.backup-relay.restore.status', ['site' => $pressableSite->id]))
            ->assertStatus(403);
    }

    public function test_stage_validates_domain_confirmation(): void
    {
        $site = $this->createCustomSite();

        $this->actingAs($this->user)
            ->postJson(route('sites.backup-relay.restore.stage', ['site' => $site->id]), [
                'archive_key' => "archives/{$site->domain}/2026-09-11.zip",
                'confirm_domain' => 'wrong-domain.example.com',
            ])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'Confirmation text did not match the site domain.']);
    }

    public function test_stage_rejects_when_backup_relay_disabled(): void
    {
        $site = $this->createCustomSite(['backup_relay_enabled' => false]);

        $this->actingAs($this->user)
            ->postJson(route('sites.backup-relay.restore.stage', ['site' => $site->id]), [
                'archive_key' => "archives/{$site->domain}/2026-09-11.zip",
                'confirm_domain' => $site->domain,
            ])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => "Backup relay is not enabled for {$site->domain}."]);
    }

    public function test_stage_rejects_when_companion_lacks_capability(): void
    {
        $site = $this->createCustomSite(['companion_capabilities' => ['backup-relay']]);

        $this->actingAs($this->user)
            ->postJson(route('sites.backup-relay.restore.stage', ['site' => $site->id]), [
                'archive_key' => "archives/{$site->domain}/2026-09-11.zip",
                'confirm_domain' => $site->domain,
            ])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_stage_rejects_when_no_sha256_hash_on_record(): void
    {
        $site = $this->createCustomSite();
        $key = "archives/{$site->domain}/2026-09-01.zip";

        // Put archive in fake S3 without sidecar and without site->backup_relay_last_sha256 matching
        Storage::disk('s3-backup-relay')->put($key, 'archive_data');

        $this->actingAs($this->user)
            ->postJson(route('sites.backup-relay.restore.stage', ['site' => $site->id]), [
                'archive_key' => $key,
                'confirm_domain' => $site->domain,
            ])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => "No integrity hash on record for archive {$key}. Restore refused."]);
    }

    public function test_stage_starts_background_job_when_valid(): void
    {
        $site = $this->createCustomSite();
        $key = "archives/{$site->domain}/2026-09-11.zip";

        // Create sidecar
        Storage::disk('s3-backup-relay')->put($key, 'archive_data');
        Storage::disk('s3-backup-relay')->put("{$key}.sha256.json", json_encode([
            'sha256' => 'abc123expectedhash',
            'size_bytes' => 1234,
        ]));

        $this->mock(BackgroundArtisan::class, function ($mock) use ($site, $key) {
            $mock->shouldReceive('start')
                ->once()
                ->withArgs(function (string $id, array $commands) use ($site, $key) {
                    return $id === "backup_restore.site.{$site->id}"
                        && str_contains($commands[0], 'clockwork:backup-restore')
                        && str_contains($commands[0], '--phase=stage')
                        && str_contains($commands[0], $key);
                })
                ->andReturn(BackgroundArtisanResult::ok());
        });

        $this->actingAs($this->user)
            ->postJson(route('sites.backup-relay.restore.stage', ['site' => $site->id]), [
                'archive_key' => $key,
                'confirm_domain' => $site->domain,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_apply_rejects_when_not_staged(): void
    {
        $site = $this->createCustomSite();

        $this->actingAs($this->user)
            ->postJson(route('sites.backup-relay.restore.apply', ['site' => $site->id]))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => "No staged restore ready to apply for {$site->domain}."]);
    }

    public function test_apply_starts_background_job_when_staged(): void
    {
        $site = $this->createCustomSite();

        Cache::put("backup_restore.site.{$site->id}.state", [
            'status' => 'staged',
            'phase' => 'stage',
            'staged_id' => 'staged_20260911_abc',
            'archive_key' => "archives/{$site->domain}/2026-09-11.zip",
        ]);

        $this->mock(BackgroundArtisan::class, function ($mock) use ($site) {
            $mock->shouldReceive('start')
                ->once()
                ->withArgs(function (string $id, array $commands) use ($site) {
                    return $id === "backup_restore.site.{$site->id}"
                        && str_contains($commands[0], 'clockwork:backup-restore')
                        && str_contains($commands[0], '--phase=apply');
                })
                ->andReturn(BackgroundArtisanResult::ok());
        });

        $this->actingAs($this->user)
            ->postJson(route('sites.backup-relay.restore.apply', ['site' => $site->id]))
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_status_returns_cached_or_idle_state(): void
    {
        $site = $this->createCustomSite();

        // Idle when no cache
        $this->actingAs($this->user)
            ->getJson(route('sites.backup-relay.restore.status', ['site' => $site->id]))
            ->assertOk()
            ->assertJson(['status' => 'idle', 'phase' => null, 'staged_id' => null]);

        // Returns cached state
        Cache::put("backup_restore.site.{$site->id}.state", [
            'status' => 'extracting',
            'phase' => 'stage',
            'progress' => 'Unpacking archive',
        ]);

        $this->actingAs($this->user)
            ->getJson(route('sites.backup-relay.restore.status', ['site' => $site->id]))
            ->assertOk()
            ->assertJson(['status' => 'extracting', 'phase' => 'stage']);
    }

    public function test_resolve_archive_sha256_resolution_order(): void
    {
        $site = $this->createCustomSite([
            'backup_relay_last_sha256' => 'newest_archive_sha256',
        ]);
        $enumerator = app(BackupArchiveEnumerator::class);

        $olderKey = "archives/{$site->domain}/2026-09-01.zip";
        $newerKey = "archives/{$site->domain}/2026-09-11.zip";

        Storage::disk('s3-backup-relay')->put($olderKey, 'older_data');
        Storage::disk('s3-backup-relay')->put($newerKey, 'newer_data');

        // Case 1: Sidecar exists -> returns sidecar sha256
        Storage::disk('s3-backup-relay')->put("{$olderKey}.sha256.json", json_encode([
            'sha256' => 'sidecar_sha256_older',
        ]));
        $this->assertSame('sidecar_sha256_older', $enumerator->resolveArchiveSha256($site, $olderKey));

        // Case 2: No sidecar, but key is the newest archive in S3 -> falls back to site->backup_relay_last_sha256
        $this->assertSame('newest_archive_sha256', $enumerator->resolveArchiveSha256($site, $newerKey));

        // Case 3: No sidecar, not the newest archive -> returns null
        $middleKey = "archives/{$site->domain}/2026-09-05.zip";
        Storage::disk('s3-backup-relay')->put($middleKey, 'middle_data');
        $this->assertNull($enumerator->resolveArchiveSha256($site, $middleKey));
    }

    public function test_backup_restore_command_stage_success(): void
    {
        $site = $this->createCustomSite();
        $key = "archives/{$site->domain}/2026-09-11.zip";
        $sha = 'hash_1234567890abcdef';

        Storage::disk('s3-backup-relay')->put($key, 'data');
        Storage::disk('s3-backup-relay')->put("{$key}.sha256.json", json_encode(['sha256' => $sha]));

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/backup/restore/stage*" => Http::response([
                'ok' => true,
                'status' => 'downloading',
            ], 200),
            "https://{$site->domain}/wp-json/clockwork/v1/backup/restore/status*" => Http::response([
                'status' => 'staged',
                'phase' => 'stage',
                'staged_id' => 'staged_001',
                'has_sql' => true,
                'has_files' => true,
                'table_prefix' => 'wp_',
            ], 200),
        ]);

        $this->artisan('clockwork:backup-restore', [
            '--site' => $site->id,
            '--key' => $key,
            '--phase' => 'stage',
            '--actor' => 'admin@example.com',
        ])->assertSuccessful();

        $cached = Cache::get("backup_restore.site.{$site->id}.state");
        $this->assertSame('staged', $cached['status']);
        $this->assertSame('staged_001', $cached['staged_id']);

        $this->assertTrue(ActionLog::query()->where('action_type', ActionLog::TYPE_BACKUP_RESTORE_STAGED)->exists());
    }

    public function test_backup_restore_command_apply_success(): void
    {
        $site = $this->createCustomSite();
        $archiveKey = "archives/{$site->domain}/2026-09-11.zip";

        Cache::put("backup_restore.site.{$site->id}.state", [
            'status' => 'staged',
            'phase' => 'stage',
            'staged_id' => 'staged_002',
            'archive_key' => $archiveKey,
        ]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/backup/restore/apply*" => Http::response([
                'ok' => true,
                'status' => 'applying_sql',
            ], 200),
            "https://{$site->domain}/wp-json/clockwork/v1/backup/restore/status*" => Http::response([
                'status' => 'applied',
                'phase' => 'apply',
                'staged_id' => 'staged_002',
            ], 200),
        ]);

        $this->artisan('clockwork:backup-restore', [
            '--site' => $site->id,
            '--phase' => 'apply',
            '--actor' => 'admin@example.com',
        ])->assertSuccessful();

        $cached = Cache::get("backup_restore.site.{$site->id}.state");
        $this->assertSame('applied', $cached['status']);

        $this->assertTrue(ActionLog::query()->where('action_type', ActionLog::TYPE_BACKUP_RESTORE_APPLIED)->exists());
    }

    public function test_backup_restore_command_apply_fail_closed_maintenance(): void
    {
        $site = $this->createCustomSite();
        $archiveKey = "archives/{$site->domain}/2026-09-11.zip";

        Cache::put("backup_restore.site.{$site->id}.state", [
            'status' => 'staged',
            'phase' => 'stage',
            'staged_id' => 'staged_003',
            'archive_key' => $archiveKey,
        ]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/backup/restore/apply*" => Http::response([
                'ok' => true,
                'status' => 'applying_sql',
            ], 200),
            "https://{$site->domain}/wp-json/clockwork/v1/backup/restore/status*" => Http::response([
                'status' => 'failed',
                'phase' => 'apply',
                'error' => 'sql_failed',
                'error_detail' => 'Database syntax error on line 42',
            ], 200),
        ]);

        $this->artisan('clockwork:backup-restore', [
            '--site' => $site->id,
            '--phase' => 'apply',
            '--actor' => 'admin@example.com',
        ])->assertFailed();

        $cached = Cache::get("backup_restore.site.{$site->id}.state");
        $this->assertSame('failed', $cached['status']);
        $this->assertSame('sql_failed', $cached['error']);
        $this->assertTrue($cached['maintenance_left_on']);

        $this->assertTrue(ActionLog::query()->where('action_type', ActionLog::TYPE_BACKUP_RESTORE_FAILED)->exists());
    }
}
