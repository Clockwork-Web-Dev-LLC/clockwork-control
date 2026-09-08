<?php

use App\Models\Site;
use App\Models\User;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('Site Dashboard Draggable Layout', function () {
    it('requires authentication to update dashboard layout', function () {
        $site = Site::factory()->create();

        $this->patch(route('sites.layout.update', $site), [
            'layout' => ['notes', 'backups', 'updates'],
        ])->assertRedirect(route('login'));
    });

    it('updates site dashboard layout via JSON request', function () {
        $user = User::factory()->create();
        $site = Site::factory()->create(['dashboard_layout' => null]);

        $customOrder = [
            'notes',
            'backups',
            'traffic',
            'updates',
            'uptime',
            'performance',
            'security',
            'seo',
            'forms',
        ];

        $response = $this->actingAs($user)->patchJson(route('sites.layout.update', $site), [
            'layout' => $customOrder,
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'layout' => $customOrder,
                'message' => 'Dashboard layout updated successfully.',
            ]);

        expect($site->fresh()->dashboard_layout)->toBe($customOrder);
    });

    it('rejects invalid widget keys in layout array', function () {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        $response = $this->actingAs($user)->patchJson(route('sites.layout.update', $site), [
            'layout' => ['notes', 'invalid_fake_widget', 'updates'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['layout.1']);
    });

    it('resets custom layout back to default when reset flag is sent', function () {
        $user = User::factory()->create();
        $site = Site::factory()->create([
            'dashboard_layout' => ['notes', 'backups', 'traffic'],
        ]);

        $response = $this->actingAs($user)->patchJson(route('sites.layout.update', $site), [
            'reset' => true,
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'layout' => Site::DEFAULT_DASHBOARD_LAYOUT,
                'message' => 'Dashboard layout reset to default.',
            ]);

        expect($site->fresh()->dashboard_layout)->toBeNull()
            ->and($site->fresh()->resolvedDashboardLayout())->toBe(Site::DEFAULT_DASHBOARD_LAYOUT);
    });

    it('resolvedDashboardLayout appends any missing default widgets to a partial custom layout', function () {
        $site = Site::factory()->create([
            'dashboard_layout' => ['notes', 'traffic'],
        ]);

        $resolved = $site->resolvedDashboardLayout();

        // First two should be the custom specified widgets
        expect($resolved[0])->toBe('notes')
            ->and($resolved[1])->toBe('traffic')
            // All other 7 widgets should be appended
            ->and(count($resolved))->toBe(count(Site::DEFAULT_DASHBOARD_LAYOUT))
            ->and(in_array('updates', $resolved, true))->toBeTrue()
            ->and(in_array('uptime', $resolved, true))->toBeTrue();
    });

    it('renders dashboard widgets in the customized sequence', function () {
        $this->mockIssueCounterZero();
        $user = User::factory()->create();

        // Put Notes first and Backups second
        $site = Site::factory()->create([
            'notes' => 'Custom layout test note',
            'dashboard_layout' => [
                'notes',
                'backups',
                'updates',
                'uptime',
                'performance',
                'traffic',
                'security',
                'seo',
                'forms',
            ],
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['site' => $site, 'tab' => 'overview']));

        $response->assertOk();
        $content = $response->getContent();

        // Check that data-widget="notes" appears before data-widget="backups" in the HTML grid
        $notesPos = strpos($content, 'data-widget="notes"');
        $backupsPos = strpos($content, 'data-widget="backups"');
        $updatesPos = strpos($content, 'data-widget="updates"');

        expect($notesPos)->not->toBeFalse()
            ->and($backupsPos)->not->toBeFalse()
            ->and($updatesPos)->not->toBeFalse()
            ->and($notesPos)->toBeLessThan($backupsPos)
            ->and($backupsPos)->toBeLessThan($updatesPos);
    });
});
