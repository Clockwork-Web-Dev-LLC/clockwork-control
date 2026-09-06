<?php

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\Checks\CloudflareCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\CloudflareFixtures;

/**
 * Coverage for CloudflareCheck (App\Services\Diagnostics\DiagnosticCheck).
 * Uses Cloudflare's token-verify endpoint (GET /user/tokens/verify) via the
 * CredentialResolver-backed api_token — result.status === "active" is the
 * only success condition; any other status string, or a non-2xx response,
 * or a transport-level exception, all map to STATUS_FAIL.
 */
describe('CloudflareCheck', function () {
    it('skips cleanly when no Cloudflare API token is configured', function () {
        config(['clockwork.cloudflare.api_token' => '']);

        $result = app(CloudflareCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toContain('CLOCKWORK_CLOUDFLARE_API_TOKEN');
    });

    it('returns ok when the token verifies as active', function () {
        config(['clockwork.cloudflare.api_token' => 'cf-token']);

        Http::fake([
            'api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(
                CloudflareFixtures::envelope(['id' => 'token-abc123', 'status' => 'active']),
                200,
            ),
        ]);

        $result = app(CloudflareCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('Token active')
            ->and($result->summary)->toContain('token-abc123');
    });

    it('returns fail when the token verifies but is not active', function () {
        config(['clockwork.cloudflare.api_token' => 'cf-token']);

        Http::fake([
            'api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(
                CloudflareFixtures::envelope(['id' => 'token-abc123', 'status' => 'disabled']),
                200,
            ),
        ]);

        $result = app(CloudflareCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Token status: disabled');
    });

    it('returns fail with the HTTP status and body when the token is rejected', function () {
        config(['clockwork.cloudflare.api_token' => 'bad-token']);

        Http::fake([
            'api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(
                array_merge(
                    CloudflareFixtures::envelope([], false),
                    ['errors' => [['code' => 1000, 'message' => 'Invalid API Token']]],
                ),
                401,
            ),
        ]);

        $result = app(CloudflareCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('HTTP 401')
            ->and($result->detail)->toContain('Invalid API Token');
    });

    it('returns fail with the exception message when the request itself throws', function () {
        config(['clockwork.cloudflare.api_token' => 'cf-token']);

        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $result = app(CloudflareCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Request failed')
            ->and($result->detail)->toContain('Connection timed out');
    });
});
