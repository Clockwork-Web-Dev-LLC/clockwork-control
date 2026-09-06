<?php

namespace Tests\Feature\Console;

use App\Models\ActionLog;
use App\Models\AppSetting;
use App\Models\Site;
use App\Services\Companion\CompanionInstaller;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Call-site coverage for clockwork:install-companion
|--------------------------------------------------------------------------
|
| CompanionInstaller (SSH transport) is fully mocked throughout — its own
| internals are out of scope here (see CompanionInstaller's SSH-boundary
| unit coverage elsewhere). What's under test is the command's own logic:
| option parsing, site resolution (--site / --all-enabled / --all-installed),
| the policy denylist (CompanionExclusion), tallying, and ActionLogger
| call-sites. --throttle-ms=0 is passed everywhere multiple sites are
| touched so the suite doesn't pay the real inter-site usleep() delay.
*/

describe('clockwork:install-companion — argument validation', function () {
    it('fails when no --site/--all-enabled/--all-installed is given', function () {
        $this->artisan('clockwork:install-companion')->assertFailed();
    });
});

describe('clockwork:install-companion — --site', function () {
    it('installs on the matching site and records a companion_install action log', function () {
        $site = Site::factory()->create(['domain' => 'example.com', 'is_wordpress' => true]);

        $this->mock(CompanionInstaller::class, function ($mock) use ($site) {
            $mock->shouldReceive('installOrUpdate')
                ->once()
                ->withArgs(fn (Site $s, bool $rotate) => $s->is($site) && $rotate === false)
                ->andReturn([
                    'result' => CompanionInstaller::RESULT_INSTALLED,
                    'message' => 'Companion 1.30.0 installed.',
                    'version' => '1.30.0',
                ]);
        });

        $this->artisan('clockwork:install-companion', ['--site' => 'example.com'])
            ->assertSuccessful();

        $log = ActionLog::query()
            ->where('site_id', $site->id)
            ->where('action_type', ActionLog::TYPE_COMPANION_INSTALL)
            ->first();

        expect($log)->not->toBeNull()
            ->and($log->ok)->toBeTrue();
    });

    it('resolves --site by numeric ID as well as domain', function () {
        $site = Site::factory()->create(['is_wordpress' => true]);

        $this->mock(CompanionInstaller::class, function ($mock) use ($site) {
            $mock->shouldReceive('installOrUpdate')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($site))
                ->andReturn(['result' => CompanionInstaller::RESULT_ALREADY_CURRENT, 'message' => 'current']);
        });

        $this->artisan('clockwork:install-companion', ['--site' => (string) $site->id])
            ->assertSuccessful();
    });

    it('passes --rotate-secret through to the installer', function () {
        $site = Site::factory()->create(['is_wordpress' => true]);

        $this->mock(CompanionInstaller::class, function ($mock) use ($site) {
            $mock->shouldReceive('installOrUpdate')
                ->once()
                ->withArgs(fn (Site $s, bool $rotate) => $s->is($site) && $rotate === true)
                ->andReturn(['result' => CompanionInstaller::RESULT_UPDATED, 'message' => 'updated', 'version' => '1.31.0']);
        });

        $this->artisan('clockwork:install-companion', ['--site' => $site->domain, '--rotate-secret' => true])
            ->assertSuccessful();
    });

    it('warns and exits successfully when no site matches the filter', function () {
        $this->artisan('clockwork:install-companion', ['--site' => 'does-not-exist.example'])
            ->assertSuccessful()
            ->expectsOutputToContain('No sites match the given filters.');
    });

    it('catches a Throwable from the installer, logs no action, tallies it as failed, and exits FAILURE', function () {
        $site = Site::factory()->create(['is_wordpress' => true]);

        $this->mock(CompanionInstaller::class, function ($mock) {
            $mock->shouldReceive('installOrUpdate')->once()->andThrow(new \RuntimeException('SSH connection refused'));
        });

        $this->artisan('clockwork:install-companion', ['--site' => $site->domain])
            ->assertFailed();

        expect(ActionLog::query()->where('site_id', $site->id)->count())->toBe(0);
    });
});

describe('clockwork:install-companion — --all-enabled', function () {
    it('only processes sites with contact_form_test_enabled=true', function () {
        $enabled1 = Site::factory()->create(['is_wordpress' => true, 'contact_form_test_enabled' => true]);
        $enabled2 = Site::factory()->create(['is_wordpress' => true, 'contact_form_test_enabled' => true]);
        $notEnabled = Site::factory()->create(['is_wordpress' => true, 'contact_form_test_enabled' => false]);

        $this->mock(CompanionInstaller::class, function ($mock) use ($enabled1, $enabled2) {
            $mock->shouldReceive('installOrUpdate')
                ->twice()
                ->withArgs(fn (Site $s) => $s->is($enabled1) || $s->is($enabled2))
                ->andReturn(['result' => CompanionInstaller::RESULT_ALREADY_CURRENT, 'message' => 'current']);
        });

        $this->artisan('clockwork:install-companion', ['--all-enabled' => true, '--throttle-ms' => 0])
            ->assertSuccessful();

        // The non-enabled site was never even considered by the mock's
        // withArgs constraint above (it would have failed the mock
        // expectation entirely had it been passed in), confirming filtering.
        expect($notEnabled)->not->toBeNull();
    });
});

describe('clockwork:install-companion — policy denylist', function () {
    it('excludes a site matching companion.excluded_domain_suffixes and never calls the installer for it', function () {
        AppSetting::query()->create(['key' => 'companion.excluded_domain_suffixes', 'value' => ['stateschools.example']]);

        $excludedSite = Site::factory()->create(['domain' => 'community.stateschools.example', 'is_wordpress' => true]);

        $this->mock(CompanionInstaller::class, function ($mock) {
            $mock->shouldNotReceive('installOrUpdate');
        });

        $this->artisan('clockwork:install-companion', ['--site' => $excludedSite->domain])
            ->assertSuccessful()
            ->expectsOutputToContain('excluded by policy');
    });

    it('--force overrides the denylist and installs anyway', function () {
        AppSetting::query()->create(['key' => 'companion.excluded_domain_suffixes', 'value' => ['stateschools.example']]);

        $excludedSite = Site::factory()->create(['domain' => 'community.stateschools.example', 'is_wordpress' => true]);

        $this->mock(CompanionInstaller::class, function ($mock) use ($excludedSite) {
            $mock->shouldReceive('installOrUpdate')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($excludedSite))
                ->andReturn(['result' => CompanionInstaller::RESULT_INSTALLED, 'message' => 'installed', 'version' => '1.30.0']);
        });

        $this->artisan('clockwork:install-companion', ['--site' => $excludedSite->domain, '--force' => true])
            ->assertSuccessful();
    });
});

describe('clockwork:install-companion — --all-installed', function () {
    it('warns and exits successfully without prompting when zero sites are eligible', function () {
        // No Companion-installed sites exist at all, so confirmFleetUpgrade()
        // short-circuits before ever calling $this->confirm() — this
        // exercises that path without needing a fragile confirm() stub.
        $this->artisan('clockwork:install-companion', ['--all-installed' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('No Companion-installed sites match.');
    });

    it('prompts for confirmation and, when confirmed, installs on every eligible site', function () {
        Http::fake(); // ActionLogger will push to Companion for these installed sites; block real HTTP.

        $site = Site::factory()->withCompanionInstalled()->create(['is_wordpress' => true]);

        $this->mock(CompanionInstaller::class, function ($mock) use ($site) {
            $mock->shouldReceive('installOrUpdate')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($site))
                ->andReturn(['result' => CompanionInstaller::RESULT_UPDATED, 'message' => 'updated', 'version' => '1.31.0']);
        });

        $this->artisan('clockwork:install-companion', ['--all-installed' => true, '--throttle-ms' => 0])
            ->expectsConfirmation('Push the local Companion build to 1 site. Continue?', 'yes')
            ->assertSuccessful();
    });

    it('declining the confirmation exits successfully without calling the installer', function () {
        $site = Site::factory()->withCompanionInstalled()->create(['is_wordpress' => true]);

        $this->mock(CompanionInstaller::class, function ($mock) {
            $mock->shouldNotReceive('installOrUpdate');
        });

        $this->artisan('clockwork:install-companion', ['--all-installed' => true])
            ->expectsConfirmation('Push the local Companion build to 1 site. Continue?', 'no')
            ->assertSuccessful();

        expect($site)->not->toBeNull();
    });
});
