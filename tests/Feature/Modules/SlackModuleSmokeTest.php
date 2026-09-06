<?php

use App\Models\User;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/**
 * Live-renders the pages that prove the Slack module actually reached the
 * app end to end after its move to modules/Slack/ — mirrors
 * MattermostModuleSmokeTest.php.
 */
it('renders /settings/slack via the module-owned route', function () {
    $this->mockIssueCounterZero();
    config(['clockwork.slack.enabled' => true]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.slack.index'));

    $response->assertOk()
        ->assertSee('Slack');
});

it('renders /settings/diagnostics with the Slack check listed exactly once', function () {
    $this->mockIssueCounterZero();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.diagnostics.index'));

    $response->assertOk();
    $content = $response->getContent();
    expect(substr_count($content, 'Slack webhook'))->toBe(1);
});
