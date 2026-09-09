<?php

use App\Services\Diagnostics\CheckResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Modules\Core\ModuleManifest;
use Modules\GridPane\GridPaneCheck;
use Modules\GridPane\GridPaneClient;
use Modules\GridPane\GridPaneReadOnlyException;
use Modules\GridPane\GridPaneServiceProvider;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('GridPane module unit tests', function () {
    beforeEach(function () {
        Http::preventStrayRequests();
    });

    it('manifest provides verified status and credential fields', function () {
        $provider = new GridPaneServiceProvider(app());
        $manifest = $provider->manifest();

        expect($manifest->id)->toBe('gridpane')
            ->and($manifest->name)->toBe('GridPane')
            ->and($manifest->status)->toBe(ModuleManifest::STATUS_VERIFIED)
            ->and($manifest->credentialFields)->toHaveKey('api_key')
            ->and($manifest->credentialFields['api_key']['secret'])->toBeTrue()
            ->and($manifest->credentialFields)->toHaveKey('base_url')
            ->and($manifest->credentialFields['base_url']['secret'])->toBeFalse()
            ->and($manifest->credentialFields)->toHaveKey('view_only')
            ->and($manifest->credentialFields['view_only']['secret'])->toBeFalse();
    });

    it('resolves GridPaneClient from container', function () {
        $client = app(GridPaneClient::class);

        expect($client)->toBeInstanceOf(GridPaneClient::class);
    });

    it('GridPaneCheck skips when no API key is configured', function () {
        config(['clockwork.gridpane.api_key' => '']);

        $check = app(GridPaneCheck::class);
        $result = $check->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toContain('No GRIDPANE_API_KEY set');
    });

    it('GridPaneCheck reports OK when token is valid and user info is returned', function () {
        config([
            'clockwork.gridpane.api_key' => 'test-valid-key',
            'clockwork.gridpane.view_only' => true,
        ]);

        Http::fake([
            'my.gridpane.com/oauth/api/v1/user' => Http::response([
                'email' => 'admin@agency.com',
                'name' => 'Agency Admin',
            ], 200),
        ]);

        $check = app(GridPaneCheck::class);
        $result = $check->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('Token valid · User: admin@agency.com · Mode: View Only');
    });

    it('GridPaneCheck does not include View Only tag when view_only is false', function () {
        config([
            'clockwork.gridpane.api_key' => 'test-valid-key',
            'clockwork.gridpane.view_only' => false,
        ]);

        Http::fake([
            'my.gridpane.com/oauth/api/v1/user' => Http::response([
                'email' => 'admin@agency.com',
                'name' => 'Agency Admin',
            ], 200),
        ]);

        $check = app(GridPaneCheck::class);
        $result = $check->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toBe('Token valid · User: admin@agency.com')
            ->and($result->summary)->not->toContain('Mode: View Only');
    });

    it('GridPaneCheck reports failure when API returns error response', function () {
        config(['clockwork.gridpane.api_key' => 'test-invalid-key']);

        Http::fake([
            'my.gridpane.com/oauth/api/v1/user' => Http::response([
                'message' => 'Unauthenticated.',
            ], 401),
        ]);

        $check = app(GridPaneCheck::class);
        $result = $check->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('HTTP 401')
            ->and($result->detail)->toContain('Unauthenticated');
    });

    it('GridPaneClient fetches user details via GET /user', function () {
        Http::fake([
            'my.gridpane.com/oauth/api/v1/user' => Http::response([
                'id' => 42,
                'email' => 'tech@agency.com',
                'name' => 'Tech Lead',
            ], 200),
        ]);

        $client = new GridPaneClient('test-key');
        $user = $client->user();

        expect($user)->toHaveKey('email', 'tech@agency.com')
            ->and($user)->toHaveKey('id', 42);
    });

    it('GridPaneClient fetches servers array handling varying payload structures', function () {
        Http::fake([
            'my.gridpane.com/oauth/api/v1/server' => Http::response([
                'data' => [
                    ['id' => 101, 'server_name' => 'gp-web-01', 'ip' => '192.0.2.1'],
                    ['id' => 102, 'server_name' => 'gp-web-02', 'ip' => '192.0.2.2'],
                ],
            ], 200),
        ]);

        $client = new GridPaneClient('test-key');
        $servers = $client->servers();

        expect($servers)->toHaveCount(2)
            ->and($servers[0]['server_name'])->toBe('gp-web-01')
            ->and($servers[1]['ip'])->toBe('192.0.2.2');
    });

    it('GridPaneClient fetches sites array handling varying payload structures', function () {
        Http::fake([
            'my.gridpane.com/oauth/api/v1/site' => Http::response([
                'sites' => [
                    ['id' => 501, 'url' => 'clientsite.com', 'server_id' => 101],
                ],
            ], 200),
        ]);

        $client = new GridPaneClient('test-key');
        $sites = $client->sites();

        expect($sites)->toHaveCount(1)
            ->and($sites[0]['url'])->toBe('clientsite.com')
            ->and($sites[0]['server_id'])->toBe(101);
    });

    it('GridPaneClient fetches single server by ID', function () {
        Http::fake([
            'my.gridpane.com/oauth/api/v1/server/101' => Http::response([
                'server' => ['id' => 101, 'label' => 'gp-node-01', 'ip' => '1.2.3.4'],
            ], 200),
        ]);

        $client = new GridPaneClient('test-key');
        $server = $client->server(101);

        expect($server)->toHaveKey('id', 101)
            ->and($server['label'])->toBe('gp-node-01');
    });

    it('GridPaneClient fetches single site by ID', function () {
        Http::fake([
            'my.gridpane.com/oauth/api/v1/site/501' => Http::response([
                'data' => ['id' => 501, 'url' => 'singlesite.test', 'server_id' => 101],
            ], 200),
        ]);

        $client = new GridPaneClient('test-key');
        $site = $client->site(501);

        expect($site)->toHaveKey('id', 501)
            ->and($site['url'])->toBe('singlesite.test');
    });

    it('GridPaneClient fetches system users', function () {
        Http::fake([
            'my.gridpane.com/oauth/api/v1/system-user' => Http::response([
                'data' => [
                    ['id' => 1, 'username' => 'gridpane', 'server_id' => 101],
                ],
            ], 200),
        ]);

        $client = new GridPaneClient('test-key');
        $users = $client->systemUsers();

        expect($users)->toHaveCount(1)
            ->and($users[0]['username'])->toBe('gridpane');
    });

    it('GridPaneClient fetches backup schedules for a site', function () {
        Http::fake([
            'my.gridpane.com/oauth/api/v1/backups/schedules/site/501' => Http::response([
                'status' => 'success',
                'schedules' => [
                    'hourly' => true,
                    'daily' => true,
                ],
            ], 200),
        ]);

        $client = new GridPaneClient('test-key');
        $schedules = $client->backupSchedules(501);

        expect($schedules)->toHaveKey('schedules')
            ->and($schedules['schedules']['daily'])->toBeTrue();
    });

    it('GridPaneClient defaults to view-only mode', function () {
        config(['clockwork.gridpane.view_only' => true]);
        $client = new GridPaneClient('test-key');

        expect($client->isViewOnly())->toBeTrue();
    });

    it('GridPaneClient throws GridPaneReadOnlyException on mutating operations when view-only', function () {
        $client = new GridPaneClient(apiKey: 'test-key', viewOnly: true);

        expect(fn () => $client->post('/site', ['url' => 'newsite.com']))
            ->toThrow(GridPaneReadOnlyException::class, 'GridPane integration is in View-Only mode. POST [/site] is prohibited.');

        expect(fn () => $client->put('/site/123', ['setting' => 'val']))
            ->toThrow(GridPaneReadOnlyException::class, 'GridPane integration is in View-Only mode. PUT [/site/123] is prohibited.');

        expect(fn () => $client->delete('/site/123'))
            ->toThrow(GridPaneReadOnlyException::class, 'GridPane integration is in View-Only mode. DELETE [/site/123] is prohibited.');

        expect(fn () => $client->runWpCli(123, 'cache flush'))
            ->toThrow(GridPaneReadOnlyException::class, 'GridPane integration is in View-Only mode. Remote WP-CLI execution is prohibited.');
    });

    it('GridPaneClient allows mutating operations when viewOnly is false', function () {
        Http::fake([
            'my.gridpane.com/oauth/api/v1/site' => Http::response(['status' => 'created'], 201),
            'my.gridpane.com/oauth/api/v1/site/123' => Http::sequence()
                ->push(['status' => 'updated'], 200)
                ->push(['status' => 'deleted'], 200),
            'my.gridpane.com/oauth/api/v1/site/run-wp-cli/123' => Http::response([
                'status' => 'success',
                'output' => 'Success: Cache flushed.',
            ], 200),
        ]);

        $client = new GridPaneClient(apiKey: 'test-key', viewOnly: false);

        expect($client->isViewOnly())->toBeFalse();
        expect($client->post('/site', ['url' => 'newsite.com'])->json())->toBe(['status' => 'created']);
        expect($client->put('/site/123', ['setting' => 'val'])->json())->toBe(['status' => 'updated']);
        expect($client->delete('/site/123')->json())->toBe(['status' => 'deleted']);
        expect($client->runWpCli(123, 'cache flush'))->toHaveKey('output', 'Success: Cache flushed.');
    });

    it('GridPaneClient throws RuntimeException when unconfigured', function () {
        $client = new GridPaneClient('');

        expect(fn () => $client->user())->toThrow(RuntimeException::class, 'GridPane API key is not configured.');
    });

    it('GridPaneClient throws RequestException on HTTP error', function () {
        Http::fake([
            'my.gridpane.com/oauth/api/v1/user' => Http::response('Server Error', 500),
        ]);

        $client = new GridPaneClient('test-key');

        expect(fn () => $client->user())->toThrow(RequestException::class);
    });

    it('GridPaneClient auto-paginates across multiple pages', function () {
        Http::fake([
            'my.gridpane.com/oauth/api/v1/site' => Http::response([
                'data' => [
                    ['id' => 1, 'url' => 'site1.com'],
                    ['id' => 2, 'url' => 'site2.com'],
                ],
                'links' => [
                    'next' => 'https://my.gridpane.com/oauth/api/v1/site?page=2',
                ],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 2,
                    'total' => 3,
                ],
            ], 200),
            'my.gridpane.com/oauth/api/v1/site?page=2' => Http::response([
                'data' => [
                    ['id' => 3, 'url' => 'site3.com'],
                ],
                'links' => [
                    'next' => null,
                ],
                'meta' => [
                    'current_page' => 2,
                    'last_page' => 2,
                    'total' => 3,
                ],
            ], 200),
        ]);

        $client = new GridPaneClient('test-key');
        $sites = $client->sites();

        expect($sites)->toHaveCount(3)
            ->and($sites[0]['url'])->toBe('site1.com')
            ->and($sites[2]['url'])->toBe('site3.com');
    });
});
