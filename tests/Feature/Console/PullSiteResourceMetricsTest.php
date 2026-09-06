<?php

namespace Tests\Feature\Console;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Phase 6 console coverage for clockwork:pull-site-metrics
|--------------------------------------------------------------------------
|
| The command builds a fresh ResourceMetricsIngestor (and, inside it, a
| fresh ClockworkCompanionClient) per site with no DI seam the test can
| intercept — so instead of mocking the collaborator, every test here
| Http::fakes the real Companion REST call the client makes
| (https://{domain}/wp-json/clockwork/v1/resource-report), verifying the
| command's targeting + real site_metrics writes end to end.
*/

function prmResourceReport(array $rows = []): array
{
    return ['ok' => true, 'rows' => $rows];
}

function prmRow(string $bucketAtIso, int $cpu = 120_000, int $wall = 400_000, int $mem = 33_554_432, int $requests = 42): array
{
    return [
        'bucket_at' => $bucketAtIso,
        'cpu_us_total' => $cpu,
        'wall_us_total' => $wall,
        'mem_peak_max' => $mem,
        'requests' => $requests,
    ];
}

describe('clockwork:pull-site-metrics', function () {
    it('pulls and writes real site_metrics rows for a Companion site advertising resource-sampler', function () {
        $site = Site::factory()->withCompanionInstalled()->create([
            'domain' => 'sampler.example.test',
            'companion_capabilities' => ['resource-sampler'],
        ]);

        $bucket = now()->startOfHour()->toIso8601String();
        Http::fake([
            'sampler.example.test/wp-json/clockwork/v1/resource-report*' => Http::response(
                prmResourceReport([prmRow($bucket)])
            ),
        ]);

        $this->artisan('clockwork:pull-site-metrics')->assertSuccessful();

        expect(DB::table('site_metrics')->where('site_id', $site->id)->count())->toBe(1);

        $row = DB::table('site_metrics')->where('site_id', $site->id)->first();
        expect($row->cpu_us_total)->toBe(120_000)
            ->and($row->requests)->toBe(42);

        expect($site->refresh()->resource_metrics_cursor_at)->not->toBeNull();
    });

    it('skips a Companion site that does not advertise resource-sampler, without calling out to it', function () {
        Site::factory()->withCompanionInstalled()->create([
            'domain' => 'no-sampler.example.test',
            'companion_capabilities' => ['malware-scan'],
        ]);

        Http::fake();

        $this->artisan('clockwork:pull-site-metrics')->assertSuccessful();

        Http::assertNothingSent();
        expect(DB::table('site_metrics')->count())->toBe(0);
    });

    it('skips sites without Companion installed entirely', function () {
        Site::factory()->create(['companion_installed' => false, 'domain' => 'no-companion.example.test']);

        Http::fake();

        $this->artisan('clockwork:pull-site-metrics')->assertSuccessful();

        Http::assertNothingSent();
    });

    it('--site limits the pull to a single site by domain', function () {
        $target = Site::factory()->withCompanionInstalled()->create([
            'domain' => 'target-metrics.example.test',
            'companion_capabilities' => ['resource-sampler'],
        ]);
        Site::factory()->withCompanionInstalled()->create([
            'domain' => 'other-metrics.example.test',
            'companion_capabilities' => ['resource-sampler'],
        ]);

        Http::fake([
            'target-metrics.example.test/*' => Http::response(prmResourceReport([prmRow(now()->toIso8601String())])),
            'other-metrics.example.test/*' => Http::response(prmResourceReport([prmRow(now()->toIso8601String())])),
        ]);

        $this->artisan('clockwork:pull-site-metrics', ['--site' => 'target-metrics.example.test'])->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'other-metrics.example.test'));
        expect(DB::table('site_metrics')->count())->toBe(1);
    });

    it('excludes Companion sites on ignored/staging servers', function () {
        $ignoredServer = Server::factory()->ignored()->create();
        Site::factory()->withCompanionInstalled()->create([
            'domain' => 'staging-metrics.example.test',
            'server_id' => $ignoredServer->id,
            'companion_capabilities' => ['resource-sampler'],
        ]);

        Http::fake();

        $this->artisan('clockwork:pull-site-metrics')->assertSuccessful();

        Http::assertNothingSent();
    });

    it('a single site erroring (ok=false from Companion) is reported but does not fail the whole run when others succeed', function () {
        $bad = Site::factory()->withCompanionInstalled()->create([
            'domain' => 'errors-out.example.test',
            'companion_capabilities' => ['resource-sampler'],
        ]);
        $good = Site::factory()->withCompanionInstalled()->create([
            'domain' => 'succeeds.example.test',
            'companion_capabilities' => ['resource-sampler'],
        ]);

        Http::fake([
            'errors-out.example.test/*' => Http::response(['ok' => false]),
            'succeeds.example.test/*' => Http::response(prmResourceReport([prmRow(now()->toIso8601String())])),
        ]);

        $this->artisan('clockwork:pull-site-metrics')->assertSuccessful();

        expect(DB::table('site_metrics')->where('site_id', $good->id)->count())->toBe(1)
            ->and(DB::table('site_metrics')->where('site_id', $bad->id)->count())->toBe(0);
    });

    it('exits FAILURE only when every site failed (systemic failure)', function () {
        Site::factory()->withCompanionInstalled()->create([
            'domain' => 'all-fail.example.test',
            'companion_capabilities' => ['resource-sampler'],
        ]);

        Http::fake(['all-fail.example.test/*' => Http::response(['ok' => false])]);

        $this->artisan('clockwork:pull-site-metrics')->assertFailed();
    });
});
