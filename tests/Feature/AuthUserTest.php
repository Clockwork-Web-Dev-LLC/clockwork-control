<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

describe('User Authentication & Remember Recaller', function () {
    it('returns empty string from getAuthPassword when password column is null', function () {
        $user = User::factory()->create([
            'password' => null,
        ]);

        expect($user->getAuthPassword())->toBe('');
        expect(hash_equals($user->getAuthPassword(), 'some-hash'))->toBeFalse();
    });

    it('safely handles recaller resolution when user has null password', function () {
        $user = User::factory()->create([
            'password' => null,
            'remember_token' => 'test-remember-token-12345',
        ]);

        // Simulate recaller check with mismatched hash
        $guard = Auth::guard('web');
        $hash = $guard->hashPasswordForCookie($user->getAuthPassword());

        expect($hash)->toBeString();
        expect(hash_equals($user->getAuthPassword(), $hash))->toBeFalse();
    });
});
