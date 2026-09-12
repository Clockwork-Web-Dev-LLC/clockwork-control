<?php

namespace Tests\Feature\Modules;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Modules\Core\Contracts\HostingProvider;
use Modules\GridPane\GridPaneHostingProvider;

describe('GridPane Integration & Commands', function () {
    beforeEach(function () {
        Http::preventStrayRequests();
        config([
            'clockwork.gridpane.api_key' => 'test-gp-token',
            'clockwork.gridpane.base_url' => 'https://my.gridpane.com/oauth/api/v1',
        ]);
    });

    it('fails fast when clockwork:import-gridpane is run without credentials', function () {
        config(['clockwork.gridpane.api_key' => '']);

        $this->artisan('clockwork:import-gridpane')
            ->assertFailed()
            ->expectsOutputToContain('GRIDPANE_API_KEY is not configured');

        expect(Server::count())->toBe(0)
            ->and(Site::count())->toBe(0);
    });

    it('runs import-gridpane in dry-run mode without committing changes to the database', function () {
        Http::fake([
            'my.gridpane.com/oauth/api/v1/server' => Http::response([
                'data' => [
                    ['id' => 1001, 'label' => 'gp-node', 'ip' => '1.2.3.4'],
                ],
            ], 200),
            'my.gridpane.com/oauth/api/v1/site' => Http::response([
                'data' => [
                    ['id' => 5001, 'url' => 'example.com', 'server_id' => 1001],
                ],
            ], 200),
            'my.gridpane.com/oauth/api/v1/system-user' => Http::response(['data' => []], 200),
        ]);

        $this->artisan('clockwork:import-gridpane --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('[DRY RUN] Servers:')
            ->expectsOutputToContain('[DRY RUN] Sites:');

        expect(Server::count())->toBe(0)
            ->and(Site::count())->toBe(0);
    });

    it('imports servers and sites and links them correctly', function () {
        Http::fake([
            'my.gridpane.com/oauth/api/v1/server' => Http::response([
                'data' => [
                    [
                        'id' => 1001,
                        'label' => 'production-gp-server',
                        'ip' => '198.51.100.25',
                    ],
                ],
            ], 200),
            'my.gridpane.com/oauth/api/v1/site' => Http::response([
                'data' => [
                    [
                        'id' => 5001,
                        'url' => 'https://example-client.com',
                        'server_id' => 1001,
                        'system_user' => 'clientuser',
                    ],
                ],
            ], 200),
            'my.gridpane.com/oauth/api/v1/system-user' => Http::response(['data' => []], 200),
        ]);

        $this->artisan('clockwork:import-gridpane')
            ->assertSuccessful()
            ->expectsOutputToContain('1 servers found')
            ->expectsOutputToContain('1 sites found');

        $server = Server::where('provider', Server::PROVIDER_GRIDPANE)->first();
        expect($server)->not->toBeNull()
            ->and($server->provider_id)->toBe('1001')
            ->and($server->name)->toBe('production-gp-server')
            ->and($server->hostname)->toBe('198.51.100.25');

        $site = Site::withoutGlobalScopes()->where('domain', 'example-client.com')->first();
        expect($site)->not->toBeNull()
            ->and($site->hosting_provider)->toBe(Site::HOSTING_PROVIDER_GRIDPANE)
            ->and($site->gridpane_site_id)->toBe('5001')
            ->and($site->server_id)->toBe($server->id)
            ->and($site->site_user)->toBe('clientuser')
            ->and($site->wp_path)->toBe('/var/www/example-client.com/htdocs');
    });

    it('resolves site_user from system_user_id when the site row has no literal system_user field', function () {
        // Real GridPane /site rows never carry system_user/user directly —
        // only system_user_id, a foreign key into /system-user. This is the
        // actual shape GridPane returns (confirmed against a live account),
        // unlike the other tests here which pass a literal system_user for
        // simplicity.
        Http::fake([
            'my.gridpane.com/oauth/api/v1/server' => Http::response([
                'data' => [
                    ['id' => 1001, 'label' => 'gp-node', 'ip' => '198.51.100.25'],
                ],
            ], 200),
            'my.gridpane.com/oauth/api/v1/site' => Http::response([
                'data' => [
                    [
                        'id' => 5001,
                        'url' => 'example-client.com',
                        'server_id' => 1001,
                        'system_user_id' => 150741,
                    ],
                ],
            ], 200),
            'my.gridpane.com/oauth/api/v1/system-user' => Http::response([
                'data' => [
                    ['id' => 150741, 'username' => 'realsiteuser10870'],
                ],
            ], 200),
        ]);

        $this->artisan('clockwork:import-gridpane')->assertSuccessful();

        $site = Site::withoutGlobalScopes()->where('domain', 'example-client.com')->first();
        expect($site->site_user)->toBe('realsiteuser10870');
    });

    it('does not clobber an existing site_user when a later import cannot resolve system_user_id', function () {
        $server = Server::factory()->gridpane()->create(['provider_id' => '1001', 'hostname' => '198.51.100.25']);
        $site = Site::factory()->gridpane()->create([
            'server_id' => $server->id,
            'gridpane_site_id' => '5001',
            'domain' => 'example-client.com',
            'site_user' => 'realsiteuser10870',
        ]);

        Http::fake([
            'my.gridpane.com/oauth/api/v1/server' => Http::response([
                'data' => [
                    ['id' => 1001, 'label' => 'gp-node', 'ip' => '198.51.100.25'],
                ],
            ], 200),
            'my.gridpane.com/oauth/api/v1/site' => Http::response([
                'data' => [
                    // system_user_id present but absent from the /system-user
                    // response below (e.g. the owning user was since deleted
                    // from GridPane) — resolution fails for this site.
                    ['id' => 5001, 'url' => 'example-client.com', 'server_id' => 1001, 'system_user_id' => 999999],
                ],
            ], 200),
            'my.gridpane.com/oauth/api/v1/system-user' => Http::response(['data' => []], 200),
        ]);

        $this->artisan('clockwork:import-gridpane')->assertSuccessful();

        $site->refresh();
        expect($site->site_user)->toBe('realsiteuser10870');
    });

    it('updates existing servers and sites idempotently', function () {
        $server = Server::factory()->gridpane()->create([
            'provider_id' => '1001',
            'hostname' => '198.51.100.25',
            'name' => 'old-name',
        ]);

        $site = Site::factory()->gridpane()->create([
            'server_id' => $server->id,
            'gridpane_site_id' => '5001',
            'domain' => 'example-client.com',
            'site_user' => 'olduser',
        ]);

        Http::fake([
            'my.gridpane.com/oauth/api/v1/server' => Http::response([
                'data' => [
                    [
                        'id' => 1001,
                        'label' => 'new-server-name',
                        'ip' => '198.51.100.25',
                    ],
                ],
            ], 200),
            'my.gridpane.com/oauth/api/v1/site' => Http::response([
                'data' => [
                    [
                        'id' => 5001,
                        'url' => 'example-client.com',
                        'server_id' => 1001,
                        'system_user' => 'newuser',
                    ],
                ],
            ], 200),
            'my.gridpane.com/oauth/api/v1/system-user' => Http::response(['data' => []], 200),
        ]);

        $this->artisan('clockwork:import-gridpane')
            ->assertSuccessful();

        expect(Server::count())->toBe(1)
            ->and(Site::withoutGlobalScopes()->count())->toBe(1);

        $server->refresh();
        expect($server->name)->toBe('new-server-name');

        $site->refresh();
        expect($site->site_user)->toBe('newuser');
    });

    it('clockwork:test-gridpane verifies API connectivity and outputs info including view-only mode', function () {
        config(['clockwork.gridpane.view_only' => true]);

        Http::fake([
            'my.gridpane.com/oauth/api/v1/user' => Http::response([
                'email' => 'founder@agency.io',
                'name' => 'Agency Founder',
            ], 200),
            'my.gridpane.com/oauth/api/v1/server' => Http::response([
                'data' => [
                    ['id' => 1, 'label' => 'gp-node-01', 'ip' => '1.2.3.4'],
                ],
            ], 200),
            'my.gridpane.com/oauth/api/v1/site' => Http::response([
                'data' => [
                    ['id' => 10, 'url' => 'fastsite.com'],
                ],
            ], 200),
        ]);

        $this->artisan('clockwork:test-gridpane')
            ->assertSuccessful()
            ->expectsOutputToContain('Operating Mode:   View Only (Read-Only)')
            ->expectsOutputToContain('Authenticated as: founder@agency.io')
            ->expectsOutputToContain('Servers visible: 1')
            ->expectsOutputToContain('Sites visible:   1');
    });

    it('restricts capabilities and transports when in view-only mode', function () {
        config(['clockwork.gridpane.view_only' => true]);

        $site = Site::factory()->gridpane()->create();
        $host = $site->host();

        expect($host)->toBeInstanceOf(GridPaneHostingProvider::class)
            ->and($host->id())->toBe(Site::HOSTING_PROVIDER_GRIDPANE)
            ->and($host->label())->toBe('GridPane')
            ->and($host->supports(HostingProvider::CAP_SSH))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_COMPANION))->toBeFalse()
            ->and($host->commandRunner())->toBeNull()
            ->and($host->companionInstaller())->toBeNull()
            ->and($host->supports(HostingProvider::CAP_SERVER_LINKAGE))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_CERT_SYNC))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_ORPHAN_DETECTION))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_PERFORMANCE_SCAN))->toBeFalse()
            ->and($host->panelUrl($site))->toBe('https://my.gridpane.com/sites');
    });

    it('enables SSH and companion capabilities and transports when view-only is false', function () {
        config(['clockwork.gridpane.view_only' => false]);

        $site = Site::factory()->gridpane()->create();
        $host = $site->host();

        expect($host)->toBeInstanceOf(GridPaneHostingProvider::class)
            ->and($host->supports(HostingProvider::CAP_SSH))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_COMPANION))->toBeTrue()
            ->and($host->commandRunner())->not->toBeNull()
            ->and($host->companionInstaller())->not->toBeNull()
            ->and($host->supports(HostingProvider::CAP_SERVER_LINKAGE))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_CERT_SYNC))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_ORPHAN_DETECTION))->toBeTrue();
    });
});
