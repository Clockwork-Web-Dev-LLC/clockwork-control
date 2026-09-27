<?php

namespace Tests\Feature\Gatekeeper;

use App\Models\ActionLog;
use App\Models\Site;
use App\Services\Companion\CompanionInstaller;
use App\Services\Fail2ban\IgnoreIpListBuilder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Core\ModuleStateResolver;

/*
|--------------------------------------------------------------------------
| clockwork:gatekeeper-rollout
|--------------------------------------------------------------------------
|
| ClockworkCompanionClient is new'd inside the command, so its REST calls
| are faked against the signed-request URLs. The SSH CompanionInstaller is
| the concrete class SpinupWpHostingProvider hands back, so it's mocked
| directly (same pattern as CompanionCanaryDeployTest). A catch-all fake
| absorbs ActionLogger's action-log mirror push.
*/

function rolloutSite(array $attrs = []): Site
{
    return Site::factory()->spinupwp()->create(array_merge([
        'is_wordpress' => true,
        'is_inactive' => false,
        'companion_installed' => true,
        'companion_version' => '1.39.1',
        'companion_secret' => 'test-secret',
        'companion_capabilities' => ['gatekeeper', 'plugins'],
        'gatekeeper_settings' => null,
        'llar_enabled' => true,
    ], $attrs));
}

function fakeCompanionFor(string $domain, array $overrides = []): void
{
    $base = "https://{$domain}/wp-json/clockwork/v1";

    Http::fake(array_merge([
        "{$base}/gatekeeper-settings" => Http::response(['ok' => true]),
        "{$base}/plugins/toggle" => Http::response(['ok' => true]),
        "{$base}/plugins/delete" => Http::response(['ok' => true]),
        "{$base}/health" => Http::response(['ok' => true, 'version' => '1.39.1', 'capabilities' => ['gatekeeper', 'plugins']]),
        '*' => Http::response(['ok' => true]),
    ], $overrides));
}

beforeEach(function () {
    $this->mock(IgnoreIpListBuilder::class, fn ($mock) => $mock->shouldReceive('build')->andReturn([]));
});

describe('candidate selection', function () {
    it('lists Companion sites still on LLAR as well as sites with no Companion', function () {
        rolloutSite(['domain' => 'llar.example']);
        rolloutSite(['domain' => 'bare.example', 'companion_installed' => false, 'companion_capabilities' => null]);
        rolloutSite(['domain' => 'done.example', 'llar_enabled' => false]);
        rolloutSite(['domain' => 'staging.skip.example']);

        $this->artisan('clockwork:gatekeeper-rollout', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('2 site(s) queued')
            ->expectsOutputToContain('llar.example')
            ->expectsOutputToContain('bare.example')
            ->doesntExpectOutputToContain('done.example')
            ->doesntExpectOutputToContain('staging.skip.example');
    });

    it('--scope=llar excludes sites with no Companion', function () {
        rolloutSite(['domain' => 'llar.example']);
        rolloutSite(['domain' => 'bare.example', 'companion_installed' => false]);

        $this->artisan('clockwork:gatekeeper-rollout', ['--dry-run' => true, '--scope' => 'llar'])
            ->assertSuccessful()
            ->expectsOutputToContain('1 site(s) queued')
            ->doesntExpectOutputToContain('bare.example');
    });

    it('rejects an unknown scope', function () {
        $this->artisan('clockwork:gatekeeper-rollout', ['--scope' => 'everything'])
            ->assertExitCode(2);
    });
});

describe('Companion site still on LLAR', function () {
    it('persists the enabled override, pushes, removes LLAR, and flips llar_enabled', function () {
        $site = rolloutSite(['domain' => 'llar.example', 'gatekeeper_settings' => ['threshold' => 6]]);
        fakeCompanionFor('llar.example');

        $this->artisan('clockwork:gatekeeper-rollout', ['--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Gatekeeper enabled')
            ->expectsOutputToContain('LLAR deactivated')
            ->expectsOutputToContain('LLAR files deleted');

        $site->refresh();

        // The override is what keeps the nightly push from turning it back off.
        expect($site->gatekeeper_settings)->toBe(['threshold' => 6, 'enabled' => true])
            ->and($site->llar_enabled)->toBeFalse()
            ->and($site->gatekeeperEnabled())->toBeTrue();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/gatekeeper-settings')
            && $r['enabled'] === true
            && $r['threshold'] === 6);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/plugins/toggle')
            && $r['action'] === 'deactivate'
            && str_contains($r['slug'], 'limit-login-attempts-reloaded'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/plugins/delete')
            && str_contains($r['slug'], 'limit-login-attempts-reloaded'));

        expect(ActionLog::query()->where('action_type', ActionLog::TYPE_LLAR_RETIRED)->where('site_id', $site->id)->exists())
            ->toBeTrue();
    });

    it('leaves LLAR alone when the Gatekeeper push fails', function () {
        $site = rolloutSite(['domain' => 'llar.example']);
        fakeCompanionFor('llar.example', [
            'https://llar.example/wp-json/clockwork/v1/gatekeeper-settings' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->artisan('clockwork:gatekeeper-rollout', ['--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Gatekeeper push failed')
            ->expectsOutputToContain('Leaving LLAR in place');

        $site->refresh();

        expect($site->llar_enabled)->toBeTrue();
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/plugins/toggle'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/plugins/delete'));
    });

    it('does not delete LLAR when deactivation fails', function () {
        $site = rolloutSite(['domain' => 'llar.example']);
        fakeCompanionFor('llar.example', [
            'https://llar.example/wp-json/clockwork/v1/plugins/toggle' => Http::response(['error' => 'nope'], 500),
        ]);

        $this->artisan('clockwork:gatekeeper-rollout', ['--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('LLAR deactivate failed');

        expect($site->fresh()->llar_enabled)->toBeTrue();
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/plugins/delete'));
    });

    it('does not enable Gatekeeper on a site that never had LLAR', function () {
        rolloutSite(['domain' => 'fresh.example', 'companion_installed' => false, 'llar_enabled' => false]);
        fakeCompanionFor('fresh.example');

        $this->mock(CompanionInstaller::class, function ($mock) {
            $mock->shouldReceive('installOrUpdate')->once()->andReturnUsing(function (Site $site) {
                $site->forceFill([
                    'companion_installed' => true,
                    'companion_capabilities' => ['gatekeeper', 'plugins'],
                    'companion_version' => '1.39.1',
                ])->save();

                return ['result' => CompanionInstaller::RESULT_INSTALLED, 'message' => 'Installed Companion.', 'version' => '1.39.1'];
            });
        });

        $this->artisan('clockwork:gatekeeper-rollout', ['--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('never had LLAR');

        $site = Site::where('domain', 'fresh.example')->firstOrFail();

        expect($site->gatekeeper_settings)->toBeNull();
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/gatekeeper-settings'));
    });
});

describe('Companion too old for Gatekeeper', function () {
    it('upgrades Companion, re-reads /health, then proceeds', function () {
        $site = rolloutSite(['domain' => 'old.example', 'companion_version' => '1.36.0', 'companion_capabilities' => ['plugins']]);
        fakeCompanionFor('old.example');

        $this->mock(CompanionInstaller::class, function ($mock) {
            $mock->shouldReceive('installOrUpdate')
                ->once()
                ->andReturn(['result' => CompanionInstaller::RESULT_UPDATED, 'message' => 'Updated to 1.39.1.', 'version' => '1.39.1']);
        });

        $this->artisan('clockwork:gatekeeper-rollout', ['--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Updated to 1.39.1')
            ->expectsOutputToContain('Gatekeeper enabled');

        $site->refresh();

        expect($site->companion_capabilities)->toContain('gatekeeper')
            ->and($site->llar_enabled)->toBeFalse();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/health'));
    });

    it('stops if Companion still lacks the capability after install', function () {
        $site = rolloutSite(['domain' => 'stuck.example', 'companion_version' => '1.36.0', 'companion_capabilities' => ['plugins']]);
        fakeCompanionFor('stuck.example', [
            'https://stuck.example/wp-json/clockwork/v1/health' => Http::response(['ok' => true, 'version' => '1.36.0', 'capabilities' => ['plugins']]),
        ]);

        $this->mock(CompanionInstaller::class, function ($mock) {
            $mock->shouldReceive('installOrUpdate')
                ->once()
                ->andReturn(['result' => CompanionInstaller::RESULT_ALREADY_CURRENT, 'message' => 'already 1.36.0']);
        });

        $this->artisan('clockwork:gatekeeper-rollout', ['--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('still does not advertise gatekeeper');

        expect($site->fresh()->llar_enabled)->toBeTrue();
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/gatekeeper-settings'));
    });

    it('aborts when the Gatekeeper module is disabled in the module directory', function () {
        $resolver = $this->mock(ModuleStateResolver::class);
        $resolver->shouldReceive('isEnabled')->with('gatekeeper')->andReturn(false);

        $this->artisan('clockwork:gatekeeper-rollout')
            ->assertFailed()
            ->expectsOutputToContain('The Gatekeeper module is disabled in the Module Directory.');
    });
});
