<?php

namespace Tests\Feature\Console;

use App\Models\ActionLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

describe('SetPassword (artisan clockwork:set-password)', function () {
    it('updates password for an existing user via --password option', function () {
        $user = User::factory()->create([
            'email' => 'operator@example.com',
            'password' => null,
        ]);

        $this->artisan('clockwork:set-password', [
            'email' => 'operator@example.com',
            '--password' => 'newSecretPass123!',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Password for operator@example.com updated successfully.');

        $user->refresh();
        expect($user->password)->not->toBeNull();
        expect(Hash::check('newSecretPass123!', $user->password))->toBeTrue();

        $log = ActionLog::where('action_type', ActionLog::TYPE_USER_PASSWORD_CHANGED)->first();
        expect($log)->not->toBeNull();
        expect($log->summary)->toContain('operator@example.com');
    });

    it('prompts for password and confirmation interactively when option is omitted', function () {
        $user = User::factory()->create([
            'email' => 'prompt@example.com',
            'password' => null,
        ]);

        $this->artisan('clockwork:set-password', ['email' => 'prompt@example.com'])
            ->expectsQuestion('Enter new password (min 8 characters): ', 'interactiveSecret123!')
            ->expectsQuestion('Confirm new password: ', 'interactiveSecret123!')
            ->assertSuccessful()
            ->expectsOutputToContain('Password for prompt@example.com updated successfully.');

        $user->refresh();
        expect(Hash::check('interactiveSecret123!', $user->password))->toBeTrue();
    });

    it('fails when interactive password confirmation does not match', function () {
        User::factory()->create(['email' => 'mismatch@example.com']);

        $this->artisan('clockwork:set-password', ['email' => 'mismatch@example.com'])
            ->expectsQuestion('Enter new password (min 8 characters): ', 'firstPass1234')
            ->expectsQuestion('Confirm new password: ', 'secondPass5678')
            ->assertFailed()
            ->expectsOutputToContain('Passwords do not match.');
    });

    it('fails when user does not exist', function () {
        $this->artisan('clockwork:set-password', [
            'email' => 'ghost@example.com',
            '--password' => 'somePassword123!',
        ])
            ->assertFailed()
            ->expectsOutputToContain("User 'ghost@example.com' was not found on the allowlist.");
    });

    it('fails when password is shorter than 8 characters', function () {
        User::factory()->create(['email' => 'tooshort@example.com']);

        $this->artisan('clockwork:set-password', [
            'email' => 'tooshort@example.com',
            '--password' => 'short',
        ])
            ->assertFailed()
            ->expectsOutputToContain('Password must be at least 8 characters.');
    });
});
