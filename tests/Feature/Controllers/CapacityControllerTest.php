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
use Illuminate\Support\Facades\DB;
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

        $response->assertOk()->assertSee('shared1.example.com');
        // Regression: capacity.index is the anchor route for the
        // "Operations & Tools" settings tier — the persistent two-tier
        // settings nav must render here too, not just on /capacity/settings.
        $response->assertSee('Operations & Tools')->assertSee('Fleet & Branding');
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
});
