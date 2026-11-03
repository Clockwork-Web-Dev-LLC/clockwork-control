<?php

use App\Models\Site;

/*
| Site's saving hook: a Companion snapshot that reports a WordPress core
| proves the site runs WordPress, so is_wordpress is switched on whenever
| such a snapshot is saved, from whichever code path writes it. Sites
| enrolled without the flag were otherwise left out of every WordPress-only
| job (core checksums, lockout and firewall pulls).
*/

describe('Site is_wordpress self-heal', function () {
    it('sets is_wordpress when a snapshot with a WordPress core is saved', function () {
        $site = Site::factory()->custom()->withCompanionInstalled()->create(['is_wordpress' => false]);
        expect($site->fresh()->is_wordpress)->toBeFalse();

        $site->forceFill([
            'companion_snapshot' => ['wp_core' => ['current_version' => '6.8.3'], 'plugins' => ['plugins' => []]],
            'companion_snapshot_at' => now(),
        ])->save();

        expect($site->fresh()->is_wordpress)->toBeTrue();
    });

    it('sets it on create too', function () {
        $site = Site::factory()->custom()->create([
            'is_wordpress' => false,
            'companion_snapshot' => ['wp_core' => ['current_version' => '6.8.3']],
        ]);

        expect($site->fresh()->is_wordpress)->toBeTrue();
    });

    it('leaves a site without a WordPress core in its snapshot alone', function () {
        $site = Site::factory()->custom()->create(['is_wordpress' => false]);

        $site->forceFill(['companion_snapshot' => ['plugins' => ['plugins' => []]]])->save();

        expect($site->fresh()->is_wordpress)->toBeFalse();
    });

    it('never clears the flag', function () {
        $site = Site::factory()->create(['is_wordpress' => true, 'companion_snapshot' => null]);

        $site->forceFill(['notes' => 'touched'])->save();

        expect($site->fresh()->is_wordpress)->toBeTrue();
    });
});
