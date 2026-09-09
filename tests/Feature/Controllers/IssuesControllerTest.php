<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatNotifier;
use App\Services\Process\BackgroundArtisan;
use App\Services\Process\BackgroundArtisanResult;
use App\Services\Scheduler\SchedulerHeartbeat;
use App\Support\Settings;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('IssuesController', function () {
    beforeEach(function () {
        $this->mockIssueCounterZero();
    });

    it('redirects unauthenticated requests to login', function () {
        $this->get(route('issues.index'))->assertRedirect(route('login'));
    });

    it('renders the issues page', function () {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('issues.index'));

        $response->assertOk()->assertSee('Issues');
    });

    it('poll-servers starts clockwork:poll-servers in the background and reports unhealthy count', function () {
        $this->mock(BackgroundArtisan::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->withArgs(fn (string $key, array $cmds) => $key === 'fleet.poll_servers'
                    && $cmds === ['clockwork:poll-servers'])
                ->andReturn(BackgroundArtisanResult::ok());
        });

        Server::factory()->create(['status' => Server::STATUS_RED]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('issues.poll-servers'));

        $response->assertOk()->assertJson(['ok' => true, 'started' => true, 'unhealthy' => 1]);
    });

    it('poll-servers returns a 500 JSON error when the background launch fails', function () {
        $this->mock(BackgroundArtisan::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->andReturn(BackgroundArtisanResult::error('ssh fleet unreachable'));
        });

        $response = $this->actingAs(User::factory()->create())
            ->post(route('issues.poll-servers'));

        $response->assertStatus(500)->assertJson(['ok' => false, 'error' => 'ssh fleet unreachable']);
    });

    it('fetch-all-db-creds starts extract-wp-configs in the background', function () {
        $server = Server::factory()->create(['last_ssh_ok_at' => now()]);
        Site::factory()->spinupwp()->create([
            'server_id' => $server->id,
            'is_wordpress' => true,
            'db_password' => null,
        ]);

        $this->mock(BackgroundArtisan::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->withArgs(fn (string $key, array $cmds) => $key === 'issues.extract_wp_configs'
                    && $cmds === ['clockwork:extract-wp-configs'])
                ->andReturn(BackgroundArtisanResult::ok());
        });

        $response = $this->actingAs(User::factory()->create())
            ->post(route('issues.fetch-all-db-creds'));

        $response->assertRedirect()
            ->assertSessionHas('status', function ($status) {
                return str_contains($status, 'Fetching DB credentials for 1 site(s) in the background');
            });
    });

    it('fetch-all-db-creds reports no candidates when nothing is missing credentials', function () {
        // No sites at all -> the eligible-sites query is empty, so the
        // controller short-circuits before touching WpConfigExtractor.
        $response = $this->actingAs(User::factory()->create())
            ->post(route('issues.fetch-all-db-creds'));

        $response->assertRedirect()
            ->assertSessionHas('status', 'No sites with missing DB credentials found.');
    });

    it('destroys an orphaned site and flashes a status message', function () {
        $server = Server::factory()->create();
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $server->id,
            'spinupwp_id' => null,
            'archived_at' => null,
            'domain' => 'orphan.example.com',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->delete(route('issues.orphans.destroy', ['siteId' => $site->id]));

        $response->assertRedirect()
            ->assertSessionHas('status', 'orphan.example.com removed from monitoring.');

        expect($site->fresh()->archived_at)->not->toBeNull();
    });

    it('rejects destroying a site that is not orphaned', function () {
        $server = Server::factory()->create();
        $site = Site::factory()->spinupwp()->create([
            'server_id' => $server->id,
            // spinupwp_id is non-null via the spinupwp() state -> not orphaned.
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->delete(route('issues.orphans.destroy', ['siteId' => $site->id]));

        $response->assertStatus(422);
        expect($site->fresh()->archived_at)->toBeNull();
    });

    it('rejects destroying a non-SpinupWP site with a null spinupwp_id — it is not a SpinupWP orphan', function () {
        $site = Site::factory()->gridpane()->create(['archived_at' => null]);

        $response = $this->actingAs(User::factory()->create())
            ->delete(route('issues.orphans.destroy', ['siteId' => $site->id]));

        $response->assertStatus(422);
        expect($site->fresh()->archived_at)->toBeNull();
    });

    it('does not list a GridPane site with a null spinupwp_id as an orphaned site', function () {
        Site::factory()->spinupwp()->create([
            'spinupwp_id' => null,
            'archived_at' => null,
            'domain' => 'real-orphan.example.com',
        ]);
        Site::factory()->gridpane()->create([
            'archived_at' => null,
            'domain' => 'gridpane-site.example.com',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('issues.index'));

        $response->assertOk()
            ->assertSee('real-orphan.example.com')
            ->assertDontSee('gridpane-site.example.com');
    });

    it('lists sites stuck in maintenance longer than two hours', function () {
        $server = Server::factory()->create(['is_ignored' => false]);
        Site::factory()->spinupwp()->create([
            'server_id' => $server->id,
            'domain' => 'stuck-maint.example.com',
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'maintenance',
            'uptime_maintenance_since' => now()->subHours(3),
            'uptime_ignored_at' => null,
        ]);
        Site::factory()->spinupwp()->create([
            'server_id' => $server->id,
            'domain' => 'fresh-maint.example.com',
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'maintenance',
            'uptime_maintenance_since' => now()->subMinutes(20),
            'uptime_ignored_at' => null,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('issues.index'));

        $response->assertOk()
            ->assertSee('stuck-maint.example.com')
            ->assertDontSee('fresh-maint.example.com')
            ->assertSee('Maintenance running longer than')
            ->assertViewHas('stuckMaintenanceSites', fn ($sites) => $sites->count() === 1);
    });

    it('lists a stale scheduler heartbeat as an issue', function () {
        app(Settings::class)->put(
            SchedulerHeartbeat::SETTING_HEARTBEAT_AT,
            now()->subMinutes(12)->toIso8601String()
        );

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('schedulerStale')->once()->andReturn(true);
            $mock->shouldReceive('schedulerRecovered')->never();
        });

        $response = $this->actingAs(User::factory()->create())
            ->get(route('issues.index'));

        $response->assertOk()
            ->assertSee('Scheduler has not ticked')
            ->assertSee('crontab is not spawning')
            ->assertViewHas('totals', fn ($totals) => ($totals['scheduler_stale'] ?? 0) === 1);
    });
});
