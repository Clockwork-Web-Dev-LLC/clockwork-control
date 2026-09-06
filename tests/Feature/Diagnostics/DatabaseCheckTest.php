<?php

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\Checks\DatabaseCheck;
use Illuminate\Support\Facades\DB;

/**
 * Coverage for DatabaseCheck (App\Services\Diagnostics\DiagnosticCheck) —
 * runs SELECT VERSION() AS version against the primary connection. This
 * check has no "skipped" state (the primary DB connection isn't an optional
 * integration), so only ok/fail apply.
 *
 * All three branches run for real against real DB connections rather than
 * mocking the DB facade, per this phase's convention for checks on the
 * app's own infrastructure. Two things worth noting from reading the source
 * and probing this directly:
 *
 *  - VERSION() is a MySQL builtin; sqlite doesn't have it. So under this
 *    test suite's real sqlite/:memory: connection (see phpunit.xml), the
 *    check's exact real query genuinely FAILS with "no such function:
 *    VERSION" — confirmed by probing `DB::selectOne('SELECT VERSION() AS
 *    version')` directly. That's exercised below with zero setup, since
 *    it's just what already happens.
 *  - To exercise the "ok" branch for real (not mocked), a *separate*,
 *    throwaway sqlite connection is spun up via config() and pointed at
 *    briefly as the default, with a real VERSION() user-defined function
 *    registered on its own PDO handle via PDO::sqliteCreateFunction() — a
 *    genuine SQLite extension mechanism, not a double of DatabaseCheck, DB,
 *    or Laravel's connection layer. It's kept on an isolated connection
 *    (rather than the shared RefreshDatabase connection) and the default is
 *    always restored in a finally block, so nothing leaks into
 *    RefreshDatabase's per-test transaction bookkeeping or into later tests.
 */
describe('DatabaseCheck', function () {
    it('returns fail against the real sqlite test connection, since VERSION() is a MySQL-only builtin', function () {
        $result = app(DatabaseCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Connection failed')
            ->and($result->detail)->toContain('no such function: VERSION');
    });

    it('returns ok with the reported version when the primary connection answers', function () {
        config(['database.connections.diagnostics_probe' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);

        $originalDefault = config('database.default');
        config(['database.default' => 'diagnostics_probe']);

        try {
            $pdo = DB::connection('diagnostics_probe')->getPdo();
            $pdo->sqliteCreateFunction('VERSION', fn () => '3.44.0-test');

            $result = app(DatabaseCheck::class)->run();
        } finally {
            config(['database.default' => $originalDefault]);
            DB::purge('diagnostics_probe');
        }

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('Connected')
            ->and($result->summary)->toContain('3.44.0-test')
            ->and($result->detail)->not->toBeEmpty();
    });

    it('returns fail with a clear message when the default connection is not configured', function () {
        $originalDefault = config('database.default');
        config(['database.default' => 'clockwork-nonexistent-connection']);

        try {
            $result = app(DatabaseCheck::class)->run();
        } finally {
            config(['database.default' => $originalDefault]);
        }

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Connection failed')
            ->and($result->detail)->toContain('clockwork-nonexistent-connection');
    });
});
