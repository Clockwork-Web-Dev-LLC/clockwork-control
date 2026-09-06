<?php

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Illuminate\Support\Facades\Http;
use Throwable;

class MicrosoftOAuthCheck implements DiagnosticCheck
{
    public function id(): string
    {
        return 'microsoft-oauth';
    }

    public function name(): string
    {
        return 'Microsoft OAuth';
    }

    public function description(): string
    {
        return 'Verify credentials present and reach Microsoft Entra discovery doc.';
    }

    public function run(): CheckResult
    {
        $resolver = app(CredentialResolver::class);
        $clientId = (string) ($resolver->get('auth_microsoft.client_id') ?? config('services.microsoft.client_id', ''));
        $clientSecret = (string) ($resolver->get('auth_microsoft.client_secret') ?? config('services.microsoft.client_secret', ''));
        $tenantId = (string) ($resolver->get('auth_microsoft.tenant_id') ?? config('services.microsoft.tenant_id', 'common'));
        if ($tenantId === '') {
            $tenantId = 'common';
        }

        if ($clientId === '' || $clientSecret === '') {
            return CheckResult::fail(
                'Missing MICROSOFT_CLIENT_ID or MICROSOFT_CLIENT_SECRET',
                'Set both in .env to enable Microsoft login.',
            );
        }

        $start = microtime(true);
        try {
            $response = Http::timeout(8)->get("https://login.microsoftonline.com/{$tenantId}/v2.0/.well-known/openid-configuration");
            $ms = (int) ((microtime(true) - $start) * 1000);

            if ($response->failed()) {
                return CheckResult::fail("Discovery doc HTTP {$response->status()}", null, $ms);
            }

            $shortClient = strlen($clientId) > 12 ? substr($clientId, 0, 8).'…' : $clientId;

            return CheckResult::ok("Reachable · client {$shortClient} · tenant {$tenantId}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Discovery request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
