<?php

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\Checks\SucuriCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Coverage for SucuriCheck (App\Services\Diagnostics\DiagnosticCheck).
 * Sucuri SiteCheck is a public, keyless scanner — there's no credential to
 * validate, so "skipped" here means scans are disabled fleet-wide
 * (clockwork.sucuri.enabled), not a missing CLOCKWORK_* env var. The probe
 * itself is just a GET against the configured base_url, treating any
 * sub-500 status as reachable per the check's own run() logic.
 */
describe('SucuriCheck', function () {
    it('skips cleanly when Sucuri scans are disabled fleet-wide', function () {
        config(['clockwork.sucuri.enabled' => false]);

        $result = app(SucuriCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toContain('Sucuri scans disabled');
    });

    it('returns ok when the base_url responds with a sub-500 status', function () {
        config([
            'clockwork.sucuri.enabled' => true,
            'clockwork.sucuri.base_url' => 'https://sitecheck.sucuri.net',
        ]);

        Http::fake([
            'sitecheck.sucuri.net*' => Http::response('<html>ok</html>', 200),
        ]);

        $result = app(SucuriCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('Reachable')
            ->and($result->summary)->toContain('HTTP 200');
    });

    it('returns fail when the base_url returns a server error', function () {
        config([
            'clockwork.sucuri.enabled' => true,
            'clockwork.sucuri.base_url' => 'https://sitecheck.sucuri.net',
        ]);

        Http::fake([
            'sitecheck.sucuri.net*' => Http::response('Bad Gateway', 502),
        ]);

        $result = app(SucuriCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toContain('Server error')
            ->and($result->summary)->toContain('HTTP 502');
    });

    it('returns fail with the exception message when the request itself throws', function () {
        config([
            'clockwork.sucuri.enabled' => true,
            'clockwork.sucuri.base_url' => 'https://sitecheck.sucuri.net',
        ]);

        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $result = app(SucuriCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Request failed')
            ->and($result->detail)->toContain('Connection timed out');
    });
});
