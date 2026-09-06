<?php

namespace Tests\Feature\Console;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteTrafficDaily;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Phase 6 console-command coverage: clockwork:push-companion-traffic
|--------------------------------------------------------------------------
|
| Pulls the last 30 days of site_traffic_daily and POSTs the rollup to each
| Companion-equipped site's /traffic-report endpoint. Http::fake() asserts
| against the real Companion HMAC route pattern (see
| tests/Feature/Companion/CompanionHmacAuthTest.php) — no service
| collaborators to mock here, buildReport() reads directly from the DB.
|
| sqlite date-boundary gotcha (found writing this suite, not previously
| documented): SiteTrafficDaily::$date is cast 'date', but Eloquent's
| fromDateTime() always serializes through getDateFormat() ('Y-m-d H:i:s')
| regardless of cast type when SAVING — so a row created the normal
| Eloquent/factory way for "today" is persisted as "2026-09-03 00:00:00".
| On real MySQL the column's true DATE type coerces this to a bare date
| transparently, so buildReport()'s whereBetween(..., [$start->toDateString(),
| $today->toDateString()]) upper bound behaves correctly in production. On
| sqlite, columns have no type enforcement, so the WHERE clause does a raw
| string comparison — "2026-09-03 00:00:00" is lexicographically GREATER
| than the bare upper bound "2026-09-03" (it's a superstring of it), so a
| row dated exactly "today" is silently excluded from the 30-day window.
| Confirmed independently against a bare PDO sqlite connection before
| writing this note — it's a sqlite-test artifact, not a production bug.
| Every row below is inserted via pctInsertRow(), which uses the real
| factory for every field except 'date' (written directly as a bare
| 'Y-m-d' string to sidestep the quirk).
*/

function pctSite(array $overrides = []): Site
{
    $serverId = $overrides['server_id'] ?? Server::factory()->create(['last_ssh_ok_at' => now()])->id;

    return Site::factory()->withCompanionInstalled()->create(array_merge([
        'server_id' => $serverId,
        'companion_capabilities' => ['traffic-report'],
    ], $overrides));
}

/**
 * Inserts a site_traffic_daily row for an exact calendar date, using the
 * real factory for every field except 'date' (which is written directly
 * as a bare 'Y-m-d' string to avoid the sqlite-only whereBetween boundary
 * quirk documented above — see the file docblock).
 */
function pctInsertRow(Site $site, string $date, array $overrides = []): void
{
    $attrs = SiteTrafficDaily::factory()->make(array_merge(['site_id' => $site->id], $overrides))->getAttributes();
    $attrs['date'] = $date;
    $attrs['created_at'] = now()->format('Y-m-d H:i:s');
    $attrs['updated_at'] = now()->format('Y-m-d H:i:s');
    DB::table('site_traffic_daily')->insert($attrs);
}

function pctFakeTraffic(Site $site, bool $ok = true, int $status = 200): void
{
    Http::fake([
        "https://{$site->domain}/wp-json/clockwork/v1/traffic-report" => Http::response(
            ['ok' => $ok],
            $status,
            ['Content-Type' => 'application/json'],
        ),
    ]);
}

describe('clockwork:push-companion-traffic — happy path', function () {
    it('pushes a zero-filled 30-day rollup with correct totals for a site with rollup data', function () {
        $site = pctSite();

        pctInsertRow($site, now()->toDateString(), ['visits' => 40, 'requests' => 400]);
        pctInsertRow($site, now()->subDays(3)->toDateString(), ['visits' => 10, 'requests' => 100]);

        pctFakeTraffic($site);

        $this->artisan('clockwork:push-companion-traffic')->assertSuccessful();

        Http::assertSent(function ($request) use ($site) {
            if ($request->url() !== "https://{$site->domain}/wp-json/clockwork/v1/traffic-report") {
                return false;
            }
            $body = json_decode($request->body(), true);

            return $body['has_data'] === true
                && $body['totals']['today'] === 40
                && $body['totals']['month_30d'] === 50
                && count($body['daily']) === 30
                && $body['source'] === 'clockwork-monitoring';
        });
    });

    it('skips a site with no rollup rows in the last 30 days, without calling Companion', function () {
        $site = pctSite();
        // Row exists but is outside the 30-day window.
        pctInsertRow($site, now()->subDays(90)->toDateString());

        Http::fake();

        $this->artisan('clockwork:push-companion-traffic')->assertSuccessful();

        Http::assertNothingSent();
    });

    it('skips a site that does not advertise the traffic-report capability', function () {
        $site = pctSite(['companion_capabilities' => ['snapshot']]);
        pctInsertRow($site, now()->toDateString());

        Http::fake();

        $this->artisan('clockwork:push-companion-traffic')->assertSuccessful();

        Http::assertNothingSent();
    });

    it('carries top_paths from the most recent day that has non-empty data, in the categorised pages/api/uploads shape', function () {
        $site = pctSite();

        pctInsertRow($site, now()->subDay()->toDateString(), [
            'visits' => 5,
            'top_paths' => [
                'pages' => [['path' => '/', 'count' => 100]],
                'api' => [['path' => '/wp-json/x', 'count' => 20]],
                'uploads' => [],
            ],
        ]);
        pctInsertRow($site, now()->toDateString(), ['visits' => 5, 'top_paths' => null]);

        pctFakeTraffic($site);

        $this->artisan('clockwork:push-companion-traffic')->assertSuccessful();

        Http::assertSent(function ($request) use ($site) {
            if ($request->url() !== "https://{$site->domain}/wp-json/clockwork/v1/traffic-report") {
                return false;
            }
            $body = json_decode($request->body(), true);

            return $body['top_paths']['pages'][0]['path'] === '/'
                && $body['top_paths']['api'][0]['path'] === '/wp-json/x'
                && $body['top_paths_date'] === now()->subDay()->toDateString();
        });
    });
});

describe('clockwork:push-companion-traffic — failure handling', function () {
    it('logs and continues (still SUCCESS overall) when the push to Companion fails for a site', function () {
        Log::spy();
        $site = pctSite();
        pctInsertRow($site, now()->toDateString());

        pctFakeTraffic($site, ok: false, status: 500);

        $this->artisan('clockwork:push-companion-traffic')->assertSuccessful();

        Log::shouldHaveReceived('warning')->with('companion.traffic_report.push_failed', \Mockery::type('array'));
    });
});

describe('clockwork:push-companion-traffic — options', function () {
    it('--site limits the run to a single site by domain', function () {
        $target = pctSite();
        $other = pctSite();
        pctInsertRow($target, now()->toDateString());
        pctInsertRow($other, now()->toDateString());

        pctFakeTraffic($target);

        $this->artisan('clockwork:push-companion-traffic', ['--site' => $target->domain])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === "https://{$target->domain}/wp-json/clockwork/v1/traffic-report");
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $other->domain));
    });

    it('--server limits the run to sites on the named server', function () {
        $serverA = Server::factory()->create();
        $serverB = Server::factory()->create();
        $onA = pctSite(['server_id' => $serverA->id]);
        $onB = pctSite(['server_id' => $serverB->id]);
        pctInsertRow($onA, now()->toDateString());
        pctInsertRow($onB, now()->toDateString());

        pctFakeTraffic($onA);

        $this->artisan('clockwork:push-companion-traffic', ['--server' => $serverA->name])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === "https://{$onA->domain}/wp-json/clockwork/v1/traffic-report");
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $onB->domain));
    });

    it('pushes supported: false when site has no SSH configured', function () {
        $serverNoSsh = Server::factory()->create([
            'last_ssh_ok_at' => null,
            'ssh_private_key' => null,
            'ssh_password' => null,
        ]);
        $site = pctSite(['server_id' => $serverNoSsh->id]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/traffic-report" => Http::response(['ok' => true]),
        ]);

        $this->artisan('clockwork:push-companion-traffic', ['--site' => $site->domain])->assertSuccessful();

        Http::assertSent(function ($request) use ($site) {
            if ($request->url() !== "https://{$site->domain}/wp-json/clockwork/v1/traffic-report") {
                return false;
            }
            $body = json_decode($request->body(), true);

            return $body['supported'] === false
                && $body['source'] === 'clockwork-monitoring'
                && $body['reason'] === 'ssh_not_configured';
        });
    });
});
