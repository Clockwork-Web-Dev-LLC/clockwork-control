<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeEvent;
use App\Models\User;
use App\Services\Chat\ChatNotifier;
use App\Services\Process\BackgroundArtisan;
use App\Services\Process\BackgroundArtisanResult;
use App\Services\Scheduler\SchedulerHeartbeat;
use App\Support\Settings;
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
            ->assertSee('Scheduler heartbeat')
            ->assertSee('never ticked')
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

        $response->assertOk()
            ->assertSee('Monitoring')
            ->assertSee('Scheduler heartbeat')
            ->assertSee('Last tick:');
    });

    it('shows a stale scheduler heartbeat on the monitoring index', function () {
        app(Settings::class)->put(
            SchedulerHeartbeat::SETTING_HEARTBEAT_AT,
            now()->subMinutes(11)->toIso8601String()
        );

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('schedulerStale')->once()->andReturn(true);
        });

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitoring.index'));

        $response->assertOk()
            ->assertSee('Scheduler heartbeat')
            ->assertSee('stale');
    });

    it('refresh starts clockwork:check-site-uptime in the background', function () {
        $this->mock(BackgroundArtisan::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->withArgs(fn (string $key, array $cmds) => $key === 'monitoring.check_site_uptime'
                    && $cmds === ['clockwork:check-site-uptime'])
                ->andReturn(BackgroundArtisanResult::ok());
        });

        $response = $this->actingAs(User::factory()->create())
            ->post(route('monitoring.refresh'));

        $response->assertRedirect()
            ->assertSessionHas('status', function ($status) {
                return str_contains($status, 'Re-probe started in the background');
            });
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

    it('classifies active outage as Not Our Fault without setting standing site SLA policy', function () {
        $server = Server::factory()->create();
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $server->id,
            'domain' => 'dns-issue.example.com',
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'down',
            'uptime_down_since' => now()->subHours(15),
            'uptime_sla_exempt' => false,
        ]);

        $event = SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_DOWN,
            'event_at' => now()->subHours(15),
            'is_sla_exempt' => false,
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('monitoring.sites.classify-outage', $site), [
                'is_sla_exempt' => 1,
                'exemption_reason' => SiteUptimeEvent::REASON_CLIENT_DNS,
                'exemption_notes' => 'Client switched NS to Cloudflare without notifying us',
            ]);

        $response->assertRedirect();
        $site->refresh();
        $event->refresh();

        expect($site->uptime_sla_exempt)->toBeFalse()
            ->and($site->uptime_exemption_reason)->toBeNull()
            ->and($site->uptime_ignored_at)->not->toBeNull()
            ->and($event->is_sla_exempt)->toBeTrue()
            ->and($event->exemption_reason)->toBe(SiteUptimeEvent::REASON_CLIENT_DNS)
            ->and($event->exemption_notes)->toBe('Client switched NS to Cloudflare without notifying us');
    });

    it('reverts outage classification back to Legit Outage', function () {
        $server = Server::factory()->create();
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $server->id,
            'domain' => 'revert.example.com',
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'down',
            'uptime_sla_exempt' => true,
            'uptime_exemption_reason' => SiteUptimeEvent::REASON_CLIENT_DNS,
            'uptime_ignored_at' => now()->subHour(),
            'uptime_ignore_reason' => 'Client DNS change',
        ]);

        $event = SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_DOWN,
            'event_at' => now()->subHours(5),
            'is_sla_exempt' => true,
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('monitoring.sites.classify-outage', $site), [
                'is_sla_exempt' => 0,
            ]);

        $response->assertRedirect();
        $site->refresh();
        $event->refresh();

        expect($site->uptime_sla_exempt)->toBeFalse()
            ->and($site->uptime_exemption_reason)->toBeNull()
            ->and($site->uptime_ignored_at)->toBeNull()
            ->and($site->uptime_ignore_reason)->toBeNull()
            ->and($event->is_sla_exempt)->toBeFalse();
    });

    it('rejects an unknown exemption reason when classifying an outage', function () {
        $server = Server::factory()->create();
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $server->id,
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'down',
        ]);

        $this->actingAs(User::factory()->create())
            ->from(route('monitoring.index'))
            ->post(route('monitoring.sites.classify-outage', $site), [
                'is_sla_exempt' => 1,
                'exemption_reason' => 'not_a_real_reason',
            ])
            ->assertSessionHasErrors('exemption_reason');
    });

    it('renders Not Our Fault badge and shows excused hero state on monitoring index', function () {
        $server = Server::factory()->create();
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $server->id,
            'domain' => 'exempt-site.example.com',
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'down',
            'uptime_down_since' => now()->subHours(8),
            'uptime_sla_exempt' => true,
            'uptime_exemption_reason' => SiteUptimeEvent::REASON_CLIENT_DNS,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitoring.index'));

        $response->assertOk()
            ->assertSee('exempt-site.example.com')
            ->assertSee('NOT OUR FAULT')
            ->assertSee('1 EXCUSED')
            ->assertSee('1 not our fault')
            ->assertSee('classify-outage-modal')
            ->assertSee('monitoringOpenClassify')
            ->assertSee('openModal')
            ->assertSee('Mark Legit')
            ->assertViewHas('currentlyNotOurFault', 1)
            ->assertViewHas('currentlyDown', 0);
    });

    it('renders Not Our Fault from an event-only incident classify without standing site SLA', function () {
        $server = Server::factory()->create();
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $server->id,
            'domain' => 'event-only-exempt.example.com',
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'down',
            'uptime_down_since' => now()->subHours(3),
            'uptime_sla_exempt' => false,
            'uptime_exemption_reason' => null,
        ]);

        SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_DOWN,
            'event_at' => now()->subHours(3),
            'is_sla_exempt' => true,
            'exemption_reason' => SiteUptimeEvent::REASON_DOMAIN_EXPIRED,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitoring.index'));

        $response->assertOk()
            ->assertSee('event-only-exempt.example.com')
            ->assertSee('NOT OUR FAULT')
            ->assertSee('Domain expired')
            ->assertViewHas('currentlyNotOurFault', 1)
            ->assertViewHas('currentlyDown', 0);
    });

    it('renders Not our fault button for down sites and includes classification modal', function () {
        $server = Server::factory()->create();
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $server->id,
            'domain' => 'down-site.example.com',
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'down',
            'uptime_down_since' => now()->subHours(2),
            'uptime_sla_exempt' => false,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('monitoring.index'));

        $response->assertOk()
            ->assertSee('down-site.example.com')
            ->assertSee('Not our fault?')
            ->assertSee('classify-outage-modal')
            ->assertSee('classify-outage-form')
            ->assertSee('monitoringOpenClassify('.$site->id, false)
            ->assertSee('openModal('.$site->id, false);
    });

    it('classifies individual historical uptime event', function () {
        $server = Server::factory()->create();
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $server->id,
            'domain' => 'event-history.example.com',
        ]);

        $event = SiteUptimeEvent::create([
            'site_id' => $site->id,
            'event_type' => SiteUptimeEvent::TYPE_DOWN,
            'event_at' => now()->subDays(5),
            'is_sla_exempt' => false,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('monitoring.events.classify', $event), [
                'is_sla_exempt' => 1,
                'exemption_reason' => SiteUptimeEvent::REASON_DOMAIN_EXPIRED,
                'exemption_notes' => 'Domain registration expired at GoDaddy',
            ]);

        $response->assertRedirect();
        $event->refresh();

        expect($event->is_sla_exempt)->toBeTrue()
            ->and($event->exemption_reason)->toBe(SiteUptimeEvent::REASON_DOMAIN_EXPIRED);
    });
});
