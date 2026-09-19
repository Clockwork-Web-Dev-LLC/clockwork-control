<?php

use App\Models\User;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('issues page renders wordpress-style screen options drawer and jump dropdown', function () {
    $response = $this->actingAs($this->user)->get(route('issues.index'));

    $response->assertOk();

    // Verify Screen Options drawer and button
    $response->assertSee('Screen Options');
    $response->assertSee('Screen Options: Elements on this page');
    $response->assertSee('Critical Only');
    $response->assertSee('Hide Routine');
    $response->assertSee('Show All');
    $response->assertSee('Fleet Priorities');

    // Verify 3 columns in the drawer
    $response->assertSee('Critical &amp; Security', false);
    $response->assertSee('Infrastructure &amp; Health', false);
    $response->assertSee('Routine Maintenance', false);

    // Verify unified toolbar
    $response->assertSee('All Issues');
    $response->assertSee('All Active');
    $response->assertSee('Emergency');
    $response->assertSee('Pressing');
});

test('jump dropdown appears when active issues exist', function () {
    $server = Server::factory()->create(['name' => 'Test Server']);
    Site::factory()->create([
        'server_id' => $server->id,
        'domain_expires_at' => now()->addDays(5),
    ]);

    $response = $this->actingAs($this->user)->get(route('issues.index'));

    $response->assertOk();
    $response->assertSee('Jump to Issue');
    $response->assertSee('Active Categories');
});

test('issue category sections do not clip dropdown menus with overflow-hidden', function () {
    $server = Server::factory()->create(['name' => 'Test Server']);
    Site::factory()->create([
        'server_id' => $server->id,
        'domain_expires_at' => now()->addDays(5),
    ]);

    $response = $this->actingAs($this->user)->get(route('issues.index'));

    $response->assertOk();
    // Verify that sections don't have card overflow-hidden mb-6 which clips priority dropdowns
    $response->assertDontSee('class="card overflow-hidden mb-6"', false);
    $response->assertSee('class="card mb-6"', false);
});
