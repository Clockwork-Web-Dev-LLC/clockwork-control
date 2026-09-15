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

    it('renders sortable headers with up/down arrows for administrators table', function () {
        $user = User::factory()->create();
        Site::factory()->create([
            'domain' => 'bookhouse.net',
            'companion_installed' => true,
            'companion_snapshot' => [
                'admins' => [
                    'admins' => [
                        [
                            'login' => 'admin',
                            'email' => 'rebornpearl@gmail.com',
                            'last_seen_at' => '2016-09-14 12:00:00',
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->get(route('security.admins'))
            ->assertOk();

        // Verifies table is wired to Alpine sortableTable component
        $response->assertSee('x-data="sortableTable()"', false);

        // Verifies all columns have sort-by triggers with up/down indicator icons
        $response->assertSee("sortBy('site')", false);
        $response->assertSee("sortBy('login')", false);
        $response->assertSee("sortBy('email')", false);
        $response->assertSee("sortBy('last_seen')", false);
        $response->assertSee("sortBy('flags')", false);

        // Verifies row carries data-sort attributes for all 5 columns
        $response->assertSee('data-sort-site="bookhouse.net"', false);
        $response->assertSee('data-sort-login="admin"', false);
        $response->assertSee('data-sort-email="rebornpearl@gmail.com"', false);
        $response->assertSee('data-sort-last_seen="1473854400"', false);
        $response->assertSee('data-sort-flags="2_default_login"', false);
    });
});
