<?php

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\WpConfigConstantInjector;

/*
|--------------------------------------------------------------------------
| EnsureCompanionTrustProxy — Phase 6 console coverage
|--------------------------------------------------------------------------
|
| WpConfigConstantInjector shells out over SSH (App\Services\Ssh\SshClient),
| so it's always mocked here — this suite must never attempt real SSH I/O.
| It's container-injected into the command, so a plain $this->mock() swap
| is enough (no Http::fake()/exec() shimming needed, unlike the Companion
| REST-client-based commands in this phase).
*/

function trustProxyCandidate(array $overrides = []): Site
{
    $server = Server::factory()->create();

    return Site::factory()->spinupwp()->create(array_merge([
        'server_id' => $server->id,
        'care_plan_enabled' => true,
        'auto_updates_paused' => false,
        'companion_installed' => true,
        'companion_secret' => null,
        'companion_snapshot' => ['plugins' => ['counts' => ['total' => 1]]],
        'cloudflare_state' => Site::CF_PROXIED,
    ], $overrides));
}

describe('clockwork:ensure-companion-trust-proxy', function () {
    it('injects the constant into every matching site and reports it as written', function () {
        $site = trustProxyCandidate(['domain' => 'trust-proxy-a.test']);

        $this->mock(WpConfigConstantInjector::class, function ($mock) use ($site) {
            $mock->shouldReceive('ensureDefined')
                ->once()
                ->withArgs(fn (Site $s, string $name, $value) => $s->is($site)
                    && $name === 'CLOCKWORK_COMPANION_TRUST_PROXY'
                    && $value === true)
                ->andReturn(true);
        });

        $this->artisan('clockwork:ensure-companion-trust-proxy')
            ->expectsOutputToContain('injected=1 already-set=0 failed=0')
            ->assertSuccessful();
    });

    it('counts an already-defined constant as already-set, not injected', function () {
        trustProxyCandidate(['domain' => 'trust-proxy-b.test']);

        $this->mock(WpConfigConstantInjector::class, function ($mock) {
            $mock->shouldReceive('ensureDefined')->once()->andReturn(false);
        });

        $this->artisan('clockwork:ensure-companion-trust-proxy')
            ->expectsOutputToContain('injected=0 already-set=1 failed=0')
            ->assertSuccessful();
    });

    it('catches a per-site injector failure, counts it, and still returns FAILURE overall without aborting the rest of the run', function () {
        $bad = trustProxyCandidate(['domain' => 'trust-proxy-bad.test']);
        $good = trustProxyCandidate(['domain' => 'trust-proxy-good.test']);

        $this->mock(WpConfigConstantInjector::class, function ($mock) use ($bad, $good) {
            $mock->shouldReceive('ensureDefined')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($bad))
                ->andThrow(new RuntimeException('wp-config.php not found or unreadable'));
            $mock->shouldReceive('ensureDefined')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($good))
                ->andReturn(true);
        });

        $this->artisan('clockwork:ensure-companion-trust-proxy')
            ->expectsOutputToContain('injected=1 already-set=0 failed=1')
            ->assertFailed();
    });

    it('--dry-run never calls the injector', function () {
        trustProxyCandidate(['domain' => 'trust-proxy-dry.test']);

        $this->mock(WpConfigConstantInjector::class, function ($mock) {
            $mock->shouldNotReceive('ensureDefined');
        });

        $this->artisan('clockwork:ensure-companion-trust-proxy', ['--dry-run' => true])->assertSuccessful();
    });

    it('excludes a site with cloudflare_state != proxied (unsafe to trust CF-Connecting-IP)', function () {
        trustProxyCandidate(['domain' => 'trust-proxy-direct.test', 'cloudflare_state' => Site::CF_DNS_ONLY]);

        $this->mock(WpConfigConstantInjector::class, function ($mock) {
            $mock->shouldNotReceive('ensureDefined');
        });

        $this->artisan('clockwork:ensure-companion-trust-proxy')
            ->expectsOutputToContain('No matching sites.')
            ->assertSuccessful();
    });

    it('excludes a site that is not care-plan enabled', function () {
        trustProxyCandidate(['domain' => 'trust-proxy-nocp.test', 'care_plan_enabled' => false]);

        $this->mock(WpConfigConstantInjector::class, function ($mock) {
            $mock->shouldNotReceive('ensureDefined');
        });

        $this->artisan('clockwork:ensure-companion-trust-proxy')->assertSuccessful();
    });

    it('--site limits the run to a single site by domain', function () {
        $target = trustProxyCandidate(['domain' => 'trust-proxy-target.test']);
        trustProxyCandidate(['domain' => 'trust-proxy-other.test']);

        $this->mock(WpConfigConstantInjector::class, function ($mock) use ($target) {
            $mock->shouldReceive('ensureDefined')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($target))
                ->andReturn(true);
        });

        $this->artisan('clockwork:ensure-companion-trust-proxy', ['--site' => 'trust-proxy-target.test'])->assertSuccessful();
    });
});
