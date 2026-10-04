<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Modules\ClientManagement\Database\Factories\ClientFactory;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

// Both pages used to @include a non-existent `client-reports::_tabs` partial
// and 500'd on every request.
describe('Client pages', function () {
    beforeEach(function () {
        $this->mockIssueCounterZero();
        $server = Server::factory()->create();
        Site::factory()->spinupwp()->create(['server_id' => $server->id]);
    });

    it('renders the clients index', function () {
        $client = ClientFactory::new()->create(['name' => 'Aslan Example']);

        $this->actingAs(User::factory()->create())
            ->get(route('clients.index'))
            ->assertOk()
            ->assertSee('Aslan Example');
    });

    it('renders a client detail page', function () {
        $client = ClientFactory::new()->create(['name' => 'Tumnus Example']);

        $this->actingAs(User::factory()->create())
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee('Tumnus Example');
    });
});
