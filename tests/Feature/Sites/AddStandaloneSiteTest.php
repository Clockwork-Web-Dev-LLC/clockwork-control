<?php

namespace Tests\Feature\Sites;

use App\Models\Site;
use App\Models\User;
use App\Services\Process\BackgroundArtisan;
use App\Services\Process\BackgroundArtisanResult;
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
        $response->assertSee('WordPress.org Directory');
        $response->assertSee('Clockwork Renegade');
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

    it('does not flash the companion secret or connection key back into the session', function () {
        $secret = bin2hex(random_bytes(32));

        $response = $this->post(route('sites.store'), [
            'domain' => 'not-a-valid-domain',
            'companion_secret' => $secret,
            'connection_key' => 'cw_'.base64_encode(json_encode(['secret' => $secret])),
        ]);

        $response->assertSessionHasErrors('domain');
        $old = session()->get('_old_input', []);
        expect($old)->not->toHaveKey('companion_secret')
            ->and($old)->not->toHaveKey('connection_key');
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

    it('does not leak transport exception details on enroll failure', function () {
        Http::fake([
            'https://wpengine-client.com/wp-json/clockwork/v1/health*' => Http::response('upstream exploded: secret=should-not-echo', 502),
        ]);

        $response = $this->post(route('sites.store'), [
            'domain' => 'wpengine-client.com',
            'companion_secret' => bin2hex(random_bytes(32)),
        ]);

        $response->assertSessionHasErrors('domain');
        $message = session('errors')->first('domain');
        expect($message)->toContain('Could not connect to wpengine-client.com')
            ->and($message)->not->toContain('upstream exploded')
            ->and($message)->not->toContain('secret=');
    });

    it('handles companion 404 route not found gracefully', function () {
        Http::fake([
            'https://wpengine-client.com/wp-json/clockwork/v1/health*' => Http::response('Not Found', 404),
            'https://wpengine-client.com/wp-json/clockwork-renegade/v1/health*' => Http::response('Not Found', 404),
        ]);

        $response = $this->post(route('sites.store'), [
            'domain' => 'wpengine-client.com',
            'companion_secret' => bin2hex(random_bytes(32)),
        ]);

        $response->assertSessionHasErrors('domain');
        expect(session('errors')->first('domain'))->toContain('HTTP 404');
        expect(Site::where('domain', 'wpengine-client.com')->exists())->toBeFalse();
    });

    it('successfully connects and enrolls a standalone site via manual domain and secret', function () {
        $secret = bin2hex(random_bytes(32));

        SsrfGuard::fake(['wpengine-client.com' => ['93.184.216.34']]);
        Http::fake([
            'https://wpengine-client.com/wp-json/clockwork/v1/health*' => Http::response([
                'ok' => true,
                'version' => '1.35.0',
                'capabilities' => ['snapshot', 'updates', 'malware-scan', 'admins', 'sso', 'backup-create'],
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
            ->and($site->cert_source)->toBe(Site::CERT_SOURCE_LIVE_PROBE)
            ->and($site->auto_updates_paused)->toBeFalse()
            // The handshake only answers from the WordPress plugin; without
            // the flag the site sat out every WordPress-only job.
            ->and($site->is_wordpress)->toBeTrue();

        $response->assertRedirect(route('sites.show', $site));
        $response->assertSessionHas('flash');
    });

    it('starts one background performance scan for the new site', function () {
        SsrfGuard::fake(['enrolled.example.com' => ['93.184.216.34']]);
        Http::fake([
            'https://enrolled.example.com/wp-json/clockwork/v1/health*' => Http::response([
                'ok' => true,
                'version' => '1.35.0',
                'capabilities' => ['updates'],
                'is_multisite' => false,
            ], 200),
            'https://enrolled.example.com*' => Http::response('<html><head><title>Test</title></head><body><h1>Hello World</h1><p>This is a real site with plenty of content to pass body check.</p></body></html>', 200),
        ]);

        $launched = [];
        $this->mock(BackgroundArtisan::class, function ($mock) use (&$launched) {
            $mock->shouldReceive('start')->andReturnUsing(function (string $lockKey, array $commands) use (&$launched) {
                $launched[] = [$lockKey, $commands];

                return BackgroundArtisanResult::ok();
            });
        });

        $this->post(route('sites.store'), [
            'domain' => 'enrolled.example.com',
            'companion_secret' => bin2hex(random_bytes(32)),
        ])->assertRedirect();

        $site = Site::where('domain', 'enrolled.example.com')->firstOrFail();
        expect($launched)->toBe([[
            'performance-baseline:'.$site->id,
            ['clockwork:run-performance-scans --site='.$site->id.' --strategy=mobile'],
        ]]);
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
                'capabilities' => ['snapshot', 'updates', 'malware-scan', 'backup-create'],
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

    it('enrolls a site whose plugin lacks backup-create but leaves off-site backups off', function () {
        $secret = bin2hex(random_bytes(32));

        SsrfGuard::fake(['old-plugin.example.com' => ['93.184.216.34']]);
        Http::fake([
            'https://old-plugin.example.com/wp-json/clockwork/v1/health*' => Http::response([
                'ok' => true,
                'version' => '1.30.3',
                'capabilities' => ['snapshot', 'updates', 'malware-scan'],
                'is_multisite' => false,
            ], 200),
            'https://old-plugin.example.com/wp-json/clockwork/v1/snapshot*' => Http::response([
                'ok' => true,
                'plugins' => ['active' => []],
            ], 200),
            'https://old-plugin.example.com*' => Http::response('<html><body><h1>Hello World</h1><p>This is a real site with plenty of content to pass body check.</p></body></html>', 200),
        ]);

        $response = $this->post(route('sites.store'), [
            'domain' => 'old-plugin.example.com',
            'companion_secret' => $secret,
        ]);

        $site = Site::where('domain', 'old-plugin.example.com')->first();
        expect($site)->not->toBeNull()
            ->and($site->companion_installed)->toBeTrue()
            ->and($site->backup_relay_enabled)->toBeFalse()
            ->and($site->backup_relay_frequency)->toBe('daily');

        $response->assertRedirect(route('sites.show', $site));
        $response->assertSessionHas('flash', fn (string $flash) => str_contains($flash, 'successfully connected and enrolled')
            && str_contains($flash, 'Off-site backups were not turned on: Companion 1.30.3 lacks the backup-create capability; update Companion or switch to Renegade.'));
    });
});
