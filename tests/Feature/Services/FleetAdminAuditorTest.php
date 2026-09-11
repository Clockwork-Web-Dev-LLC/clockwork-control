<?php

use App\Models\IgnoredWpAdmin;
use App\Models\Site;
use App\Services\Security\FleetAdminAuditor;
use App\Support\Settings;

describe('FleetAdminAuditor', function () {
    it('flags the default admin login even when the allowlist is empty', function () {
        $site = Site::factory()->create([
            'companion_installed' => true,
            'companion_snapshot' => [
                'admins' => [
                    'admins' => [
                        ['login' => 'admin', 'email' => 'owner@client.test', 'display_name' => 'Owner'],
                        ['login' => 'editor-boss', 'email' => 'owner@client.test', 'display_name' => 'Also owner'],
                    ],
                    'default_admin_present' => true,
                ],
            ],
        ]);

        $rows = app(FleetAdminAuditor::class)->inventory();
        $byLogin = collect($rows)->where('site_id', $site->id)->keyBy('login');

        expect($byLogin['admin']['flagged'])->toBeTrue()
            ->and($byLogin['admin']['flags'])->toContain('default_login')
            ->and($byLogin['editor-boss']['flagged'])->toBeFalse();
    });

    it('flags unapproved emails only after an allowlist is configured', function () {
        $site = Site::factory()->create([
            'companion_installed' => true,
            'companion_snapshot' => [
                'admins' => [
                    'admins' => [
                        ['login' => 'agency', 'email' => 'ops@agency.test'],
                        ['login' => 'freelancer', 'email' => 'dev@freelance.test'],
                    ],
                ],
            ],
        ]);

        app(Settings::class)->put(FleetAdminAuditor::SETTING_DOMAINS, ['agency.test']);

        $rows = collect(app(FleetAdminAuditor::class)->inventory())->where('site_id', $site->id)->keyBy('login');

        expect($rows['agency']['flagged'])->toBeFalse()
            ->and($rows['freelancer']['flagged'])->toBeTrue()
            ->and($rows['freelancer']['flags'])->toContain('unapproved_email');
    });

    it('does not count acknowledged admins as flagged sites', function () {
        $site = Site::factory()->create([
            'companion_installed' => true,
            'companion_snapshot' => [
                'admins' => [
                    'admins' => [
                        ['login' => 'admin', 'email' => 'owner@client.test'],
                    ],
                ],
            ],
        ]);

        IgnoredWpAdmin::query()->create([
            'site_id' => $site->id,
            'subject' => 'owner@client.test',
        ]);

        expect(app(FleetAdminAuditor::class)->flaggedSites())->toHaveCount(0);
    });
});
