<?php

use App\Models\User;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

test('guests are redirected to login from /settings', function () {
    $this->get(route('settings.index'))
        ->assertRedirect(route('login'));
});

test('authenticated operators can access /settings hub with all categories', function () {
    $this->mockIssueCounterZero();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('settings.index'));

    $response->assertOk()
        ->assertSee('Settings &amp; Operations', false)
        ->assertSee('Agency Branding', false)
        ->assertSee('Fleet Policies', false)
        ->assertSee('Integrations &amp; Alerts', false)
        ->assertSee('Operations &amp; Tools', false)
        ->assertSee('System &amp; Workspace', false)
        // Check key destinations are present
        ->assertSee(route('settings.companion.index'))
        ->assertSee(route('settings.tags.index'))
        ->assertSee(route('settings.wordpress-plugins.index'))
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
        ->assertSee('Agency Branding', false)
        ->assertSee('Fleet Policies', false)
        ->assertSee('Integrations &amp; Alerts', false)
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

test('settings pages render persistent navigation', function () {
    $this->mockIssueCounterZero();
    $user = User::factory()->create();

    // 1. Agency Branding (White Labeling)
    $response = $this->actingAs($user)->get(route('settings.companion.index'));
    $response->assertOk()
        ->assertSee('Agency Branding', false)
        ->assertSee('White Label &amp; Styling Hub', false);

    // 2. Fleet Policies (WordPress Plugins)
    $response = $this->actingAs($user)->get(route('settings.wordpress-plugins.index'));
    $response->assertOk()
        ->assertSee('Fleet Policies', false)
        ->assertSee('WordPress Plugins')
        ->assertSee('Scheduling &amp; Ingest', false)
        ->assertSee('Security Scans')
        ->assertSee('Backup Relay');

    // 3. Integrations & Alerts
    $response = $this->actingAs($user)->get(route('settings.integrations.index'));
    $response->assertOk()
        ->assertSee('Integrations &amp; Alerts', false)
        ->assertSee('API Credentials')
        ->assertSee('Module Directory');

    // 4. System & Workspace (Users)
    $response = $this->actingAs($user)->get(route('settings.users.index'));
    $response->assertOk()
        ->assertSee('System &amp; Workspace', false)
        ->assertSee('Team &amp; Users', false)
        ->assertSee('Database Maintenance');

    // 5. System Updates
    $response = $this->actingAs($user)->get(route('settings.updates.index'));
    $response->assertOk()
        ->assertSee('System &amp; Workspace', false)
        ->assertSee('System Updates')
        ->assertSee('Database Maintenance');

    // 6. Diagnostics
    $response = $this->actingAs($user)->get(route('settings.diagnostics.index'));
    $response->assertOk()
        ->assertSee('System &amp; Workspace', false)
        ->assertSee('Diagnostics &amp; Health', false);
});
