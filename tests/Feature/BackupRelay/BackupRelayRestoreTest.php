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
        BackupRestoreCommand::$maxPollSeconds = BackupRestoreCommand::MAX_POLL_SECONDS;
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

        $this->get(route('sites.backup-relay.restore.precheck', ['site' => $site->id, 'key' => 'x']))
            ->assertRedirect(route('login'));

        $this->post(route('sites.backup-relay.restore.discard', ['site' => $site->id]))
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

        $this->actingAs($this->user)
            ->getJson(route('sites.backup-relay.restore.precheck', ['site' => $pressableSite->id, 'key' => 'x']))
            ->assertStatus(403);

        $this->actingAs($this->user)
            ->postJson(route('sites.backup-relay.restore.discard', ['site' => $pressableSite->id]))
            ->assertStatus(403);
    }

    public function test_stage_validates_domain_confirmation(): void
    {
        $site = $this->createCustomSite();

        $this->mock(BackgroundArtisan::class, function ($mock) {
            $mock->shouldNotReceive('start');
        });

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
        Http::fake();
        $site = $this->createCustomSite(['backup_relay_enabled' => false]);

        $this->actingAs($this->user)
            ->postJson(route('sites.backup-relay.restore.stage', ['site' => $site->id]), [
                'archive_key' => "archives/{$site->domain}/2026-09-11.zip",
                'confirm_domain' => $site->domain,
            ])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => "Backup relay is not enabled for {$site->domain}."]);

        Http::assertNothingSent();
    }

    public function test_stage_rejects_when_companion_lacks_capability(): void
    {
        Http::fake();
        $site = $this->createCustomSite(['companion_capabilities' => ['backup-relay']]);

        $this->actingAs($this->user)
            ->postJson(route('sites.backup-relay.restore.stage', ['site' => $site->id]), [
                'archive_key' => "archives/{$site->domain}/2026-09-11.zip",
                'confirm_domain' => $site->domain,
            ])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        Http::assertNothingSent();
    }

    public function test_stage_rejects_when_no_sha256_hash_on_record(): void
    {
        Http::fake();
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

        Http::assertNothingSent();
    }

    public function test_stage_rejects_when_disk_does_not_support_presigned_urls(): void
    {
        Http::fake();
        config()->set('filesystems.disks.s3-backup-relay.driver', 'local');
        $site = $this->createCustomSite();

        $this->actingAs($this->user)
            ->postJson(route('sites.backup-relay.restore.stage', ['site' => $site->id]), [
                'archive_key' => "archives/{$site->domain}/2026-09-11.zip",
                'confirm_domain' => $site->domain,
            ])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'Backup relay storage disk does not support presigned URLs.']);

        Http::assertNothingSent();
    }

    public function test_stage_returns_409_while_backup_now_lock_is_held(): void
    {
        $site = $this->createCustomSite();
        $key = "archives/{$site->domain}/2026-09-11.zip";

        Storage::disk('s3-backup-relay')->put($key, 'archive_data');
        Storage::disk('s3-backup-relay')->put("{$key}.sha256.json", json_encode([
            'sha256' => 'abc123expectedhash',
        ]));

        // Simulate the BackgroundArtisan Cache::add lock a running Backup Now holds
        Cache::put("backup_relay.run.site.{$site->id}", now()->toIso8601String(), 3600);

        $this->mock(BackgroundArtisan::class, function ($mock) {
            $mock->shouldNotReceive('start');
        });

        $this->actingAs($this->user)
            ->postJson(route('sites.backup-relay.restore.stage', ['site' => $site->id]), [
                'archive_key' => $key,
                'confirm_domain' => $site->domain,
            ])
            ->assertStatus(409)
            ->assertJson(['ok' => false]);
    }

    public function test_run_backup_now_returns_409_while_restore_is_in_flight(): void
    {
        $site = $this->createCustomSite();

        Cache::put("backup_restore.site.{$site->id}.state", [
            'status' => 'downloading',
            'phase' => 'stage',
        ]);

        $this->mock(BackgroundArtisan::class, function ($mock) {
            $mock->shouldNotReceive('start');
        });

        $this->actingAs($this->user)
            ->postJson(route('sites.backup-relay.run-now', ['site' => $site->id]))
            ->assertStatus(409)
            ->assertJson(['ok' => false]);
    }

    public function test_run_backup_now_returns_409_while_restore_lock_is_held(): void
    {
        $site = $this->createCustomSite();

        // Simulate the BackgroundArtisan Cache::add lock a running restore holds
        Cache::put("backup_restore.site.{$site->id}", now()->toIso8601String(), 3600);

        $this->mock(BackgroundArtisan::class, function ($mock) {
            $mock->shouldNotReceive('start');
        });

        $this->actingAs($this->user)
            ->postJson(route('sites.backup-relay.run-now', ['site' => $site->id]))
            ->assertStatus(409)
            ->assertJson(['ok' => false]);
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
            ->postJson(route('sites.backup-relay.restore.apply', ['site' => $site->id]), [
                'archive_key' => "archives/{$site->domain}/2026-09-11.zip",
            ])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => "No staged restore ready to apply for {$site->domain}."]);
    }

    public function test_apply_starts_background_job_when_staged(): void
    {
        $site = $this->createCustomSite();
        $archiveKey = "archives/{$site->domain}/2026-09-11.zip";

        Cache::put("backup_restore.site.{$site->id}.state", [
            'status' => 'staged',
            'phase' => 'stage',
            'staged_id' => 'staged_20260911_abc',
            'archive_key' => $archiveKey,
        ]);

        $this->mock(BackgroundArtisan::class, function ($mock) use ($site, $archiveKey) {
            $mock->shouldReceive('start')
                ->once()
                ->withArgs(function (string $id, array $commands) use ($site, $archiveKey) {
                    return $id === "backup_restore.site.{$site->id}"
                        && str_contains($commands[0], 'clockwork:backup-restore')
                        && str_contains($commands[0], '--phase=apply')
                        && str_contains($commands[0], '--key=')
                        && str_contains($commands[0], $archiveKey);
                })
                ->andReturn(BackgroundArtisanResult::ok());
        });

        $this->actingAs($this->user)
            ->postJson(route('sites.backup-relay.restore.apply', ['site' => $site->id]), [
                'archive_key' => $archiveKey,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_discard_clears_cached_restore_state(): void
    {
        $site = $this->createCustomSite();

        Cache::put("backup_restore.site.{$site->id}.state", [
            'status' => 'staged',
            'phase' => 'stage',
            'staged_id' => 'staged_20260911_abc',
            'archive_key' => "archives/{$site->domain}/2026-09-11.zip",
        ]);

        $this->actingAs($this->user)
            ->postJson(route('sites.backup-relay.restore.discard', ['site' => $site->id]))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertNull(Cache::get("backup_restore.site.{$site->id}.state"));
    }

    public function test_precheck_reports_sha256_availability(): void
    {
        $site = $this->createCustomSite();
        $withSidecar = "archives/{$site->domain}/2026-09-01.zip";
        $withoutSidecar = "archives/{$site->domain}/2026-08-01.zip";

        Storage::disk('s3-backup-relay')->put($withSidecar, 'archive_data');
        Storage::disk('s3-backup-relay')->put("{$withSidecar}.sha256.json", json_encode([
            'sha256' => 'abc123expectedhash',
        ]));
        Storage::disk('s3-backup-relay')->put($withoutSidecar, 'archive_data');
        // A newer archive so neither key falls back to backup_relay_last_sha256
        Storage::disk('s3-backup-relay')->put("archives/{$site->domain}/2026-09-11.zip", 'newest');

        $this->actingAs($this->user)
            ->getJson(route('sites.backup-relay.restore.precheck', ['site' => $site->id, 'key' => $withSidecar]))
            ->assertOk()
            ->assertJson(['sha256_available' => true]);

        $this->actingAs($this->user)
            ->getJson(route('sites.backup-relay.restore.precheck', ['site' => $site->id, 'key' => $withoutSidecar]))
            ->assertOk()
            ->assertJson(['sha256_available' => false]);
    }

    public function test_precheck_returns_false_when_disk_is_misconfigured(): void
    {
        $site = $this->createCustomSite();
        config()->set('clockwork.backup_relay.disk', 'no-such-disk');

        $this->actingAs($this->user)
            ->getJson(route('sites.backup-relay.restore.precheck', ['site' => $site->id, 'key' => "archives/{$site->domain}/2026-09-11.zip"]))
            ->assertOk()
            ->assertJson(['sha256_available' => false]);
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
            '--key' => $archiveKey,
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
            '--key' => $archiveKey,
            '--phase' => 'apply',
            '--actor' => 'admin@example.com',
        ])->assertFailed();

        $cached = Cache::get("backup_restore.site.{$site->id}.state");
        $this->assertSame('failed', $cached['status']);
        $this->assertSame('sql_failed', $cached['error']);
        $this->assertTrue($cached['maintenance_left_on']);

        $this->assertTrue(ActionLog::query()->where('action_type', ActionLog::TYPE_BACKUP_RESTORE_FAILED)->exists());
    }

    public function test_backup_restore_command_apply_rejects_mismatched_archive_key(): void
    {
        Http::fake();
        $site = $this->createCustomSite();
        $stagedKey = "archives/{$site->domain}/2026-09-11.zip";

        Cache::put("backup_restore.site.{$site->id}.state", [
            'status' => 'staged',
            'phase' => 'stage',
            'staged_id' => 'staged_004',
            'archive_key' => $stagedKey,
        ]);

        $this->artisan('clockwork:backup-restore', [
            '--site' => $site->id,
            '--key' => "archives/{$site->domain}/2026-08-01.zip",
            '--phase' => 'apply',
            '--actor' => 'admin@example.com',
        ])->assertFailed();

        $cached = Cache::get("backup_restore.site.{$site->id}.state");
        $this->assertSame('failed', $cached['status']);
        $this->assertSame('archive_key_mismatch', $cached['error']);

        // No apply POST may go out on mismatch. (ActionLogger mirrors the
        // failure row to Companion over HTTP, so assertNothingSent is too strict.)
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/backup/restore/apply'));

        $this->assertTrue(ActionLog::query()->where('action_type', ActionLog::TYPE_BACKUP_RESTORE_FAILED)->exists());
    }

    public function test_backup_restore_command_apply_poll_timeout_sets_maintenance_left_on(): void
    {
        BackupRestoreCommand::$maxPollSeconds = 0;

        $site = $this->createCustomSite();
        $archiveKey = "archives/{$site->domain}/2026-09-11.zip";

        Cache::put("backup_restore.site.{$site->id}.state", [
            'status' => 'staged',
            'phase' => 'stage',
            'staged_id' => 'staged_005',
            'archive_key' => $archiveKey,
        ]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/backup/restore/apply*" => Http::response([
                'ok' => true,
                'status' => 'applying_sql',
            ], 200),
        ]);

        $this->artisan('clockwork:backup-restore', [
            '--site' => $site->id,
            '--key' => $archiveKey,
            '--phase' => 'apply',
            '--actor' => 'admin@example.com',
        ])->assertFailed();

        $cached = Cache::get("backup_restore.site.{$site->id}.state");
        $this->assertSame('failed', $cached['status']);
        $this->assertSame('timeout', $cached['error']);
        // Conservative fail-closed: no affirmative evidence maintenance was
        // lifted, so the operator must be warned it may still be on.
        $this->assertTrue($cached['maintenance_left_on']);

        $this->assertTrue(ActionLog::query()->where('action_type', ActionLog::TYPE_BACKUP_RESTORE_FAILED)->exists());
    }

    public function test_backup_restore_command_stage_records_filename_in_state(): void
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
                'staged_id' => 'staged_006',
            ], 200),
        ]);

        $this->artisan('clockwork:backup-restore', [
            '--site' => $site->id,
            '--key' => $key,
            '--phase' => 'stage',
            '--actor' => 'admin@example.com',
        ])->assertSuccessful();

        $cached = Cache::get("backup_restore.site.{$site->id}.state");
        $this->assertSame($key, $cached['archive_key']);
        $this->assertSame('2026-09-11.zip', $cached['filename']);
    }

    public function test_site_overview_does_not_render_restore_button_for_non_custom_sites(): void
    {
        $spinupSite = Site::factory()->spinupwp()->create([
            'domain' => 'spinup-no-restore.example.com',
        ]);

        $this->actingAs($this->user)
            ->get(route('sites.show', ['site' => $spinupSite->id, 'tab' => 'overview']))
            ->assertOk()
            ->assertDontSee('Restore this archive to the site');

        $customSite = $this->createCustomSite();

        $this->actingAs($this->user)
            ->get(route('sites.show', ['site' => $customSite->id, 'tab' => 'overview']))
            ->assertOk()
            ->assertSee('Restore this archive to the site');
    }
}
