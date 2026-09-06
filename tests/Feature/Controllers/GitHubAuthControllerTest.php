<?php

use App\Models\User;
use Illuminate\Support\Facades\Redirect;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('redirect (GET /auth/github/redirect)', function () {
    it('builds the GitHub driver with a redirectUrl anchored to this request and redirects to GitHub', function () {
        $driver = Mockery::mock(GithubProvider::class);
        $driver->shouldReceive('scopes')->once()->with(['read:user', 'user:email'])->andReturnSelf();
        $driver->shouldReceive('redirectUrl')->once()->with(route('auth.github.callback'))->andReturnSelf();
        $driver->shouldReceive('redirect')->once()->andReturn(Redirect::away('https://github.com/login/oauth/authorize?mock=1'));

        Socialite::shouldReceive('driver')->once()->with('github')->andReturn($driver);

        $response = $this->get(route('auth.github.redirect'));

        $response->assertRedirect('https://github.com/login/oauth/authorize?mock=1');
    });
});

describe('callback (GET /auth/github/callback)', function () {
    it('logs in a user with a valid, allowlisted GitHub email', function () {
        $this->mockIssueCounterZero();

        $allowedUser = User::factory()->create(['email' => 'operator@clockworkwd.com', 'name' => 'Old Name']);

        $githubUser = SocialiteUser::fake([
            'id' => 'gh-12345',
            'name' => 'Test User',
            'email' => 'OPERATOR@clockworkwd.com',
            'avatar' => 'https://avatars.githubusercontent.com/u/12345',
        ]);

        $driver = Mockery::mock(GithubProvider::class);
        $driver->shouldReceive('redirectUrl')->once()->with(route('auth.github.callback'))->andReturnSelf();
        $driver->shouldReceive('user')->once()->andReturn($githubUser);

        Socialite::shouldReceive('driver')->once()->with('github')->andReturn($driver);

        $response = $this->get(route('auth.github.callback'));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($allowedUser->fresh());

        $allowedUser->refresh();
        expect($allowedUser->email)->toBe('operator@clockworkwd.com');
        expect($allowedUser->name)->toBe('Test User');
        expect($allowedUser->github_id)->toBe('gh-12345');
        expect($allowedUser->avatar_url)->toBe('https://avatars.githubusercontent.com/u/12345');
        expect($allowedUser->last_login_at)->not->toBeNull();
    });

    it('rejects a valid GitHub email that has no matching allowlist row', function () {
        $githubUser = SocialiteUser::fake([
            'id' => 'gh-99999',
            'name' => 'Intruder',
            'email' => 'stranger@example.com',
        ]);

        $driver = Mockery::mock(GithubProvider::class);
        $driver->shouldReceive('redirectUrl')->once()->with(route('auth.github.callback'))->andReturnSelf();
        $driver->shouldReceive('user')->once()->andReturn($githubUser);

        Socialite::shouldReceive('driver')->once()->with('github')->andReturn($driver);

        $response = $this->get(route('auth.github.callback'));

        $response->assertRedirect(route('login'))
            ->assertSessionHas('login_denial', "stranger@example.com isn't on the Clockwork team. Ask an administrator to add you.");

        $this->assertGuest();
        expect(User::where('email', 'stranger@example.com')->exists())->toBeFalse();
    });

    it('rejects a revoked user even though the email row exists', function () {
        $revokedUser = User::factory()->create([
            'email' => 'revoked@clockworkwd.com',
            'revoked_at' => now()->subDay(),
        ]);

        $githubUser = SocialiteUser::fake([
            'id' => 'gh-777',
            'email' => 'revoked@clockworkwd.com',
        ]);

        $driver = Mockery::mock(GithubProvider::class);
        $driver->shouldReceive('redirectUrl')->once()->with(route('auth.github.callback'))->andReturnSelf();
        $driver->shouldReceive('user')->once()->andReturn($githubUser);

        Socialite::shouldReceive('driver')->once()->with('github')->andReturn($driver);

        $response = $this->get(route('auth.github.callback'));

        $response->assertRedirect(route('login'))
            ->assertSessionHas('login_denial', 'Access for revoked@clockworkwd.com has been revoked.');

        $this->assertGuest();
        expect($revokedUser->fresh()->github_id)->toBeNull();
    });

    it('redirects to login with a denial when Socialite throws', function () {
        $driver = Mockery::mock(GithubProvider::class);
        $driver->shouldReceive('redirectUrl')->once()->with(route('auth.github.callback'))->andReturnSelf();
        $driver->shouldReceive('user')->once()->andThrow(new Exception('invalid_token'));

        Socialite::shouldReceive('driver')->once()->with('github')->andReturn($driver);

        $response = $this->get(route('auth.github.callback'));

        $response->assertRedirect(route('login'))
            ->assertSessionHas('login_denial', 'GitHub sign-in failed. Try again, and let an administrator know if it keeps happening.');

        $this->assertGuest();
    });

    it('redirects to login when GitHub returns no email', function () {
        $githubUser = SocialiteUser::fake(['email' => '']);

        $driver = Mockery::mock(GithubProvider::class);
        $driver->shouldReceive('redirectUrl')->once()->with(route('auth.github.callback'))->andReturnSelf();
        $driver->shouldReceive('user')->once()->andReturn($githubUser);

        Socialite::shouldReceive('driver')->once()->with('github')->andReturn($driver);

        $response = $this->get(route('auth.github.callback'));

        $response->assertRedirect(route('login'))
            ->assertSessionHas('login_denial', "GitHub didn't return an email address. Make sure you're signed into a GitHub account.");

        $this->assertGuest();
    });
});
