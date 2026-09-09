<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeEvent;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('MonitoringController', function () {
    beforeEach(function () {
        $this->mockIssueCounterZero();
    });

    it('redirects unauthenticated requests to login', function () {
        $this->get(route('monitoring.index'))->assertRedirect(route('login'));
    });

    it('renders the uptime activity index', function () {
        $server = Server::factory()->create();
        Site::factory()->spinupwp()->create([
            'server_id' => $server->id,
            'domain' => 'monitored.example.com',
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'up',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitoring.index'));

        $response->assertOk()
            ->assertSee('Monitoring')
            ->assertSee('monitored.example.com')
            ->assertSee('id="monitoring-search"', false)
            ->assertSee('id="monitoring-search-clear"', false)
            ->assertSee('site-row', false)
            ->assertSee('data-search="monitored.example.com', false)
            ->assertSee('id="monitoring-no-match"', false);
    });

    it('renders sites in maintenance mode with the maintenance badge and counter', function () {
        $server = Server::factory()->create();
        Site::factory()->spinupwp()->create([
            'server_id' => $server->id,
            'domain' => 'maint.example.com',
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'maintenance',
            'uptime_maintenance_since' => now()->subMinutes(12),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitoring.index'));

        $response->assertOk()
            ->assertSee('maint.example.com')
            ->assertSee('MAINT')
            ->assertSee('In maintenance 12m')
            ->assertSee('1 maint')
            ->assertViewHas('currentlyMaintenance', 1);
    });

    it('renders separate 7d and 30d fleet uptime headline figures', function () {
        $server = Server::factory()->create();
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $server->id,
            'domain' => 'seven-day-uptime.example.com',
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'up',
        ]);

        // A single down/up pair 10 days ago: inside the 30d window, outside
        // the 7d window — 30d should show less than 100%, 7d should show
        // exactly 100% (the outage never happened within its window).
        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => 'down',
            'event_at' => now()->subDays(10),
        ]);
        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => 'up',
            'event_at' => now()->subDays(10)->addHours(2),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitoring.index'));

        $response->assertOk()
            ->assertSee('Fleet uptime (7d)')
            ->assertSee('Fleet uptime (30d)')
            ->assertSee('100.00%')
            ->assertViewHas('avg7d', 100.0)
            ->assertViewHas('avg30d', fn ($avg30d) => $avg30d !== null && $avg30d < 100.0);
    });

    it('renders the monitoring settings page', function () {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitoring.settings'));

        $response->assertOk()->assertSee('Monitoring');
    });

    it('refresh runs clockwork:check-site-uptime and flashes the summary', function () {
        Artisan::shouldReceive('call')
            ->once()
            ->with('clockwork:check-site-uptime')
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->once()
            ->andReturn("Probing 3 sites...\nDone. up=3 down=0 elapsed=1.2s\n");

        $response = $this->actingAs(User::factory()->create())
            ->post(route('monitoring.refresh'));

        $response->assertRedirect()
            ->assertSessionHas('status', 'Uptime refresh complete. Done. up=3 down=0 elapsed=1.2s');
    });

    it('updates monitoring settings with valid input', function () {
        $response = $this->actingAs(User::factory()->create())
            ->patch(route('monitoring.settings.update'), [
                'interval_minutes' => 10,
                'failure_threshold' => 3,
            ]);

        $response->assertRedirect(route('monitoring.settings'))
            ->assertSessionHas('status');

        $settings = app(Settings::class);
        expect((int) $settings->get('monitoring.uptime_interval_minutes'))->toBe(10);
        expect((int) $settings->get('monitoring.uptime_failure_threshold'))->toBe(3);
    });

    it('rejects an out-of-range interval on settings update', function () {
        $response = $this->actingAs(User::factory()->create())
            ->patch(route('monitoring.settings.update'), [
                'interval_minutes' => 7, // not in the allowed [1,5,10,15] enum
                'failure_threshold' => 3,
            ]);

        $response->assertSessionHasErrors('interval_minutes');
    });
});
