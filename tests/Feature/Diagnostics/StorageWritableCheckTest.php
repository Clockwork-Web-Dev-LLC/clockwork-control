<?php

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\Checks\StorageWritableCheck;

/**
 * Coverage for StorageWritableCheck (App\Services\Diagnostics\DiagnosticCheck)
 * — writes + deletes a real probe file under storage_path('app'). This check
 * has no "skipped" state (local storage isn't an optional integration), so
 * only ok/fail apply.
 *
 * Both branches run for real against the actual local filesystem rather than
 * mocking it, per this phase's convention for a check whose entire job is
 * confirming real filesystem access works:
 *
 *  - The "ok" test lets the real write+unlink round-trip happen against the
 *    real storage/app directory used by the test run.
 *  - The "fail" test chmod's that same real directory to read-only (0555)
 *    immediately before running the check, so file_put_contents() genuinely
 *    fails — a real permission error, not a mock. Permissions are always
 *    restored in a finally block so the directory is left exactly as found
 *    and no artifact/state leaks into later tests.
 */
describe('StorageWritableCheck', function () {
    it('returns ok after a real write+delete round-trip against storage/app', function () {
        $result = app(StorageWritableCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('Writable')
            ->and($result->summary)->toContain('bytes round-tripped');
    });

    it('returns fail with a real permission error when storage/app is not writable', function () {
        $dir = storage_path('app');
        $originalPerms = fileperms($dir) & 0777;

        chmod($dir, 0555);

        try {
            $result = app(StorageWritableCheck::class)->run();
        } finally {
            chmod($dir, $originalPerms);
        }

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toContain('Cannot write to')
            ->and($result->summary)->toContain($dir)
            ->and($result->detail)->toBe('file_put_contents returned false');
    });
});
