<?php

namespace Tests\Feature\Console;

use App\Models\ActionLog;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| AddUser (artisan clockwork:add-user {email} {--name=})
|--------------------------------------------------------------------------
|
| This command IS the allowlist mechanism itself (there's no separate
| allowlist model to check against — see AddUser::handle(): it creates/
| restores the User row directly), so full coverage means: new-user create,
| idempotent re-add, revoked-user restore, invalid email rejection, and the
| --name option/default.
*/

describe('creating a new user', function () {
    it('creates the user, lowercases/trims the email, and logs TYPE_USER_ADDED', function () {
        $this->artisan('clockwork:add-user', ['email' => '  Test@Example.COM  ', '--name' => 'Test User'])
            ->assertSuccessful()
            ->expectsOutputToContain('Added test@example.com to the allowlist.');

        $user = User::where('email', 'test@example.com')->first();
        expect($user)->not->toBeNull();
        expect($user->name)->toBe('Test User');
        expect($user->revoked_at)->toBeNull();
        expect($user->password)->toBeNull();

        $log = ActionLog::query()->where('action_type', ActionLog::TYPE_USER_ADDED)->first();
        expect($log)->not->toBeNull();
        expect($log->ok)->toBeTrue();
        expect($log->actor)->toBe('cli');
        expect($log->summary)->toContain('test@example.com');
    });

    it('defaults --name to the email address when not given', function () {
        $this->artisan('clockwork:add-user', ['email' => 'noname@example.com'])
            ->assertSuccessful();

        expect(User::where('email', 'noname@example.com')->first()->name)->toBe('noname@example.com');
    });

    it('rejects an invalid email and creates no user', function () {
        $this->artisan('clockwork:add-user', ['email' => 'not-an-email'])
            ->assertFailed()
            ->expectsOutputToContain("'not-an-email' doesn't look like a valid email address.");

        expect(User::where('email', 'not-an-email')->exists())->toBeFalse();
    });
});

describe('re-adding an existing, non-revoked user', function () {
    it('updates the name and does NOT log TYPE_USER_ADDED or TYPE_USER_RESTORED again', function () {
        $existing = User::factory()->create(['email' => 'already@example.com', 'name' => 'Old Name']);

        $this->artisan('clockwork:add-user', ['email' => 'already@example.com', '--name' => 'New Name'])
            ->assertSuccessful()
            ->expectsOutputToContain('already@example.com already on the allowlist; name updated.');

        expect($existing->refresh()->name)->toBe('New Name');
        expect(ActionLog::query()->whereIn('action_type', [ActionLog::TYPE_USER_ADDED, ActionLog::TYPE_USER_RESTORED])->count())->toBe(0);
    });
});

describe('restoring a revoked user', function () {
    it('clears revoked_at, updates the name, and logs TYPE_USER_RESTORED', function () {
        $revoked = User::factory()->create([
            'email' => 'revoked@example.com',
            'name' => 'Old Name',
            'revoked_at' => now()->subDay(),
        ]);

        $this->artisan('clockwork:add-user', ['email' => 'revoked@example.com', '--name' => 'Restored Name'])
            ->assertSuccessful()
            ->expectsOutputToContain('Restored revoked@example.com (was revoked).');

        $revoked->refresh();
        expect($revoked->revoked_at)->toBeNull();
        expect($revoked->name)->toBe('Restored Name');

        $log = ActionLog::query()->where('action_type', ActionLog::TYPE_USER_RESTORED)->first();
        expect($log)->not->toBeNull();
        expect($log->summary)->toContain('revoked@example.com');
    });
});
