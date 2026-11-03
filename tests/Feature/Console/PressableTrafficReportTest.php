<?php

namespace Tests\Feature\Console;

use App\Models\Site;
use App\Models\SiteTrafficDaily;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Pressable\PressableClient;

/*
|--------------------------------------------------------------------------
| Phase 6 console-command coverage: clockwork:pressable-traffic-report
|--------------------------------------------------------------------------
|
| Builds a 30-day daily traffic rollup from three separate PressableClient
| ->siteMetrics() calls (requests+http_status, uniques+hostname — both
| "Past 1 month" — and requests+path for "Past 1 day" top-paths) and POSTs
| it to each Companion-equipped Pressable site's /traffic-report endpoint.
| Independently of that push, every Pressable site gets the days Pressable
| actually returned upserted into site_traffic_daily (the client-report
| history) — never the zero-filled days of the Companion payload.
| PressableClient is mocked directly as a collaborator (same reasoning as
| PressableBackupsReportTest's docblock) since the three calls differ only
| by argument, not by method name — each is matched here via a distinct
| ->with() expectation. app.timezone is UTC in this suite (config/app.php),
| matching PressableTrafficReport's own use of gmdate() for bucketing, so
| Carbon::today() and gmdate('Y-m-d', $ts) agree on "today" without any
| timezone gymnastics.
*/

function ptrSite(array $overrides = []): Site
{
    return Site::factory()->pressable()->withCompanionInstalled()->create(array_merge([
        'companion_capabilities' => ['traffic-report'],
    ], $overrides));
}

/**
 * Wires up the three distinct siteMetrics() call shapes the command makes,
 * each matched by its own ->with() so mismatched args fail loudly rather
 * than silently returning the wrong fixture. The top-paths call only feeds
 * the Companion payload, so it happens once when the site gets the push and
 * never when it doesn't.
 */
function ptrMockPressable(Site $site, ?callable $configure = null, bool $pushes = true): void
{
    test()->mock(PressableClient::class, function ($mock) use ($site, $configure, $pushes) {
        $mock->shouldReceive('isConfigured')->andReturn(true);

        $today = Carbon::today();
        $threeDaysAgo = Carbon::today()->subDays(3);

        $mock->shouldReceive('siteMetrics')
            ->with($site->pressable_site_id, ['requests'], ['http_status'], 'Past 1 month')
            ->andReturn([
                ['timestamp' => $today->copy()->setTime(10, 0)->timestamp, 'dimension' => ['200' => 30, '404' => 5]],
                ['timestamp' => $threeDaysAgo->copy()->setTime(9, 0)->timestamp, 'dimension' => ['200' => 10]],
            ]);

        $mock->shouldReceive('siteMetrics')
            ->with($site->pressable_site_id, ['uniques'], ['hostname'], 'Past 1 month')
            ->andReturn([
                ['timestamp' => $today->copy()->setTime(10, 0)->timestamp, 'dimension' => ['example.com' => 25, 'www.example.com' => 15]],
                ['timestamp' => $threeDaysAgo->copy()->setTime(9, 0)->timestamp, 'dimension' => ['example.com' => 10]],
            ]);

        $mock->shouldReceive('siteMetrics')
            ->with($site->pressable_site_id, ['requests'], ['path'], 'Past 1 day')
            ->times($pushes ? 1 : 0)
            ->andReturn([
                ['dimension' => ['/' => 100, '/wp-admin/' => 20, '/wp-content/uploads/img.jpg' => 5]],
            ]);

        if ($configure) {
            $configure($mock);
        }
    });
}

function ptrFakeTraffic(Site $site, bool $ok = true, int $status = 200): void
{
    Http::fake([
        "https://{$site->domain}/wp-json/clockwork/v1/traffic-report*" => Http::response(
            ['ok' => $ok],
            $status,
            ['Content-Type' => 'application/json'],
        ),
    ]);
}

/**
 * Mocks the two "Past 1 month" series (requests by status, uniques by
 * hostname) with the given periods; the top-paths call returns nothing.
 *
 * @param  list<array<string, mixed>>  $requestPeriods
 * @param  list<array<string, mixed>>  $uniquePeriods
 */
function ptrMockSeries(Site $site, array $requestPeriods, array $uniquePeriods): void
{
    test()->mock(PressableClient::class, function ($mock) use ($site, $requestPeriods, $uniquePeriods) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('siteMetrics')->with($site->pressable_site_id, ['requests'], ['http_status'], 'Past 1 month')->andReturn($requestPeriods);
        $mock->shouldReceive('siteMetrics')->with($site->pressable_site_id, ['uniques'], ['hostname'], 'Past 1 month')->andReturn($uniquePeriods);
        $mock->shouldReceive('siteMetrics')->with($site->pressable_site_id, ['requests'], ['path'], 'Past 1 day')->andReturn([]);
    });
}

/**
 * Seeds a site_traffic_daily row the way MySQL's DATE column stores it (a
 * bare Y-m-d), so the command's (site_id, date) upsert key matches it on
 * SQLite too.
 *
 * @param  array<string, mixed>  $values
 */
function ptrSeedDay(Site $site, Carbon $day, array $values): void
{
    DB::table('site_traffic_daily')->insert(array_merge([
        'site_id' => $site->id,
        'date' => $day->toDateString(),
        'requests' => 0,
        'unique_ips' => 0,
        'visits' => 0,
        'bytes_sent' => 0,
        'status_2xx' => 0,
        'status_3xx' => 0,
        'status_4xx' => 0,
        'status_5xx' => 0,
        'created_at' => now()->subMonth(),
        'updated_at' => now()->subMonth(),
    ], $values));
}

function ptrRow(Site $site, Carbon $day): ?SiteTrafficDaily
{
    return SiteTrafficDaily::where('site_id', $site->id)
        ->whereDate('date', $day->toDateString())
        ->first();
}

describe('clockwork:pressable-traffic-report — happy path', function () {
    it('aggregates Edge Logs + Uniques&Views metrics into a 30-day rollup with correct totals and categorised top paths', function () {
        $site = ptrSite();
        ptrMockPressable($site);
        ptrFakeTraffic($site);

        $this->artisan('clockwork:pressable-traffic-report')->assertSuccessful();

        Http::assertSent(function ($request) use ($site) {
            if ($request->url() !== "https://{$site->domain}/wp-json/clockwork/v1/traffic-report") {
                return false;
            }
            $body = json_decode($request->body(), true);

            return $body['source'] === 'pressable'
                && count($body['daily']) === 30
                && $body['totals']['today'] === 40
                && $body['totals']['week_7d'] === 50
                && $body['totals']['month_30d'] === 50
                && $body['has_data'] === true
                && $body['today_partial'] === true
                && $body['top_paths']['pages'][0] === ['path' => '/', 'hits' => 100]
                && $body['top_paths']['api'][0] === ['path' => '/wp-admin/', 'hits' => 20]
                && $body['top_paths']['uploads'][0] === ['path' => '/wp-content/uploads/img.jpg', 'hits' => 5]
                && $body['top_paths_date'] === Carbon::today()->toDateString();
        });

        // Only the two days Pressable returned are stored — not the 28
        // zero-filled ones the Companion chart needs.
        expect(SiteTrafficDaily::where('site_id', $site->id)->count())->toBe(2);

        $today = ptrRow($site, Carbon::today());
        expect($today->requests)->toBe(35)
            ->and($today->status_2xx)->toBe(30)
            ->and($today->status_4xx)->toBe(5)
            ->and($today->visits)->toBe(40)
            ->and($today->unique_ips)->toBe(40)
            ->and($today->bytes_sent)->toBe(0);

        $earlier = ptrRow($site, Carbon::today()->subDays(3));
        expect($earlier->requests)->toBe(10)
            ->and($earlier->visits)->toBe(10);
    });

    it('reports has_data=false and zeroed totals when Pressable returns no metrics at all', function () {
        $site = ptrSite();

        $this->mock(PressableClient::class, function ($mock) use ($site) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('siteMetrics')->with($site->pressable_site_id, ['requests'], ['http_status'], 'Past 1 month')->andReturn([]);
            $mock->shouldReceive('siteMetrics')->with($site->pressable_site_id, ['uniques'], ['hostname'], 'Past 1 month')->andReturn([]);
            $mock->shouldReceive('siteMetrics')->with($site->pressable_site_id, ['requests'], ['path'], 'Past 1 day')->andReturn([]);
        });

        ptrFakeTraffic($site);

        $this->artisan('clockwork:pressable-traffic-report')->assertSuccessful();

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['has_data'] === false
                && count($body['daily']) === 30
                && $body['totals'] === ['today' => 0, 'week_7d' => 0, 'month_30d' => 0]
                && $body['top_paths'] === ['pages' => [], 'api' => [], 'uploads' => []];
        });

        expect(SiteTrafficDaily::where('site_id', $site->id)->count())->toBe(0);
    });

    it('records history but does not push for a site that does not advertise the traffic-report capability', function () {
        $site = ptrSite(['companion_capabilities' => ['snapshot']]);
        ptrMockPressable($site, pushes: false);

        Http::fake();

        $this->artisan('clockwork:pressable-traffic-report')
            ->expectsOutputToContain("capability 'traffic-report' not advertised")
            ->assertSuccessful();

        Http::assertNothingSent();
        expect(SiteTrafficDaily::where('site_id', $site->id)->count())->toBe(2);
    });

    it('records history for a Pressable site without Companion, without trying to push', function () {
        $site = Site::factory()->pressable()->create();
        ptrMockPressable($site, pushes: false);

        Http::fake();

        $this->artisan('clockwork:pressable-traffic-report')
            ->expectsOutputToContain('push skipped: Companion not installed')
            ->assertSuccessful();

        Http::assertNothingSent();
        expect(ptrRow($site, Carbon::today())->requests)->toBe(35)
            ->and(ptrRow($site, Carbon::today()->subDays(3))->visits)->toBe(10);
    });

    it('ignores sites that are not on Pressable or have no Pressable site id', function () {
        Site::factory()->spinupwp()->withCompanionInstalled()->create();
        Site::factory()->pressable()->withCompanionInstalled()->create(['pressable_site_id' => null]);

        $this->mock(PressableClient::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('siteMetrics')->never();
        });

        Http::fake();

        $this->artisan('clockwork:pressable-traffic-report')
            ->expectsOutputToContain('No Pressable sites matched.')
            ->assertSuccessful();

        Http::assertNothingSent();
        expect(SiteTrafficDaily::count())->toBe(0);
    });
});

describe('clockwork:pressable-traffic-report — stored history', function () {
    it('leaves days Pressable returned nothing for untouched, so earlier history is never overwritten with zeros', function () {
        $site = ptrSite();
        $missing = Carbon::today()->subDays(5);
        $returned = Carbon::today()->subDays(3);

        // Rows an earlier host's access-log rollup wrote.
        ptrSeedDay($site, $missing, [
            'requests' => 500, 'unique_ips' => 60, 'visits' => 50, 'bytes_sent' => 1234567,
            'status_2xx' => 480, 'status_4xx' => 20, 'top_paths' => json_encode([['path' => '/', 'hits' => 9]]),
        ]);
        ptrSeedDay($site, $returned, [
            'requests' => 999, 'visits' => 99, 'bytes_sent' => 7654321,
            'top_paths' => json_encode([['path' => '/about/', 'hits' => 4]]),
        ]);

        ptrMockPressable($site);
        ptrFakeTraffic($site);

        $this->artisan('clockwork:pressable-traffic-report')->assertSuccessful();

        $untouched = ptrRow($site, $missing);
        expect($untouched->requests)->toBe(500)
            ->and($untouched->visits)->toBe(50)
            ->and($untouched->unique_ips)->toBe(60)
            ->and($untouched->bytes_sent)->toBe(1234567)
            ->and($untouched->status_2xx)->toBe(480)
            ->and($untouched->status_4xx)->toBe(20)
            ->and($untouched->top_paths)->toBe([['path' => '/', 'hits' => 9]]);

        // A returned day takes Pressable's counts but keeps what Pressable
        // has no per-day figure for.
        $updated = ptrRow($site, $returned);
        expect($updated->requests)->toBe(10)
            ->and($updated->visits)->toBe(10)
            ->and($updated->bytes_sent)->toBe(7654321)
            ->and($updated->top_paths)->toBe([['path' => '/about/', 'hits' => 4]]);

        // The Companion chart still gets the full zero-filled series.
        Http::assertSent(fn ($request) => count(json_decode($request->body(), true)['daily']) === 30);
    });

    it('updates only the metric family Pressable returned for a day', function () {
        $site = ptrSite();
        $requestsOnly = Carbon::today()->subDays(2);
        $uniquesOnly = Carbon::today()->subDays(4);

        ptrSeedDay($site, $requestsOnly, ['requests' => 300, 'status_2xx' => 300, 'visits' => 77, 'unique_ips' => 88]);
        ptrSeedDay($site, $uniquesOnly, ['requests' => 400, 'status_2xx' => 390, 'status_5xx' => 10, 'visits' => 66, 'unique_ips' => 70]);

        ptrMockSeries(
            $site,
            requestPeriods: [['timestamp' => $requestsOnly->copy()->setTime(8, 0)->timestamp, 'dimension' => ['200' => 12, '301' => 3]]],
            uniquePeriods: [['timestamp' => $uniquesOnly->timestamp, 'dimension' => ['example.com' => 21]]],
        );
        ptrFakeTraffic($site);

        $this->artisan('clockwork:pressable-traffic-report')->assertSuccessful();

        $a = ptrRow($site, $requestsOnly);
        expect($a->requests)->toBe(15)
            ->and($a->status_2xx)->toBe(12)
            ->and($a->status_3xx)->toBe(3)
            ->and($a->visits)->toBe(77)
            ->and($a->unique_ips)->toBe(88);

        $b = ptrRow($site, $uniquesOnly);
        expect($b->requests)->toBe(400)
            ->and($b->status_2xx)->toBe(390)
            ->and($b->status_5xx)->toBe(10)
            ->and($b->visits)->toBe(21)
            ->and($b->unique_ips)->toBe(21);
    });

    it('treats empty buckets as no data', function () {
        $site = ptrSite();
        $seeded = Carbon::today()->subDays(6);
        $unseeded = Carbon::today()->subDays(7);

        ptrSeedDay($site, $seeded, ['requests' => 250, 'visits' => 25, 'unique_ips' => 30, 'status_2xx' => 250]);

        ptrMockSeries(
            $site,
            requestPeriods: [
                ['timestamp' => $seeded->copy()->setTime(8, 0)->timestamp, 'dimension' => []],
                ['timestamp' => $unseeded->copy()->setTime(8, 0)->timestamp, 'dimension' => []],
            ],
            uniquePeriods: [
                ['timestamp' => $seeded->timestamp, 'dimension' => []],
                ['timestamp' => $unseeded->timestamp],
            ],
        );
        ptrFakeTraffic($site);

        $this->artisan('clockwork:pressable-traffic-report')->assertSuccessful();

        $row = ptrRow($site, $seeded);
        expect($row->requests)->toBe(250)
            ->and($row->visits)->toBe(25)
            ->and($row->unique_ips)->toBe(30)
            ->and(ptrRow($site, $unseeded))->toBeNull();
    });

    it('stores only the last 28 days, while the Companion payload still carries all 30', function () {
        $site = ptrSite();
        $oldest = Carbon::today()->subDays(29);
        $edge = Carbon::today()->subDays(27);

        ptrMockSeries(
            $site,
            requestPeriods: [
                ['timestamp' => $oldest->copy()->setTime(16, 0)->timestamp, 'dimension' => ['200' => 7]],
                ['timestamp' => $edge->copy()->setTime(16, 0)->timestamp, 'dimension' => ['200' => 9]],
            ],
            uniquePeriods: [
                ['timestamp' => $oldest->timestamp, 'dimension' => ['example.com' => 3]],
                ['timestamp' => $edge->timestamp, 'dimension' => ['example.com' => 4]],
            ],
        );
        ptrFakeTraffic($site);

        $this->artisan('clockwork:pressable-traffic-report')->assertSuccessful();

        expect(ptrRow($site, $oldest))->toBeNull()
            ->and(ptrRow($site, $edge)->requests)->toBe(9)
            ->and(SiteTrafficDaily::where('site_id', $site->id)->count())->toBe(1);

        Http::assertSent(function ($request) use ($oldest) {
            $daily = collect(json_decode($request->body(), true)['daily'])->keyBy('date');

            return $daily->count() === 30
                && $daily->get($oldest->toDateString())['requests'] === 7
                && $daily->get($oldest->toDateString())['visits'] === 3;
        });
    });
});

describe('clockwork:pressable-traffic-report — skip and failure handling', function () {
    it('exits FAILURE immediately when Pressable is not configured, without touching any site', function () {
        ptrSite();

        $this->mock(PressableClient::class, fn ($mock) => $mock->shouldReceive('isConfigured')->andReturn(false));

        Http::fake();

        $this->artisan('clockwork:pressable-traffic-report')->assertFailed();

        Http::assertNothingSent();
    });

    it('logs and continues (still SUCCESS overall) when the Pressable metrics fetch fails for a site, without pushing to Companion', function () {
        Log::spy();
        $site = ptrSite();

        $this->mock(PressableClient::class, function ($mock) use ($site) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('siteMetrics')
                ->with($site->pressable_site_id, ['requests'], ['http_status'], 'Past 1 month')
                ->andThrow(new \RuntimeException('Pressable 503'));
        });

        Http::fake();

        $this->artisan('clockwork:pressable-traffic-report')->assertSuccessful();

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->with('companion.pressable_traffic_report.fetch_failed', \Mockery::type('array'));
        expect(SiteTrafficDaily::where('site_id', $site->id)->count())->toBe(0);
    });

    it('logs and continues (still SUCCESS overall) when the push to Companion itself fails, keeping the recorded history', function () {
        Log::spy();
        $site = ptrSite();
        ptrMockPressable($site);

        ptrFakeTraffic($site, ok: false, status: 500);

        $this->artisan('clockwork:pressable-traffic-report')->assertSuccessful();

        Log::shouldHaveReceived('warning')->with('companion.pressable_traffic_report.push_failed', \Mockery::type('array'));
        expect(SiteTrafficDaily::where('site_id', $site->id)->count())->toBe(2);
    });
});

describe('clockwork:pressable-traffic-report — options', function () {
    it('--site limits the run to a single site by domain', function () {
        $target = ptrSite();
        $other = ptrSite();

        $this->mock(PressableClient::class, function ($mock) use ($target) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('siteMetrics')->with($target->pressable_site_id, ['requests'], ['http_status'], 'Past 1 month')->andReturn([]);
            $mock->shouldReceive('siteMetrics')->with($target->pressable_site_id, ['uniques'], ['hostname'], 'Past 1 month')->andReturn([]);
            $mock->shouldReceive('siteMetrics')->with($target->pressable_site_id, ['requests'], ['path'], 'Past 1 day')->andReturn([]);
        });

        ptrFakeTraffic($target);

        $this->artisan('clockwork:pressable-traffic-report', ['--site' => $target->domain])->assertSuccessful();

        Http::assertSent(fn ($request) => strtok($request->url(), '?') === "https://{$target->domain}/wp-json/clockwork/v1/traffic-report");
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $other->domain));
    });
});

describe('clockwork:pressable-traffic-report — schedule', function () {
    it('runs early enough to finish before the client-report run that reads its rows', function () {
        $find = fn (string $command): Event => collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_ends_with((string) $event->command, $command));

        $from = Carbon::parse('2030-03-01 00:00:00', 'UTC');
        $traffic = Carbon::instance($find('clockwork:pressable-traffic-report')->nextRunDate($from));
        $reports = Carbon::instance($find('clockwork:send-client-reports')->nextRunDate($from));

        // Same morning, at least an hour ahead of the reports (the run takes
        // ~15-20 min), and late enough that the previous UTC day is complete.
        expect($traffic->toDateString())->toBe($reports->toDateString())
            ->and($traffic->diffInMinutes($reports))->toBeGreaterThanOrEqual(60)
            ->and($from->diffInMinutes($traffic))->toBeGreaterThanOrEqual(120);
    });
});
