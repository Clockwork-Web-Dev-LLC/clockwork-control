<?php

namespace Tests\Feature\Sites;

use App\Models\Site;
use App\Models\User;
use App\Support\SsrfGuard;
use Illuminate\Support\Facades\Http;

describe('Add Standalone Site', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    });

    it('renders the create site page successfully', function () {
        $response = $this->get(route('sites.create'));

        $response->assertOk();
        $response->assertSee('Connect WordPress Site');
        $response->assertSee('Download Plugin (.zip)');
    });

    it('rejects invalid domain formats', function () {
        $response = $this->post(route('sites.store'), [
            'domain' => 'not-a-valid-domain',
            'companion_secret' => bin2hex(random_bytes(32)),
        ]);

        $response->assertSessionHasErrors('domain');
        expect(Site::count())->toBe(0);
    });

    it('rejects already registered domains', function () {
        Site::factory()->custom()->create(['domain' => 'existing-site.com']);

        $response = $this->post(route('sites.store'), [
            'domain' => 'existing-site.com',
            'companion_secret' => bin2hex(random_bytes(32)),
        ]);

        $response->assertSessionHasErrors('domain');
    });

    it('rejects missing companion secret', function () {
        $response = $this->post(route('sites.store'), [
            'domain' => 'new-site.com',
            'companion_secret' => '',
        ]);

        $response->assertSessionHasErrors('companion_secret');
    });

    it('handles companion 401 signature mismatch gracefully', function () {
        Http::fake([
            'https://wpengine-client.com/wp-json/clockwork/v1/health*' => Http::response([
                'ok' => false,
                'error' => 'invalid_signature',
            ], 401),
        ]);

        $response = $this->post(route('sites.store'), [
            'domain' => 'https://wpengine-client.com/',
            'companion_secret' => 'wrong-secret-00000000000000000000000000000000000000000000000000000000',
        ]);

        $response->assertSessionHasErrors('companion_secret');
        expect(Site::where('domain', 'wpengine-client.com')->exists())->toBeFalse();
    });

    it('handles companion 404 route not found gracefully', function () {
        Http::fake([
            'https://wpengine-client.com/wp-json/clockwork/v1/health*' => Http::response('Not Found', 404),
        ]);

        $response = $this->post(route('sites.store'), [
            'domain' => 'wpengine-client.com',
            'companion_secret' => bin2hex(random_bytes(32)),
        ]);

        $response->assertSessionHasErrors('domain');
        expect(Site::where('domain', 'wpengine-client.com')->exists())->toBeFalse();
    });

    it('successfully connects and enrolls a standalone site via manual domain and secret', function () {
        $secret = bin2hex(random_bytes(32));

        SsrfGuard::fake(['wpengine-client.com' => ['93.184.216.34']]);
        Http::fake([
            'https://wpengine-client.com/wp-json/clockwork/v1/health*' => Http::response([
                'ok' => true,
                'version' => '1.35.0',
                'capabilities' => ['snapshot', 'updates', 'malware-scan', 'admins', 'sso'],
                'is_multisite' => false,
            ], 200),
            'https://wpengine-client.com/wp-json/clockwork/v1/snapshot*' => Http::response([
                'ok' => true,
                'plugins' => ['active' => [], 'counts' => ['updates_available' => 0]],
            ], 200),
            'https://wpengine-client.com*' => Http::response('<html><head><title>Test</title></head><body><h1>Hello World</h1><p>This is a real site with plenty of content to pass body check.</p></body></html>', 200),
        ]);

        $response = $this->post(route('sites.store'), [
            'domain' => 'https://wpengine-client.com/wp-admin/',
            'companion_secret' => $secret,
            'hosting_provider' => 'custom',
        ]);

        $site = Site::where('domain', 'wpengine-client.com')->first();
        expect($site)->not->toBeNull()
            ->and($site->server_id)->toBeNull()
            ->and($site->hosting_provider)->toBe('custom')
            ->and($site->companion_installed)->toBeTrue()
            ->and($site->companion_version)->toBe('1.35.0')
            ->and($site->companion_secret)->toBe($secret)
            ->and($site->companion_capabilities)->toContain('snapshot', 'malware-scan', 'sso')
            ->and($site->uptime_monitoring_enabled)->toBeTrue()
            ->and($site->backup_relay_enabled)->toBeTrue()
            ->and($site->backup_relay_frequency)->toBe('daily')
            ->and($site->cert_source)->toBe(Site::CERT_SOURCE_LIVE_PROBE);

        $response->assertRedirect(route('sites.show', $site));
        $response->assertSessionHas('flash');
    });

    it('successfully connects and enrolls a standalone site via ManageWP-style Connection Key', function () {
        $secret = bin2hex(random_bytes(32));
        $connectionPayload = json_encode([
            'url' => 'https://client-wpengine.com',
            'secret' => $secret,
            'version' => '1.35.0',
        ]);
        $connectionKey = 'cw_'.base64_encode($connectionPayload);

        SsrfGuard::fake(['client-wpengine.com' => ['93.184.216.34']]);
        Http::fake([
            'https://client-wpengine.com/wp-json/clockwork/v1/health*' => Http::response([
                'ok' => true,
                'version' => '1.35.0',
                'capabilities' => ['snapshot', 'updates', 'malware-scan'],
                'is_multisite' => false,
            ], 200),
            'https://client-wpengine.com/wp-json/clockwork/v1/snapshot*' => Http::response([
                'ok' => true,
                'plugins' => ['active' => []],
            ], 200),
            'https://client-wpengine.com*' => Http::response('<html><body><h1>Hello World</h1><p>This is a real site with plenty of content to pass body check.</p></body></html>', 200),
        ]);

        $response = $this->post(route('sites.store'), [
            'connection_key' => $connectionKey,
            'hosting_provider' => 'wpengine',
        ]);

        $site = Site::where('domain', 'client-wpengine.com')->first();
        expect($site)->not->toBeNull()
            ->and($site->server_id)->toBeNull()
            ->and($site->hosting_provider)->toBe(Site::HOSTING_PROVIDER_CUSTOM)
            ->and($site->backup_relay_enabled)->toBeTrue()
            ->and($site->backup_relay_frequency)->toBe('daily')
            ->and($site->companion_installed)->toBeTrue()
            ->and($site->companion_secret)->toBe($secret);

        $response->assertRedirect(route('sites.show', $site));
    });
});
