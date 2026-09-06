<?php

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Uses Cloudflare's canonical token-verify endpoint:
 * GET /user/tokens/verify → result.status === "active" when the token is
 * valid and not revoked. Read-only; touches nothing in any zone.
 */
class CloudflareCheck implements DiagnosticCheck
{
    public function __construct(private readonly CredentialResolver $resolver) {}

    public function id(): string
    {
        return 'cloudflare';
    }

    public function name(): string
    {
        return 'Cloudflare API';
    }

    public function description(): string
    {
        return 'Verify the read API token via GET /user/tokens/verify.';
    }

    public function run(): CheckResult
    {
        $token = (string) $this->resolver->get('cloudflare.api_token');
        if ($token === '') {
            return CheckResult::skipped('No CLOCKWORK_CLOUDFLARE_API_TOKEN set');
        }

        $baseUrl = (string) $this->resolver->get('cloudflare.base_url', 'https://api.cloudflare.com/client/v4');

        $start = microtime(true);
        try {
            $response = Http::baseUrl($baseUrl)
                ->withToken($token)
                ->timeout(8)
                ->get('/user/tokens/verify');

            $ms = (int) ((microtime(true) - $start) * 1000);
            if ($response->failed()) {
                return CheckResult::fail(
                    "HTTP {$response->status()}",
                    substr((string) $response->body(), 0, 500),
                    $ms,
                );
            }

            $status = (string) ($response->json('result.status') ?? '?');
            $tokenId = (string) ($response->json('result.id') ?? '?');

            return $status === 'active'
                ? CheckResult::ok("Token active · id {$tokenId}", null, $ms)
                : CheckResult::fail("Token status: {$status}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
