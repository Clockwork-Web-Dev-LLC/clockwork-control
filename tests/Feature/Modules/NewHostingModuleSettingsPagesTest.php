<?php

use App\Models\User;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/**
 * Live-renders the two settings pages every module wires into
 * (IntegrationCredentialsController, DiagnosticsController) with the real
 * WP Engine/Kinsta/Cloudways modules registered, rather than trusting the
 * ModuleRegistry aggregation counts alone — confirms the manifests actually
 * reach the views without a 500, and that each new provider's label
 * appears on the page a user would look at to configure it.
 */
it('renders /settings/integrations with the 3 new providers listed', function () {
    $this->mockIssueCounterZero();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.integrations.index'));

    $response->assertOk()
        ->assertSee('WP Engine')
        ->assertSee('Kinsta')
        ->assertSee('Cloudways')
        ->assertSee('Vultr')
        ->assertSee('Linode (Akamai)');
});

it('renders /settings/diagnostics with the 3 new modules\' checks listed', function () {
    $this->mockIssueCounterZero();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.diagnostics.index'));

    $response->assertOk()
        ->assertSee('WP Engine')
        ->assertSee('Kinsta')
        ->assertSee('Cloudways')
        ->assertSee('Vultr')
        ->assertSee('Linode');
});
