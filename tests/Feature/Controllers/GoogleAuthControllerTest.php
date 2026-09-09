<?php

use App\Models\User;
use Illuminate\Support\Facades\Redirect;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('redirect (GET /auth/google/redirect)', function () {
    it('builds the Google driver with a redirectUrl anchored to this request and redirects to Google', function () {
        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('redirectUrl')->once()->with(route('auth.google.callback'))->andReturnSelf();
        $driver->shouldReceive('redirect')->once()->andReturn(Redirect::away('https://accounts.google.com/o/oauth2/auth?mock=1'));

        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);

        $response = $this->get(route('auth.google.redirect'));

        $response->assertRedirect('https://accounts.google.com/o/oauth2/auth?mock=1');
    });
});

describe('callback (GET /auth/google/callback)', function () {
    it('logs in a user with a valid, allowlisted Google email', function () {
        $this->mockIssueCounterZero();

        $allowedUser = User::factory()->create(['email' => 'operator@clockworkwd.com', 'name' => 'Old Name']);

        $googleUser = SocialiteUser::fake([
            'id' => 'google-123',
            'name' => 'Test User',
            'email' => 'OPERATOR@clockworkwd.com', // mixed case — controller lowercases before matching
            'avatar' => 'https://example.com/avatar.jpg',
        ]);

        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('redirectUrl')->once()->with(route('auth.google.callback'))->andReturnSelf();
        $driver->shouldReceive('user')->once()->andReturn($googleUser);

        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($allowedUser->fresh());

        $allowedUser->refresh();
        expect($allowedUser->email)->toBe('operator@clockworkwd.com');
        expect($allowedUser->name)->toBe('Test User');
        expect($allowedUser->google_id)->toBe('google-123');
        expect($allowedUser->avatar_url)->toBe('https://example.com/avatar.jpg');
        expect($allowedUser->last_login_at)->not->toBeNull();
    });

    it('rejects a valid Google email that has no matching allowlist row', function () {
        $googleUser = SocialiteUser::fake([
            'id' => 'google-999',
            'name' => 'Stranger',
            'email' => 'stranger@gmail.com',
        ]);

        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('redirectUrl')->once()->with(route('auth.google.callback'))->andReturnSelf();
        $driver->shouldReceive('user')->once()->andReturn($googleUser);

        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'))
            ->assertSessionHas('login_denial', "stranger@gmail.com isn't on the Clockwork team. Ask an administrator to add you.");

        $this->assertGuest();
        expect(User::where('email', 'stranger@gmail.com')->exists())->toBeFalse();
    });

    it('rejects a revoked user even though the email row exists', function () {
        $revokedUser = User::factory()->create([
            'email' => 'revoked@clockworkwd.com',
            'revoked_at' => now()->subDay(),
        ]);

        $googleUser = SocialiteUser::fake([
            'id' => 'google-777',
            'email' => 'revoked@clockworkwd.com',
        ]);

        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('redirectUrl')->once()->with(route('auth.google.callback'))->andReturnSelf();
        $driver->shouldReceive('user')->once()->andReturn($googleUser);

        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'))
            ->assertSessionHas('login_denial', 'Access for revoked@clockworkwd.com has been revoked.');

        $this->assertGuest();
        expect($revokedUser->fresh()->google_id)->toBeNull();
    });

    it('redirects to login with a denial when Socialite throws', function () {
        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('redirectUrl')->once()->with(route('auth.google.callback'))->andReturnSelf();
        $driver->shouldReceive('user')->once()->andThrow(new Exception('invalid_grant'));

        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'))
            ->assertSessionHas('login_denial', 'Google sign-in failed. Try again, and let an administrator know if it keeps happening.');

        $this->assertGuest();
    });

    it('redirects to login when Google returns no email', function () {
        $googleUser = SocialiteUser::fake(['email' => '']);

        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('redirectUrl')->once()->with(route('auth.google.callback'))->andReturnSelf();
        $driver->shouldReceive('user')->once()->andReturn($googleUser);

        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'))
            ->assertSessionHas('login_denial', "Google didn't return an email address. Make sure you're signed into a Google account.");

        $this->assertGuest();
    });

    it('sends hd on redirect when a hosted domain is configured', function () {
        config(['services.google.hosted_domain' => 'clockworkwd.com']);

        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('redirectUrl')->once()->with(route('auth.google.callback'))->andReturnSelf();
        $driver->shouldReceive('with')->once()->with(['hd' => 'clockworkwd.com'])->andReturnSelf();
        $driver->shouldReceive('redirect')->once()->andReturn(Redirect::away('https://accounts.google.com/o/oauth2/auth?hd=clockworkwd.com'));

        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);

        $this->get(route('auth.google.redirect'))
            ->assertRedirect('https://accounts.google.com/o/oauth2/auth?hd=clockworkwd.com');
    });

    it('rejects a callback whose hd claim does not match GOOGLE_HD', function () {
        config(['services.google.hosted_domain' => 'clockworkwd.com']);
        User::factory()->create(['email' => 'operator@clockworkwd.com']);

        $googleUser = SocialiteUser::fake([
            'id' => 'google-hd-mismatch',
            'email' => 'operator@clockworkwd.com',
            'hd' => 'gmail.com',
        ]);

        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('redirectUrl')->once()->with(route('auth.google.callback'))->andReturnSelf();
        $driver->shouldReceive('user')->once()->andReturn($googleUser);

        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'))
            ->assertSessionHas('login_denial', 'Google sign-in is restricted to the clockworkwd.com workspace. Use a matching account, or ask an administrator to add you.');

        $this->assertGuest();
    });
});
