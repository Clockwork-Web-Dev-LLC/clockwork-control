<?php

namespace Tests\Feature\Console;

use App\Models\ActionLog;
use App\Models\AppSetting;
use App\Models\Site;
use App\Services\Companion\CompanionInstaller;
use Modules\Pressable\PressableCompanionInstaller;

/*
|--------------------------------------------------------------------------
| Call-site coverage for clockwork:install-companion-pressable
|--------------------------------------------------------------------------
|
| PressableCompanionInstaller (Pressable's async command-API transport) is
| fully mocked — its own internals (chunked upload, secret bootstrap/rotate,
| edge-cache purge) are out of scope here. This command is --site-only
| (repeatable) by design, so there's no --all sweep to cover. All fixture
| sites use companion_installed=false (factory default), so ActionLogger's
| Companion-mirror push never fires and no Http::fake is required.
*/

describe('clockwork:install-companion-pressable — argument validation', function () {
    it('fails when no --site is given', function () {
        $this->artisan('clockwork:install-companion-pressable')->assertFailed();
    });
});

describe('clockwork:install-companion-pressable — --site', function () {
    it('installs on a matching Pressable-tracked site and records an action log', function () {
        $site = Site::factory()->pressable()->create(['domain' => 'pressable-example.com', 'is_wordpress' => true]);

        $this->mock(PressableCompanionInstaller::class, function ($mock) use ($site) {
            $mock->shouldReceive('installOrUpdate')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($site))
                ->andReturn([
                    'result' => CompanionInstaller::RESULT_INSTALLED,
                    'message' => 'Companion 1.30.0 installed on pressable-example.com (Pressable).',
                    'version' => '1.30.0',
                ]);
        });

        $this->artisan('clockwork:install-companion-pressable', ['--site' => ['pressable-example.com']])
            ->assertSuccessful();

        $log = ActionLog::query()
            ->where('site_id', $site->id)
            ->where('action_type', ActionLog::TYPE_COMPANION_INSTALL)
            ->first();

        expect($log)->not->toBeNull()->and($log->ok)->toBeTrue();
    });

    it('accepts multiple --site selectors, including a numeric ID', function () {
        $siteA = Site::factory()->pressable()->create(['is_wordpress' => true]);
        $siteB = Site::factory()->pressable()->create(['is_wordpress' => true]);

        $this->mock(PressableCompanionInstaller::class, function ($mock) use ($siteA, $siteB) {
            $mock->shouldReceive('installOrUpdate')
                ->twice()
                ->withArgs(fn (Site $s) => $s->is($siteA) || $s->is($siteB))
                ->andReturn(['result' => CompanionInstaller::RESULT_ALREADY_CURRENT, 'message' => 'current']);
        });

        $this->artisan('clockwork:install-companion-pressable', [
            '--site' => [$siteA->domain, (string) $siteB->id],
            '--throttle-ms' => 0,
        ])->assertSuccessful();
    });

    it('warns about a selector that matches no Pressable-tracked site but still exits successfully', function () {
        $this->artisan('clockwork:install-companion-pressable', ['--site' => ['not-a-real-site.example']])
            ->assertSuccessful()
            ->expectsOutputToContain("'not-a-real-site.example' matched no Pressable-tracked site")
            ->expectsOutputToContain('No sites match the given filters.');
    });

    it('does not match a SpinupWP-hosted site even by domain', function () {
        Site::factory()->spinupwp()->create(['domain' => 'web-only.example']);

        $this->mock(PressableCompanionInstaller::class, function ($mock) {
            $mock->shouldNotReceive('installOrUpdate');
        });

        $this->artisan('clockwork:install-companion-pressable', ['--site' => ['web-only.example']])
            ->assertSuccessful()
            ->expectsOutputToContain('matched no Pressable-tracked site');
    });

    it('a failed install tallies as failed and exits FAILURE', function () {
        $site = Site::factory()->pressable()->create(['is_wordpress' => true]);

        $this->mock(PressableCompanionInstaller::class, function ($mock) {
            $mock->shouldReceive('installOrUpdate')->once()->andThrow(new \RuntimeException('Pressable API timeout'));
        });

        $this->artisan('clockwork:install-companion-pressable', ['--site' => [$site->domain]])
            ->assertFailed();
    });
});

describe('clockwork:install-companion-pressable — policy denylist', function () {
    it('excludes a site matching companion.excluded_domain_suffixes and never calls the installer', function () {
        AppSetting::query()->create(['key' => 'companion.excluded_domain_suffixes', 'value' => ['stateschools.example']]);

        $excludedSite = Site::factory()->pressable()->create(['domain' => 'apps.stateschools.example', 'is_wordpress' => true]);

        $this->mock(PressableCompanionInstaller::class, function ($mock) {
            $mock->shouldNotReceive('installOrUpdate');
        });

        $this->artisan('clockwork:install-companion-pressable', ['--site' => [$excludedSite->domain]])
            ->assertSuccessful()
            ->expectsOutputToContain('excluded by policy');
    });

    it('--force overrides the denylist and installs anyway', function () {
        AppSetting::query()->create(['key' => 'companion.excluded_domain_suffixes', 'value' => ['stateschools.example']]);

        $excludedSite = Site::factory()->pressable()->create(['domain' => 'apps.stateschools.example', 'is_wordpress' => true]);

        $this->mock(PressableCompanionInstaller::class, function ($mock) use ($excludedSite) {
            $mock->shouldReceive('installOrUpdate')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($excludedSite))
                ->andReturn(['result' => CompanionInstaller::RESULT_INSTALLED, 'message' => 'installed', 'version' => '1.30.0']);
        });

        $this->artisan('clockwork:install-companion-pressable', ['--site' => [$excludedSite->domain], '--force' => true])
            ->assertSuccessful();
    });
});
