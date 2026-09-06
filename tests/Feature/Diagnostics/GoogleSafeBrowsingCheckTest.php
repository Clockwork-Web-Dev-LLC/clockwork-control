<?php

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\Checks\GoogleSafeBrowsingCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Coverage for GoogleSafeBrowsingCheck (App\Services\Diagnostics\DiagnosticCheck).
 * threatMatches:find has no dedicated "verify key" endpoint, so run() sends
 * a real minimal request checking a known-clean URL — any 200 (even zero
 * matches) proves the key is valid, per the check's own docblock. Note this
 * only exercises run()'s own logic; the incidental mocks of this class in
 * tests/Feature/Controllers/IntegrationCredentialsControllerTest.php test a
 * *different* class's (IntegrationCredentialsController) dispatch behavior
 * and never call through to this check's real run().
 *
 * GoogleSafeBrowsingCheck resolves its key via CredentialResolver, which is
 * DB-first with config() fallback — CredentialResolver::load() reads the
 * (empty, per-test) integration_credentials table, so plain config() calls
 * here are sufficient without seeding a row.
 */
describe('GoogleSafeBrowsingCheck', function () {
    it('skips cleanly when no Google Safe Browsing key is configured', function () {
        config(['clockwork.security_scans.google_safe_browsing_key' => '']);

        $result = app(GoogleSafeBrowsingCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toContain('CLOCKWORK_GOOGLE_SAFE_BROWSING_KEY');
    });

    it('returns ok when threatMatches:find responds 200 for a valid key', function () {
        config(['clockwork.security_scans.google_safe_browsing_key' => 'gsb-key']);

        Http::fake([
            'safebrowsing.googleapis.com/v4/threatMatches:find*' => Http::response([
                'matches' => [],
            ], 200),
        ]);

        $result = app(GoogleSafeBrowsingCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('Key valid')
            ->and($result->summary)->toContain('HTTP 200');

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'key=gsb-key')
                && $request['threatInfo']['threatEntries'][0]['url'] === 'https://www.google.com/';
        });
    });

    it('returns fail with the response body when the key is rejected', function () {
        config(['clockwork.security_scans.google_safe_browsing_key' => 'bad-key']);

        Http::fake([
            'safebrowsing.googleapis.com/v4/threatMatches:find*' => Http::response([
                'error' => [
                    'code' => 400,
                    'message' => 'API key not valid. Please pass a valid API key.',
                    'status' => 'INVALID_ARGUMENT',
                ],
            ], 400),
        ]);

        $result = app(GoogleSafeBrowsingCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('HTTP 400')
            ->and($result->detail)->toContain('API key not valid');
    });

    it('returns fail with the exception message when the request itself throws', function () {
        config(['clockwork.security_scans.google_safe_browsing_key' => 'gsb-key']);

        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $result = app(GoogleSafeBrowsingCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Request failed')
            ->and($result->detail)->toContain('Connection timed out');
    });
});
