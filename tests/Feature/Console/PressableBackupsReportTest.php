<?php

namespace Tests\Feature\Console;

use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Pressable\PressableClient;

/*
|--------------------------------------------------------------------------
| Phase 6 console-command coverage: clockwork:pressable-backups-report
|--------------------------------------------------------------------------
|
| Pulls per-site filesystem/database backup history from Pressable (via the
| dedicated /backups/fs and /backups/db endpoints — see the command's own
| docblock for why not the combined siteBackups() endpoint) plus an
| optional offsite-archive manifest from S3, and POSTs the assembled report
| to each Companion-equipped Pressable site's /backups-report endpoint.
|
| PressableClient is mocked directly as a collaborator (same style as
| PushCompanionBackupsReportTest mocking SpinupWpClient) — its real
| transport is Illuminate\Http\Client under the hood, but re-deriving
| OAuth2-token + metrics-endpoint HTTP fixtures for every test here would
| duplicate PressableClient's own test coverage rather than this command's.
| Only the outbound Companion push is asserted at the real HTTP boundary
| via Http::fake(), matching CompanionHmacAuthTest's approach. Existing
| tests/Fixtures/PressableFixtures.php shapes (site/backup/listResponse) are
| raw pre-unwrap API payloads for a different corner of Pressable (site
| listing) — not reused here since PressableClient::siteFilesystemBackups()
| etc. return already-unwrapped arrays, same reasoning pcbrMockSpinup()
| applies to SpinupWpClient.
*/

function pbrSite(array $overrides = []): Site
{
    return Site::factory()->pressable()->withCompanionInstalled()->create(array_merge([
        'companion_capabilities' => ['backups-report'],
    ], $overrides));
}

function pbrMockPressable(?callable $configure = null): void
{
    test()->mock(PressableClient::class, function ($mock) use ($configure) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        if ($configure) {
            $configure($mock);
        }
    });
}

function pbrManifestKey(): string
{
    return rtrim((string) config('clockwork.backup_relay.s3_prefix'), '/').'/download-links.json';
}

describe('clockwork:pressable-backups-report — happy path', function () {
    it('parses fs/db history from Pressable and pushes a report with no offsite archive when no manifest exists', function () {
        Storage::fake('s3');
        $site = pbrSite();

        pbrMockPressable(function ($mock) use ($site) {
            $mock->shouldReceive('siteFilesystemBackups')->once()->with($site->pressable_site_id)->andReturn([
                ['backup_timestamp' => '2026-08-29 00:00:00', 'title' => 'Sat, 29 Aug 2026 00:00:00 UTC - 272.92 MB'],
                ['backup_timestamp' => '2026-08-22 00:00:00', 'title' => 'Sat, 22 Aug 2026 00:00:00 UTC - 250.00 MB (on-demand)'],
            ]);
            $mock->shouldReceive('siteDatabaseBackups')->once()->with($site->pressable_site_id)->andReturn([
                ['backup_timestamp' => '2026-08-29 06:00:00', 'title' => 'Sat, 29 Aug 2026 06:00:00 UTC - 12.50 MB'],
            ]);
        });

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/backups-report" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:pressable-backups-report')->assertSuccessful();

        Http::assertSent(function ($request) use ($site) {
            if ($request->url() !== "https://{$site->domain}/wp-json/clockwork/v1/backups-report") {
                return false;
            }
            $body = json_decode($request->body(), true);

            return $body['source'] === 'pressable'
                && count($body['history_files']) === 2
                && count($body['history_database']) === 1
                && $body['history_files'][0]['type'] === 'automatic'
                && $body['history_files'][1]['type'] === 'on-demand'
                && $body['last_backup_at'] === '2026-08-29T06:00:00+00:00'
                && $body['config']['files'] === true
                && $body['config']['database'] === true
                && $body['offsite_archive'] === null;
        });
    });

    it('attaches the offsite-archive manifest entry when the S3 download-links manifest lists the site', function () {
        Storage::fake('s3');
        $site = pbrSite();

        pbrMockPressable(function ($mock) {
            $mock->shouldReceive('siteFilesystemBackups')->once()->andReturn([]);
            $mock->shouldReceive('siteDatabaseBackups')->once()->andReturn([]);
        });

        Storage::disk('s3')->put(pbrManifestKey(), json_encode([
            'sites' => [
                $site->domain => [
                    'fs_download_url' => 'https://relay.example.com/fs.tar.gz',
                    'db_download_url' => 'https://relay.example.com/db.sql.gz',
                ],
            ],
            'generated_at' => '2026-08-30T00:00:00+00:00',
            'expires_at' => '2026-09-06T00:00:00+00:00',
        ]));

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/backups-report" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:pressable-backups-report')->assertSuccessful();

        Http::assertSent(function ($request) use ($site) {
            if ($request->url() !== "https://{$site->domain}/wp-json/clockwork/v1/backups-report") {
                return false;
            }
            $body = json_decode($request->body(), true);

            return $body['offsite_archive'] === [
                'active' => true,
                'last_archived_at' => '2026-08-30T00:00:00+00:00',
                'fs_download_url' => 'https://relay.example.com/fs.tar.gz',
                'db_download_url' => 'https://relay.example.com/db.sql.gz',
                'download_expires_at' => '2026-09-06T00:00:00+00:00',
            ];
        });
    });

    it('treats an unreadable offsite-archive manifest as absent rather than failing the run', function () {
        Log::spy();
        Storage::fake('s3');
        $site = pbrSite();

        pbrMockPressable(function ($mock) {
            $mock->shouldReceive('siteFilesystemBackups')->once()->andReturn([]);
            $mock->shouldReceive('siteDatabaseBackups')->once()->andReturn([]);
        });

        Storage::disk('s3')->put(pbrManifestKey(), 'not valid json{{{');

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/backups-report" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:pressable-backups-report')->assertSuccessful();

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['offsite_archive'] === null;
        });
        Log::shouldHaveReceived('warning')->with('companion.pressable_backups_report.offsite_manifest_unreadable', \Mockery::type('array'));
    });
});

describe('clockwork:pressable-backups-report — offsite S3 archive enrichment (no manifest, backup-relay-enabled site)', function () {
    it('attaches real presigned URLs and an accurate expiry when off-site archives exist', function () {
        Storage::fake('s3');
        Storage::fake('s3-backup-relay');
        $disk = Storage::disk('s3-backup-relay');

        $site = pbrSite(['backup_relay_enabled' => true]);
        $disk->put("archives/{$site->domain}/2026-09-06_fs.bz2", str_repeat('A', 1024));
        $disk->put("archives/{$site->domain}/2026-09-06_db.sql", str_repeat('B', 512));

        pbrMockPressable(function ($mock) {
            $mock->shouldReceive('siteFilesystemBackups')->once()->andReturn([]);
            $mock->shouldReceive('siteDatabaseBackups')->once()->andReturn([]);
        });

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/backups-report" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:pressable-backups-report')->assertSuccessful();

        Http::assertSent(function ($request) use ($site) {
            if ($request->url() !== "https://{$site->domain}/wp-json/clockwork/v1/backups-report") {
                return false;
            }
            $archive = json_decode($request->body(), true)['offsite_archive'];

            $expiresInHours = now()->diffInHours(Carbon::parse($archive['download_expires_at']));

            return $archive['active'] === true
                && ! empty($archive['fs_download_url'])
                && ! empty($archive['db_download_url'])
                && $archive['download_expires_at'] !== null
                && $expiresInHours >= 23 && $expiresInHours <= 24;
        });
    });

    it('does not attach an offsite_archive when the disk cannot mint real presigned URLs, even if matching files exist', function () {
        Storage::fake('s3');
        Storage::fake('s3-backup-relay');
        Storage::disk('s3-backup-relay')->put('archives/no-presigned.example.com/2026-09-06_fs.bz2', 'x');

        $site = pbrSite(['domain' => 'no-presigned.example.com', 'backup_relay_enabled' => true]);

        config(['filesystems.disks.s3-backup-relay.driver' => 'local']);

        pbrMockPressable(function ($mock) {
            $mock->shouldReceive('siteFilesystemBackups')->once()->andReturn([]);
            $mock->shouldReceive('siteDatabaseBackups')->once()->andReturn([]);
        });

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/backups-report" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:pressable-backups-report')->assertSuccessful();

        Http::assertSent(function ($request) {
            return json_decode($request->body(), true)['offsite_archive'] === null;
        });
    });
});

describe('clockwork:pressable-backups-report — skip and failure handling', function () {
    it('exits FAILURE immediately when Pressable is not configured, without touching any site', function () {
        Storage::fake('s3');
        pbrSite();

        $this->mock(PressableClient::class, fn ($mock) => $mock->shouldReceive('isConfigured')->andReturn(false));

        Http::fake();

        $this->artisan('clockwork:pressable-backups-report')->assertFailed();

        Http::assertNothingSent();
    });

    it('skips a site whose Companion does not advertise the backups-report capability', function () {
        Storage::fake('s3');
        pbrSite(['companion_capabilities' => ['snapshot']]);

        pbrMockPressable();

        Http::fake();

        $this->artisan('clockwork:pressable-backups-report')->assertSuccessful();

        Http::assertNothingSent();
    });

    it('logs and continues (still SUCCESS overall) when the Pressable fetch fails for a site, without pushing to Companion', function () {
        Log::spy();
        Storage::fake('s3');
        pbrSite();

        pbrMockPressable(function ($mock) {
            $mock->shouldReceive('siteFilesystemBackups')->once()->andThrow(new \RuntimeException('Pressable 503'));
        });

        Http::fake();

        $this->artisan('clockwork:pressable-backups-report')->assertSuccessful();

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->with('companion.pressable_backups_report.fetch_failed', \Mockery::type('array'));
    });

    it('logs and continues (still SUCCESS overall) when the push to Companion itself fails', function () {
        Log::spy();
        Storage::fake('s3');
        $site = pbrSite();

        pbrMockPressable(function ($mock) {
            $mock->shouldReceive('siteFilesystemBackups')->once()->andReturn([]);
            $mock->shouldReceive('siteDatabaseBackups')->once()->andReturn([]);
        });

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/backups-report" => Http::response(['message' => 'error'], 500, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:pressable-backups-report')->assertSuccessful();

        Log::shouldHaveReceived('warning')->with('companion.pressable_backups_report.push_failed', \Mockery::type('array'));
    });
});

describe('clockwork:pressable-backups-report — options', function () {
    it('--site limits the run to a single site by domain', function () {
        Storage::fake('s3');
        $target = pbrSite();
        $other = pbrSite();

        pbrMockPressable(function ($mock) {
            $mock->shouldReceive('siteFilesystemBackups')->once()->andReturn([]);
            $mock->shouldReceive('siteDatabaseBackups')->once()->andReturn([]);
        });

        Http::fake([
            "https://{$target->domain}/wp-json/clockwork/v1/backups-report" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:pressable-backups-report', ['--site' => $target->domain])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === "https://{$target->domain}/wp-json/clockwork/v1/backups-report");
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $other->domain));
    });
});
