<?php

use App\Models\Site;
use App\Models\SitePerformanceScan;
use App\Models\SiteSecurityScan;
use App\Models\SiteTrafficDaily;
use App\Models\SiteUptimeEvent;
use App\Models\User;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('Site Overview Command Center Dashboard', function () {
    it('requires authentication to view site overview', function () {
        $site = Site::factory()->create();

        $this->get(route('sites.show', $site))->assertRedirect(route('login'));
    });

    it('renders the complete 3-column command center widget dashboard', function () {
        $this->mockIssueCounterZero();
        $user = User::factory()->create();

        $site = Site::factory()->create([
            'domain' => 'dashboard-test.example.com',
            'is_wordpress' => true,
            'care_plan_enabled' => true,
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'up',
            'uptime_last_up_at' => now()->subDays(5),
            'notes' => 'Important client instructions: check WooCommerce before core updates.',
            'backup_relay_enabled' => true,
            'backup_relay_last_archived_at' => now()->subHours(2),
            'domain_expires_at' => now()->addDays(120),
            'domain_registrar' => 'Cloudflare Registrar',
            'seo_indexable' => true,
            'seo_checked_at' => now()->subDay(),
            'companion_snapshot' => [
                'plugins' => [
                    'counts' => ['updates_available' => 2],
                ],
                'themes' => [
                    'counts' => ['updates_available' => 0],
                ],
                'wp_core' => [
                    'update_available' => false,
                ],
                'environment' => [
                    'php_version' => '8.3.6',
                    'wp_version' => '6.7.1',
                ],
            ],
        ]);

        // Add performance scan
        SitePerformanceScan::create([
            'site_id' => $site->id,
            'strategy' => SitePerformanceScan::STRATEGY_MOBILE,
            'status' => SitePerformanceScan::STATUS_OK,
            'performance_score' => 95,
            'lcp_ms' => 1400,
            'scanned_at' => now()->subDays(2),
        ]);

        // Add security scan
        SiteSecurityScan::create([
            'site_id' => $site->id,
            'scan_type' => SiteSecurityScan::TYPE_SITECHECK,
            'status' => 'clean',
            'summary' => 'No malware found',
            'scanned_at' => now()->subDay(),
        ]);

        // Add traffic rollups
        SiteTrafficDaily::create([
            'site_id' => $site->id,
            'date' => now()->subDay()->toDateString(),
            'requests' => 1500,
            'visits' => 450,
            'unique_ips' => 320,
            'status_2xx' => 1400,
            'status_3xx' => 50,
            'status_4xx' => 30,
            'status_5xx' => 20,
            'bytes_sent' => 50000000,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['site' => $site, 'tab' => 'overview']));

        $response->assertOk()
            ->assertSee('dashboard-test.example.com')
            // Widget 1: Updates
            ->assertSee('Updates')
            ->assertSee('2 updates')
            // Widget 2: Uptime Monitor
            ->assertSee('Uptime Monitor')
            ->assertSee('Overall uptime 100.0%')
            // Widget 3: Performance & Speed
            ->assertSee('Performance &amp; Speed', false)
            ->assertSee('95')
            // Widget 4: Backups
            ->assertSee('Backups')
            ->assertSee('Backups are successful')
            // Widget 5: Analytics & Traffic
            ->assertSee('Analytics &amp; Traffic', false)
            ->assertSee('450')
            // Widget 6: Site Notes
            ->assertSee('Site Notes')
            ->assertSee('Important client instructions: check WooCommerce before core updates.')
            // Widget 7: Security & Integrity
            ->assertSee('Security &amp; Integrity', false)
            ->assertSee('Sucuri SiteCheck')
            // Widget 8: SEO & Domain
            ->assertSee('SEO &amp; Domain', false)
            ->assertSee('Cloudflare Registrar')
            ->assertSee('Indexable');
    });

    it('calculates 30-day uptime percentage accurately across downtime events', function () {
        $site = Site::factory()->create([
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'up',
        ]);

        // 100% when no downtime events
        expect($site->computeUptimePercentage(30))->toBe(100.0);

        // Add a 60-minute downtime event pair (DOWN then UP 60 minutes later)
        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_DOWN,
            'status_code' => 500,
            'error' => 'HTTP 500 Internal Server Error',
            'event_at' => now()->subDays(2),
        ]);
        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_UP,
            'status_code' => 200,
            'event_at' => now()->subDays(2)->addMinutes(60),
        ]);

        $pct = $site->computeUptimePercentage(30);
        // Over 30 days = 43,200 minutes. 60 min downtime = (43140/43200) * 100 ≈ 99.86%
        expect($pct)->toBeLessThan(100.0)
            ->and($pct)->toBeGreaterThan(99.0);
    });

    it('requires authentication to update site notes', function () {
        $site = Site::factory()->create();

        $this->patch(route('sites.notes.update', $site), ['notes' => 'New note'])
            ->assertRedirect(route('login'));
    });

    it('updates site notes via JSON AJAX request', function () {
        $user = User::factory()->create();
        $site = Site::factory()->create(['notes' => 'Old note']);

        $response = $this->actingAs($user)
            ->patchJson(route('sites.notes.update', $site), [
                'notes' => 'Updated operator notes for deployment.',
            ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'notes' => 'Updated operator notes for deployment.',
                'message' => 'Site notes saved successfully.',
            ]);

        expect($site->fresh()->notes)->toBe('Updated operator notes for deployment.');
    });

    it('updates site notes via standard web form request and redirects back', function () {
        $user = User::factory()->create();
        $site = Site::factory()->create(['notes' => null]);

        $response = $this->actingAs($user)
            ->from(route('sites.show', $site))
            ->patch(route('sites.notes.update', $site), [
                'notes' => 'Saved via web form submit.',
            ]);

        $response->assertRedirect(route('sites.show', $site))
            ->assertSessionHas('status', 'Site notes saved successfully.');

        expect($site->fresh()->notes)->toBe('Saved via web form submit.');
    });
});
