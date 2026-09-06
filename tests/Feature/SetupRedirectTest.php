<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RendersAuthenticatedPages;
use Tests\TestCase;

/**
 * Phase 8: RedirectToSetupIfFreshInstall is applied only to the dashboard
 * route, gated on Server::count() === 0 && Site::count() === 0 — see the
 * middleware's own docblock for why that condition (not "zero credentials
 * configured") is the safe one. Verified here against the test database,
 * not live production data — an earlier attempt to verify this by deleting
 * and rolling back real production rows was the wrong approach (the bulk
 * delete across every related table was too slow against real data and had
 * to be killed mid-transaction; no data was harmed, since nothing had
 * committed, but this test is the correct way to cover this behavior).
 */
class SetupRedirectTest extends TestCase
{
    use RefreshDatabase;
    use RendersAuthenticatedPages;

    protected function setUp(): void
    {
        parent::setUp();

        // Every test here hits an authenticated page, so every test needs this.
        $this->mockIssueCounterZero();
    }

    public function test_dashboard_redirects_to_setup_when_fleet_is_empty(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('setup.index'));
    }

    public function test_dashboard_does_not_redirect_once_a_server_exists(): void
    {
        $user = User::factory()->create();
        Server::create([
            'name' => 'test.example.com',
            'hostname' => '203.0.113.10',
            'ssh_user' => 'clockwork-deploy',
            'provider' => Server::PROVIDER_DIGITALOCEAN,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
    }

    public function test_dashboard_does_not_redirect_once_a_site_exists(): void
    {
        $user = User::factory()->create();
        Site::create([
            'domain' => 'test.example.com',
            'server_id' => null,
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'pressable_site_id' => '12345',
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
    }

    public function test_setup_page_itself_never_redirects(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('setup.index'));

        $response->assertOk();
    }
}
