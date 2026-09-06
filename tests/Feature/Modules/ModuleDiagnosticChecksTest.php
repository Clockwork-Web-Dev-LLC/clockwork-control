<?php

use App\Services\Diagnostics\CheckResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Azure\AzureCheck;
use Modules\DigitalOcean\DigitalOceanCheck;
use Modules\Hetzner\HetznerCheck;
use Modules\Pressable\PressableCheck;
use Modules\SpinupWp\SpinupWpCheck;
use Tests\Fixtures\AzureFixtures;
use Tests\Fixtures\SpinupWpFixtures;

/**
 * Coverage for the 5 module-contributed DiagnosticCheck implementations
 * (App\Services\Diagnostics\DiagnosticCheck). Per CheckResult's docblock,
 * "skipped" means the integration isn't configured — not an error — so an
 * unconfigured integration must resolve to CheckResult::STATUS_SKIPPED, a
 * configured + successful probe to CheckResult::STATUS_OK, and a configured
 * + failing probe to CheckResult::STATUS_FAIL with a non-empty detail/summary
 * explaining what went wrong.
 *
 * AzureCheck/HetznerCheck/PressableCheck resolve their client from the
 * container (app(XClient::class)), which the real XServiceProvider binds to
 * read credentials via CredentialResolver — so setting config('clockwork.*')
 * here is sufficient without needing to construct the client directly.
 * DigitalOceanCheck/SpinupWpCheck call Illuminate\Support\Facades\Http
 * directly rather than going through a dedicated client class.
 */
describe('AzureCheck', function () {
    it('skips cleanly when no Azure credentials are configured', function () {
        config([
            'clockwork.azure.tenant_id' => '',
            'clockwork.azure.client_id' => '',
            'clockwork.azure.client_secret' => '',
            'clockwork.azure.subscription_id' => '',
        ]);

        $result = app(AzureCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toContain('CLOCKWORK_AZURE_TENANT_ID');
    });

    it('returns ok when the client-credentials flow and subscription lookup succeed', function () {
        config([
            'clockwork.azure.tenant_id' => 'tenant-1',
            'clockwork.azure.client_id' => 'client-1',
            'clockwork.azure.client_secret' => 'secret-1',
            'clockwork.azure.subscription_id' => 'sub-1',
        ]);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(AzureFixtures::oauthToken(), 200),
            'management.azure.com/subscriptions/sub-1*' => Http::response([
                'subscriptionId' => 'sub-1',
                'displayName' => 'Clockwork Production',
                'state' => 'Enabled',
            ], 200),
        ]);

        $result = app(AzureCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('Clockwork Production')
            ->and($result->summary)->toContain('Enabled');
    });

    it('returns fail with a message when the subscription lookup errors', function () {
        config([
            'clockwork.azure.tenant_id' => 'tenant-1',
            'clockwork.azure.client_id' => 'client-1',
            'clockwork.azure.client_secret' => 'secret-1',
            'clockwork.azure.subscription_id' => 'sub-1',
        ]);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(AzureFixtures::oauthToken(), 200),
            'management.azure.com/subscriptions/sub-1*' => Http::response([
                'error' => ['message' => 'The client does not have authorization.'],
            ], 403),
        ]);

        $result = app(AzureCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Request failed')
            ->and($result->detail)->not->toBeEmpty();
    });
});

describe('HetznerCheck', function () {
    it('skips cleanly when no Hetzner token is configured', function () {
        config(['clockwork.hetzner.token' => '']);

        $result = app(HetznerCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toContain('CLOCKWORK_HETZNER_TOKEN');
    });

    it('returns ok when the token check against /locations succeeds', function () {
        config(['clockwork.hetzner.token' => 'hz-token']);

        Http::fake([
            'api.hetzner.cloud/v1/locations*' => Http::response([
                'locations' => [
                    ['id' => 1, 'name' => 'nbg1'],
                    ['id' => 2, 'name' => 'fsn1'],
                ],
            ], 200),
        ]);

        $result = app(HetznerCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('2 location(s) visible');
    });

    it('returns fail with a message when the token is rejected', function () {
        config(['clockwork.hetzner.token' => 'bad-token']);

        Http::fake([
            'api.hetzner.cloud/v1/locations*' => Http::response([
                'error' => ['message' => 'unable to authenticate'],
            ], 401),
        ]);

        $result = app(HetznerCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Request failed')
            ->and($result->detail)->not->toBeEmpty();
    });
});

describe('DigitalOceanCheck', function () {
    it('skips cleanly when no DigitalOcean token is configured', function () {
        config(['clockwork.digitalocean.token' => '']);

        $result = app(DigitalOceanCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toContain('CLOCKWORK_DIGITALOCEAN_TOKEN');
    });

    it('returns ok when GET /account succeeds', function () {
        config(['clockwork.digitalocean.token' => 'do-token']);

        Http::fake([
            'api.digitalocean.com/v2/account' => Http::response([
                'account' => ['email' => 'ops@clockworkwd.com', 'status' => 'active'],
            ], 200),
        ]);

        $result = app(DigitalOceanCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('ops@clockworkwd.com')
            ->and($result->summary)->toContain('active');
    });

    it('returns fail with the HTTP status and body when the token is rejected', function () {
        config(['clockwork.digitalocean.token' => 'bad-token']);

        Http::fake([
            'api.digitalocean.com/v2/account' => Http::response([
                'id' => 'unauthorized',
                'message' => 'Unable to authenticate you.',
            ], 401),
        ]);

        $result = app(DigitalOceanCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('HTTP 401')
            ->and($result->detail)->toContain('Unable to authenticate you.');
    });
});

describe('PressableCheck', function () {
    beforeEach(function () {
        // PressableClient caches its OAuth2 token in the app cache store
        // across instances (see PressableClient::TOKEN_CACHE_KEY) — clear it
        // so one test's fake token doesn't leak into the next.
        Cache::flush();
    });

    it('skips cleanly when no Pressable client credentials are configured', function () {
        config([
            'clockwork.pressable.client_id' => '',
            'clockwork.pressable.client_secret' => '',
        ]);

        $result = app(PressableCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toContain('CLOCKWORK_PRESSABLE_CLIENT_ID');
    });

    it('returns ok when the OAuth2 client-credentials flow and GET /account succeed', function () {
        config([
            'clockwork.pressable.client_id' => 'client-1',
            'clockwork.pressable.client_secret' => 'secret-1',
        ]);

        Http::fake([
            'my.pressable.com/auth/token' => Http::response([
                'access_token' => 'fake-pressable-token',
                'expires_in' => 3599,
            ], 200),
            'my.pressable.com/v1/account' => Http::response([
                'data' => ['email' => 'agency@clockworkwd.com', 'name' => 'Clockwork Agency'],
            ], 200),
        ]);

        $result = app(PressableCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('agency@clockworkwd.com');
    });

    it('appends view only mode when pressable is configured as view only', function () {
        config([
            'clockwork.pressable.client_id' => 'client-1',
            'clockwork.pressable.client_secret' => 'secret-1',
            'clockwork.pressable.view_only' => true,
        ]);

        Http::fake([
            'my.pressable.com/auth/token' => Http::response([
                'access_token' => 'fake-pressable-token',
                'expires_in' => 3599,
            ], 200),
            'my.pressable.com/v1/account' => Http::response([
                'data' => ['email' => 'agency@clockworkwd.com', 'name' => 'Clockwork Agency'],
            ], 200),
        ]);

        $result = app(PressableCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('agency@clockworkwd.com')
            ->and($result->summary)->toContain('· Mode: View Only');
    });

    it('returns fail with a message when GET /account errors', function () {
        config([
            'clockwork.pressable.client_id' => 'client-1',
            'clockwork.pressable.client_secret' => 'secret-1',
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

        $result = app(PressableCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Request failed')
            ->and($result->detail)->not->toBeEmpty();
    });
});

describe('SpinupWpCheck', function () {
    it('skips cleanly when no SpinupWP token is configured', function () {
        config(['clockwork.spinupwp.token' => '']);

        $result = app(SpinupWpCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toContain('CLOCKWORK_SPINUPWP_TOKEN');
    });

    it('returns ok when GET /servers succeeds', function () {
        config(['clockwork.spinupwp.token' => 'swp-token']);

        Http::fake([
            'api.spinupwp.app/v1/servers*' => Http::response([
                'data' => [SpinupWpFixtures::server()],
                'meta' => ['total' => 7],
            ], 200),
        ]);

        $result = app(SpinupWpCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('7 servers visible');
    });

    it('appends view only mode when spinupwp is configured as view only', function () {
        config([
            'clockwork.spinupwp.token' => 'swp-token',
            'clockwork.spinupwp.view_only' => true,
        ]);

        Http::fake([
            'api.spinupwp.app/v1/servers*' => Http::response([
                'data' => [SpinupWpFixtures::server()],
                'meta' => ['total' => 7],
            ], 200),
        ]);

        $result = app(SpinupWpCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('7 servers visible')
            ->and($result->summary)->toContain('· Mode: View Only');
    });

    it('returns fail with the HTTP status and body when the token is rejected', function () {
        config(['clockwork.spinupwp.token' => 'bad-token']);

        Http::fake([
            'api.spinupwp.app/v1/servers*' => Http::response([
                'message' => 'Unauthenticated.',
            ], 401),
        ]);

        $result = app(SpinupWpCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('HTTP 401')
            ->and($result->detail)->toContain('Unauthenticated.');
    });
});
