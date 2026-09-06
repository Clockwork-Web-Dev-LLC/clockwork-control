<?php

use App\Http\Controllers\AppearanceSettingsController;
use App\Models\User;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('AppearanceSettingsController', function () {
    it('persists theme preference to user record and cookie when authenticated', function (string $theme) {
        $user = User::factory()->create(['theme' => 'system']);

        $response = $this->actingAs($user)
            ->postJson(route('settings.appearance.update'), [
                'theme' => $theme,
            ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'theme' => $theme,
            ])
            ->assertPlainCookie(AppearanceSettingsController::COOKIE_NAME, $theme);

        expect($user->fresh()->theme)->toBe($theme);
    })->with(AppearanceSettingsController::VALID_THEMES);

    it('rejects invalid theme names with 422 for authenticated user', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('settings.appearance.update'), [
                'theme' => 'rainbow-glitter',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['theme']);
    });

    it('redirects unauthenticated appearance update requests', function () {
        $response = $this->post(route('settings.appearance.update'), [
            'theme' => 'midnight',
        ]);

        $response->assertRedirect(route('login'));
    });

    beforeEach(function () {
        $this->mockIssueCounterZero();
    });

    it('renders the configured theme in html data-theme attribute on authenticated page', function () {
        $user = User::factory()->create(['theme' => 'midnight']);

        $response = $this->actingAs($user)->get(route('settings.tags.index'));

        $response->assertOk()
            ->assertSee('data-theme="midnight"', false);
    });

    it('prefers valid cookie over user default if cookie is present', function () {
        $user = User::factory()->create(['theme' => 'light']);

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('cw_theme', 'dark')
            ->get(route('settings.tags.index'));

        $response->assertOk()
            ->assertSee('data-theme="dark"', false);
    });

    it('renders data-theme from cookie on guest page', function () {
        $response = $this->withUnencryptedCookie('cw_theme', 'midnight')
            ->get(route('login'));

        $response->assertOk()
            ->assertSee('data-theme="midnight"', false);
    });

    it('defaults to light when neither cookie nor user theme exists', function () {
        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertSee('data-theme="light"', false);
    });
});
