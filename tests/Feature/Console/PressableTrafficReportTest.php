<?php

namespace Tests\Feature\Console;

use App\Models\Site;
use Illuminate\Support\Carbon;
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
 * than silently returning the wrong fixture.
 */
function ptrMockPressable(Site $site, ?callable $configure = null): void
{
    test()->mock(PressableClient::class, function ($mock) use ($site, $configure) {
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
        "https://{$site->domain}/wp-json/clockwork/v1/traffic-report" => Http::response(
            ['ok' => $ok],
            $status,
            ['Content-Type' => 'application/json'],
        ),
    ]);
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
                && $body['totals'] === ['today' => 0, 'week_7d' => 0, 'month_30d' => 0]
                && $body['top_paths'] === ['pages' => [], 'api' => [], 'uploads' => []];
        });
    });

    it('skips a site that does not advertise the traffic-report capability', function () {
        $site = ptrSite(['companion_capabilities' => ['snapshot']]);

        $this->mock(PressableClient::class, fn ($mock) => $mock->shouldReceive('isConfigured')->andReturn(true));

        Http::fake();

        $this->artisan('clockwork:pressable-traffic-report')->assertSuccessful();

        Http::assertNothingSent();
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
    });

    it('logs and continues (still SUCCESS overall) when the push to Companion itself fails', function () {
        Log::spy();
        $site = ptrSite();
        ptrMockPressable($site);

        ptrFakeTraffic($site, ok: false, status: 500);

        $this->artisan('clockwork:pressable-traffic-report')->assertSuccessful();

        Log::shouldHaveReceived('warning')->with('companion.pressable_traffic_report.push_failed', \Mockery::type('array'));
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

        Http::assertSent(fn ($request) => $request->url() === "https://{$target->domain}/wp-json/clockwork/v1/traffic-report");
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $other->domain));
    });
});
