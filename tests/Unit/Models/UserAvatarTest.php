<?php

use App\Models\User;

describe('User avatar and initials', function () {
    it('generates a gravatar URL from the user email', function () {
        $user = new User([
            'name' => 'Aaron Reimann',
            'email' => 'AaronR@ClockworkWD.com  ',
        ]);

        $expectedHash = md5('aaronr@clockworkwd.com');
        expect($user->gravatarUrl())->toBe("https://www.gravatar.com/avatar/{$expectedHash}?s=80&d=mp")
            ->and($user->gravatarUrl(128, 'identicon'))->toBe("https://www.gravatar.com/avatar/{$expectedHash}?s=128&d=identicon");
    });

    it('returns custom avatar_url when present', function () {
        $user = new User([
            'name' => 'Aaron Reimann',
            'email' => 'aaronr@clockworkwd.com',
            'avatar_url' => 'https://example.com/custom-photo.jpg',
        ]);

        expect($user->avatarUrl())->toBe('https://example.com/custom-photo.jpg');
    });

    it('falls back to gravatar when avatar_url is null or empty', function () {
        $user = new User([
            'name' => 'Aaron Reimann',
            'email' => 'aaronr@clockworkwd.com',
            'avatar_url' => null,
        ]);

        $expectedHash = md5('aaronr@clockworkwd.com');
        expect($user->avatarUrl())->toBe("https://www.gravatar.com/avatar/{$expectedHash}?s=80&d=mp");
    });

    it('computes smart initials from user name', function () {
        $u1 = new User(['name' => 'Aaron Reimann']);
        expect($u1->initials())->toBe('AR');

        $u2 = new User(['name' => 'Jane Mary Doe']);
        expect($u2->initials())->toBe('JD');

        $u3 = new User(['name' => 'Aaron']);
        expect($u3->initials())->toBe('AA');

        $u4 = new User(['name' => '']);
        expect($u4->initials())->toBe('OP');
    });
});
