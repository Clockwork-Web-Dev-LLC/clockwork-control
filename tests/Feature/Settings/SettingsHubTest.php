<?php

use App\Models\User;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

test('guests are redirected to login from /settings', function () {
    $this->get(route('settings.index'))
        ->assertRedirect(route('login'));
});

test('authenticated operators can access /settings hub with all 4 categories', function () {
    $this->mockIssueCounterZero();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('settings.index'));

    $response->assertOk()
        ->assertSee('Settings &amp; Operations', false)
        ->assertSee('Fleet &amp; Branding', false)
        ->assertSee('Integrations &amp; Alerts', false)
        ->assertSee('Operations &amp; Tools', false)
        ->assertSee('System &amp; Workspace', false)
        // Check key destinations are present
        ->assertSee(route('settings.companion.index'))
        ->assertSee(route('settings.tags.index'))
        ->assertSee(route('settings.integrations.index'))
        ->assertSee(route('settings.modules.index'))
        ->assertSee(route('capacity.index'))
        ->assertSee(route('operations.server-updates.index'))
        ->assertSee(route('settings.users.index'))
        ->assertSee(route('settings.updates.index'));
});

test('settings tabs partial renders correctly', function () {
    $this->mockIssueCounterZero();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('settings.index'));

    $response->assertOk()
        ->assertSee('Overview')
        ->assertSee('Fleet &amp; Branding', false)
        ->assertSee('Integrations &amp; Alerts', false)
        ->assertSee('Operations &amp; Tools', false)
        ->assertSee('System &amp; Workspace', false);
});

test('module directory renders with search input in header and tabs', function () {
    $this->mockIssueCounterZero();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('settings.modules.index'));

    $response->assertOk()
        ->assertSee('Module directory')
        ->assertSee('Search modules, tags, author...')
        ->assertSee('Integrations &amp; Alerts', false);
});
