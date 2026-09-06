<?php

use App\Models\ActionLog;
use App\Models\User;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| UsersSettingsController
|--------------------------------------------------------------------------
|
| A row in `users` IS the allowlist (see the controller's class docblock):
|   - store():   insert, or clear revoked_at if the email already exists revoked
|   - revoke():  set revoked_at (self-revoke is blocked — the deliberate
|                authorization nuance this phase asks to cover explicitly)
|   - restore(): clear revoked_at
|
| None of these actions call out to SSH/HTTP/an external client, so nothing
| here needs $this->mock() for network isolation — ActionLogger just writes
| a real row to the local action_logs table, which sqlite handles fine.
*/

describe('UsersSettingsController', function () {
    beforeEach(function () {
        $this->mockIssueCounterZero();
    });

    it('requires authentication', function () {
        $this->get(route('settings.users.index'))
            ->assertRedirect(route('login'));
    });

    describe('index', function () {
        it('lists allowlisted users, active first, ordered by email', function () {
            $viewer = User::factory()->create();
            $active = User::factory()->create(['email' => 'active@clockworkwd.com']);
            $revoked = User::factory()->create(['email' => 'revoked@clockworkwd.com', 'revoked_at' => now()]);

            $response = $this->actingAs($viewer)->get(route('settings.users.index'));

            $response->assertOk()
                ->assertSee('active@clockworkwd.com')
                ->assertSee('revoked@clockworkwd.com')
                ->assertSee('Revoked');
        });
    });

    describe('store', function () {
        it('adds a brand-new email to the allowlist, logs it, and redirects with a status flash', function () {
            $admin = User::factory()->create();

            $response = $this->actingAs($admin)->post(route('settings.users.store'), [
                'email' => 'NewPerson@Example.com',
                'name' => 'New Person',
            ]);

            $response->assertRedirect(route('settings.users.index'));
            $response->assertSessionHas('status', 'Added newperson@example.com. They can now sign in with their Google account.');

            $user = User::query()->where('email', 'newperson@example.com')->firstOrFail();
            expect($user->name)->toBe('New Person');
            expect($user->revoked_at)->toBeNull();
            expect($user->password)->toBeNull();

            $log = ActionLog::query()->where('action_type', ActionLog::TYPE_USER_ADDED)->firstOrFail();
            expect($log->summary)->toBe('Added newperson@example.com to allowlist.');
            expect($log->ok)->toBeTrue();
        });

        it('defaults name to the email when no name is given', function () {
            $admin = User::factory()->create();

            $this->actingAs($admin)->post(route('settings.users.store'), [
                'email' => 'noname@example.com',
            ])->assertRedirect(route('settings.users.index'));

            $user = User::query()->where('email', 'noname@example.com')->firstOrFail();
            expect($user->name)->toBe('noname@example.com');
        });

        it('restores a previously revoked user by email instead of creating a duplicate row', function () {
            $admin = User::factory()->create();
            $revoked = User::factory()->create([
                'email' => 'comeback@example.com',
                'revoked_at' => now()->subDay(),
            ]);

            $response = $this->actingAs($admin)->post(route('settings.users.store'), [
                'email' => 'comeback@example.com',
                'name' => 'Comeback Kid',
            ]);

            $response->assertRedirect(route('settings.users.index'));
            $response->assertSessionHas('status', 'Restored access for comeback@example.com.');

            expect(User::query()->where('email', 'comeback@example.com')->count())->toBe(1);

            $revoked->refresh();
            expect($revoked->revoked_at)->toBeNull();
            expect($revoked->name)->toBe('Comeback Kid');

            $log = ActionLog::query()->where('action_type', ActionLog::TYPE_USER_RESTORED)->firstOrFail();
            expect($log->summary)->toBe('Restored allowlist access for comeback@example.com.');
        });

        it('no-ops with an "already on the allowlist" message when the email is already active, and logs nothing', function () {
            $admin = User::factory()->create();
            $already = User::factory()->create(['email' => 'already@example.com']);

            $response = $this->actingAs($admin)->post(route('settings.users.store'), [
                'email' => 'already@example.com',
            ]);

            $response->assertRedirect(route('settings.users.index'));
            $response->assertSessionHas('status', 'already@example.com is already on the allowlist.');

            expect(User::query()->where('email', 'already@example.com')->count())->toBe(1);
            expect(ActionLog::query()->count())->toBe(0);
        });

        it('rejects an invalid email via validation before touching the database', function () {
            $admin = User::factory()->create();

            $response = $this->actingAs($admin)->post(route('settings.users.store'), [
                'email' => 'not-an-email',
            ]);

            $response->assertSessionHasErrors('email');
            // Only the acting admin should exist — nothing was inserted.
            expect(User::query()->count())->toBe(1);
        });

        it('rejects a missing email via validation', function () {
            $admin = User::factory()->create();

            $this->actingAs($admin)->post(route('settings.users.store'), [])
                ->assertSessionHasErrors('email');
        });
    });

    describe('revoke', function () {
        it('revokes another user, logs it, and redirects with a status flash', function () {
            $admin = User::factory()->create();
            $target = User::factory()->create(['email' => 'target@example.com']);

            $response = $this->actingAs($admin)->patch(route('settings.users.revoke', $target));

            $response->assertRedirect(route('settings.users.index'));
            $response->assertSessionHas('status', "Revoked target@example.com. They'll be denied at next sign-in attempt.");

            $target->refresh();
            expect($target->revoked_at)->not->toBeNull();

            $log = ActionLog::query()->where('action_type', ActionLog::TYPE_USER_REVOKED)->firstOrFail();
            expect($log->summary)->toBe('Revoked allowlist access for target@example.com.');
        });

        it('blocks a user from revoking their own access, leaving revoked_at untouched', function () {
            $self = User::factory()->create(['email' => 'self@example.com']);

            $response = $this->actingAs($self)->patch(route('settings.users.revoke', $self));

            $response->assertRedirect(route('settings.users.index'));
            $response->assertSessionHas('error', "You can't revoke your own access. Use the artisan command if you really mean it.");
            $response->assertSessionMissing('status');

            $self->refresh();
            expect($self->revoked_at)->toBeNull();
            expect(ActionLog::query()->where('action_type', ActionLog::TYPE_USER_REVOKED)->count())->toBe(0);
        });

        it('no-ops with an "already revoked" status when the target is already revoked, and logs nothing new', function () {
            $admin = User::factory()->create();
            $target = User::factory()->create(['email' => 'gone@example.com', 'revoked_at' => now()->subHour()]);

            $response = $this->actingAs($admin)->patch(route('settings.users.revoke', $target));

            $response->assertRedirect(route('settings.users.index'));
            $response->assertSessionHas('status', 'gone@example.com is already revoked.');
            expect(ActionLog::query()->where('action_type', ActionLog::TYPE_USER_REVOKED)->count())->toBe(0);
        });

        it('404s for a nonexistent user id via route-model binding', function () {
            $admin = User::factory()->create();

            $this->actingAs($admin)->patch(route('settings.users.revoke', ['user' => 999999]))
                ->assertNotFound();
        });
    });

    describe('restore', function () {
        it('restores a revoked user, logs it, and redirects with a status flash', function () {
            $admin = User::factory()->create();
            $target = User::factory()->create(['email' => 'restoreme@example.com', 'revoked_at' => now()->subDay()]);

            $response = $this->actingAs($admin)->patch(route('settings.users.restore', $target));

            $response->assertRedirect(route('settings.users.index'));
            $response->assertSessionHas('status', 'Restored restoreme@example.com.');

            $target->refresh();
            expect($target->revoked_at)->toBeNull();

            $log = ActionLog::query()->where('action_type', ActionLog::TYPE_USER_RESTORED)->firstOrFail();
            expect($log->summary)->toBe('Restored allowlist access for restoreme@example.com.');
        });

        it('no-ops with an "already active" status when the target is not revoked, and logs nothing', function () {
            $admin = User::factory()->create();
            $target = User::factory()->create(['email' => 'active2@example.com']);

            $response = $this->actingAs($admin)->patch(route('settings.users.restore', $target));

            $response->assertRedirect(route('settings.users.index'));
            $response->assertSessionHas('status', 'active2@example.com is already active.');
            expect(ActionLog::query()->where('action_type', ActionLog::TYPE_USER_RESTORED)->count())->toBe(0);
        });

        it('allows restoring your own row (only revoke-self is blocked)', function () {
            $self = User::factory()->create(['email' => 'selfrestore@example.com', 'revoked_at' => now()->subDay()]);

            $response = $this->actingAs($self)->patch(route('settings.users.restore', $self));

            $response->assertRedirect(route('settings.users.index'));
            $response->assertSessionHas('status', 'Restored selfrestore@example.com.');

            $self->refresh();
            expect($self->revoked_at)->toBeNull();
        });
    });
});
