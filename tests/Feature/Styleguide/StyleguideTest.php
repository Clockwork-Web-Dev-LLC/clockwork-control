<?php

use App\Models\User;

describe('Styleguide page (Existing Design System)', function () {
    it('requires authentication to view the styleguide', function () {
        $response = $this->get(route('styleguide.index'));
        $response->assertRedirect(route('login'));
    });

    it('renders the design system styleguide for authenticated users at /styleguide', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('styleguide.index'));

        $response->assertOk()
            ->assertSee('Design System & Styleguide')
            ->assertSee('Control Center Design Language')
            ->assertSee('Color Palette & Surface Tokens')
            ->assertSee('Typography System')
            ->assertSee('Buttons & Action Elements')
            ->assertSee('Status Pills & Micro-Indicators')
            ->assertSee('High-Density Data Tables');
    });
});
