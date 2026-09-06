<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('redirect (GET /auth/microsoft/redirect)', function () {
    it('redirects to Microsoft login endpoint and sets state in session', function () {
        $response = $this->get(route('auth.microsoft.redirect'));

        $response->assertRedirect();
        $targetUrl = $response->headers->get('Location');
        expect($targetUrl)->toContain('https://login.microsoftonline.com/common/oauth2/v2.0/authorize');
        expect($targetUrl)->toContain('response_type=code');
        expect(session('oauth_microsoft_state'))->not->toBeNull();
    });
});

describe('callback (GET /auth/microsoft/callback)', function () {
    it('rejects callback when state mismatches or is missing', function () {
        $response = $this->withSession(['oauth_microsoft_state' => 'expected-state'])
            ->get(route('auth.microsoft.callback', ['state' => 'wrong-state', 'code' => 'auth-code']));

        $response->assertRedirect(route('login'))
            ->assertSessionHas('login_denial', 'Microsoft sign-in state mismatch. Please try again.');
        $this->assertGuest();
    });

    it('rejects callback when code is missing or error is returned', function () {
        $response = $this->withSession(['oauth_microsoft_state' => 'test-state'])
            ->get(route('auth.microsoft.callback', ['state' => 'test-state', 'error_description' => 'User canceled']));

        $response->assertRedirect(route('login'))
            ->assertSessionHas('login_denial', 'Microsoft sign-in failed: User canceled');
        $this->assertGuest();
    });

    it('logs in a user with a valid allowlisted Microsoft account', function () {
        $this->mockIssueCounterZero();

        $allowedUser = User::factory()->create(['email' => 'operator@clockworkwd.com', 'name' => 'Old Name']);

        Http::fake([
            'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'fake-ms-token',
                'token_type' => 'Bearer',
            ]),
            'https://graph.microsoft.com/v1.0/me' => Http::response([
                'id' => 'ms-user-999',
                'displayName' => 'Test User',
                'mail' => 'OPERATOR@clockworkwd.com',
                'userPrincipalName' => 'operator@clockworkwd.com',
            ]),
        ]);

        $response = $this->withSession(['oauth_microsoft_state' => 'valid-state'])
            ->get(route('auth.microsoft.callback', ['state' => 'valid-state', 'code' => 'sample-auth-code']));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($allowedUser->fresh());

        $allowedUser->refresh();
        expect($allowedUser->email)->toBe('operator@clockworkwd.com');
        expect($allowedUser->name)->toBe('Test User');
        expect($allowedUser->microsoft_id)->toBe('ms-user-999');
        expect($allowedUser->last_login_at)->not->toBeNull();
    });

    it('rejects a valid Microsoft email that has no matching allowlist row', function () {
        Http::fake([
            'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'fake-ms-token',
            ]),
            'https://graph.microsoft.com/v1.0/me' => Http::response([
                'id' => 'ms-stranger',
                'displayName' => 'Unknown Person',
                'mail' => 'unknown@example.com',
            ]),
        ]);

        $response = $this->withSession(['oauth_microsoft_state' => 'valid-state'])
            ->get(route('auth.microsoft.callback', ['state' => 'valid-state', 'code' => 'sample-code']));

        $response->assertRedirect(route('login'))
            ->assertSessionHas('login_denial', "unknown@example.com isn't on the Clockwork team. Ask an administrator to add you.");
        $this->assertGuest();
    });

    it('rejects a revoked user even though the email row exists', function () {
        $revokedUser = User::factory()->create([
            'email' => 'revoked@clockworkwd.com',
            'revoked_at' => now()->subDay(),
        ]);

        Http::fake([
            'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'fake-ms-token',
            ]),
            'https://graph.microsoft.com/v1.0/me' => Http::response([
                'id' => 'ms-revoked',
                'displayName' => 'Revoked User',
                'mail' => 'revoked@clockworkwd.com',
            ]),
        ]);

        $response = $this->withSession(['oauth_microsoft_state' => 'valid-state'])
            ->get(route('auth.microsoft.callback', ['state' => 'valid-state', 'code' => 'sample-code']));

        $response->assertRedirect(route('login'))
            ->assertSessionHas('login_denial', 'Access for revoked@clockworkwd.com has been revoked.');
        $this->assertGuest();
        expect($revokedUser->fresh()->microsoft_id)->toBeNull();
    });

    it('redirects to login when Microsoft token exchange fails', function () {
        Http::fake([
            'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(['error' => 'invalid_client'], 400),
        ]);

        $response = $this->withSession(['oauth_microsoft_state' => 'valid-state'])
            ->get(route('auth.microsoft.callback', ['state' => 'valid-state', 'code' => 'sample-code']));

        $response->assertRedirect(route('login'))
            ->assertSessionHas('login_denial', 'Microsoft token exchange failed. Please verify credentials.');
        $this->assertGuest();
    });

    it('redirects to login when Microsoft profile returns no email', function () {
        Http::fake([
            'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'fake-ms-token',
            ]),
            'https://graph.microsoft.com/v1.0/me' => Http::response([
                'id' => 'ms-noemail',
                'displayName' => 'No Email User',
                'mail' => null,
                'userPrincipalName' => null,
            ]),
        ]);

        $response = $this->withSession(['oauth_microsoft_state' => 'valid-state'])
            ->get(route('auth.microsoft.callback', ['state' => 'valid-state', 'code' => 'sample-code']));

        $response->assertRedirect(route('login'))
            ->assertSessionHas('login_denial', "Microsoft didn't return an email address. Make sure you're signed into a Microsoft account.");
        $this->assertGuest();
    });
});
