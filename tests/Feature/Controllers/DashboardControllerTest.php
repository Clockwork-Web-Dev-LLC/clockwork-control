<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
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
});
