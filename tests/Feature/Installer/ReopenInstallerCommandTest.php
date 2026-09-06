<?php

use App\Http\Middleware\EnforceInstallerGate;
use App\Models\ActionLog;

describe('clockwork:installer:reopen', function () {
    afterEach(function () {
        EnforceInstallerGate::fake(null);
        @unlink(EnforceInstallerGate::sentinelPath());
        @unlink(storage_path('installer_reopened'));
    });

    it('reopens the installer by removing the sentinel file with --force', function () {
        $sentinel = EnforceInstallerGate::sentinelPath();
        file_put_contents($sentinel, '{"installed_at":"2026-09-05"}');

        expect(file_exists($sentinel))->toBeTrue();

        $this->artisan('clockwork:installer:reopen', ['--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Web installer has been reopened.');

        expect(file_exists($sentinel))->toBeFalse();

        expect(ActionLog::where('action_type', ActionLog::TYPE_INSTALLER_REOPENED)
            ->where('summary', 'like', '%Web installer reopened%')
            ->exists())->toBeTrue();
    });

    it('handles reopen when installer is already open', function () {
        $sentinel = EnforceInstallerGate::sentinelPath();
        @unlink($sentinel);

        $this->artisan('clockwork:installer:reopen', ['--force' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('The installer is already open');
    });
});
