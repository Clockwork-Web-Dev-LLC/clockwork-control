<?php

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\Checks\GoogleOAuthCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Coverage for GoogleOAuthCheck (App\Services\Diagnostics\DiagnosticCheck).
 *
 * Unlike most checks in this suite, GoogleOAuthCheck does NOT return
 * CheckResult::STATUS_SKIPPED when unconfigured — its own run() calls
 * CheckResult::fail() for missing GOOGLE_CLIENT_ID/GOOGLE_CLIENT_SECRET, so
 * that path is asserted as STATUS_FAIL here to match the real implementation
 * rather than the skip/ok/fail convention most other checks follow.
 *
 * When both creds are present, the check reaches Google's OpenID discovery
 * document over Http::fake() — it never touches the client_secret itself
 * (no code-exchange round trip is possible outside a real OAuth flow).
 */
describe('GoogleOAuthCheck', function () {
    it('fails when GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET are both missing', function () {
        config([
            'services.google.client_id' => '',
            'services.google.client_secret' => '',
        ]);

        $result = app(GoogleOAuthCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toContain('GOOGLE_CLIENT_ID')
            ->and($result->summary)->toContain('GOOGLE_CLIENT_SECRET');
    });

    it('fails when only the client id is missing', function () {
        config([
            'services.google.client_id' => '',
            'services.google.client_secret' => 'secret-1',
        ]);

        $result = app(GoogleOAuthCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toContain('GOOGLE_CLIENT_ID');
    });

    it('fails when only the client secret is missing', function () {
        config([
            'services.google.client_id' => 'client-1',
            'services.google.client_secret' => '',
        ]);

        $result = app(GoogleOAuthCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toContain('GOOGLE_CLIENT_SECRET');
    });

    it('returns ok when both creds are set and the discovery doc is reachable', function () {
        config([
            'services.google.client_id' => 'my-really-long-client-id.apps.googleusercontent.com',
            'services.google.client_secret' => 'shhh',
        ]);

        Http::fake([
            'accounts.google.com/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://accounts.google.com',
            ], 200),
        ]);

        $result = app(GoogleOAuthCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('https://accounts.google.com')
            // the check truncates client ids over 12 chars to their first 8
            // chars + an ellipsis before including them in the summary.
            ->and($result->summary)->toContain('my-reall…')
            ->and($result->summary)->not->toContain('googleusercontent');
    });

    it('includes the full client id in the summary when it is 12 characters or fewer', function () {
        config([
            'services.google.client_id' => 'short-id',
            'services.google.client_secret' => 'shhh',
        ]);

        Http::fake([
            'accounts.google.com/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://accounts.google.com',
            ], 200),
        ]);

        $result = app(GoogleOAuthCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('short-id');
    });

    it('returns fail with the HTTP status when the discovery doc responds with an error', function () {
        config([
            'services.google.client_id' => 'client-1',
            'services.google.client_secret' => 'secret-1',
        ]);

        Http::fake([
            'accounts.google.com/.well-known/openid-configuration' => Http::response('', 503),
        ]);

        $result = app(GoogleOAuthCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Discovery doc HTTP 503');
    });

    it('returns fail with the exception message when the discovery request throws', function () {
        config([
            'services.google.client_id' => 'client-1',
            'services.google.client_secret' => 'secret-1',
        ]);

        Http::fake(function () {
            throw new ConnectionException('Could not resolve host: accounts.google.com');
        });

        $result = app(GoogleOAuthCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Discovery request failed')
            ->and($result->detail)->toContain('Could not resolve host');
    });
});
