<?php

use App\Models\ActionLog;
use App\Models\User;
use App\Support\UserProvisioner;

describe('UserProvisioner', function () {
    it('creates a new operator on the allowlist with null password', function () {
        $provisioner = app(UserProvisioner::class);

        $result = $provisioner->addOrRestore('founder@agency.test', 'Agency Founder', 'installer');

        expect($result['status'])->toBe('created')
            ->and($result['user']->email)->toBe('founder@agency.test')
            ->and($result['user']->name)->toBe('Agency Founder')
            ->and($result['user']->password)->toBeNull()
            ->and($result['user']->revoked_at)->toBeNull();

        expect(User::where('email', 'founder@agency.test')->exists())->toBeTrue();

        expect(ActionLog::where('action_type', ActionLog::TYPE_USER_ADDED)->exists())->toBeTrue();
    });

    it('restores a previously revoked user without duplication', function () {
        $user = User::factory()->create([
            'email' => 'revoked@agency.test',
            'name' => 'Old Name',
            'revoked_at' => now()->subDay(),
        ]);

        $provisioner = app(UserProvisioner::class);

        $result = $provisioner->addOrRestore('revoked@agency.test', 'New Name', 'installer');

        expect($result['status'])->toBe('restored')
            ->and($result['user']->id)->toBe($user->id)
            ->and($result['user']->fresh()->revoked_at)->toBeNull()
            ->and($result['user']->fresh()->name)->toBe('New Name');

        expect(User::where('email', 'revoked@agency.test')->count())->toBe(1);
    });

    it('rejects invalid email formats', function () {
        $provisioner = app(UserProvisioner::class);

        $provisioner->addOrRestore('invalid-not-an-email');
    })->throws(InvalidArgumentException::class);
});
