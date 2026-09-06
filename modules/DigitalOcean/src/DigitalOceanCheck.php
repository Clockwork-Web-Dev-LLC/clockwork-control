<?php

namespace Modules\DigitalOcean;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Hits GET /v2/account on DigitalOcean's API. The account endpoint is a
 * read-only "who am I" probe — returns 200 with the account email + status
 * when the token is valid, 401 when not. Never mutates state.
 */
class DigitalOceanCheck implements DiagnosticCheck
{
    public function __construct(private readonly CredentialResolver $resolver) {}

    public function id(): string
    {
        return 'digitalocean';
    }

    public function name(): string
    {
        return 'DigitalOcean API';
    }

    public function description(): string
    {
        return 'Verify the personal access token via GET /v2/account.';
    }

    public function run(): CheckResult
    {
        $token = (string) $this->resolver->get('digitalocean.token');
        if ($token === '') {
            return CheckResult::skipped('No CLOCKWORK_DIGITALOCEAN_TOKEN set');
        }

        $baseUrl = (string) $this->resolver->get('digitalocean.base_url', 'https://api.digitalocean.com/v2');

        $start = microtime(true);
        try {
            $response = Http::baseUrl($baseUrl)
                ->withToken($token)
                ->timeout(8)
                ->get('/account');

            $ms = (int) ((microtime(true) - $start) * 1000);
            if ($response->failed()) {
                return CheckResult::fail(
                    "HTTP {$response->status()}",
                    substr((string) $response->body(), 0, 500),
                    $ms,
                );
            }

            $email = (string) ($response->json('account.email') ?? '?');
            $status = (string) ($response->json('account.status') ?? '?');

            return CheckResult::ok("Authed as {$email} · {$status}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
