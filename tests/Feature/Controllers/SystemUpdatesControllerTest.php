<?php

use App\Models\Site;
use App\Models\User;
use App\Services\Updates\SystemUpdateService;
use App\Support\IssueCounter;
use App\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| SystemUpdatesController — Settings → Updates
|--------------------------------------------------------------------------
|
| Tests for the WordPress-style operator-triggered update center:
| - Core updates check against upstream GitHub Releases API
| - Up to date vs Update available states
| - Pre-update safety recommendations (DB backup prompt)
| - Companion plugin fleet rollout breakdown
| - Operator-triggered Check Again and Apply Update actions
*/

beforeEach(function () {
    Cache::flush();
});

it('redirects unauthenticated requests to login', function () {
    $this->get(route('settings.updates.index'))->assertRedirect(route('login'));
    $this->post(route('settings.updates.check'))->assertRedirect(route('login'));
    $this->post(route('settings.updates.apply'))->assertRedirect(route('login'));
});

it('renders the updates page when up to date', function () {
    Http::fake([
        '*' => Http::response([
            'tag_name' => 'v1.0.0',
            'name' => 'Version 1.0.0',
            'body' => 'Initial release.',
            'published_at' => now()->toIso8601String(),
            'html_url' => 'https://github.com/Clockwork-Web-Dev-LLC/clockwork-control/releases/tag/v1.0.0',
        ], 200),
    ]);

    $this->mockIssueCounterZero();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.updates.index'));

    $response->assertOk()
        ->assertSee('Clockwork Updates')
        ->assertSee('Check Again')
        ->assertSee('You have the latest version of Clockwork Control.')
        ->assertSee('Clockwork Control Core')
        ->assertSee('Clockwork Companion Plugin (Fleet)')
        ->assertSee('Module Directory Catalog');
});

it('renders update available banner and changelog when a newer version exists', function () {
    Http::fake([
        '*' => Http::response([
            'tag_name' => 'v2.0.0',
            'name' => 'Clockwork Control 2.0.0 Release',
            'body' => 'Exciting new features and fleet security enhancements.',
            'published_at' => now()->toIso8601String(),
            'html_url' => 'https://github.com/Clockwork-Web-Dev-LLC/clockwork-control/releases/tag/v2.0.0',
        ], 200),
    ]);

    $this->mockIssueCounterZero();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.updates.index'));

    $response->assertOk()
        ->assertSee('An updated version of Clockwork Control is available!')
        ->assertSee('v2.0.0')
        ->assertSee('Clockwork Control 2.0.0 Release')
        ->assertSee('Exciting new features and fleet security enhancements.')
        ->assertSee('Update to v2.0.0')
        ->assertSee('Before updating, please ensure you have downloaded a database backup.');
});

it('handles operator-triggered check again action', function () {
    Http::fake([
        '*' => Http::response([
            'tag_name' => 'v2.1.0',
            'name' => 'Clockwork Control 2.1.0',
            'body' => 'Speed and bug fixes.',
            'published_at' => now()->toIso8601String(),
            'html_url' => 'https://github.com/Clockwork-Web-Dev-LLC/clockwork-control/releases/tag/v2.1.0',
        ], 200),
    ]);

    $this->mockIssueCounterZero();

    $response = $this->actingAs(User::factory()->create())
        ->post(route('settings.updates.check'));

    $response->assertRedirect(route('settings.updates.index'))
        ->assertSessionHas('status_update_available');
});

it('handles operator-triggered check again when up to date', function () {
    Http::fake([
        '*' => Http::response([
            'tag_name' => 'v1.0.0',
            'name' => 'Clockwork Control 1.0.0',
            'body' => 'Current.',
            'published_at' => now()->toIso8601String(),
            'html_url' => 'https://github.com/Clockwork-Web-Dev-LLC/clockwork-control/releases/tag/v1.0.0',
        ], 200),
    ]);

    $this->mockIssueCounterZero();

    $response = $this->actingAs(User::factory()->create())
        ->post(route('settings.updates.check'));

    $response->assertRedirect(route('settings.updates.index'))
        ->assertSessionHas('status_update_ok');
});

it('handles operator-triggered apply update action safely', function () {
    // Process::fake() is load-bearing here, not decoration: without it this
    // test would run a real `git pull` and `composer install` against
    // whatever checkout `./vendor/bin/pest` happens to run in.
    Process::fake();

    $this->mockIssueCounterZero();

    $response = $this->actingAs(User::factory()->create())
        ->post(route('settings.updates.apply'));

    $response->assertRedirect(route('settings.updates.index'));
    expect(session()->has('status_update_ok') || session()->has('status_update_error'))->toBeTrue();

    Process::assertRan(fn ($process) => is_array($process->command) && str($process->command[0] ?? '')->contains('git'));
    Process::assertRan(fn ($process) => is_array($process->command) && str($process->command[0] ?? '')->contains('composer'));
});

it('forbids operators from applying a system update', function () {
    Process::fake();

    $this->actingAs(User::factory()->operator()->create())
        ->post(route('settings.updates.apply'))
        ->assertForbidden();
});

it('forwards HOME/COMPOSER_HOME to the git and composer subprocesses regardless of the ambient environment', function () {
    // Regression coverage: `php artisan serve` run without --no-reload
    // strips almost every env var (including HOME) from its worker
    // process to support hot-reload-on-.env-change, leaving Composer with
    // nowhere to write its cache/config and this step failing — purely as
    // an artifact of which dev server happens to be in front of PHP.
    Process::fake();

    $this->mockIssueCounterZero();

    $this->actingAs(User::factory()->create())
        ->post(route('settings.updates.apply'));

    Process::assertRan(function ($process) {
        if (! is_array($process->command) || ! str($process->command[0] ?? '')->contains('git')) {
            return false;
        }

        return ! empty($process->environment['HOME']) && ! empty($process->environment['COMPOSER_HOME']);
    });

    Process::assertRan(function ($process) {
        if (! is_array($process->command) || ! str($process->command[0] ?? '')->contains('composer')) {
            return false;
        }

        return ! empty($process->environment['HOME']) && ! empty($process->environment['COMPOSER_HOME']);
    });
});

it('falls back to a Clockwork-owned directory when HOME/COMPOSER_HOME are entirely unset', function () {
    // Proves the actual bug scenario, not just that *some* ambient HOME
    // gets threaded through: with both unset (exactly what an unpatched
    // `php artisan serve` worker sees), the fallback must still produce a
    // real, writable, non-empty path rather than leaving Composer stranded.
    $originalHome = getenv('HOME');
    $originalComposerHome = getenv('COMPOSER_HOME');
    putenv('HOME');
    putenv('COMPOSER_HOME');

    try {
        Process::fake();
        $this->mockIssueCounterZero();

        $this->actingAs(User::factory()->create())
            ->post(route('settings.updates.apply'));

        Process::assertRan(function ($process) {
            if (! is_array($process->command) || ! str($process->command[0] ?? '')->contains('composer')) {
                return false;
            }

            return ($process->environment['HOME'] ?? '') === storage_path('app/subprocess-home')
                && ($process->environment['COMPOSER_HOME'] ?? '') === storage_path('app/subprocess-home').'/composer';
        });

        expect(is_dir(storage_path('app/subprocess-home')))->toBeTrue();
    } finally {
        $originalHome === false ? putenv('HOME') : putenv("HOME={$originalHome}");
        $originalComposerHome === false ? putenv('COMPOSER_HOME') : putenv("COMPOSER_HOME={$originalComposerHome}");
    }
});

it('aborts the apply action without touching git or composer when the working copy is dirty', function () {
    Process::fake([
        'git status --porcelain' => Process::result(' M app/Foo.php'),
    ]);

    $this->mockIssueCounterZero();

    $response = $this->actingAs(User::factory()->create())
        ->post(route('settings.updates.apply'));

    $response->assertRedirect(route('settings.updates.index'))
        ->assertSessionHas('status_update_error');

    Process::assertNotRan(fn ($process) => is_array($process->command) && str($process->command[0] ?? '')->contains('composer'));
});

it('displays companion plugin fleet breakdown properly', function () {
    Http::fake();
    $this->mockIssueCounterZero();

    $bundledVer = ltrim((string) config('clockwork.companion.version', '1.33.0'), 'v');

    Site::factory()->create([
        'companion_installed' => true,
        'companion_version' => $bundledVer,
    ]);

    Site::factory()->create([
        'companion_installed' => true,
        'companion_version' => '1.28.0',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.updates.index'));

    $response->assertOk()
        ->assertSee('Clockwork Companion Plugin (Fleet)')
        ->assertSee('v'.$bundledVer)
        ->assertSee('Older Version Pending');
});

it('enriches subprocess PATH with Homebrew, Herd, and Composer paths', function () {
    $env = app(SystemUpdateService::class)->subprocessEnv();

    expect($env)->toHaveKeys(['HOME', 'COMPOSER_HOME', 'PATH'])
        ->and($env['PATH'])->toContain('/opt/homebrew/bin')
        ->and($env['PATH'])->toContain('/opt/homebrew/sbin')
        ->and($env['PATH'])->toContain('/usr/local/bin')
        ->and($env['PATH'])->toContain('/usr/local/sbin')
        ->and($env['PATH'])->toContain('.config/herd/bin')
        ->and($env['PATH'])->toContain('.composer/vendor/bin');
});

it('skips composer install when composer.json and composer.lock have not changed', function () {
    $revParseCount = 0;
    Process::fake(function ($process) use (&$revParseCount) {
        $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        if (str_contains($cmd, 'git rev-parse HEAD')) {
            $revParseCount++;

            return Process::result($revParseCount === 1 ? 'commit_aaa' : 'commit_bbb');
        }

        if (str_contains($cmd, 'git diff')) {
            return Process::result('');
        }

        return Process::result('');
    });

    $this->mockIssueCounterZero();

    $response = $this->actingAs(User::factory()->create())
        ->post(route('settings.updates.apply'));

    $response->assertRedirect(route('settings.updates.index'));

    Process::assertRan(fn ($p) => is_array($p->command) && str($p->command[0] ?? '')->contains('git') && ($p->command[1] ?? '') === 'pull');
    Process::assertNotRan(fn ($p) => is_array($p->command) && str($p->command[0] ?? '')->contains('composer'));

    $service = app(SystemUpdateService::class);
    $lastResult = $service->getLastApplyResult();
    expect($lastResult)->not->toBeNull()
        ->and($lastResult['success'])->toBeTrue();

    $composerStep = collect($lastResult['steps'])->firstWhere('step', 'Installing updated dependencies (composer install --no-dev)');
    expect($composerStep)->not->toBeNull()
        ->and($composerStep['output'])->toContain('No dependency changes in this update; skipping composer install.');
});

it('runs composer install when composer.lock changes in git pull', function () {
    $revParseCount = 0;
    Process::fake(function ($process) use (&$revParseCount) {
        $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        if (str_contains($cmd, 'git rev-parse HEAD')) {
            $revParseCount++;

            return Process::result($revParseCount === 1 ? 'commit_aaa' : 'commit_bbb');
        }

        if (str_contains($cmd, 'git diff')) {
            return Process::result("composer.lock\n");
        }

        if (str_contains($cmd, 'composer')) {
            return Process::result('Installing dependencies from lock file');
        }

        return Process::result('');
    });

    $this->mockIssueCounterZero();

    $this->actingAs(User::factory()->create())
        ->post(route('settings.updates.apply'));

    Process::assertRan(fn ($p) => is_array($p->command) && str($p->command[0] ?? '')->contains('composer'));

    $lastResult = app(SystemUpdateService::class)->getLastApplyResult();
    expect($lastResult['success'])->toBeTrue();
});

it('automatically rolls back git working copy when composer install fails', function () {
    $revParseCount = 0;
    Process::fake(function ($process) use (&$revParseCount) {
        $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        if (str_contains($cmd, 'git rev-parse HEAD')) {
            $revParseCount++;

            return Process::result($revParseCount === 1 ? 'commit_before_pull' : 'commit_after_pull');
        }

        if (str_contains($cmd, 'git diff')) {
            return Process::result("composer.lock\n");
        }

        if (str_contains($cmd, 'composer')) {
            return Process::result(output: '', errorOutput: 'composer: command not found', exitCode: 127);
        }

        if (str_contains($cmd, 'git reset --hard')) {
            return Process::result('HEAD is now at commit_before_pull');
        }

        return Process::result('');
    });

    $this->mockIssueCounterZero();

    $response = $this->actingAs(User::factory()->create())
        ->post(route('settings.updates.apply'));

    $response->assertRedirect(route('settings.updates.index'))
        ->assertSessionHas('status_update_error');

    Process::assertRan(function ($p) {
        $cmd = is_array($p->command) ? implode(' ', $p->command) : (string) $p->command;

        return str_contains($cmd, 'git reset --hard commit_before_pull');
    });

    $lastResult = app(SystemUpdateService::class)->getLastApplyResult();
    expect($lastResult)->not->toBeNull()
        ->and($lastResult['success'])->toBeFalse()
        ->and($lastResult['error'])->toContain('Composer install failed')
        ->and($lastResult['error'])->toContain('Codebase was automatically rolled back to commit_before_pull');
});

it('automatically rolls back git working copy when migrations fail', function () {
    $revParseCount = 0;
    Process::fake(function ($process) use (&$revParseCount) {
        $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        if (str_contains($cmd, 'git rev-parse HEAD')) {
            $revParseCount++;

            return Process::result($revParseCount === 1 ? 'commit_before_pull' : 'commit_after_pull');
        }

        if (str_contains($cmd, 'git diff')) {
            return Process::result('');
        }

        if (str_contains($cmd, 'git reset --hard')) {
            return Process::result('HEAD is now at commit_before_pull');
        }

        return Process::result('');
    });

    Artisan::shouldReceive('call')
        ->with('migrate', ['--force' => true])
        ->once()
        ->andReturn(1);

    Artisan::shouldReceive('output')
        ->andReturn('SQLSTATE[42S01]: Base table or view already exists');

    Artisan::shouldReceive('call')
        ->with('optimize:clear')
        ->andReturn(0);

    $this->mockIssueCounterZero();

    $response = $this->actingAs(User::factory()->create())
        ->post(route('settings.updates.apply'));

    $response->assertRedirect(route('settings.updates.index'))
        ->assertSessionHas('status_update_error');

    Process::assertRan(function ($p) {
        $cmd = is_array($p->command) ? implode(' ', $p->command) : (string) $p->command;

        return str_contains($cmd, 'git reset --hard commit_before_pull');
    });

    $lastResult = app(SystemUpdateService::class)->getLastApplyResult();
    expect($lastResult)->not->toBeNull()
        ->and($lastResult['success'])->toBeFalse()
        ->and($lastResult['error'])->toContain('Migration failed')
        ->and($lastResult['error'])->toContain('Codebase was automatically rolled back to commit_before_pull');
});

it('displays the last update execution log panel on the updates page', function () {
    Http::fake();
    $this->mockIssueCounterZero();

    $settings = app(Settings::class);
    $settings->put(SystemUpdateService::SETTING_LAST_APPLY_RESULT, [
        'success' => false,
        'applied_at' => '2026-09-09T14:30:00+00:00',
        'version' => '1.5.5',
        'from_commit' => 'a1b2c3d',
        'to_commit' => 'e4f5g6h',
        'error' => 'Composer install failed: command not found. Codebase was automatically rolled back to a1b2c3d.',
        'steps' => [
            [
                'step' => 'Pulling latest updates from git (origin/main)',
                'success' => true,
                'output' => 'Fast-forward',
            ],
            [
                'step' => 'Installing updated dependencies (composer install --no-dev)',
                'success' => false,
                'output' => 'composer: command not found. Codebase was automatically rolled back to a1b2c3d.',
            ],
        ],
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.updates.index'));

    $response->assertOk()
        ->assertSee('Last Update Execution Log')
        ->assertSee('failed')
        ->assertSee('Composer install failed: command not found')
        ->assertSee('Codebase was automatically rolled back to a1b2c3d')
        ->assertSee('Pulling latest updates from git (origin/main)');
});

it('renders authenticated pages without 500 error even if IssueCounter throws an exception', function () {
    Http::fake();

    $this->partialMock(IssueCounter::class, function ($mock) {
        $mock->shouldReceive('total')->andThrow(new QueryException(
            'mysql',
            'select * from sites',
            [],
            new Exception("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'sites.uptime_maintenance_since' in 'where clause'")
        ));
    });

    $response = $this->actingAs(User::factory()->create())
        ->get(route('settings.updates.index'));

    $response->assertOk()
        ->assertSee('Clockwork Updates');
});
