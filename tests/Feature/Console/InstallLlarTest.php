<?php

namespace Tests\Feature\Console;

use App\Models\Site;
use App\Services\Sites\LlarInstaller;

/*
|--------------------------------------------------------------------------
| Call-site coverage for clockwork:install-llar
|--------------------------------------------------------------------------
|
| LlarInstaller (wp-cli over SSH) is fully mocked — its own internals are
| out of scope here. This command performs no DB writes or ActionLogger
| calls of its own (LlarInstaller::process() is a pure remote-effect +
| return-array collaborator), so the tallying summary line is the
| observable proof the command routed the right sites through it.
*/

describe('clockwork:install-llar — argument validation', function () {
    it('fails when neither --site nor --all-missing is given', function () {
        $this->artisan('clockwork:install-llar')->assertFailed();
    });
});

describe('clockwork:install-llar — --site', function () {
    it('resolves the site by domain and reports the installer result', function () {
        $site = Site::factory()->create(['domain' => 'llar-target.example', 'is_wordpress' => true]);

        $this->mock(LlarInstaller::class, function ($mock) use ($site) {
            $mock->shouldReceive('process')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($site))
                ->andReturn(['result' => LlarInstaller::RESULT_INSTALLED, 'message' => 'LLAR installed and lockout email disabled.']);
        });

        $this->artisan('clockwork:install-llar', ['--site' => 'llar-target.example'])
            ->assertSuccessful()
            ->expectsOutputToContain('installed=1');
    });

    it('resolves the site by numeric ID', function () {
        $site = Site::factory()->create(['is_wordpress' => true]);

        $this->mock(LlarInstaller::class, function ($mock) use ($site) {
            $mock->shouldReceive('process')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($site))
                ->andReturn(['result' => LlarInstaller::RESULT_ALREADY_PRESENT, 'message' => 'already present']);
        });

        $this->artisan('clockwork:install-llar', ['--site' => (string) $site->id])
            ->assertSuccessful()
            ->expectsOutputToContain('already-present=1');
    });

    it('warns and exits successfully when no site matches', function () {
        $this->artisan('clockwork:install-llar', ['--site' => 'nope.example'])
            ->assertSuccessful()
            ->expectsOutputToContain('No sites match the given filters.');
    });

    it('a failed install tallies as failed and exits FAILURE', function () {
        $site = Site::factory()->create(['is_wordpress' => true]);

        $this->mock(LlarInstaller::class, function ($mock) {
            $mock->shouldReceive('process')->once()->andReturn(['result' => LlarInstaller::RESULT_FAILED, 'message' => 'wp-cli exit 1']);
        });

        $this->artisan('clockwork:install-llar', ['--site' => $site->domain])
            ->assertFailed()
            ->expectsOutputToContain('failed=1');
    });
});

describe('clockwork:install-llar — --all-missing', function () {
    it('only processes WordPress sites with llar_enabled=false', function () {
        $missingA = Site::factory()->create(['is_wordpress' => true, 'llar_enabled' => false]);
        $missingB = Site::factory()->create(['is_wordpress' => true, 'llar_enabled' => false]);
        $alreadyHasIt = Site::factory()->create(['is_wordpress' => true, 'llar_enabled' => true]);
        $nonWp = Site::factory()->create(['is_wordpress' => false, 'llar_enabled' => false]);

        $this->mock(LlarInstaller::class, function ($mock) use ($missingA, $missingB) {
            $mock->shouldReceive('process')
                ->twice()
                ->withArgs(fn (Site $s) => $s->is($missingA) || $s->is($missingB))
                ->andReturn(['result' => LlarInstaller::RESULT_INSTALLED, 'message' => 'installed']);
        });

        $this->artisan('clockwork:install-llar', ['--all-missing' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('installed=2');

        expect($alreadyHasIt)->not->toBeNull()->and($nonWp)->not->toBeNull();
    });
});
