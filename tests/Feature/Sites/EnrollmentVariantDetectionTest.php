<?php

use App\Models\Site;
use App\Models\User;
use App\Services\Companion\ClockworkCompanionClient;
use App\Support\SsrfGuard;
use Illuminate\Support\Facades\Http;

describe('Enrollment Variant Detection (Renegade vs Classic Companion)', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create());
    });

    it('enrolls a site as renegade when connection key specifies variant renegade', function () {
        $secret = bin2hex(random_bytes(32));
        $connectionPayload = json_encode([
            'url' => 'https://renegade-site.com',
            'secret' => $secret,
            'variant' => 'renegade',
        ]);
        $connectionKey = base64_encode($connectionPayload);

        SsrfGuard::fake(['renegade-site.com' => ['93.184.216.34']]);
        Http::fake([
            'https://renegade-site.com/wp-json/clockwork-renegade/v1/health*' => Http::response([
                'ok' => true,
                'version' => '1.0.0',
                'capabilities' => ['snapshot', 'updates', 'malware-scan', 'admins', 'sso'],
                'is_multisite' => false,
            ], 200),
            'https://renegade-site.com/wp-json/clockwork-renegade/v1/snapshot*' => Http::response([
                'ok' => true,
                'plugins' => ['active' => [], 'counts' => ['updates_available' => 0]],
            ], 200),
            'https://renegade-site.com*' => Http::response('<html><head><title>Renegade</title></head><body><h1>Renegade</h1><p>Full content test</p></body></html>', 200),
        ]);

        $response = $this->post(route('sites.store'), [
            'connection_key' => $connectionKey,
        ]);

        $site = Site::where('domain', 'renegade-site.com')->first();
        expect($site)->not->toBeNull()
            ->and($site->companion_installed)->toBeTrue()
            ->and($site->companion_variant)->toBe('renegade')
            ->and($site->isRenegade())->toBeTrue()
            ->and($site->isClassicCompanion())->toBeFalse()
            ->and($site->companion_version)->toBe('1.0.0')
            ->and($site->auto_updates_paused)->toBeFalse();

        $response->assertRedirect(route('sites.show', $site));
    });

    it('falls back to renegade when classic companion returns 404', function () {
        $secret = bin2hex(random_bytes(32));

        SsrfGuard::fake(['auto-detect-renegade.com' => ['93.184.216.34']]);
        Http::fake([
            'https://auto-detect-renegade.com/wp-json/clockwork/v1/health*' => Http::response('Not Found', 404),
            'https://auto-detect-renegade.com/wp-json/clockwork-renegade/v1/health*' => Http::response([
                'ok' => true,
                'version' => '1.0.0',
                'capabilities' => ['snapshot', 'updates', 'admins'],
                'is_multisite' => false,
            ], 200),
            'https://auto-detect-renegade.com/wp-json/clockwork-renegade/v1/snapshot*' => Http::response([
                'ok' => true,
                'plugins' => ['active' => [], 'counts' => ['updates_available' => 0]],
            ], 200),
            'https://auto-detect-renegade.com*' => Http::response('<html><head><title>Auto</title></head><body><h1>Auto Detect</h1><p>Content</p></body></html>', 200),
        ]);

        $response = $this->post(route('sites.store'), [
            'domain' => 'auto-detect-renegade.com',
            'companion_secret' => $secret,
        ]);

        $site = Site::where('domain', 'auto-detect-renegade.com')->first();
        expect($site)->not->toBeNull()
            ->and($site->companion_installed)->toBeTrue()
            ->and($site->companion_variant)->toBe('renegade')
            ->and($site->companion_version)->toBe('1.0.0');

        $response->assertRedirect(route('sites.show', $site));
    });

    it('enrolls classic companion when clockwork/v1/health responds successfully', function () {
        $secret = bin2hex(random_bytes(32));

        SsrfGuard::fake(['classic-site.com' => ['93.184.216.34']]);
        Http::fake([
            'https://classic-site.com/wp-json/clockwork/v1/health*' => Http::response([
                'ok' => true,
                'version' => '1.35.0',
                'capabilities' => ['snapshot', 'updates', 'code-snippets'],
                'is_multisite' => false,
            ], 200),
            'https://classic-site.com/wp-json/clockwork/v1/snapshot*' => Http::response([
                'ok' => true,
                'plugins' => ['active' => [], 'counts' => ['updates_available' => 0]],
            ], 200),
            'https://classic-site.com*' => Http::response('<html><head><title>Classic</title></head><body><h1>Classic</h1><p>Content</p></body></html>', 200),
        ]);

        $response = $this->post(route('sites.store'), [
            'domain' => 'classic-site.com',
            'companion_secret' => $secret,
        ]);

        $site = Site::where('domain', 'classic-site.com')->first();
        expect($site)->not->toBeNull()
            ->and($site->companion_installed)->toBeTrue()
            ->and($site->companion_variant)->toBe('companion')
            ->and($site->isClassicCompanion())->toBeTrue()
            ->and($site->isRenegade())->toBeFalse()
            ->and($site->companion_version)->toBe('1.35.0');
    });

    it('returns error when neither renegade nor classic companion route is found', function () {
        $secret = bin2hex(random_bytes(32));

        SsrfGuard::fake(['empty-site.com' => ['93.184.216.34']]);
        Http::fake([
            'https://empty-site.com/wp-json/clockwork/v1/health*' => Http::response('Not Found', 404),
            'https://empty-site.com/wp-json/clockwork-renegade/v1/health*' => Http::response('Not Found', 404),
        ]);

        $response = $this->post(route('sites.store'), [
            'domain' => 'empty-site.com',
            'companion_secret' => $secret,
        ]);

        $response->assertSessionHasErrors('domain');
        expect(Site::where('domain', 'empty-site.com')->exists())->toBeFalse();
    });

    it('configures companion client to use clockwork-renegade route namespace and signs accordingly', function () {
        $secret = bin2hex(random_bytes(32));
        $site = Site::factory()->create([
            'domain' => 'my-renegade-wp.com',
            'companion_secret' => $secret,
            'companion_variant' => 'renegade',
        ]);

        SsrfGuard::fake(['my-renegade-wp.com' => ['93.184.216.34']]);

        $capturedUrl = null;
        $capturedSignature = null;
        $capturedTimestamp = null;

        Http::fake([
            'https://my-renegade-wp.com/wp-json/clockwork-renegade/v1/health*' => function ($request) use (&$capturedUrl, &$capturedSignature, &$capturedTimestamp) {
                $capturedUrl = (string) $request->url();
                $capturedSignature = $request->header('X-Clockwork-Signature')[0] ?? null;
                $capturedTimestamp = $request->header('X-Clockwork-Timestamp')[0] ?? null;

                return Http::response(['ok' => true, 'version' => '1.0.0'], 200);
            },
        ]);

        $client = new ClockworkCompanionClient($site);
        expect($client->routeNamespace())->toBe('clockwork-renegade/v1');

        $result = $client->health();
        expect($result['ok'])->toBeTrue()
            ->and($capturedUrl)->toContain('/wp-json/clockwork-renegade/v1/health');

        // Verify HMAC calculation matches Clockwork Renegade's expectation
        $expectedPayload = "GET\n/wp-json/clockwork-renegade/v1/health\n{$capturedTimestamp}\n";
        $expectedSignature = hash_hmac('sha256', $expectedPayload, $secret);
        expect($capturedSignature)->toBe($expectedSignature);
    });
});
