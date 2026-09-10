<?php

use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Site;
use App\Models\User;
use Modules\Core\InstalledModule;
use Modules\Core\ModuleStateResolver;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('DashboardController', function () {
    beforeEach(function () {
        $this->mockIssueCounterZero();
    });

    it('redirects unauthenticated requests to login', function () {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    });

    it('redirects to /setup when the fleet has no servers and no sites', function () {
        // Deliberately create nothing — RedirectToSetupIfFreshInstall triggers
        // only on Server::count() === 0 && Site::count() === 0.
        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'));

        $response->assertRedirect(route('setup.index'));
    });

    it('renders the normal dashboard when the fleet has servers and sites', function () {
        $server = Server::factory()->create(['name' => 'web1.example.com']);
        Site::factory()->spinupwp()->create(['server_id' => $server->id, 'domain' => 'client-one.example.com']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'));

        $response->assertOk()->assertSee('web1.example.com');
    });

    it('shows only the Refresh from SpinupWP fleet action when GridPane is disabled', function () {
        // ModuleStateResolver is a request-scoped singleton that memoizes
        // installed_modules on first access — every module's own
        // ModuleServiceProvider::register() already triggered that first
        // access during this test's own app bootstrap, before this test
        // body even runs, so a plain InstalledModule::create() here has no
        // effect until the memoized cache is explicitly flushed. See
        // tests/Feature/Modules/ModuleEnableDisableTest.php for the same
        // pattern applied directly against the resolver.
        $server = Server::factory()->create(['name' => 'web1a.example.com']);
        Site::factory()->spinupwp()->create(['server_id' => $server->id]);
        InstalledModule::create(['module_id' => 'gridpane', 'name' => 'GridPane', 'enabled' => false]);
        app(ModuleStateResolver::class)->flush();

        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Refresh from SpinupWP')
            ->assertDontSee('Refresh from GridPane');
    });

    it('shows only the Refresh from GridPane fleet action when SpinupWP is disabled', function () {
        $server = Server::factory()->create(['name' => 'web1b.example.com', 'provider' => 'gridpane']);
        Site::factory()->spinupwp()->create(['server_id' => $server->id]);
        InstalledModule::create(['module_id' => 'spinupwp', 'name' => 'SpinupWP', 'enabled' => false]);
        app(ModuleStateResolver::class)->flush();

        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Refresh from GridPane')
            ->assertDontSee('Refresh from SpinupWP');
    });

    it('renders servers.show for each allowed tab', function (string $tab) {
        $server = Server::factory()->create(['name' => 'web2.example.com']);
        Site::factory()->spinupwp()->create(['server_id' => $server->id, 'domain' => 'client-two.example.com']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('servers.show', ['server' => $server, 'tab' => $tab]));

        $response->assertOk()->assertSee('web2.example.com');
    })->with(['sites', 'stats', 'updates', 'bans', 'settings']);

    it('defaults to the sites tab when no tab segment is given', function () {
        $server = Server::factory()->create(['name' => 'web3.example.com']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('servers.show', ['server' => $server]));

        $response->assertOk()->assertSee('web3.example.com');
    });

    it('404s on an unrecognized tab segment', function () {
        $server = Server::factory()->create();

        $response = $this->actingAs(User::factory()->create())
            ->get('/servers/'.$server->id.'/not-a-real-tab');

        $response->assertNotFound();
    });

    it('shows the provider-missing banner with a remove action once a poll confirms the server is gone at its provider', function () {
        $server = Server::factory()->create([
            'name' => 'web4.example.com',
            'provider' => 'digitalocean',
            'provider_missing_since' => now()->subDay(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('servers.show', ['server' => $server]));

        $response->assertOk()
            ->assertSee('no longer exists at')
            ->assertSee(route('servers.destroy', $server), false);
    });

    it('does not show the provider-missing banner for a server currently polling fine', function () {
        $server = Server::factory()->create([
            'name' => 'web5.example.com',
            'provider_missing_since' => null,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('servers.show', ['server' => $server]));

        $response->assertOk()->assertDontSee('no longer exists at');
    });

    it('shows a Refresh from SpinupWP action for a server with a spinupwp_id', function () {
        $server = Server::factory()->create(['name' => 'web6.example.com', 'spinupwp_id' => 12345]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('servers.show', ['server' => $server]));

        $response->assertOk()
            ->assertSee('Refresh from SpinupWP')
            ->assertDontSee('Refresh from GridPane');
    });

    it('shows a Refresh from GridPane action, not SpinupWP, for a GridPane-provider server', function () {
        $server = Server::factory()->create([
            'name' => 'web7.example.com',
            'spinupwp_id' => null,
            'provider' => Server::PROVIDER_GRIDPANE,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('servers.show', ['server' => $server]));

        $response->assertOk()
            ->assertSee('Refresh from GridPane')
            ->assertDontSee('Refresh from SpinupWP');
    });

    it('hides the Refresh from GridPane action on a GridPane-provider server once the module is disabled', function () {
        $server = Server::factory()->create([
            'name' => 'web7b.example.com',
            'spinupwp_id' => null,
            'provider' => Server::PROVIDER_GRIDPANE,
        ]);
        InstalledModule::create(['module_id' => 'gridpane', 'name' => 'GridPane', 'enabled' => false]);
        app(ModuleStateResolver::class)->flush();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('servers.show', ['server' => $server]));

        $response->assertOk()
            ->assertDontSee('Refresh from GridPane')
            ->assertDontSee('Refresh from SpinupWP');
    });

    it('refuses the Refresh from GridPane POST action once the module is disabled', function () {
        $server = Server::factory()->create(['provider' => Server::PROVIDER_GRIDPANE]);
        InstalledModule::create(['module_id' => 'gridpane', 'name' => 'GridPane', 'enabled' => false]);
        app(ModuleStateResolver::class)->flush();

        $response = $this->actingAs(User::factory()->create())
            ->post(route('servers.refreshFromGridPane'));

        $response->assertRedirect()->assertSessionHas('status_error', 'GridPane is not enabled for this fleet.');
    });

    it('hides both refresh actions for a server managed by neither panel', function () {
        $server = Server::factory()->create([
            'name' => 'web8.example.com',
            'spinupwp_id' => null,
            'provider' => 'digitalocean',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('servers.show', ['server' => $server]));

        $response->assertOk()
            ->assertDontSee('Refresh from SpinupWP')
            ->assertDontSee('Refresh from GridPane');
    });

    it('shows Refresh from GridPane in dashboard actions when gridpane module is enabled', function () {
        Server::factory()->create();
        InstalledModule::create(['module_id' => 'gridpane', 'name' => 'GridPane', 'enabled' => true]);
        app(ModuleStateResolver::class)->flush();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Refresh from GridPane')
            ->assertDontSee('&amp;amp;');
    });

    it('displays GridPane badge and label on server cards for GridPane servers', function () {
        $server = Server::factory()->create([
            'name' => 'gp-node1.example.com',
            'spinupwp_id' => null,
            'provider' => Server::PROVIDER_GRIDPANE,
            'provider_id' => '101',
        ]);

        expect($server->provider_label)->toBe('GridPane server');
        expect($server->isGridPane())->toBeTrue();
        expect($server->isSpinupWp())->toBeFalse();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('GridPane server')
            ->assertSee('GridPane server #101');
    });

    it('shows Sync sites from GridPane on the server sites tab for GridPane servers', function () {
        InstalledModule::create(['module_id' => 'gridpane', 'name' => 'GridPane', 'enabled' => true]);
        app(ModuleStateResolver::class)->flush();

        $server = Server::factory()->create([
            'name' => 'gp-node2.example.com',
            'spinupwp_id' => null,
            'provider' => Server::PROVIDER_GRIDPANE,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('servers.show', ['server' => $server, 'tab' => 'sites']));

        $response->assertOk()
            ->assertSee('Sync sites from GridPane');
    });

    it('wires the app shell so layout and theme init both run', function () {
        Server::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('x-data="appChrome()"', false);
    });

    it('cloaks both fleet view panes until Alpine boots', function () {
        Server::factory()->create();

        $html = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        expect($html)
            ->toContain('x-show="viewMode === \'cards\'" x-cloak')
            ->toContain('x-show="viewMode === \'table\'" x-cloak')
            ->toContain('querySelectorAll(\'[data-server-id]\')');
    });

    it('falls back to the latest snapshot when a server has no metrics in the last 24h', function () {
        $stale = Server::factory()->create(['name' => 'stale-node.example.com']);
        $fresh = Server::factory()->create(['name' => 'fresh-node.example.com']);
        Site::factory()->spinupwp()->create(['server_id' => $stale->id, 'domain' => 'stale-site.example.com']);
        Site::factory()->spinupwp()->create(['server_id' => $fresh->id, 'domain' => 'fresh-site.example.com']);

        ServerMetric::factory()->create([
            'server_id' => $stale->id,
            'recorded_at' => now()->subDays(5),
            'cpu_pct' => 37.0,
            'memory_pct' => 41.0,
            'disk_pct' => 19.0,
        ]);
        ServerMetric::factory()->create([
            'server_id' => $fresh->id,
            'recorded_at' => now()->subHour(),
            'cpu_pct' => 12.0,
            'memory_pct' => 22.0,
            'disk_pct' => 8.0,
        ]);

        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('37%')
            ->assertSee('12%')
            ->assertSee('Last sample')
            ->assertSee('5 days ago');
    });
});
