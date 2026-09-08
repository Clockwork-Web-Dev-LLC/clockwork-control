<?php

namespace Tests\Feature\Controllers;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RendersAuthenticatedPages;
use Tests\TestCase;

class BackupRelayArchivesTest extends TestCase
{
    use RefreshDatabase;
    use RendersAuthenticatedPages;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockIssueCounterZero();
        $this->user = User::factory()->create();
    }

    public function test_guest_is_redirected_to_login_for_archives(): void
    {
        $site = Site::query()->create([
            'domain' => 'archives-test.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '501',
            'backup_relay_enabled' => true,
            'is_wordpress' => true,
        ]);

        $this->get(route('settings.backup-relay.archives', ['site' => $site->id]))
            ->assertRedirect(route('login'));

        $this->get(route('settings.backup-relay.download', ['site' => $site->id, 'key' => base64_encode('foo')]))
            ->assertRedirect(route('login'));
    }

    public function test_archives_endpoint_returns_json_with_s3_objects(): void
    {
        Storage::fake('s3-backup-relay');
        $disk = Storage::disk('s3-backup-relay');

        $site = Site::query()->create([
            'domain' => 'mysite.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '502',
            'backup_relay_enabled' => true,
            'is_wordpress' => true,
        ]);

        // Place test files in S3 Glacier disk:
        // In-repo archive
        $disk->put("archives/{$site->domain}/2026-09-08_full_123.archive", str_repeat('A', 1024 * 1024));
        // External agent files
        $disk->put("{$site->domain}/fs/2026-09-07_fs.bz2", str_repeat('B', 512 * 1024));
        $disk->put("{$site->domain}/db/2026-09-07_db.sql", str_repeat('C', 256 * 1024));

        $res = $this->actingAs($this->user)
            ->getJson(route('settings.backup-relay.archives', ['site' => $site->id]))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'site_id' => $site->id,
                'domain' => 'mysite.example.com',
                'total_count' => 3,
            ]);

        $data = $res->json();
        $this->assertCount(3, $data['archives']);

        $types = collect($data['archives'])->pluck('type')->sort()->values()->all();
        $this->assertSame(['db', 'fs', 'full'], $types);

        foreach ($data['archives'] as $archive) {
            $this->assertNotEmpty($archive['download_url']);
            $this->assertSame('GLACIER_IR', $archive['storage_class']);
            $this->assertNotEmpty($archive['size_formatted']);
        }
    }

    public function test_download_endpoint_validates_key_presence_and_encoding(): void
    {
        $site = Site::query()->create([
            'domain' => 'download-test.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '503',
            'backup_relay_enabled' => true,
            'is_wordpress' => true,
        ]);

        // Missing key
        $this->actingAs($this->user)
            ->get(route('settings.backup-relay.download', ['site' => $site->id]))
            ->assertStatus(400);

        // Invalid base64 key
        $this->actingAs($this->user)
            ->get(route('settings.backup-relay.download', ['site' => $site->id, 'key' => '!!!not-base64!!!']))
            ->assertStatus(400);
    }

    public function test_download_endpoint_prevents_unauthorized_key_traversal(): void
    {
        $siteA = Site::query()->create([
            'domain' => 'site-a.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '504',
            'backup_relay_enabled' => true,
            'is_wordpress' => true,
        ]);

        // Trying to download site-b's archive using site-a's endpoint
        $foreignKey = 'archives/site-b.example.com/2026-09-08.archive';

        $this->actingAs($this->user)
            ->get(route('settings.backup-relay.download', [
                'site' => $siteA->id,
                'key' => base64_encode($foreignKey),
            ]))
            ->assertStatus(403);
    }

    public function test_download_endpoint_returns_404_if_object_missing_in_s3(): void
    {
        Storage::fake('s3-backup-relay');

        $site = Site::query()->create([
            'domain' => 'site-missing.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '505',
            'backup_relay_enabled' => true,
            'is_wordpress' => true,
        ]);

        $missingKey = "archives/{$site->domain}/missing.archive";

        $this->actingAs($this->user)
            ->get(route('settings.backup-relay.download', [
                'site' => $site->id,
                'key' => base64_encode($missingKey),
            ]))
            ->assertStatus(404);
    }

    public function test_download_endpoint_serves_existing_archive(): void
    {
        Storage::fake('s3-backup-relay');
        $disk = Storage::disk('s3-backup-relay');

        $site = Site::query()->create([
            'domain' => 'site-served.example.com',
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '506',
            'backup_relay_enabled' => true,
            'is_wordpress' => true,
        ]);

        $validKey = "archives/{$site->domain}/2026-09-08_backup.archive";
        $disk->put($validKey, 'ARCHIVE_CONTENT_FOR_DOWNLOAD_TEST');

        $res = $this->actingAs($this->user)
            ->get(route('settings.backup-relay.download', [
                'site' => $site->id,
                'key' => base64_encode($validKey),
            ]));

        // Depending on disk driver (fake supports download stream), verify success
        $this->assertTrue(in_array($res->getStatusCode(), [200, 302], true));
    }
}
