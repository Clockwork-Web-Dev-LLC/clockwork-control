<?php

use App\Models\User;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/**
 * Live-renders the pages that prove the Mattermost module actually reached
 * the app end to end after its move to modules/Mattermost/ — not just that
 * ModuleRegistry lists it, but that a real authenticated request to its
 * settings page and to the shared diagnostics page both work.
 */
it('renders /settings/mattermost via the module-owned route', function () {
    $this->mockIssueCounterZero();
    config(['clockwork.mattermost.enabled' => true]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.mattermost.index'));

    $response->assertOk()
        ->assertSee('Mattermost');
});

it('renders /settings/diagnostics with the Mattermost check listed exactly once', function () {
    $this->mockIssueCounterZero();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.diagnostics.index'));

    $response->assertOk();
    $content = $response->getContent();
    expect(substr_count($content, 'Mattermost webhook'))->toBe(1);
});
