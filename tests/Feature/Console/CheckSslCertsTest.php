<?php

namespace Tests\Feature\Console;

use App\Services\Ssl\SslChecker;

/*
|--------------------------------------------------------------------------
| Call-site coverage for CheckSslCerts
|--------------------------------------------------------------------------
|
| CheckSslCerts is a thin wrapper — SslChecker::run()'s own logic (state
| transitions, Mattermost notification gating, per-provider cert refresh)
| is already deeply covered in tests/Feature/Ssl/SslCheckerNotificationTest.php
| (Phase 4). This file only confirms the artisan entry point actually
| resolves SslChecker from the container, calls run() with the right
| $silent value from the --silent flag, and echoes back its result summary.
*/

describe('CheckSslCerts', function () {
    it('calls SslChecker::run(false) by default and prints the summary', function () {
        $this->mock(SslChecker::class, function ($mock) {
            $mock->shouldReceive('run')
                ->once()
                ->with(false)
                ->andReturn(['checked' => 5, 'refreshed' => 2, 'transitioned' => 1, 'notified' => 1]);
        });

        $this->artisan('clockwork:check-ssl-certs')
            ->expectsOutputToContain('Checked 5 sites, 2 refreshed from SpinupWP, 1 transitions, 1 Mattermost notifications.')
            ->assertSuccessful();
    });

    /*
     * NOTE — real discrepancy found while writing this test, not fixed here
     * per phase instructions (test-writing only):
     *
     * '--silent' isn't just this command's own option — Symfony Console
     * registers a *global* `--silent` flag on every command (see
     * vendor/symfony/console/Application.php ~line 1188/1013:
     * `new InputOption('--silent', null, InputOption::VALUE_NONE, 'Do not
     * output any message')`, which drives output verbosity to -2, quieter
     * than --quiet). Because CheckSslCerts's own signature option shares
     * that exact name, passing --silent suppresses ALL command output —
     * including the "Checked N sites..." summary line — not just the
     * Mattermost notifications the command's docblock describes. Confirmed
     * directly against Artisan::call() with a captured BufferedOutput: the
     * non-silent run prints the summary, the --silent run prints nothing,
     * even though $checker->run(true) is still invoked and the command
     * still exits 0. This is worth a rename (e.g. --quiet-mattermost) to
     * stop shadowing Symfony's own flag, but that's an app/ change outside
     * this phase's scope.
     */
    it('passes --silent through as $silent=true, still exits successfully — output itself is suppressed by Symfony\'s own global --silent flag of the same name', function () {
        $this->mock(SslChecker::class, function ($mock) {
            $mock->shouldReceive('run')
                ->once()
                ->with(true)
                ->andReturn(['checked' => 3, 'refreshed' => 0, 'transitioned' => 0, 'notified' => 0]);
        });

        $this->artisan('clockwork:check-ssl-certs', ['--silent' => true])
            ->doesntExpectOutput()
            ->assertSuccessful();
    });
});
