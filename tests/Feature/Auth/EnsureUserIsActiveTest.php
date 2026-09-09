<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('EnsureUserIsActive', function () {
    it('logs out a revoked user who still holds a live session', function () {
        $this->mockIssueCounterZero();

        $user = User::factory()->create(['revoked_at' => now()->subMinute()]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('login'))
            ->assertSessionHas('login_denial', 'Your account has been revoked. Please contact an administrator.');

        $this->assertGuest();
    });

    it('logs out a revoked user hitting /login instead of sending them to the dashboard', function () {
        $user = User::factory()->create(['revoked_at' => now()]);

        $response = $this->actingAs($user)->get(route('login'));

        $response->assertRedirect(route('login'))
            ->assertSessionHas('login_denial', 'Your account has been revoked. Please contact an administrator.');

        $this->assertGuest();
    });
});

describe('User::invalidateSessions', function () {
    it('cycles remember_token and deletes stored database sessions', function () {
        $user = User::factory()->create(['remember_token' => 'keep-me']);
        $original = $user->remember_token;

        DB::table('sessions')->insert([
            'id' => 'session-to-kill',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Pest',
            'payload' => 'payload',
            'last_activity' => time(),
        ]);

        $user->invalidateSessions();

        expect($user->fresh()->remember_token)->not->toBe($original);
        expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
    });
});
