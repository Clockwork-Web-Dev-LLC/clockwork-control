<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RendersAuthenticatedPages;
use Tests\TestCase;

class LayoutSwitcherTest extends TestCase
{
    use RefreshDatabase;
    use RendersAuthenticatedPages;

    private function createServerAndUser(): User
    {
        Server::create([
            'name' => 'test.example.com',
            'hostname' => '203.0.113.10',
            'ssh_user' => 'clockwork-deploy',
            'provider' => Server::PROVIDER_DIGITALOCEAN,
        ]);

        $this->mockIssueCounterZero();

        return User::factory()->create();
    }

    public function test_dashboard_renders_layout_switcher_and_cute_toast_pill(): void
    {
        $user = $this->createServerAndUser();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        // Verifies the cute floating micro-toast pill exists
        $response->assertSee('cw-layout-toast', false);
        $response->assertSee('toastVisible', false);
        // Verifies the layout switcher button exists with the animation bindings
        $response->assertSee('cw-layout-switcher-btn', false);
        $response->assertSee('toggleLayoutStyle()', false);
        $response->assertSee('cw-jelly-squish', false);
        $response->assertSee('cw-icon-spin', false);
    }

    public function test_layout_renders_with_command_center_when_cookie_set(): void
    {
        $user = $this->createServerAndUser();

        $response = $this->actingAs($user)
            ->withCookie('cw_layout_style', 'command-center')
            ->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('cw-sidebar-rail', false);
        $response->assertSee('cw-subnav-tier', false);
    }

    public function test_mobile_chrome_uses_a_sheet_and_hides_the_studio_tabs_below_md(): void
    {
        $user = $this->createServerAndUser();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('id="cw-mobile-drawer"', false);
        $response->assertSee('cw-mobile-drawer', false);
        $response->assertSee('closeMobileNav()', false);
        $response->assertSee('cw-subnav-tier hidden md:block', false);
        $response->assertSee('cw-top-jump-btn hidden md:inline-flex', false);
        $response->assertSee('cw-layout-switcher-btn hidden lg:inline-flex', false);
        $response->assertSee('Toggle navigation drawer');
        $response->assertSee('>Menu<', false);
    }
}
