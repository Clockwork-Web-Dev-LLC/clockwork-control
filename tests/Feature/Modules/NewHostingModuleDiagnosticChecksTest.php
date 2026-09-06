<?php

use App\Services\Diagnostics\CheckResult;
use Illuminate\Support\Facades\Http;
use Modules\Cloudways\CloudwaysCheck;
use Modules\GridPane\GridPaneCheck;
use Modules\Kinsta\KinstaCheck;
use Modules\WPEngine\WPEngineCheck;

/**
 * Coverage for the 3 new module-contributed DiagnosticCheck implementations,
 * mirroring ModuleDiagnosticChecksTest's skip/ok/fail pattern for the
 * original 5. Each check's real probe is exercised through Http::fake()
 * against its actual client class rather than mocking the client, so a
 * regression in the client's request shape would also surface here.
 */
describe('WPEngineCheck', function () {
    it('skips cleanly when no WP Engine credentials are configured', function () {
        config([
            'clockwork.wpengine.api_user_id' => '',
            'clockwork.wpengine.api_password' => '',
        ]);

        $result = app(WPEngineCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toContain('CLOCKWORK_WPENGINE_API_USER_ID');
    });

    it('returns ok when GET /installs succeeds', function () {
        config([
            'clockwork.wpengine.api_user_id' => 'user-1',
            'clockwork.wpengine.api_password' => 'pass-1',
        ]);

        Http::fake([
            'api.wpengine.com/v1/installs*' => Http::response([
                'previous' => null,
                'next' => null,
                'count' => 12,
                'results' => [],
            ], 200),
        ]);

        $result = app(WPEngineCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('12 install(s) visible')
            ->and($result->summary)->toContain('· Mode: View Only');
    });

    it('returns ok without view only tag when WP Engine view_only is false', function () {
        config([
            'clockwork.wpengine.api_user_id' => 'user-1',
            'clockwork.wpengine.api_password' => 'pass-1',
            'clockwork.wpengine.view_only' => false,
        ]);

        Http::fake([
            'api.wpengine.com/v1/installs*' => Http::response([
                'previous' => null,
                'next' => null,
                'count' => 12,
                'results' => [],
            ], 200),
        ]);

        $result = app(WPEngineCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('12 install(s) visible')
            ->and($result->summary)->not->toContain('· Mode: View Only');
    });

    it('returns fail with a message when GET /installs is rejected', function () {
        config([
            'clockwork.wpengine.api_user_id' => 'user-1',
            'clockwork.wpengine.api_password' => 'bad-pass',
        ]);

        Http::fake([
            'api.wpengine.com/v1/installs*' => Http::response([
                'message' => 'Unauthorized',
            ], 401),
        ]);

        $result = app(WPEngineCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Request failed')
            ->and($result->detail)->not->toBeEmpty();
    });
});

describe('KinstaCheck', function () {
    it('skips cleanly when no Kinsta API key is configured', function () {
        config(['clockwork.kinsta.api_key' => '']);

        $result = app(KinstaCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toContain('CLOCKWORK_KINSTA_API_KEY');
    });

    it('returns ok on any non-401 status from GET /sites (company param not supplied by ping())', function () {
        config(['clockwork.kinsta.api_key' => 'kinsta-key']);

        // ping() deliberately omits the required `company` query param, so a
        // valid key is expected to come back 400/422 here — see
        // KinstaClient::ping()'s docblock for why that still counts as "ok".
        Http::fake([
            'api.kinsta.com/v2/sites*' => Http::response(['message' => 'company is required'], 422),
        ]);

        $result = app(KinstaCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('HTTP 422')
            ->and($result->summary)->toContain('· Mode: View Only');
    });

    it('returns ok without view only tag when Kinsta view_only is false', function () {
        config([
            'clockwork.kinsta.api_key' => 'kinsta-key',
            'clockwork.kinsta.view_only' => false,
        ]);

        Http::fake([
            'api.kinsta.com/v2/sites*' => Http::response(['message' => 'company is required'], 422),
        ]);

        $result = app(KinstaCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('HTTP 422')
            ->and($result->summary)->not->toContain('· Mode: View Only');
    });

    it('returns fail when the API key itself is rejected with a 401', function () {
        config(['clockwork.kinsta.api_key' => 'bad-key']);

        Http::fake([
            'api.kinsta.com/v2/sites*' => Http::response(['message' => 'Unauthenticated'], 401),
        ]);

        $result = app(KinstaCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Request failed')
            ->and($result->detail)->toContain('401');
    });
});

describe('CloudwaysCheck', function () {
    it('skips cleanly when no Cloudways credentials are configured', function () {
        config([
            'clockwork.cloudways.api_key' => '',
            'clockwork.cloudways.email' => '',
        ]);

        $result = app(CloudwaysCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toContain('CLOCKWORK_CLOUDWAYS_API_KEY');
    });

    it('returns ok when the OAuth2 token exchange and GET /server both succeed', function () {
        config([
            'clockwork.cloudways.api_key' => 'cw-key',
            'clockwork.cloudways.email' => 'agency@clockworkwd.com',
        ]);

        Http::fake([
            'api.cloudways.com/api/v2/oauth/access_token' => Http::response([
                'access_token' => 'fake-cloudways-token',
                'expires_in' => 3600,
            ], 200),
            'api.cloudways.com/api/v2/server' => Http::response([
                'servers' => [['id' => 1], ['id' => 2], ['id' => 3]],
            ], 200),
        ]);

        $result = app(CloudwaysCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('3 servers visible')
            ->and($result->summary)->toContain('· Mode: View Only');
    });

    it('returns ok without view only tag when Cloudways view_only is false', function () {
        config([
            'clockwork.cloudways.api_key' => 'cw-key',
            'clockwork.cloudways.email' => 'agency@clockworkwd.com',
            'clockwork.cloudways.view_only' => false,
        ]);

        Http::fake([
            'api.cloudways.com/api/v2/oauth/access_token' => Http::response([
                'access_token' => 'fake-cloudways-token',
                'expires_in' => 3600,
            ], 200),
            'api.cloudways.com/api/v2/server' => Http::response([
                'servers' => [['id' => 1], ['id' => 2], ['id' => 3]],
            ], 200),
        ]);

        $result = app(CloudwaysCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('3 servers visible')
            ->and($result->summary)->not->toContain('· Mode: View Only');
    });

    it('returns fail with a message when the token exchange is rejected', function () {
        config([
            'clockwork.cloudways.api_key' => 'bad-key',
            'clockwork.cloudways.email' => 'agency@clockworkwd.com',
        ]);

        Http::fake([
            'api.cloudways.com/api/v2/oauth/access_token' => Http::response([
                'error' => 'invalid_grant',
            ], 401),
        ]);

        $result = app(CloudwaysCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Request failed')
            ->and($result->detail)->not->toBeEmpty();
    });
});

describe('GridPaneCheck', function () {
    it('skips cleanly when no GridPane API key is configured', function () {
        config([
            'clockwork.gridpane.api_key' => '',
        ]);

        $result = app(GridPaneCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toContain('No GRIDPANE_API_KEY set');
    });

    it('returns ok when GET /user succeeds', function () {
        config([
            'clockwork.gridpane.api_key' => 'valid-gridpane-key',
        ]);

        Http::fake([
            'my.gridpane.com/oauth/api/v1/user' => Http::response([
                'email' => 'ops@clockworkcontrol.com',
                'name' => 'Clockwork Control Ops',
            ], 200),
        ]);

        $result = app(GridPaneCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('Token valid')
            ->and($result->summary)->toContain('ops@clockworkcontrol.com');
    });

    it('returns fail with a message when GET /user returns an error', function () {
        config([
            'clockwork.gridpane.api_key' => 'invalid-key',
        ]);

        Http::fake([
            'my.gridpane.com/oauth/api/v1/user' => Http::response([
                'message' => 'Unauthenticated.',
            ], 401),
        ]);

        $result = app(GridPaneCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('HTTP 401')
            ->and($result->detail)->toContain('Unauthenticated.');
    });
});
