<?php

use App\Models\ActionLog;
use App\Models\Server;
use App\Models\Site;
use App\Services\Companion\CompanionInstaller;
use App\Support\Settings;

/*
|--------------------------------------------------------------------------
| CompanionFleetDeploy — Phase 6 console coverage
|--------------------------------------------------------------------------
|
| App\Services\Companion\CompanionInstaller is the concrete class
| SpinupWpHostingProvider type-hints as `companionInstaller` (aliased
| `SshCompanionInstaller` — see modules/SpinupWp/src/SpinupWpHostingProvider.php),
| so mocking it directly swaps out what `$site->host()->companionInstaller()`
| resolves for every fleet site, same as tests/Feature/Controllers/SitesControllerTest.php.
|
| Fleet sites are created with companion_installed=true but no
| companion_secret, so ActionLogger::record's Companion-mirror push
| (pushToCompanion) never fires and doesn't need its own Http::fake().
|
| --throttle-ms=0 everywhere below — the command's real 1000ms default
| inter-site sleep would make this suite slow for no test value.
*/

function fleetSite(array $overrides = []): Site
{
    $server = Server::factory()->create();

    return Site::factory()->spinupwp()->create(array_merge([
        'server_id' => $server->id,
        'companion_installed' => true,
        'companion_secret' => null,
    ], $overrides));
}

function verifyCanaryGate(string $capability = 'malware-scan'): void
{
    app(Settings::class)->put('companion.canary_verified_at', now()->toIso8601String());
    app(Settings::class)->put('companion.canary_verified_capability', $capability);
}

describe('clockwork:companion-fleet-deploy', function () {
    it('refuses to run without --force when the canary has not been verified', function () {
        fleetSite();

        $this->mock(CompanionInstaller::class, function ($mock) {
            $mock->shouldNotReceive('installOrUpdate');
        });

        $this->artisan('clockwork:companion-fleet-deploy', ['--throttle-ms' => 0])->assertFailed();
    });

    it('reports nothing to deploy and exits successfully when every candidate is excluded', function () {
        $canary = fleetSite(['domain' => 'fleet-canary.test']);
        $skipped = fleetSite(['domain' => 'fleet-skip.test']);
        $policyExcluded = fleetSite(['domain' => 'blocked.stateschools.example']);

        app(Settings::class)->put('companion.canary_site_ids', [$canary->id]);
        app(Settings::class)->put('companion.excluded_domain_suffixes', ['stateschools.example']);
        verifyCanaryGate();

        $this->mock(CompanionInstaller::class, function ($mock) {
            $mock->shouldNotReceive('installOrUpdate');
        });

        $this->artisan('clockwork:companion-fleet-deploy', [
            '--skip' => 'fleet-skip.test',
            '--throttle-ms' => 0,
        ])->assertSuccessful();
    });

    it('deploys to the filtered fleet and aggregates per-site results — excluding canary/skip/policy sites and counting a success and a failure separately', function () {
        $canary = fleetSite(['domain' => 'fd-canary.test']);
        $skipped = fleetSite(['domain' => 'fd-skip.test']);
        $policyExcluded = fleetSite(['domain' => 'blocked2.stateschools.example']);
        $succeeds = fleetSite(['domain' => 'fd-succeeds.test']);
        $fails = fleetSite(['domain' => 'fd-fails.test']);

        app(Settings::class)->put('companion.canary_site_ids', [$canary->id]);
        app(Settings::class)->put('companion.excluded_domain_suffixes', ['stateschools.example']);
        verifyCanaryGate();

        // Exactly two calls total — proves canary/skip/policy sites never
        // reach the installer, and that the aggregation below reflects two
        // distinct per-site outcomes rather than a single passed-through result.
        $this->mock(CompanionInstaller::class, function ($mock) {
            $mock->shouldReceive('installOrUpdate')
                ->twice()
                ->andReturnUsing(function (Site $site) {
                    if ($site->domain === 'fd-fails.test') {
                        throw new RuntimeException('SSH connect timed out');
                    }

                    return ['result' => CompanionInstaller::RESULT_UPDATED, 'message' => 'Upgraded.', 'version' => '1.30.4'];
                });
        });

        $this->artisan('clockwork:companion-fleet-deploy', ['--skip' => 'fd-skip.test', '--throttle-ms' => 0])
            ->expectsConfirmation('Deploy Companion to 2 site(s)? This writes a new plugin file to every site.', 'yes')
            ->assertFailed();

        expect(ActionLog::query()->where('site_id', $succeeds->id)->where('action_type', ActionLog::TYPE_COMPANION_UPDATE)->exists())->toBeTrue();
        // The thrown exception is caught before recordCompanionInstall runs — no row for the failed site.
        expect(ActionLog::query()->where('site_id', $fails->id)->exists())->toBeFalse();
        expect(ActionLog::query()->whereIn('site_id', [$canary->id, $skipped->id, $policyExcluded->id])->exists())->toBeFalse();
    });

    it('aborts without deploying when the operator declines the confirmation prompt', function () {
        fleetSite(['domain' => 'fd-decline.test']);
        verifyCanaryGate();

        $this->mock(CompanionInstaller::class, function ($mock) {
            $mock->shouldNotReceive('installOrUpdate');
        });

        $this->artisan('clockwork:companion-fleet-deploy', ['--throttle-ms' => 0])
            ->expectsConfirmation('Deploy Companion to 1 site(s)? This writes a new plugin file to every site.', 'no')
            ->assertSuccessful();
    });

    it('--force bypasses the canary-verification gate', function () {
        $site = fleetSite(['domain' => 'fd-force.test']);

        $this->mock(CompanionInstaller::class, function ($mock) {
            $mock->shouldReceive('installOrUpdate')
                ->once()
                ->andReturn(['result' => CompanionInstaller::RESULT_ALREADY_CURRENT, 'message' => 'Already current.', 'version' => '1.30.4']);
        });

        $this->artisan('clockwork:companion-fleet-deploy', ['--force' => true, '--throttle-ms' => 0])
            ->expectsConfirmation('Deploy Companion to 1 site(s)? This writes a new plugin file to every site.', 'yes')
            ->assertSuccessful();
    });
});
