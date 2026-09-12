<?php

namespace Tests\Feature\Console;

use App\Models\Server;
use App\Models\Site;
use App\Services\DigitalOcean\SpacesClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\BackupRelay\Services\BackupArchiveEnumerator;
use Modules\SpinupWp\SpinupWpClient;

/*
|--------------------------------------------------------------------------
| Phase 6 console-command coverage: clockwork:push-companion-backups
|--------------------------------------------------------------------------
|
| Pulls per-site backup config from SpinupWP + run history from DO Spaces
| and POSTs the assembled report to each site's Companion /backups-report
| endpoint. SpinupWpClient and SpacesClient are mocked directly as
| collaborators (same style as ProcessServerUpdatesTest mocking
| ServerUpdater/AptUpdateProbe) — both wrap non-HTTP-fakeable transports
| (SpacesClient sits on a Flysystem disk, not Illuminate\Http). Only the
| outbound Companion push is asserted at the real HTTP boundary via
| Http::fake(), matching CompanionHmacAuthTest's approach.
*/

function pcbrSite(array $overrides = []): Site
{
    return Site::factory()->withCompanionInstalled()->create(array_merge([
        'companion_capabilities' => ['backups-report'],
    ], $overrides));
}

function pcbrMockSpinup(?callable $configure = null): void
{
    test()->mock(SpinupWpClient::class, function ($mock) use ($configure) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        if ($configure) {
            $configure($mock);
        }
    });
}

describe('clockwork:push-companion-backups — happy path', function () {
    it('pushes SpinupWP config + Spaces history/schedules to a care-plan site with 90-day retention', function () {
        $site = pcbrSite(['care_plan_enabled' => true]);

        pcbrMockSpinup(function ($mock) use ($site) {
            $mock->shouldReceive('siteBackupConfig')
                ->once()
                ->with($site->spinupwp_id)
                ->andReturn(['files' => true, 'database' => true, 'next_run_time' => '2026-09-04T03:00:00+00:00']);
        });

        $this->mock(SpacesClient::class, function ($mock) use ($site) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('listSiteBackupObjects')->once()->with(\Mockery::on(fn ($s) => $s->is($site)))->andReturn([
                ['key' => "{$site->domain}/2026-09-01-03-00-00-files.tar.gz", 'size' => 100, 'last_modified' => now()->subDays(2)->timestamp],
            ]);
            $mock->shouldReceive('toHistoryRows')->once()->andReturn([
                ['date' => now()->subDays(2)->toIso8601String(), 'type' => 'daily', 'database_bytes' => 4200, 'files_bytes' => 100, 'notes' => null],
            ]);
            $mock->shouldReceive('inferSchedules')->once()->andReturn([['type' => 'daily', 'hour_utc' => 3]]);
        });

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/backups-report" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:push-companion-backups')->assertSuccessful();

        Http::assertSent(function ($request) use ($site) {
            if ($request->url() !== "https://{$site->domain}/wp-json/clockwork/v1/backups-report") {
                return false;
            }
            $body = json_decode($request->body(), true);

            return $body['source'] === 'spinupwp+spaces'
                && $body['config'] === ['files' => true, 'database' => true, 'next_run_time' => '2026-09-04T03:00:00+00:00']
                && $body['care_plan_enabled'] === true
                && $body['retention_days'] === 90
                && count($body['history']) === 1
                && count($body['schedules']) === 1;
        });
    });

    it('uses 30-day retention and filters out history older than the window for a non-care-plan site', function () {
        $site = pcbrSite(['care_plan_enabled' => false]);

        pcbrMockSpinup(function ($mock) {
            $mock->shouldReceive('siteBackupConfig')->once()->andReturn(['files' => true, 'database' => false]);
        });

        $this->mock(SpacesClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('listSiteBackupObjects')->once()->andReturn([]);
            $mock->shouldReceive('toHistoryRows')->once()->andReturn([
                ['date' => now()->subDays(10)->toIso8601String(), 'type' => 'daily', 'database_bytes' => 1, 'files_bytes' => 1, 'notes' => null],
                ['date' => now()->subDays(45)->toIso8601String(), 'type' => 'daily', 'database_bytes' => 1, 'files_bytes' => 1, 'notes' => null],
            ]);
            $mock->shouldReceive('inferSchedules')->once()->andReturn([]);
        });

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/backups-report" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:push-companion-backups')->assertSuccessful();

        Http::assertSent(function ($request) use ($site) {
            if ($request->url() !== "https://{$site->domain}/wp-json/clockwork/v1/backups-report") {
                return false;
            }
            $body = json_decode($request->body(), true);

            return $body['retention_days'] === 30 && count($body['history']) === 1;
        });
    });

    it('pushes config-only (empty history/schedules) when Spaces is not configured, and warns', function () {
        $site = pcbrSite();

        pcbrMockSpinup(function ($mock) {
            $mock->shouldReceive('siteBackupConfig')->once()->andReturn(['files' => true, 'database' => true]);
        });

        $this->mock(SpacesClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(false);
            $mock->shouldNotReceive('listSiteBackupObjects');
        });

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/backups-report" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:push-companion-backups')->assertSuccessful();

        Http::assertSent(function ($request) use ($site) {
            if ($request->url() !== "https://{$site->domain}/wp-json/clockwork/v1/backups-report") {
                return false;
            }
            $body = json_decode($request->body(), true);

            return $body['history'] === [] && $body['schedules'] === [];
        });
    });
});

describe('clockwork:push-companion-backups — offsite S3 archive enrichment', function () {
    it('attaches real presigned URLs and an accurate expiry when off-site archives exist for a backup-relay-enabled site', function () {
        Storage::fake('s3-backup-relay');
        $disk = Storage::disk('s3-backup-relay');

        $site = pcbrSite(['backup_relay_enabled' => true]);

        $disk->put("archives/{$site->domain}/2026-09-06_fs.bz2", str_repeat('A', 1024));
        $disk->put("archives/{$site->domain}/2026-09-06_db.sql", str_repeat('B', 512));

        pcbrMockSpinup(function ($mock) use ($site) {
            $mock->shouldReceive('siteBackupConfig')->once()->with($site->spinupwp_id)->andReturn(['files' => true, 'database' => true]);
        });
        $this->mock(SpacesClient::class, fn ($mock) => $mock->shouldReceive('isConfigured')->andReturn(false));

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/backups-report" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:push-companion-backups')->assertSuccessful();

        Http::assertSent(function ($request) use ($site) {
            if ($request->url() !== "https://{$site->domain}/wp-json/clockwork/v1/backups-report") {
                return false;
            }
            $body = json_decode($request->body(), true);
            $archive = $body['offsite_archive'];

            // download_expires_at must reflect the real ~24h presigned URL
            // TTL, not be silently null while the link actually expires.
            $expiresInHours = now()->diffInHours(Carbon::parse($archive['download_expires_at']));

            return $archive['active'] === true
                && ! empty($archive['fs_download_url'])
                && ! empty($archive['db_download_url'])
                && $archive['download_expires_at'] !== null
                && $expiresInHours >= 23 && $expiresInHours <= 24;
        });
    });

    it('does not attach an offsite_archive when the disk cannot mint real presigned URLs, even if matching files exist', function () {
        Storage::fake('s3-backup-relay');
        $disk = Storage::disk('s3-backup-relay');

        $site = pcbrSite(['backup_relay_enabled' => true]);
        $disk->put("archives/{$site->domain}/2026-09-06_fs.bz2", str_repeat('A', 1024));

        // Simulate a disk that isn't actually S3-backed (e.g. misconfigured
        // driver) — getDownloadUrl() would fall back to an operator-authed
        // Clockwork Control route, which is useless on the client-facing
        // Companion page, so the whole payload should be omitted instead.
        config(['filesystems.disks.s3-backup-relay.driver' => 'local']);

        pcbrMockSpinup(function ($mock) use ($site) {
            $mock->shouldReceive('siteBackupConfig')->once()->with($site->spinupwp_id)->andReturn(['files' => true, 'database' => true]);
        });
        $this->mock(SpacesClient::class, fn ($mock) => $mock->shouldReceive('isConfigured')->andReturn(false));

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/backups-report" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:push-companion-backups')->assertSuccessful();

        Http::assertSent(function ($request) use ($site) {
            if ($request->url() !== "https://{$site->domain}/wp-json/clockwork/v1/backups-report") {
                return false;
            }
            $body = json_decode($request->body(), true);

            return $body['offsite_archive'] === null;
        });
    });
});

describe('clockwork:push-companion-backups — skip and failure handling', function () {
    it('skips SpinupWP-backed sites but still succeeds when SpinupWP is not configured', function () {
        // Used to be a hard FAILURE, but custom/unhosted sites' reports are
        // built purely from the Glacier archive store — a missing SpinupWP
        // token must not block them (their Backups page stays empty
        // otherwise). No custom sites exist here, so nothing is pushed;
        // the SpinupWP-backed site is skipped without any HTTP traffic.
        pcbrSite();

        $this->mock(SpinupWpClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(false);
        });

        Http::fake();

        $this->artisan('clockwork:push-companion-backups')
            ->expectsOutputToContain('SpinupWP token not configured')
            ->assertSuccessful();

        Http::assertNothingSent();
    });

    it('pushes a combined Glacier report to a custom/unhosted site even without SpinupWP', function () {
        $site = Site::factory()->custom()->withCompanionInstalled()->create([
            'companion_capabilities' => ['backups-report'],
            'backup_relay_enabled' => true,
        ]);

        $this->mock(SpinupWpClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(false);
        });

        $this->mock(BackupArchiveEnumerator::class, function ($mock) use ($site) {
            $mock->shouldReceive('forSite')->with(\Mockery::on(fn ($s) => $s->is($site)))->andReturn([
                'archives' => [[
                    'type' => 'full',
                    'size_bytes' => 85_128_567,
                    'archived_at' => now()->subDay()->toIso8601String(),
                    'download_url' => 'https://s3.example/presigned.zip',
                ]],
                'last_archived_at' => now()->subDay()->toIso8601String(),
            ]);
            $mock->shouldReceive('supportsPresignedUrls')->andReturn(true);
        });

        Http::fake(["https://{$site->domain}/*" => Http::response(['ok' => true])]);

        $this->artisan('clockwork:push-companion-backups')->assertSuccessful();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), 'backups-report')
                && ($body['source'] ?? null) === 'clockwork-companion'
                && ($body['history_scope'] ?? null) === 'combined'
                && is_array($body['config'] ?? null) // plugin 400s on a missing/non-array config
                && ($body['history'][0]['type'] ?? null) === 'full'
                && ($body['history'][0]['size_bytes'] ?? null) === 85_128_567;
        });
    });

    it('skips a site whose Companion does not advertise the backups-report capability', function () {
        $site = pcbrSite(['companion_capabilities' => ['snapshot']]);

        pcbrMockSpinup();
        $this->mock(SpacesClient::class, fn ($mock) => $mock->shouldReceive('isConfigured')->andReturn(true));

        Http::fake();

        $this->artisan('clockwork:push-companion-backups')->assertSuccessful();

        Http::assertNothingSent();
    });

    it('logs and continues (still SUCCESS overall) when the SpinupWP fetch fails for a site, without pushing to Companion', function () {
        Log::spy();
        $site = pcbrSite();

        pcbrMockSpinup(function ($mock) {
            $mock->shouldReceive('siteBackupConfig')->once()->andThrow(new \RuntimeException('SpinupWP 503'));
        });
        $this->mock(SpacesClient::class, fn ($mock) => $mock->shouldReceive('isConfigured')->andReturn(true));

        Http::fake();

        $this->artisan('clockwork:push-companion-backups')->assertSuccessful();

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->with('companion.backups_report.spinupwp_fetch_failed', \Mockery::type('array'));
    });

    it('logs and continues (still SUCCESS overall) when the push to Companion itself fails', function () {
        Log::spy();
        $site = pcbrSite();

        pcbrMockSpinup(function ($mock) {
            $mock->shouldReceive('siteBackupConfig')->once()->andReturn(['files' => true, 'database' => true]);
        });
        $this->mock(SpacesClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('listSiteBackupObjects')->once()->andReturn([]);
            $mock->shouldReceive('toHistoryRows')->once()->andReturn([]);
            $mock->shouldReceive('inferSchedules')->once()->andReturn([]);
        });

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/backups-report" => Http::response(['message' => 'error'], 500, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:push-companion-backups')->assertSuccessful();

        Log::shouldHaveReceived('warning')->with('companion.backups_report.push_failed', \Mockery::type('array'));
    });
});

describe('clockwork:push-companion-backups — options', function () {
    it('--site limits the run to a single site by domain', function () {
        $target = pcbrSite();
        $other = pcbrSite();

        pcbrMockSpinup(function ($mock) {
            $mock->shouldReceive('siteBackupConfig')->once()->andReturn(['files' => true, 'database' => true]);
        });
        $this->mock(SpacesClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('listSiteBackupObjects')->once()->andReturn([]);
            $mock->shouldReceive('toHistoryRows')->once()->andReturn([]);
            $mock->shouldReceive('inferSchedules')->once()->andReturn([]);
        });

        Http::fake([
            "https://{$target->domain}/wp-json/clockwork/v1/backups-report" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:push-companion-backups', ['--site' => $target->domain])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === "https://{$target->domain}/wp-json/clockwork/v1/backups-report");
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $other->domain));
    });

    it('--server limits the run to sites on the named server', function () {
        $serverA = Server::factory()->create();
        $serverB = Server::factory()->create();
        $onA = pcbrSite(['server_id' => $serverA->id]);
        $onB = pcbrSite(['server_id' => $serverB->id]);

        pcbrMockSpinup(function ($mock) {
            $mock->shouldReceive('siteBackupConfig')->once()->andReturn(['files' => true, 'database' => true]);
        });
        $this->mock(SpacesClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('listSiteBackupObjects')->once()->andReturn([]);
            $mock->shouldReceive('toHistoryRows')->once()->andReturn([]);
            $mock->shouldReceive('inferSchedules')->once()->andReturn([]);
        });

        Http::fake([
            "https://{$onA->domain}/wp-json/clockwork/v1/backups-report" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->artisan('clockwork:push-companion-backups', ['--server' => $serverA->name])->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), $onB->domain));
    });
});
