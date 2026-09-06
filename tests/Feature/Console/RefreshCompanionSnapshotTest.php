<?php

namespace Tests\Feature\Console;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Pressable\PressableClient;

/*
|--------------------------------------------------------------------------
| Phase 6 console-command coverage: clockwork:refresh-companion-snapshot
|--------------------------------------------------------------------------
|
| Pulls /snapshot from each Companion-equipped site and caches the payload
| on sites.companion_snapshot. Real behavior asserted via Http::fake()
| against the real Companion HMAC route pattern (see
| tests/Feature/Companion/CompanionHmacAuthTest.php) rather than mocking
| ClockworkCompanionClient itself.
|
| Note on the raw-SQL --pending-updates-only branch (RefreshCompanionSnapshot.php
| lines ~160-167, JSON_EXTRACT via whereRaw): verified directly against the
| sqlite test driver before writing the test below (single-quoted '$.path'
| literal parses fine under sqlite's JSON1 functions) — unlike the
| IssueCounter case documented in tests/Concerns/RendersAuthenticatedPages.php,
| this one does NOT trip the MySQL-only-syntax gotcha, so it's exercised
| directly with no partial-mock workaround needed.
*/

function csSnapshotSite(array $overrides = []): Site
{
    return Site::factory()->withCompanionInstalled()->create(array_merge([
        'companion_capabilities' => ['snapshot', 'plugins'],
    ], $overrides));
}

describe('clockwork:refresh-companion-snapshot — happy path', function () {
    it('pulls /snapshot and caches the payload on companion_snapshot for a site with the snapshot capability', function () {
        $site = csSnapshotSite();

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/plugins" => Http::response(
                ['ok' => true, 'counts' => ['total' => 12, 'updates_available' => 2]],
                200,
                ['Content-Type' => 'application/json'],
            ),
            "https://{$site->domain}/wp-json/clockwork/v1/snapshot" => Http::response(
                [
                    'plugins' => ['counts' => ['total' => 12, 'updates_available' => 2]],
                    'admins' => ['count' => 3],
                    'wp_cron' => ['counts' => ['overdue' => 0]],
                ],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->artisan('clockwork:refresh-companion-snapshot')->assertSuccessful();

        $site->refresh();
        expect($site->companion_snapshot)->toBe([
            'plugins' => ['counts' => ['total' => 12, 'updates_available' => 2]],
            'admins' => ['count' => 3],
            'wp_cron' => ['counts' => ['overdue' => 0]],
        ]);
        expect($site->companion_snapshot_at)->not->toBeNull();
        expect($site->companion_last_seen_at)->not->toBeNull();

        Http::assertSent(fn ($request) => $request->url() === "https://{$site->domain}/wp-json/clockwork/v1/plugins");
        Http::assertSent(fn ($request) => $request->url() === "https://{$site->domain}/wp-json/clockwork/v1/snapshot");
    });

    it('skips sites that do not advertise the snapshot capability, without making any HTTP call', function () {
        $site = csSnapshotSite(['companion_capabilities' => ['plugins']]);

        Http::fake();

        $this->artisan('clockwork:refresh-companion-snapshot')->assertSuccessful();

        Http::assertNothingSent();
        expect($site->refresh()->companion_snapshot)->toBeNull();
    });

    it('does not force a /plugins refresh when the site lacks the plugins capability', function () {
        $site = csSnapshotSite(['companion_capabilities' => ['snapshot']]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/snapshot" => Http::response(
                ['plugins' => ['counts' => ['total' => 1, 'updates_available' => 0]]],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->artisan('clockwork:refresh-companion-snapshot')->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/plugins'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/snapshot'));
    });
});

describe('clockwork:refresh-companion-snapshot — failure handling', function () {
    it('logs and continues (still exits SUCCESS) when /snapshot fails for a site, leaving its cached snapshot untouched', function () {
        Log::spy();
        $site = csSnapshotSite(['companion_capabilities' => ['snapshot']]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/snapshot" => Http::response(
                ['message' => 'Internal Server Error'],
                500,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->artisan('clockwork:refresh-companion-snapshot')->assertSuccessful();

        expect($site->refresh()->companion_snapshot)->toBeNull();
        Log::shouldHaveReceived('warning')->with('companion.snapshot.failed', \Mockery::type('array'));
    });

    it('does not let a failed forced /plugins refresh block the /snapshot pull that follows it', function () {
        $site = csSnapshotSite();

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/plugins" => Http::response(
                ['message' => 'gateway timeout'],
                504,
                ['Content-Type' => 'application/json'],
            ),
            "https://{$site->domain}/wp-json/clockwork/v1/snapshot" => Http::response(
                ['plugins' => ['counts' => ['total' => 5, 'updates_available' => 1]]],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->artisan('clockwork:refresh-companion-snapshot')->assertSuccessful();

        expect($site->refresh()->companion_snapshot)->toBe(['plugins' => ['counts' => ['total' => 5, 'updates_available' => 1]]]);
    });
});

describe('clockwork:refresh-companion-snapshot — Pressable edge-cache purge', function () {
    it('best-effort purges the Pressable edge cache before pulling /snapshot for a Pressable site', function () {
        $site = Site::factory()->pressable()->withCompanionInstalled()->create([
            'companion_capabilities' => ['snapshot'],
        ]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/snapshot" => Http::response(
                ['plugins' => ['counts' => ['total' => 1, 'updates_available' => 0]]],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->mock(PressableClient::class, function ($mock) use ($site) {
            $mock->shouldReceive('purgeEdgeCache')->once()->with($site->pressable_site_id);
        });

        $this->artisan('clockwork:refresh-companion-snapshot')->assertSuccessful();

        expect($site->refresh()->companion_snapshot)->not->toBeNull();
    });

    it('does not let a Pressable edge-cache purge failure block the snapshot pull', function () {
        $site = Site::factory()->pressable()->withCompanionInstalled()->create([
            'companion_capabilities' => ['snapshot'],
        ]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/snapshot" => Http::response(
                ['plugins' => ['counts' => ['total' => 1, 'updates_available' => 0]]],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->mock(PressableClient::class, function ($mock) {
            $mock->shouldReceive('purgeEdgeCache')->once()->andThrow(new \RuntimeException('Pressable API down'));
        });

        $this->artisan('clockwork:refresh-companion-snapshot')->assertSuccessful();

        expect($site->refresh()->companion_snapshot)->not->toBeNull();
    });
});

describe('clockwork:refresh-companion-snapshot — options', function () {
    it('--site limits the run to a single site by domain', function () {
        $target = csSnapshotSite();
        $other = csSnapshotSite();

        Http::fake([
            "https://{$target->domain}/*" => Http::response(
                ['plugins' => ['counts' => ['total' => 1, 'updates_available' => 0]]],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->artisan('clockwork:refresh-companion-snapshot', ['--site' => $target->domain])->assertSuccessful();

        expect($target->refresh()->companion_snapshot)->not->toBeNull();
        expect($other->refresh()->companion_snapshot)->toBeNull();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $other->domain));
    });

    it('--server limits the run to sites on the named server', function () {
        $serverA = Server::factory()->create();
        $serverB = Server::factory()->create();
        $onA = csSnapshotSite(['server_id' => $serverA->id]);
        $onB = csSnapshotSite(['server_id' => $serverB->id]);

        Http::fake([
            "https://{$onA->domain}/*" => Http::response(
                ['plugins' => ['counts' => ['total' => 1, 'updates_available' => 0]]],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->artisan('clockwork:refresh-companion-snapshot', ['--server' => $serverA->name])->assertSuccessful();

        expect($onA->refresh()->companion_snapshot)->not->toBeNull();
        expect($onB->refresh()->companion_snapshot)->toBeNull();
    });

    it('--pending-updates-only includes a site SpinupWP flags as having updates but whose cached snapshot shows none, and excludes one whose snapshot already reflects updates', function () {
        $stale = csSnapshotSite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => null,
        ]);
        $alreadyFresh = csSnapshotSite([
            'wp_plugin_updates' => true,
            'companion_snapshot' => ['plugins' => ['counts' => ['updates_available' => 3]]],
        ]);
        $noUpdatesFlagged = csSnapshotSite([
            'wp_plugin_updates' => false,
            'wp_theme_updates' => false,
            'wp_core_update' => false,
            'companion_snapshot' => null,
        ]);

        Http::fake([
            "https://{$stale->domain}/*" => Http::response(
                ['plugins' => ['counts' => ['total' => 1, 'updates_available' => 1]]],
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->artisan('clockwork:refresh-companion-snapshot', ['--pending-updates-only' => true])->assertSuccessful();

        expect($stale->refresh()->companion_snapshot)->not->toBeNull();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $alreadyFresh->domain));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $noUpdatesFlagged->domain));
    });
});
