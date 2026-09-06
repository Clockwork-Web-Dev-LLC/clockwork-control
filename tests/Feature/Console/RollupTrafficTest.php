<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteTrafficDaily;
use App\Models\ThreatLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Behavioral coverage for RollupTraffic
|--------------------------------------------------------------------------
|
| RollupTraffic's aggregation queries are inline raw SQL on the command
| itself (selectRaw/whereRaw with JSON_EXTRACT/JSON_UNQUOTE and MySQL
| REGEXP), not an injectable collaborator, so there's no service to mock
| away — same "inline raw SQL, not a service" situation documented in
| CapacityControllerTest for UNIX_TIMESTAMP(). The equivalent shim here is
| registering REGEXP and JSON_UNQUOTE as real functions on the sqlite PDO
| connection so the queries execute with MySQL-equivalent semantics:
|   - JSON_EXTRACT already works natively in sqlite (built-in JSON1), and
|     already returns the unquoted scalar, so JSON_UNQUOTE is a no-op shim.
|   - REGEXP has no sqlite equivalent at all, so it's implemented via PCRE
|     (the patterns used here — the static-asset extension list and the
|     sitemap.xml api-bucket pattern — are plain PCRE-compatible strings,
|     so no MySQL-ERE-specific translation is needed for these tests).
*/
beforeEach(function () {
    $pdo = DB::connection()->getPdo();

    $pdo->sqliteCreateFunction('REGEXP', function ($pattern, $subject) {
        return $subject !== null && @preg_match('~'.$pattern.'~i', $subject) === 1;
    });

    $pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($value) {
        return $value;
    });
});

function rtSite(): Site
{
    // Hostname deliberately outside the 1.x/2.x/... test IP ranges used
    // below, so FleetSelfIps::list() never accidentally excludes a test IP.
    // (hostname+ssh_port is unique, so each call needs a distinct value.)
    static $n = 0;
    $n++;
    $server = Server::factory()->create(['hostname' => "10.0.{$n}.99"]);

    return Site::factory()->spinupwp()->create(['server_id' => $server->id]);
}

function rtLog(Site $site, Carbon $eventAt, array $overrides = []): ThreatLog
{
    return ThreatLog::factory()->create(array_merge([
        'site_id' => $site->id,
        'event_at' => $eventAt,
    ], $overrides));
}

describe('RollupTraffic', function () {
    it('aggregates requests, unique IPs, status buckets, and the bot/static-asset-excluded visit count for a single day', function () {
        $site = rtSite();
        $today = Carbon::today()->addHours(12);

        rtLog($site, $today, ['ip' => '1.1.1.1', 'request_path' => '/', 'status_code' => 200]);
        rtLog($site, $today, ['ip' => '1.1.1.1', 'request_path' => '/about', 'status_code' => 200]);
        rtLog($site, $today, ['ip' => '2.2.2.2', 'request_path' => '/wp-login.php', 'status_code' => 403]);
        rtLog($site, $today, ['ip' => '3.3.3.3', 'request_path' => '/wp-content/uploads/img.jpg', 'status_code' => 200]);
        rtLog($site, $today, ['ip' => '4.4.4.4', 'request_path' => '/wp-json/wp/v2/posts', 'status_code' => 200]);
        rtLog($site, $today, ['ip' => '5.5.5.5', 'request_path' => '/some/404', 'status_code' => 404]);

        $this->artisan('clockwork:rollup-traffic', ['--backfill' => 1])->assertSuccessful();

        $row = SiteTrafficDaily::query()->where('site_id', $site->id)->where('date', Carbon::today()->toDateString())->first();

        expect($row)->not->toBeNull()
            ->and($row->requests)->toBe(6)
            ->and($row->unique_ips)->toBe(5)
            ->and($row->status_2xx)->toBe(4)
            ->and($row->status_3xx)->toBe(0)
            ->and($row->status_4xx)->toBe(2)
            ->and($row->status_5xx)->toBe(0)
            // visits = distinct IPs excluding 403s and static-asset paths:
            // 1.1.1.1 (both its hits collapse to one IP), 4.4.4.4, 5.5.5.5 — not
            // 2.2.2.2 (403) and not 3.3.3.3 (the .jpg static-asset path).
            ->and($row->visits)->toBe(3);

        expect($row->top_paths['uploads'])->toHaveCount(1)
            ->and($row->top_paths['uploads'][0]['path'])->toBe('/wp-content/uploads/img.jpg')
            ->and($row->top_paths['api'])->toHaveCount(1)
            ->and($row->top_paths['api'][0]['path'])->toBe('/wp-json/wp/v2/posts')
            ->and($row->top_paths['pages'])->toHaveCount(4);
    });

    it('writes one rollup row per day across the backfill window', function () {
        $site = rtSite();
        $today = Carbon::today()->addHours(10);
        $yesterday = Carbon::yesterday()->addHours(10);

        rtLog($site, $today, ['ip' => '1.1.1.1']);
        rtLog($site, $yesterday, ['ip' => '2.2.2.2']);
        rtLog($site, $yesterday, ['ip' => '2.2.2.2']);

        $this->artisan('clockwork:rollup-traffic', ['--backfill' => 2])->assertSuccessful();

        expect(SiteTrafficDaily::query()->where('site_id', $site->id)->count())->toBe(2);

        $todayRow = SiteTrafficDaily::query()->where('site_id', $site->id)->where('date', $today->toDateString())->first();
        $yesterdayRow = SiteTrafficDaily::query()->where('site_id', $site->id)->where('date', $yesterday->toDateString())->first();

        expect($todayRow->requests)->toBe(1)
            ->and($yesterdayRow->requests)->toBe(2)
            ->and($yesterdayRow->unique_ips)->toBe(1);
    });

    it('is idempotent — re-running upserts instead of duplicating rows, and reflects newly ingested rows', function () {
        $site = rtSite();
        $today = Carbon::today()->addHours(9);

        rtLog($site, $today, ['ip' => '1.1.1.1']);
        $this->artisan('clockwork:rollup-traffic', ['--backfill' => 1])->assertSuccessful();

        expect(SiteTrafficDaily::query()->where('site_id', $site->id)->count())->toBe(1);
        expect(SiteTrafficDaily::query()->where('site_id', $site->id)->first()->requests)->toBe(1);

        rtLog($site, $today, ['ip' => '9.9.9.9']);
        $this->artisan('clockwork:rollup-traffic', ['--backfill' => 1])->assertSuccessful();

        // Still exactly one row for the day (upsert on [site_id, date]), now
        // reflecting both requests.
        expect(SiteTrafficDaily::query()->where('site_id', $site->id)->count())->toBe(1);
        expect(SiteTrafficDaily::query()->where('site_id', $site->id)->first()->requests)->toBe(2);
    });

    it('limits to a single site via --site and leaves other sites untouched', function () {
        $target = rtSite();
        $other = rtSite();
        $today = Carbon::today()->addHours(8);

        rtLog($target, $today, ['ip' => '1.1.1.1']);
        rtLog($other, $today, ['ip' => '2.2.2.2']);

        $this->artisan('clockwork:rollup-traffic', ['--backfill' => 1, '--site' => $target->domain])->assertSuccessful();

        expect(SiteTrafficDaily::query()->where('site_id', $target->id)->count())->toBe(1)
            ->and(SiteTrafficDaily::query()->where('site_id', $other->id)->count())->toBe(0);
    });

    it('writes nothing for a day with no threat_logs rows, and reports SUCCESS with zero sites when none match', function () {
        rtSite();

        $this->artisan('clockwork:rollup-traffic', ['--backfill' => 1])->assertSuccessful();

        expect(SiteTrafficDaily::query()->count())->toBe(0);

        $this->artisan('clockwork:rollup-traffic', ['--site' => 'no-such-domain.example.com'])
            ->expectsOutputToContain('No sites matched.')
            ->assertSuccessful();
    });
});
