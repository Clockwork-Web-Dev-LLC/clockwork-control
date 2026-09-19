<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteTrafficDaily;
use App\Models\Tag;
use App\Models\User;
use App\Services\Process\BackgroundArtisan;
use App\Services\Process\BackgroundArtisanResult;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Pressable\PressableClient;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('CapacityController', function () {
    beforeEach(function () {
        $this->mockIssueCounterZero();

        // CapacityController::index's 7d CPU-sparkline query uses MySQL-only
        // UNIX_TIMESTAMP() in a raw SELECT (FLOOR(UNIX_TIMESTAMP(recorded_at) / 21600))
        // that runs unconditionally whenever a Shared tag exists, even with zero
        // matching rows -- sqlite can't parse the function name at all. Same class
        // of gotcha as IssueCounter/WeirdStatsAggregator, but this one is inline
        // raw SQL rather than an injectable service, so it can't be mocked away;
        // registering the function on the sqlite PDO connection is the equivalent
        // test-only shim.
        DB::connection()->getPdo()->sqliteCreateFunction('UNIX_TIMESTAMP', function ($value) {
            return $value === null ? null : Carbon::parse($value)->getTimestamp();
        });
    });

    it('redirects unauthenticated requests to login', function () {
        $this->get(route('capacity.index'))->assertRedirect(route('login'));
    });

    it('renders the capacity page for a Shared-tagged server with traffic', function () {
        $tag = Tag::factory()->create(['name' => 'Shared']);
        $server = Server::factory()->create(['name' => 'shared1.example.com']);
        $server->tags()->attach($tag);
        $site = Site::factory()->spinupwp()->create(['server_id' => $server->id]);
        SiteTrafficDaily::factory()->create(['site_id' => $site->id]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('capacity.index'));

        // Operations workspace: capacity.index renders dedicated operations tabs
        // (Capacity, Fleet Updates, Maintenance History, SSH Credentials).
        $response->assertSee('Fleet Updates')->assertSee('Maintenance History')->assertDontSee('Fleet & Branding');
    });

    it('shows the missing-tag notice when no Shared tag exists', function () {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('capacity.index'));

        $response->assertOk()->assertSee('No');
        $response->assertSee('Shared', false);
        $response->assertSee('tag exists', false);
    });

    it('toggles site-metrics collection off and starts a background Companion push', function () {
        $this->mock(BackgroundArtisan::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->withArgs(fn (string $key, array $cmds) => $key === 'capacity.push_sampler_state'
                    && $cmds === ['clockwork:push-site-metrics-state'])
                ->andReturn(BackgroundArtisanResult::ok());
        });

        $response = $this->actingAs(User::factory()->create())
            ->post(route('capacity.site-metrics.toggle'));

        $response->assertRedirect()
            ->assertSessionHas(
                'status',
                'Per-site CPU collection paused. Companion sites are being notified in the background.'
            );
    });

    it('redirects unauthenticated requests to capacity settings', function () {
        $this->get(route('capacity.settings'))->assertRedirect(route('login'));
    });

    it('renders the capacity settings page', function () {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('capacity.settings'));

        $response->assertOk()
            ->assertSee('Capacity Settings')
            ->assertSee('30,000 visits')
            ->assertSee('Shared-Server Visit Quota');
    });

    it('redirects /settings/capacity to /capacity/settings', function () {
        $response = $this->actingAs(User::factory()->create())
            ->get('/settings/capacity');

        $response->assertRedirect(route('capacity.settings'));
    });

    it('updates capacity settings with valid input and respects them on the capacity page', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->patch(route('capacity.settings.update'), [
                'visit_threshold' => 50_000,
                'rolling_days' => 45,
                'trending_window_days' => 14,
                'cpu_threshold' => 75.5,
                'memory_threshold' => 85.0,
                'disk_threshold' => 90.0,
            ]);

        $response->assertRedirect(route('capacity.settings'))
            ->assertSessionHas('status', 'Capacity settings saved. Threshold updates take effect immediately across the Capacity dashboard and issue counting.');

        $settings = app(Settings::class);
        expect((int) $settings->get('capacity.visit_threshold'))->toBe(50_000)
            ->and((int) $settings->get('capacity.rolling_days'))->toBe(45)
            ->and((int) $settings->get('capacity.trending_window_days'))->toBe(14)
            ->and((float) $settings->get('capacity.cpu_threshold'))->toBe(75.5)
            ->and((float) $settings->get('capacity.memory_threshold'))->toBe(85.0)
            ->and((float) $settings->get('capacity.disk_threshold'))->toBe(90.0);

        // Verify that /capacity renders the new threshold when Shared tag exists
        $tag = Tag::factory()->create(['name' => 'Shared']);
        $server = Server::factory()->create(['name' => 'shared1.example.com']);
        $server->tags()->attach($tag);

        $capacityResponse = $this->actingAs($user)->get(route('capacity.index'));
        $capacityResponse->assertOk()
            ->assertSee('50,000')
            ->assertSee('45d');
    });

    it('validates capacity settings inputs', function () {
        $response = $this->actingAs(User::factory()->create())
            ->patch(route('capacity.settings.update'), [
                'visit_threshold' => 500, // min 1000
                'rolling_days' => 3, // min 7
                'trending_window_days' => 0, // min 1
                'cpu_threshold' => 150, // max 100
                'memory_threshold' => 5, // min 10
                'disk_threshold' => 'not-a-number',
            ]);

        $response->assertSessionHasErrors([
            'visit_threshold',
            'rolling_days',
            'trending_window_days',
            'cpu_threshold',
            'memory_threshold',
            'disk_threshold',
        ]);
    });

    it('renders the Pressable Fleet Capacity section when Pressable is configured', function () {
        Cache::forget('pressable.capacity.account_summary');
        Cache::forget('pressable.capacity.account_summary.negative');

        $this->mock(PressableClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('account')->once()->andReturn([
                'productName' => 'Agency 1',
                'organization' => 'ClockworkWP',
                'email' => 'systems@clockworkwp.com',
                'capacity' => [
                    'sites' => [
                        'billable' => 100,
                        'staging' => 4,
                        'total' => 105,
                        'maxBillable' => 100,
                        'maxStaging' => 101,
                    ],
                ],
                'pageViews' => [
                    'currentMonth' => ['people' => 500, 'views' => 1200],
                    'lastMonth' => ['people' => 450, 'views' => 1100],
                ],
            ]);
        });

        $site = Site::factory()->create([
            'hosting_provider' => 'pressable',
            'domain' => 'pressable-site.example.com',
            'companion_installed' => true,
        ]);
        SiteTrafficDaily::factory()->create([
            'site_id' => $site->id,
            'date' => now()->toDateString(),
            'visits' => 1500,
            'requests' => 12000,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('capacity.index'));

        $response->assertOk()
            ->assertSee('Pressable Fleet Capacity')
            ->assertSee('Agency 1')
            ->assertSee('ClockworkWP')
            ->assertSee('pressable-site.example.com')
            ->assertSee('1,500')
            ->assertSee('Companion Active');

        expect(Cache::has('pressable.capacity.account_summary'))->toBeTrue();
    });

    it('identifies over-quota and trending Pressable sites without per-site API calls', function () {
        Cache::forget('pressable.capacity.account_summary');
        Cache::forget('pressable.capacity.account_summary.negative');
        Carbon::setTestNow('2026-09-13 12:00:00');

        $this->mock(PressableClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('account')->andReturn([
                'productName' => 'Agency 1',
                'organization' => 'ClockworkWP',
                'capacity' => [
                    'sites' => ['billable' => 10, 'staging' => 0, 'total' => 10, 'maxBillable' => 10],
                ],
            ]);
        });

        $overQuotaSite = Site::factory()->create([
            'hosting_provider' => 'pressable',
            'domain' => 'high-traffic-pressable.com',
        ]);
        SiteTrafficDaily::factory()->create([
            'site_id' => $overQuotaSite->id,
            'date' => now()->toDateString(),
            'visits' => 35_000,
            'requests' => 150_000,
        ]);

        $trendingSite = Site::factory()->create([
            'hosting_provider' => 'pressable',
            'domain' => 'trending-up-pressable.com',
        ]);
        SiteTrafficDaily::factory()->create([
            'site_id' => $trendingSite->id,
            'date' => now()->toDateString(),
            'visits' => 20_000, // 20k MTD on day 13 → 20k * 30/13 ≈ 46k month-end
            'requests' => 50_000,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('capacity.index'));

        $response->assertOk()
            ->assertSee('high-traffic-pressable.com')
            ->assertSee('Pressable Sites Exceeding Quota')
            ->assertSee('trending-up-pressable.com')
            ->assertSee('Pressable Sites Trending Toward Overage');

        Carbon::setTestNow();
    });

    it('does not trip Pressable over-quota on rolling 30d when calendar MTD is under threshold', function () {
        Cache::forget('pressable.capacity.account_summary');
        Cache::forget('pressable.capacity.account_summary.negative');
        Carbon::setTestNow('2026-09-13 12:00:00');

        $this->mock(PressableClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('account')->andReturn([
                'productName' => 'Agency 1',
                'capacity' => [
                    'sites' => ['billable' => 1, 'staging' => 0, 'total' => 1, 'maxBillable' => 10],
                ],
            ]);
        });

        $site = Site::factory()->create([
            'hosting_provider' => 'pressable',
            'domain' => 'rolling-only-pressable.com',
        ]);
        SiteTrafficDaily::factory()->create([
            'site_id' => $site->id,
            'date' => now()->subDays(20)->toDateString(),
            'visits' => 40_000,
            'requests' => 80_000,
        ]);
        SiteTrafficDaily::factory()->create([
            'site_id' => $site->id,
            'date' => now()->toDateString(),
            'visits' => 500,
            'requests' => 2_000,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('capacity.index'));

        $response->assertOk()
            ->assertDontSee('Pressable Sites Exceeding Quota')
            ->assertDontSee('Pressable Sites Trending Toward Overage');

        Carbon::setTestNow();
    });

    it('handles Pressable API exceptions gracefully and continues rendering capacity', function () {
        Cache::forget('pressable.capacity.account_summary');
        Cache::forget('pressable.capacity.account_summary.negative');

        $this->mock(PressableClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('account')->andThrow(new RuntimeException('Connection timeout to Pressable API'));
        });

        $site = Site::factory()->create([
            'hosting_provider' => 'pressable',
            'domain' => 'surviving-site.com',
        ]);
        SiteTrafficDaily::factory()->create([
            'site_id' => $site->id,
            'date' => now()->toDateString(),
            'visits' => 250,
            'requests' => 1500,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('capacity.index'));

        $response->assertOk()
            ->assertSee('Pressable Fleet Capacity')
            ->assertSee('surviving-site.com');
    });

    it('displays Pressable pill in per-site CPU leaderboard when a site is hosted on Pressable', function () {
        $tag = Tag::factory()->create(['name' => 'Shared']);
        $server = Server::factory()->create(['name' => 'shared1.example.com']);
        $server->tags()->attach($tag);

        $site = Site::factory()->create([
            'hosting_provider' => 'pressable',
            'domain' => 'pressable-cpu-hog.com',
            'server_id' => null,
        ]);

        DB::table('site_metrics')->insert([
            'site_id' => $site->id,
            'bucket_at' => now()->subHours(2),
            'cpu_us_total' => 20_000_000,
            'wall_us_total' => 30_000_000,
            'mem_peak_bytes' => 128 * 1024 * 1024,
            'requests' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('capacity.index'));

        $response->assertOk()
            ->assertSee('pressable-cpu-hog.com')
            ->assertSee('Pressable');
    });

    it('renders quick jump buttons and fleet filter toolbar when Pressable is present', function () {
        Cache::forget('pressable.capacity.account_summary');

        $this->mock(PressableClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('account')->andReturn([
                'productName' => 'Agency 1',
                'organization' => 'ClockworkWP',
                'capacity' => [
                    'sites' => ['billable' => 100, 'staging' => 4, 'total' => 105],
                ],
            ]);
        });

        Site::factory()->create([
            'hosting_provider' => 'pressable',
            'domain' => 'pressable-jump-site.com',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('capacity.index'));

        $response->assertOk()
            ->assertSee('Pressable (1)')
            ->assertSee('Shared VPS')
            ->assertSee('All Fleets')
            ->assertSee('Pressable Cloud')
            ->assertSee('Back to top');
    });

    it('renders rolling visits and column header Visits 30d instead of Visits MTD in Chillin table', function () {
        Carbon::setTestNow('2026-09-19 12:00:00');

        $tag = Tag::factory()->create(['name' => 'Shared']);
        $server = Server::factory()->create(['name' => 'shared-chillin.example.com']);
        $server->tags()->attach($tag);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'chillin-site.example.com',
            'is_inactive' => false,
        ]);

        // 10,000 visits last month (2026-08-25, within 30d rolling window from 2026-08-21 to 2026-09-19)
        SiteTrafficDaily::factory()->create([
            'site_id' => $site->id,
            'date' => '2026-08-25',
            'visits' => 10_000,
        ]);

        // 1,000 visits this month (2026-09-05)
        SiteTrafficDaily::factory()->create([
            'site_id' => $site->id,
            'date' => '2026-09-05',
            'visits' => 1_000,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('capacity.index'));

        $response->assertOk();
        // Column header must be Visits 30d and NOT Visits MTD
        $response->assertSee('Visits 30d');
        $response->assertDontSee('>Visits MTD<', false);
        // Headroom row displays the rolling 11,000 visits, not just the calendar MTD 1,000
        $response->assertSee('11,000');

        Carbon::setTestNow();
    });

    it('displays billable sites without invented cap when Pressable API reports maxBillable and maxSites <= 0', function () {
        Cache::forget('pressable.capacity.account_summary');
        Cache::forget('pressable.capacity.account_summary.negative');

        $this->mock(PressableClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('account')->andReturn([
                'productName' => 'Agency 1',
                'organization' => 'ClockworkWP',
                'capacity' => [
                    'sites' => [
                        'billable' => 158,
                        'staging' => 0,
                        'total' => 158,
                        'maxBillable' => 0,
                        'maxStaging' => 0,
                    ],
                ],
                'maxSites' => 0,
                'sitesCount' => 158,
            ]);
        });

        Site::factory()->create([
            'hosting_provider' => 'pressable',
            'domain' => 'pressable-nocap.example.com',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('capacity.index'));

        $response->assertOk();
        $response->assertSee('158');
        $response->assertDontSee('/ 100');

        Cache::forget('pressable.capacity.account_summary');
    });

    it('excludes inactive shared sites from capacity over-quota and trending tables', function () {
        Carbon::setTestNow('2026-09-19 12:00:00');

        $tag = Tag::factory()->create(['name' => 'Shared']);
        $server = Server::factory()->create(['name' => 'shared-quota.example.com']);
        $server->tags()->attach($tag);

        $inactiveSite = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'inactive-high-traffic.example.com',
            'is_inactive' => true,
        ]);

        SiteTrafficDaily::factory()->create([
            'site_id' => $inactiveSite->id,
            'date' => '2026-09-10',
            'visits' => 45_000,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('capacity.index'));

        $response->assertOk();
        $response->assertDontSee('inactive-high-traffic.example.com');

        Carbon::setTestNow();
    });

    it('scopes hot-server counting in IssueCounter strictly to shared non-ignored servers', function () {
        $sharedTag = Tag::factory()->create(['name' => 'Shared']);

        $nonSharedServer = Server::factory()->create([
            'name' => 'dedicated-hot.example.com',
            'is_ignored' => false,
        ]);

        $sharedServer = Server::factory()->create([
            'name' => 'shared-hot.example.com',
            'is_ignored' => false,
        ]);
        $sharedServer->tags()->attach($sharedTag);

        // Put non-shared server over CPU yellow threshold (85% > 70%)
        DB::table('server_metrics')->insert([
            'server_id' => $nonSharedServer->id,
            'cpu_pct' => 85.0,
            'memory_pct' => 40.0,
            'disk_pct' => 50.0,
            'recorded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $counter = app(\App\Support\IssueCounter::class);

        // Non-shared server over CPU threshold must NOT count toward hot servers
        expect($counter->countHotServers())->toBe(0);

        // Now put shared server over CPU threshold (85% > 70%)
        DB::table('server_metrics')->insert([
            'server_id' => $sharedServer->id,
            'cpu_pct' => 85.0,
            'memory_pct' => 40.0,
            'disk_pct' => 50.0,
            'recorded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Shared server over threshold DOES increment hot server count
        expect($counter->countHotServers())->toBe(1);
    });
});
