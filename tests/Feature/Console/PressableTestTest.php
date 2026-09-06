<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\PressableFixtures;

/**
 * Coverage for the `clockwork:pressable-test` connectivity-check command
 * (App\Console\Commands\PressableTest). Resolves PressableClient from the
 * container directly and prints straight to the console.
 */
describe('clockwork:pressable-test', function () {
    beforeEach(function () {
        // PressableClient caches its OAuth2 token in the app cache store
        // across instances (see PressableClient::TOKEN_CACHE_KEY) — clear it
        // so one test's fake token doesn't leak into the next.
        Cache::flush();
    });

    it('exits with failure and reports nothing configured when client credentials are missing', function () {
        config([
            'clockwork.pressable.client_id' => '',
            'clockwork.pressable.client_secret' => '',
        ]);

        $this->artisan('clockwork:pressable-test')
            ->assertFailed()
            ->expectsOutputToContain('CLOCKWORK_PRESSABLE_CLIENT_ID / CLOCKWORK_PRESSABLE_CLIENT_SECRET are not set');
    });

    it('authenticates and lists sites when credentials are valid', function () {
        config([
            'clockwork.pressable.client_id' => 'client-1',
            'clockwork.pressable.client_secret' => 'secret-1',
            'clockwork.pressable.auth_url' => 'https://my.pressable.com/auth/token',
            'clockwork.pressable.base_url' => 'https://my.pressable.com/v1',
        ]);

        Http::fake([
            'my.pressable.com/auth/token' => Http::response([
                'access_token' => 'fake-pressable-token',
                'expires_in' => 3599,
            ], 200),
            'my.pressable.com/v1/account' => Http::response([
                'data' => ['organization' => 'Clockwork Agency'],
            ], 200),
            'my.pressable.com/v1/sites*' => Http::response(
                PressableFixtures::listResponse([PressableFixtures::site()]),
                200
            ),
        ]);

        $this->artisan('clockwork:pressable-test')
            ->assertSuccessful()
            ->expectsOutputToContain('Organization: Clockwork Agency')
            ->expectsOutputToContain('Sites visible: 1');
    });

    it('exits with failure and reports the error when the account lookup fails', function () {
        config([
            'clockwork.pressable.client_id' => 'client-1',
            'clockwork.pressable.client_secret' => 'secret-1',
            'clockwork.pressable.auth_url' => 'https://my.pressable.com/auth/token',
            'clockwork.pressable.base_url' => 'https://my.pressable.com/v1',
        ]);

        Http::fake([
            'my.pressable.com/auth/token' => Http::response([
                'access_token' => 'fake-pressable-token',
                'expires_in' => 3599,
            ], 200),
            'my.pressable.com/v1/account' => Http::response([
                'message' => 'Internal server error',
            ], 500),
        ]);

        $this->artisan('clockwork:pressable-test')
            ->assertFailed()
            ->expectsOutputToContain('Pressable GET /account failed');
    });
});
