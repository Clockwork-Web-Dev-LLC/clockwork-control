<?php

use App\Models\ActionLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
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

        it('shows an accurate revoked-count roll-up tile', function () {
            $viewer = User::factory()->create();
            User::factory()->count(2)->create(['revoked_at' => now()]);

            $response = $this->actingAs($viewer)->get(route('settings.users.index'));

            $response->assertOk()->assertSeeInOrder(['Revoked', '2', 'No longer authorized']);
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
            expect($user->role)->toBe(User::ROLE_OPERATOR);

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

        it('blocks an administrator from demoting their own account', function () {
            $admin = User::factory()->create(['email' => 'admin@example.com', 'role' => User::ROLE_ADMIN]);
            User::factory()->create(['role' => User::ROLE_ADMIN]);

            $response = $this->actingAs($admin)->post(route('settings.users.store'), [
                'email' => 'admin@example.com',
                'role' => User::ROLE_OPERATOR,
            ]);

            $response->assertRedirect(route('settings.users.index'));
            $response->assertSessionHas('error', "You can't demote your own account.");
            expect($admin->fresh()->role)->toBe(User::ROLE_ADMIN);
        });

        it('blocks demoting the last active administrator', function () {
            $actingAdmin = User::factory()->create(['email' => 'acting@example.com', 'role' => User::ROLE_ADMIN]);
            $targetAdmin = User::factory()->create(['email' => 'target@example.com', 'role' => User::ROLE_ADMIN]);

            // Demoting when 2 active admins exist succeeds
            $response = $this->actingAs($actingAdmin)->post(route('settings.users.store'), [
                'email' => 'target@example.com',
                'role' => User::ROLE_OPERATOR,
            ]);
            $response->assertRedirect(route('settings.users.index'));
            expect($targetAdmin->fresh()->role)->toBe(User::ROLE_OPERATOR);

            // Now actingAdmin is the only admin left — trying to demote actingAdmin is blocked
            $response = $this->actingAs($actingAdmin)->post(route('settings.users.store'), [
                'email' => 'acting@example.com',
                'role' => User::ROLE_OPERATOR,
            ]);
            $response->assertSessionHas('error', "You can't demote your own account.");
            expect($actingAdmin->fresh()->role)->toBe(User::ROLE_ADMIN);
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
            $originalRememberToken = $target->remember_token;

            $response = $this->actingAs($admin)->patch(route('settings.users.revoke', $target));

            $response->assertRedirect(route('settings.users.index'));
            $response->assertSessionHas('status', 'Revoked target@example.com. Their sessions have been ended.');

            $target->refresh();
            expect($target->revoked_at)->not->toBeNull();
            expect($target->remember_token)->not->toBe($originalRememberToken);

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

        it('allows an admin to restore a revoked teammate', function () {
            $self = User::factory()->create(['email' => 'selfrestore@example.com']);
            $target = User::factory()->create(['email' => 'gone@example.com', 'revoked_at' => now()->subDay()]);

            $response = $this->actingAs($self)->patch(route('settings.users.restore', $target));

            $response->assertRedirect(route('settings.users.index'));
            $response->assertSessionHas('status', 'Restored gone@example.com.');

            $target->refresh();
            expect($target->revoked_at)->toBeNull();
        });

        it('blocks operators from managing the allowlist', function () {
            $operator = User::factory()->operator()->create();
            $target = User::factory()->create();

            $this->actingAs($operator)->get(route('settings.users.index'))->assertForbidden();
            $this->actingAs($operator)->post(route('settings.users.store'), [
                'email' => 'new@example.com',
            ])->assertForbidden();
            $this->actingAs($operator)->patch(route('settings.users.revoke', $target))->assertForbidden();
        });
    });

    describe('password management', function () {
        it('allows storing a user with an initial local password', function () {
            $admin = User::factory()->create();

            $response = $this->actingAs($admin)->post(route('settings.users.store'), [
                'email' => 'localoperator@example.com',
                'name' => 'Local Operator',
                'password' => 'secretPass123!',
            ]);

            $response->assertRedirect(route('settings.users.index'));
            $response->assertSessionHas('status', 'Added localoperator@example.com. They can now sign in with their password.');

            $user = User::query()->where('email', 'localoperator@example.com')->firstOrFail();
            expect($user->password)->not->toBeNull();
            expect(Hash::check('secretPass123!', $user->password))->toBeTrue();
        });

        it('updates user password via patch route', function () {
            $admin = User::factory()->create();
            $target = User::factory()->create([
                'email' => 'targetoperator@example.com',
                'password' => 'oldpassword123',
            ]);
            $originalRememberToken = $target->remember_token;

            $response = $this->actingAs($admin)->patch(route('settings.users.password', $target), [
                'password' => 'newSecretPass456!',
                'password_confirmation' => 'newSecretPass456!',
            ]);

            $response->assertRedirect(route('settings.users.index'));
            $response->assertSessionHas('status', 'Updated password for targetoperator@example.com.');

            $target->refresh();
            expect(Hash::check('newSecretPass456!', $target->password))->toBeTrue();
            expect($target->remember_token)->not->toBe($originalRememberToken);

            $log = ActionLog::query()->where('action_type', ActionLog::TYPE_USER_PASSWORD_CHANGED)->firstOrFail();
            expect($log->summary)->toBe('Updated password for targetoperator@example.com.');
        });

        it('rejects password updates that do not match confirmation or are too short', function () {
            $admin = User::factory()->create();
            $target = User::factory()->create();

            $response = $this->actingAs($admin)->patch(route('settings.users.password', $target), [
                'password' => 'short',
                'password_confirmation' => 'mismatch',
            ]);

            $response->assertSessionHasErrors(['password']);
        });
    });
});
