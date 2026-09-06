<?php

use App\Models\User;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('show (GET /login)', function () {
    it('renders the login page for a guest with active auth providers', function () {
        // LoginController only lists providers whose isConfigured() is true —
        // a registered-but-uncredentialed module (the real default state)
        // would otherwise render a button that fails when clicked.
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.github.client_id' => 'test-client-id',
            'services.github.client_secret' => 'test-client-secret',
        ]);
        config([
            'services.microsoft.client_id' => 'test-client-id',
            'services.microsoft.client_secret' => 'test-client-secret',
        ]);

        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertSee('Sign in with Google')
            ->assertSee('Sign in with GitHub')
            ->assertSee('Sign in with Microsoft');
    });

    it('hides a provider from the login page when it has no credentials configured', function () {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);
        config(['services.github.client_id' => null, 'services.github.client_secret' => null]);

        $response = $this->get(route('login'));

        $response->assertOk()->assertDontSee('Sign in with Google')->assertDontSee('Sign in with GitHub');
    });

    it('redirects an already-authenticated user to the dashboard', function () {
        $this->mockIssueCounterZero();

        $response = $this->actingAs(User::factory()->create())->get(route('login'));

        $response->assertRedirect(route('dashboard'));
    });

    it('surfaces a login_denial flash message on the page', function () {
        $response = $this->withSession(['login_denial' => 'nope@example.com is not on the team.'])
            ->get(route('login'));

        $response->assertOk()->assertSee('nope@example.com is not on the team.');
    });
});

describe('logout (POST /logout)', function () {
    it('redirects unauthenticated requests away', function () {
        $response = $this->post(route('logout'));

        $response->assertRedirect(route('login'));
    });

    it('logs the user out and invalidates the session', function () {
        $this->mockIssueCounterZero();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('login'))
            ->assertSessionHas('login_status', 'You have been signed out.');

        $this->assertGuest();

        // A subsequent authenticated-only request should now be redirected to login.
        $followUp = $this->get(route('dashboard'));
        $followUp->assertRedirect(route('login'));
    });
});
