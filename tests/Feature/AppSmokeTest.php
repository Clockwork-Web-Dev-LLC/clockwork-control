<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RendersAuthenticatedPages;
use Tests\TestCase;

/**
 * Boot-level smoke tests: migrations run on sqlite, the app boots (the
 * Settings service reads app_settings during every request), the auth
 * gate holds, and the dashboard renders for an allowlisted user.
 */
class AppSmokeTest extends TestCase
{
    use RefreshDatabase;
    use RendersAuthenticatedPages;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_authenticated_user_sees_the_dashboard(): void
    {
        // A server must exist or RedirectToSetupIfFreshInstall (Phase 8)
        // sends an empty-fleet instance to /setup instead — correct behavior
        // for a fresh install, but this test is specifically about a real,
        // in-use instance's dashboard.
        Server::create([
            'name' => 'test.example.com',
            'hostname' => '203.0.113.10',
            'ssh_user' => 'clockwork-deploy',
            'provider' => Server::PROVIDER_DIGITALOCEAN,
        ]);

        $this->mockIssueCounterZero();

        $this->actingAs(User::factory()->create());

        $this->get('/')->assertOk();
    }
}
