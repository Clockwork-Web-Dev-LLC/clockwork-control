<?php

namespace Modules\Cloudways;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies Cloudways credentials by performing the OAuth2 token exchange
 * (POST /oauth/access_token) and, on success, a minimal follow-up GET
 * /server call. Mirrors SpinupWpCheck/DigitalOceanCheck's shape: a cheap,
 * read-only probe that proves the credentials actually authenticate,
 * rather than only checking they're non-empty.
 *
 * Uses CloudwaysClient directly (rather than reimplementing the HTTP calls
 * inline, as SpinupWpCheck/DigitalOceanCheck do) because the token exchange
 * is a two-step flow — this keeps that logic in one place.
 */
class CloudwaysCheck implements DiagnosticCheck
{
    public function __construct(private readonly CredentialResolver $resolver) {}

    public function id(): string
    {
        return 'cloudways';
    }

    public function name(): string
    {
        return 'Cloudways API';
    }

    public function description(): string
    {
        return 'Verify the API key via OAuth2 token exchange, then GET /server.';
    }

    public function run(): CheckResult
    {
        $apiKey = (string) $this->resolver->get('cloudways.api_key');
        $email = (string) $this->resolver->get('cloudways.email');
        if ($apiKey === '' || $email === '') {
            return CheckResult::skipped('No CLOCKWORK_CLOUDWAYS_API_KEY / CLOCKWORK_CLOUDWAYS_EMAIL set');
        }

        $baseUrl = (string) $this->resolver->get('cloudways.base_url', 'https://api.cloudways.com/api/v2');
        $timeout = (int) $this->resolver->get('cloudways.timeout', 15);

        $start = microtime(true);
        try {
            $client = new CloudwaysClient(
                apiKey: $apiKey,
                email: $email,
                baseUrl: $baseUrl,
                timeout: $timeout,
            );

            $servers = $client->servers();

            $ms = (int) ((microtime(true) - $start) * 1000);

            $viewOnlyVal = $this->resolver->get('cloudways.view_only');
            $isViewOnly = $viewOnlyVal !== null ? filter_var($viewOnlyVal, FILTER_VALIDATE_BOOLEAN) : (bool) config('clockwork.cloudways.view_only', true);
            $modePart = $isViewOnly ? ' · Mode: View Only' : '';

            return CheckResult::ok(
                'Token exchange OK · '.count($servers)." servers visible{$modePart}",
                null,
                $ms,
            );
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
