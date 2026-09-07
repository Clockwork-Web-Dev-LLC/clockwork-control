<?php

use App\Models\Site;
use App\Models\User;
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
