<?php

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\Checks\OutboundHttpCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Coverage for OutboundHttpCheck (App\Services\Diagnostics\DiagnosticCheck)
 * — the control check that proves outbound HTTPS egress works at all, by
 * hitting the stable, anonymous api.github.com root. It has no credential
 * and therefore no "skipped" state; only ok/fail apply. Kept fully offline
 * here via Http::fake() (see the docblock note in
 * tests/Feature/Controllers/DiagnosticsControllerTest.php referencing this
 * check, which fakes it for an unrelated controller test but doesn't
 * exercise its own ok/fail/exception branches — this file does).
 */
describe('OutboundHttpCheck', function () {
    it('returns ok when api.github.com responds successfully', function () {
        Http::fake([
            'api.github.com/' => Http::response(['current_user_url' => 'https://api.github.com/user'], 200),
        ]);

        $result = app(OutboundHttpCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('Reachable')
            ->and($result->summary)->toContain('HTTP 200');
    });

    it('returns fail with the status and body when api.github.com responds with an error', function () {
        Http::fake([
            'api.github.com/' => Http::response('Service Unavailable', 503),
        ]);

        $result = app(OutboundHttpCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('HTTP 503')
            ->and($result->detail)->toContain('Service Unavailable');
    });

    it('returns fail with the exception message when the network is unreachable', function () {
        Http::fake(function () {
            throw new ConnectionException('Could not resolve host: api.github.com');
        });

        $result = app(OutboundHttpCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Network unreachable')
            ->and($result->detail)->toContain('Could not resolve host');
    });
});
