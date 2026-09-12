<?php

namespace Tests\Feature\Console;

use App\Models\Site;
use App\Models\SiteIngestExclusion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\PressableFixtures;

/*
|--------------------------------------------------------------------------
| Coverage for `clockwork:import-pressable` (App\Console\Commands\ImportPressable)
|--------------------------------------------------------------------------
|
| PressableClient::sites() returns the raw `data` array from GET /sites
| unmodified (see PressableClient::paginate()) — there is no intermediate
| DTO/transform layer. PressableFixtures::site() (built for the
| clockwork:pressable-test connectivity check, see PressableTestTest) does
| NOT carry the `url` key ImportPressable::upsertSite() actually reads for
| the domain (it ships `primaryDomain.name` instead, which nothing in this
| command reads) — so every row below layers a `url` override on top of the
| base fixture rather than hand-rolling a new payload shape.
*/
function pressableConfig(): void
{
    config([
        'clockwork.pressable.client_id' => 'client-1',
        'clockwork.pressable.client_secret' => 'secret-1',
        'clockwork.pressable.auth_url' => 'https://my.pressable.com/auth/token',
        'clockwork.pressable.base_url' => 'https://my.pressable.com/v1',
    ]);
}

function fakePressableAuth(): array
{
    return [
        'my.pressable.com/auth/token' => Http::response([
            'access_token' => 'fake-pressable-token',
            'expires_in' => 3599,
        ], 200),
    ];
}

describe('clockwork:import-pressable', function () {
    beforeEach(function () {
        // PressableClient caches its OAuth2 token in the app cache store —
        // see PressableTestTest's identical beforeEach for why this matters.
        Cache::flush();
    });

    it('fails fast with no writes when Pressable credentials are not configured', function () {
        config([
            'clockwork.pressable.client_id' => '',
            'clockwork.pressable.client_secret' => '',
        ]);

        $this->artisan('clockwork:import-pressable')
            ->assertFailed()
            ->expectsOutputToContain('CLOCKWORK_PRESSABLE_CLIENT_ID / CLOCKWORK_PRESSABLE_CLIENT_SECRET are not set');

        expect(Site::count())->toBe(0);
    });

    it('creates a new site from a live Pressable listing, disabling uptime monitoring for a non-live one', function () {
        pressableConfig();

        Http::fake(array_merge(fakePressableAuth(), [
            'my.pressable.com/v1/sites*' => Http::response(
                PressableFixtures::listResponse([
                    PressableFixtures::site(['id' => '111222', 'url' => 'live-example.com', 'state' => 'live']),
                    PressableFixtures::site(['id' => '333444', 'url' => 'staging-example.com', 'state' => 'staging']),
                ]),
                200
            ),
        ]));

        $this->artisan('clockwork:import-pressable')->assertSuccessful();

        expect(Site::count())->toBe(2);

        $live = Site::where('domain', 'live-example.com')->firstOrFail();
        expect($live->pressable_site_id)->toBe('111222')
            ->and($live->hosting_provider)->toBe(Site::HOSTING_PROVIDER_PRESSABLE)
            ->and($live->server_id)->toBeNull()
            ->and($live->is_wordpress)->toBeTrue()
            ->and($live->uptime_monitoring_enabled)->toBeTrue()
            ->and($live->auto_updates_paused)->toBeFalse();

        $staging = Site::where('domain', 'staging-example.com')->firstOrFail();
        expect($staging->pressable_site_id)->toBe('333444')
            ->and($staging->uptime_monitoring_enabled)->toBeFalse();
    });

    it('is idempotent: running the import twice does not duplicate rows and marks the second run unchanged', function () {
        pressableConfig();

        Http::fake(array_merge(fakePressableAuth(), [
            'my.pressable.com/v1/sites*' => Http::response(
                PressableFixtures::listResponse([
                    PressableFixtures::site(['id' => '111222', 'url' => 'repeat-example.com', 'state' => 'live']),
                ]),
                200
            ),
        ]));

        $this->artisan('clockwork:import-pressable')->assertSuccessful();
        expect(Site::count())->toBe(1);

        $this->artisan('clockwork:import-pressable')
            ->assertSuccessful()
            ->expectsOutputToContain('"unchanged":1');

        expect(Site::count())->toBe(1);
        expect(Site::where('domain', 'repeat-example.com')->count())->toBe(1);
    });

    it('updates an existing (archived) row in place instead of failing the unique-domain constraint', function () {
        pressableConfig();

        $archived = Site::factory()->pressable()->archived()->create([
            'domain' => 'reused-domain.com',
            'pressable_site_id' => '999000',
        ]);

        Http::fake(array_merge(fakePressableAuth(), [
            'my.pressable.com/v1/sites*' => Http::response(
                PressableFixtures::listResponse([
                    PressableFixtures::site(['id' => '999000', 'url' => 'reused-domain.com', 'state' => 'live']),
                ]),
                200
            ),
        ]));

        $this->artisan('clockwork:import-pressable')
            ->assertSuccessful()
            ->expectsOutputToContain('"created":0');

        expect(Site::withoutGlobalScopes()->count())->toBe(1);
        expect($archived->fresh()->pressable_site_id)->toBe('999000');
    });

    it('renames an existing row in place when Pressable reports a changed url for the same site id, instead of crashing on the pressable_site_id unique constraint', function () {
        // Regression, found live 2026-09-12: Pressable's url for an
        // already-tracked site changed from tlolawfirm.com to
        // www.tlolawfirm.com while the id stayed stable. The old
        // domain-only lookup missed the existing row and tried to INSERT a
        // "new" site with the same pressable_site_id, throwing a
        // UniqueConstraintViolationException that rolled back the entire
        // fleet-wide import transaction — every other site's update was
        // lost too, not just this one.
        pressableConfig();

        $existing = Site::factory()->pressable()->create([
            'domain' => 'tlolawfirm.com',
            'pressable_site_id' => '1789853',
        ]);

        Http::fake(array_merge(fakePressableAuth(), [
            'my.pressable.com/v1/sites*' => Http::response(
                PressableFixtures::listResponse([
                    PressableFixtures::site(['id' => '1789853', 'url' => 'www.tlolawfirm.com', 'state' => 'live']),
                ]),
                200
            ),
        ]));

        $this->artisan('clockwork:import-pressable')
            ->assertSuccessful()
            ->expectsOutputToContain('"created":0');

        expect(Site::withoutGlobalScopes()->count())->toBe(1);
        expect($existing->fresh()->domain)->toBe('www.tlolawfirm.com');
        expect(Site::withoutGlobalScopes()->where('domain', 'tlolawfirm.com')->exists())->toBeFalse();
    });

    it('skips a domain that is still an active SpinupWP site instead of repurposing it', function () {
        pressableConfig();

        $spinupSite = Site::factory()->spinupwp()->create([
            'domain' => 'siteclient.example',
            'spinupwp_id' => '5150',
        ]);
        $originalServerId = $spinupSite->server_id;

        Http::fake(array_merge(fakePressableAuth(), [
            'my.pressable.com/v1/sites*' => Http::response(
                PressableFixtures::listResponse([
                    PressableFixtures::site(['id' => '444555', 'url' => 'siteclient.example', 'state' => 'live']),
                ]),
                200
            ),
        ]));

        $this->artisan('clockwork:import-pressable')
            ->assertSuccessful()
            ->expectsOutputToContain('still an active SpinupWP site')
            ->expectsOutputToContain('"skipped_active_spinupwp":1');

        $spinupSite->refresh();
        expect($spinupSite->hosting_provider)->toBe(Site::HOSTING_PROVIDER_SPINUPWP)
            ->and($spinupSite->server_id)->toBe($originalServerId)
            ->and($spinupSite->pressable_site_id)->toBeNull();
    });

    it('nulls pressable_site_id (sweep) for a local row no longer present in the API response', function () {
        pressableConfig();

        $goneFromApi = Site::factory()->pressable()->create([
            'domain' => 'deleted-from-pressable.com',
            'pressable_site_id' => '700800',
        ]);

        Http::fake(array_merge(fakePressableAuth(), [
            'my.pressable.com/v1/sites*' => Http::response(
                PressableFixtures::listResponse([
                    PressableFixtures::site(['id' => '111222', 'url' => 'still-there.com', 'state' => 'live']),
                ]),
                200
            ),
        ]));

        $this->artisan('clockwork:import-pressable')
            ->assertSuccessful()
            ->expectsOutputToContain('"pressable_id_nulled":1');

        expect($goneFromApi->fresh()->pressable_site_id)->toBeNull();
    });

    it('skips a row with no domain rather than crashing', function () {
        pressableConfig();

        Http::fake(array_merge(fakePressableAuth(), [
            'my.pressable.com/v1/sites*' => Http::response(
                PressableFixtures::listResponse([
                    PressableFixtures::site(['id' => '1', 'url' => null]),
                ]),
                200
            ),
        ]));

        $this->artisan('clockwork:import-pressable')
            ->assertSuccessful()
            ->expectsOutputToContain('"skipped_no_domain":1');

        expect(Site::count())->toBe(0);
    });

    it('runs in dry-run mode without committing changes to the database', function () {
        pressableConfig();

        Http::fake(array_merge(fakePressableAuth(), [
            'my.pressable.com/v1/sites*' => Http::response(
                PressableFixtures::listResponse([
                    PressableFixtures::site(['id' => '12345', 'url' => 'new-site.com', 'state' => 'live']),
                ]),
                200
            ),
        ]));

        $this->artisan('clockwork:import-pressable --dry-run')
            ->expectsOutputToContain('[DRY RUN] Sites:')
            ->assertSuccessful();

        expect(Site::count())->toBe(0);
    });

    it('skips a dropped Pressable site and does not restore pressable_site_id on the archived row', function () {
        pressableConfig();

        $site = Site::factory()->pressable()->create([
            'domain' => 'dropped-pressable.com',
            'pressable_site_id' => '999000',
        ]);
        SiteIngestExclusion::recordFromSite($site, 'drop it');
        $site->forceFill(['archived_at' => now(), 'pressable_site_id' => null])->save();

        Http::fake(array_merge(fakePressableAuth(), [
            'my.pressable.com/v1/sites*' => Http::response(
                PressableFixtures::listResponse([
                    PressableFixtures::site(['id' => '999000', 'url' => 'dropped-pressable.com', 'state' => 'live']),
                ]),
                200
            ),
        ]));

        $this->artisan('clockwork:import-pressable')
            ->assertSuccessful()
            ->expectsOutputToContain('dropped from Clockwork ingest')
            ->expectsOutputToContain('"skipped_excluded":1');

        expect(Site::count())->toBe(0);
        $fresh = Site::withoutGlobalScopes()->findOrFail($site->id);
        expect($fresh->pressable_site_id)->toBeNull()
            ->and($fresh->archived_at)->not->toBeNull();
    });

    it('skips a domain dropped from SpinupWP rather than converting the archived row to Pressable', function () {
        pressableConfig();

        $site = Site::factory()->spinupwp()->archived()->create([
            'domain' => 'shared-drop.example',
            'spinupwp_id' => null,
        ]);
        SiteIngestExclusion::factory()->create([
            'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP,
            'domain' => 'shared-drop.example',
            'site_id' => $site->id,
            'provider_site_id' => '5150',
        ]);

        Http::fake(array_merge(fakePressableAuth(), [
            'my.pressable.com/v1/sites*' => Http::response(
                PressableFixtures::listResponse([
                    PressableFixtures::site(['id' => '444555', 'url' => 'shared-drop.example', 'state' => 'live']),
                ]),
                200
            ),
        ]));

        $this->artisan('clockwork:import-pressable')
            ->assertSuccessful()
            ->expectsOutputToContain('"skipped_excluded":1');

        $fresh = Site::withoutGlobalScopes()->findOrFail($site->id);
        expect($fresh->hosting_provider)->toBe(Site::HOSTING_PROVIDER_SPINUPWP)
            ->and($fresh->pressable_site_id)->toBeNull();
    });
});
