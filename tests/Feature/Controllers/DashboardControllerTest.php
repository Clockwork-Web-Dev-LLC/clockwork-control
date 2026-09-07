<?php

use App\Models\Server;
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
});
