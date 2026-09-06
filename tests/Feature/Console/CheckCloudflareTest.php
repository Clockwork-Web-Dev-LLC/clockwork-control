<?php

namespace Tests\Feature\Console;

use App\Models\Server;
use App\Models\Site;
use App\Services\Cloudflare\CloudflareDetector;

/*
|--------------------------------------------------------------------------
| Call-site coverage for CheckCloudflare
|--------------------------------------------------------------------------
|
| CloudflareDetector::detect() does real DNS lookups (dns_get_record) and
| CloudflareDetector::ranges()/rangesV6() hit the network on a cache miss —
| this suite mocks CloudflareDetector entirely so it never touches DNS or
| HTTP. The behavioral assertions are on the DB mutation (Site::update)
| the command performs per result, since this is real data-mutation work,
| not just a read/diagnose command.
*/

describe('CheckCloudflare', function () {
    it('classifies each monitored site and persists the detector result', function () {
        $proxiedSite = Site::factory()->create(['domain' => 'proxied.example.com', 'cloudflare_state' => Site::CF_UNKNOWN]);
        $dnsOnlySite = Site::factory()->create(['domain' => 'dnsonly.example.com', 'cloudflare_state' => Site::CF_UNKNOWN]);

        $this->mock(CloudflareDetector::class, function ($mock) {
            $mock->shouldReceive('detect')
                ->with('proxied.example.com')
                ->once()
                ->andReturn(['state' => Site::CF_PROXIED, 'a_record' => '104.16.1.1', 'ns_record' => null]);
            $mock->shouldReceive('detect')
                ->with('dnsonly.example.com')
                ->once()
                ->andReturn(['state' => Site::CF_DNS_ONLY, 'a_record' => '10.0.0.5', 'ns_record' => 'kim.ns.cloudflare.com']);
        });

        $this->artisan('clockwork:check-cloudflare')->assertSuccessful();

        $proxiedSite->refresh();
        expect($proxiedSite->cloudflare_state)->toBe(Site::CF_PROXIED)
            ->and($proxiedSite->resolved_a_record)->toBe('104.16.1.1')
            ->and($proxiedSite->resolved_ns_record)->toBeNull()
            ->and($proxiedSite->cloudflare_checked_at)->not->toBeNull();

        $dnsOnlySite->refresh();
        expect($dnsOnlySite->cloudflare_state)->toBe(Site::CF_DNS_ONLY)
            ->and($dnsOnlySite->resolved_a_record)->toBe('10.0.0.5')
            ->and($dnsOnlySite->resolved_ns_record)->toBe('kim.ns.cloudflare.com');
    });

    it('excludes sites on a non-monitored (ignored) server', function () {
        $ignoredServer = Server::factory()->ignored()->create();
        $excludedSite = Site::factory()->create(['server_id' => $ignoredServer->id, 'domain' => 'ignored.example.com']);

        $this->mock(CloudflareDetector::class, function ($mock) {
            $mock->shouldReceive('detect')->never();
        });

        $this->artisan('clockwork:check-cloudflare')->assertSuccessful();

        expect($excludedSite->refresh()->cloudflare_checked_at)->toBeNull();
    });

    it('reports no match and exits successfully when nothing matches --site', function () {
        Site::factory()->create(['domain' => 'someone-else.example.com']);

        $this->mock(CloudflareDetector::class, function ($mock) {
            $mock->shouldReceive('detect')->never();
        });

        $this->artisan('clockwork:check-cloudflare', ['--site' => 'does-not-exist.example.com'])
            ->expectsOutputToContain('No sites match the filter.')
            ->assertSuccessful();
    });

    it('limits to a single site via --site by domain', function () {
        $target = Site::factory()->create(['domain' => 'target.example.com']);
        Site::factory()->create(['domain' => 'other.example.com']);

        $this->mock(CloudflareDetector::class, function ($mock) {
            $mock->shouldReceive('detect')
                ->with('target.example.com')
                ->once()
                ->andReturn(['state' => Site::CF_NOT_USING, 'a_record' => '1.2.3.4', 'ns_record' => null]);
        });

        $this->artisan('clockwork:check-cloudflare', ['--site' => 'target.example.com'])->assertSuccessful();

        expect($target->refresh()->cloudflare_state)->toBe(Site::CF_NOT_USING);
    });

    it('limits to a single site via --site by numeric ID', function () {
        $target = Site::factory()->create(['domain' => 'by-id.example.com']);
        Site::factory()->create(['domain' => 'other-id.example.com']);

        $this->mock(CloudflareDetector::class, function ($mock) {
            $mock->shouldReceive('detect')
                ->with('by-id.example.com')
                ->once()
                ->andReturn(['state' => Site::CF_UNKNOWN, 'a_record' => null, 'ns_record' => null]);
        });

        $this->artisan('clockwork:check-cloudflare', ['--site' => (string) $target->id])->assertSuccessful();

        expect($target->refresh()->cloudflare_checked_at)->not->toBeNull();
    });
});
