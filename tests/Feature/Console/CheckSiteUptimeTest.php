<?php

namespace Tests\Feature\Console;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Phase 6 console coverage for clockwork:check-site-uptime
|--------------------------------------------------------------------------
|
| The uptime_monitoring_enabled gate is already covered end-to-end in
| tests/Feature/Uptime/UptimeStateMachineTest.php (a real $this->artisan()
| call), and UptimeStateUpdater's transition logic is deeply covered there
| too — this file does NOT re-test either. What's still a genuine gap in
| the command's own CLI entry point: the --site option, the hostMonitored
| (ignored-server) exclusion, and the probe-throws catch/synthesize path
| that keeps a DNS failure or socket error from silently skipping a site.
*/

describe('clockwork:check-site-uptime', function () {
    it('--site limits the run to a single site, leaving every other enabled site untouched', function () {
        Http::fake(['*' => Http::response(str_repeat('Welcome to this monitored homepage. ', 20), 200)]);

        $target = Site::factory()->create([
            'domain' => 'only-me.example.test',
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'unknown',
        ]);
        $other = Site::factory()->create([
            'domain' => 'not-me.example.test',
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'unknown',
        ]);

        $this->artisan('clockwork:check-site-uptime', ['--site' => (string) $target->id])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'only-me.example.test'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'not-me.example.test'));

        expect($target->refresh()->uptime_last_checked_at)->not->toBeNull()
            ->and($other->refresh()->uptime_last_checked_at)->toBeNull();
    });

    it('excludes sites on ignored/staging servers via the hostMonitored scope', function () {
        Http::fake(['*' => Http::response(str_repeat('Welcome to this monitored homepage. ', 20), 200)]);

        $ignoredServer = Server::factory()->ignored()->create();
        Site::factory()->create([
            'domain' => 'staging-host.example.test',
            'server_id' => $ignoredServer->id,
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'unknown',
        ]);
        $monitored = Site::factory()->create([
            'domain' => 'prod-host.example.test',
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'unknown',
        ]);

        $this->artisan('clockwork:check-site-uptime')->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'staging-host.example.test'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'prod-host.example.test'));
        expect($monitored->refresh()->uptime_last_checked_at)->not->toBeNull();
    });

    it('catches a probe exception (DNS/socket failure), records it as transport-failed, and keeps going instead of crashing', function () {
        $unreachable = Site::factory()->create([
            'domain' => 'dns-fail.example.test',
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'up',
            'uptime_consecutive_failures' => 0,
        ]);
        $reachable = Site::factory()->create([
            'domain' => 'still-fine.example.test',
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'unknown',
        ]);

        Http::fake([
            'dns-fail.example.test/*' => fn () => throw new ConnectionException('Could not resolve host: dns-fail.example.test'),
            '*' => Http::response(str_repeat('Welcome to this monitored homepage. ', 20), 200),
        ]);

        // The command's own handle() must not let the probe exception escape
        // — a real transport failure on one site (of ~150) must not abort
        // the whole nightly sweep.
        $this->artisan('clockwork:check-site-uptime')->assertSuccessful();

        $unreachable->refresh();
        $reachable->refresh();

        // FAILURE_THRESHOLD_FOR_DOWN is 2 (see UptimeStateMachineTest), so a
        // single synthesized failure ticks the streak up without flipping
        // state yet — proof the synthetic UptimeProbeResult actually ran
        // through UptimeStateUpdater rather than being silently dropped.
        expect($unreachable->uptime_consecutive_failures)->toBe(1)
            ->and($unreachable->uptime_state)->toBe('up')
            ->and($reachable->uptime_state)->toBe('up')
            ->and($reachable->uptime_last_checked_at)->not->toBeNull();
    });
});
