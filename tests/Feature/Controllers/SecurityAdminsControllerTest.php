<?php

use App\Models\Site;
use App\Models\User;
use App\Services\Security\FleetAdminAuditor;

describe('Security admins directory', function () {
    it('lists snapshot administrators and saves the allowlist', function () {
        $user = User::factory()->create();
        Site::factory()->create([
            'domain' => 'flagged.test',
            'companion_installed' => true,
            'companion_snapshot' => [
                'admins' => [
                    'admins' => [
                        ['login' => 'admin', 'email' => 'root@flagged.test'],
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('security.admins'))
            ->assertOk()
            ->assertSee('admin')
            ->assertSee('flagged.test');

        $this->actingAs($user)
            ->patch(route('security.admins.allowlist'), [
                'approved_domains' => 'agency.test',
                'approved_emails' => 'owner@client.test',
            ])
            ->assertRedirect();

        $auditor = app(FleetAdminAuditor::class);
        expect($auditor->approvedDomains())->toBe(['agency.test'])
            ->and($auditor->approvedEmails())->toBe(['owner@client.test']);
    });
});
