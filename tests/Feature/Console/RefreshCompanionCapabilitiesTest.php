<?php

use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\CompanionFixtures;

/*
|--------------------------------------------------------------------------
| RefreshCompanionCapabilities — Phase 6 console coverage
|--------------------------------------------------------------------------
|
| ClockworkCompanionClient is `new`'d directly inside the command, so its
| /health call is faked via Http::fake() against the real signed-request
| URL rather than container-mocked (see tests/Feature/Controllers/
| SitesControllerTest.php for the same pattern). PressableClient is
| resolved from the container but never called — every fixture site here
| is SpinupWP-shaped, so the isPressable() edge-cache-purge branch never
| triggers and needs no mock.
*/

function capabilitiesSite(array $overrides = []): Site
{
    $server = Server::factory()->create();

    return Site::factory()->spinupwp()->create(array_merge([
        'server_id' => $server->id,
        'companion_installed' => true,
        'companion_secret' => 'test-secret-'.uniqid(),
        'companion_version' => '1.29.0',
        'companion_capabilities' => ['malware-scan'],
        'is_multisite' => false,
    ], $overrides));
}

describe('clockwork:refresh-companion-capabilities', function () {
    it('updates companion_capabilities + companion_version when /health reports a change', function () {
        $site = capabilitiesSite(['domain' => 'refresh-changed.test']);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/health" => Http::response(
                CompanionFixtures::health(['version' => '1.30.4', 'capabilities' => ['malware-scan', 'resource-metrics', 'two-factor']]),
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->artisan('clockwork:refresh-companion-capabilities')->assertSuccessful();

        $site->refresh();
        expect($site->companion_version)->toBe('1.30.4')
            ->and($site->companion_capabilities)->toEqualCanonicalizing(['malware-scan', 'resource-metrics', 'two-factor'])
            ->and($site->companion_last_seen_at)->not->toBeNull();
    });

    it('leaves the site untouched (unchanged) when /health reports the same version + capability set, even in a different order', function () {
        $site = capabilitiesSite([
            'domain' => 'refresh-same.test',
            'companion_version' => '1.30.4',
            'companion_capabilities' => ['malware-scan', 'resource-metrics'],
        ]);
        $lastSeenBefore = $site->companion_last_seen_at;

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/health" => Http::response(
                CompanionFixtures::health(['version' => '1.30.4', 'capabilities' => ['resource-metrics', 'malware-scan']]),
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->artisan('clockwork:refresh-companion-capabilities')
            ->expectsOutputToContain('ok=0 unchanged=1 failed=0')
            ->assertSuccessful();

        $site->refresh();
        expect($site->companion_last_seen_at?->timestamp)->toBe($lastSeenBefore?->timestamp);
    });

    it('counts a /health failure as failed and does not touch the site row', function () {
        $site = capabilitiesSite(['domain' => 'refresh-fail.test', 'companion_capabilities' => ['malware-scan']]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/health" => Http::response('Service Unavailable', 503),
        ]);

        $this->artisan('clockwork:refresh-companion-capabilities')
            ->expectsOutputToContain('ok=0 unchanged=0 failed=1')
            ->assertSuccessful();

        expect($site->refresh()->companion_capabilities)->toBe(['malware-scan']);
    });

    it('--site limits the run to a single site by domain', function () {
        $target = capabilitiesSite(['domain' => 'refresh-target.test']);
        $other = capabilitiesSite(['domain' => 'refresh-other.test']);

        Http::fake([
            "https://{$target->domain}/wp-json/clockwork/v1/health" => Http::response(
                CompanionFixtures::health(['version' => '1.30.5']),
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->artisan('clockwork:refresh-companion-capabilities', ['--site' => 'refresh-target.test'])->assertSuccessful();

        expect($target->refresh()->companion_version)->toBe('1.30.5');
        expect($other->refresh()->companion_version)->toBe('1.29.0');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $other->domain));
    });

    it('exits successfully with a warning when no Companion-equipped site matches', function () {
        $this->artisan('clockwork:refresh-companion-capabilities')
            ->expectsOutputToContain('No Companion-equipped sites matched.')
            ->assertSuccessful();
    });
});
