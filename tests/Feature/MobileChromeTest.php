<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RendersAuthenticatedPages;
use Tests\TestCase;

class MobileChromeTest extends TestCase
{
    use RefreshDatabase;
    use RendersAuthenticatedPages;

    public function test_app_css_does_not_force_the_jump_button_visible_on_phones(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $this->assertDoesNotMatchRegularExpression(
            '/\.cw-top-jump-btn\s*\{\s*display:\s*inline-flex;/',
            $css,
            'Unscoped .cw-top-jump-btn { display: inline-flex } overrides Tailwind hidden on phones.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.cw-font-stepper\s*\{\s*display:\s*inline-flex;/',
            $css,
            'Unscoped .cw-font-stepper { display: inline-flex } overrides Tailwind hidden on phones.',
        );
        $this->assertStringContainsString('body.cw-mobile-nav-open', $css);
        $this->assertStringContainsString('.cw-mobile-drawer', $css);
    }

    public function test_dashboard_renders_the_mobile_sheet_as_the_phone_nav(): void
    {
        Server::create([
            'name' => 'test.example.com',
            'hostname' => '203.0.113.10',
            'ssh_user' => 'clockwork-deploy',
            'provider' => Server::PROVIDER_DIGITALOCEAN,
        ]);
        $this->mockIssueCounterZero();

        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('id="cw-mobile-drawer"', false);
        $response->assertSee('aria-label="Navigation"', false);
        $response->assertSee('cw-mobile-nav-link', false);
        $response->assertSee('closeMobileNav()', false);
        $response->assertSee('cw-subnav-tier hidden md:block', false);
        $response->assertSee('cw-layout-switcher-btn hidden lg:inline-flex', false);
    }
}
