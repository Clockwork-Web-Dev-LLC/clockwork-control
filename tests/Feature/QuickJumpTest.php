<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RendersAuthenticatedPages;
use Tests\TestCase;

class QuickJumpTest extends TestCase
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

    public function test_quick_jump_modal_renders_in_app_shell(): void
    {
        $user = $this->createServerAndUser();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('id="cw-quick-jump-modal"', false);
        $response->assertSee('x-show="paletteOpen"', false);
        $response->assertSee('x-ref="paletteInput"', false);
        $response->assertSee('x-model="paletteQuery"', false);
        $response->assertSee('@input="onPaletteInput()"', false);
        $response->assertSee('@keydown.down.prevent="paletteDown()"', false);
        $response->assertSee('@keydown.up.prevent="paletteUp()"', false);
        $response->assertSee('@keydown.enter.prevent="selectCurrent()"', false);
    }

    public function test_quick_jump_footer_does_not_contain_clockwork_control(): void
    {
        $user = $this->createServerAndUser();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Navigate with <kbd class="cmd-kbd">↑</kbd> <kbd class="cmd-kbd">↓</kbd> · Select with <kbd class="cmd-kbd">↵</kbd>', false);

        // Modal container must not have Clockwork Control in its footer
        $content = $response->getContent();
        $this->assertIsString($content);

        preg_match('/<div id="cw-quick-jump-modal".*?<div x-data="confirmModal\(\)"/s', $content, $matches);
        $modalHtml = $matches[0] ?? '';
        $this->assertNotEmpty($modalHtml, 'Quick jump modal was not found in response');
        $this->assertStringNotContainsString('Clockwork Control', $modalHtml);
    }

    public function test_quick_jump_items_include_expected_jump_codes(): void
    {
        $user = $this->createServerAndUser();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertIsString($content);

        // Verify window.cwQuickJumpItems is populated with the correct jump codes
        $this->assertStringContainsString("code: 'GS', kbd: 'G S'", $content);
        $this->assertStringContainsString("code: 'GT', kbd: 'G T'", $content);
        $this->assertStringContainsString("code: 'GI', kbd: 'G I'", $content);
        $this->assertStringContainsString("code: 'GM', kbd: 'G M'", $content);
        $this->assertStringContainsString("code: 'GU', kbd: 'G U'", $content);
        $this->assertStringContainsString("code: 'GX', kbd: 'G X'", $content);
        $this->assertStringContainsString("code: 'GD', kbd: 'G D'", $content);
    }
}
