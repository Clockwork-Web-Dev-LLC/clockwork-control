<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RefreshDatabase::class, RendersAuthenticatedPages::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->mockIssueCounterZero();
});

test('monitoring page renders wordpress-style screen options button and drawer', function () {
    $server = Server::factory()->create();
    Site::factory()->spinupwp()->create([
        'server_id' => $server->id,
        'domain' => 'monitored-screen-options.example.com',
        'uptime_monitoring_enabled' => true,
        'uptime_state' => 'up',
    ]);

    $response = $this->actingAs($this->user)->get(route('monitoring.index'));

    $response->assertOk();

    // Screen Options toggle button in header actions
    $response->assertSee('Screen Options');
    $response->assertSee('title="Customize monitoring pagination and display settings"', false);

    // Screen Options drawer container and header
    $response->assertSee('Screen Options: Monitoring Display');
    $response->assertSee('Customize table pagination and display settings. Saved in your browser.');

    // Presets
    $response->assertSee('setPerPage(25)', false);
    $response->assertSee('setPerPage(50)', false);
    $response->assertSee('50 (Default)');
    $response->assertSee('setPerPage(100)', false);
    $response->assertSee('setPerPage(\'all\')', false);
    $response->assertSee('resetScreenOptions()', false);

    // Pagination form in Screen Options
    $response->assertSee('Number of sites per page:');
    $response->assertSee('id="screen-options-per-page"', false);
    $response->assertSee('x-model.number="perPageInput"', false);
    $response->assertSee('@submit.prevent="applyPerPage()"', false);

    // Display notes in drawer
    $response->assertSee('Fleet totals and 7d/30d uptime averages always reflect all monitored sites');
    $response->assertSee('Instant search matches across all sites; pagination dynamically re-indexes');
});

test('monitoring page renders table pagination bar with controls', function () {
    $server = Server::factory()->create();
    Site::factory()->spinupwp()->create([
        'server_id' => $server->id,
        'domain' => 'paginated-site.example.com',
        'uptime_monitoring_enabled' => true,
        'uptime_state' => 'up',
    ]);

    $response = $this->actingAs($this->user)->get(route('monitoring.index'));

    $response->assertOk();

    // Sites table card has ID for smooth scrolling
    $response->assertSee('id="monitoring-sites-card"', false);

    // Pagination container
    $response->assertSee('id="monitoring-pagination"', false);
    $response->assertSee('prevPage()', false);
    $response->assertSee('nextPage()', false);
    $response->assertSee('goToPage(p)', false);
    $response->assertSee('x-text="pageStart"', false);
    $response->assertSee('x-text="pageEnd"', false);
    $response->assertSee('x-text="totalSites"', false);
});
