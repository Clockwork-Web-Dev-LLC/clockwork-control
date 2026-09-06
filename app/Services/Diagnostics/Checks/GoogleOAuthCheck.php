<?php

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Two-part check:
 * 1. Both client_id and client_secret must be set in config.
 * 2. Google's OpenID discovery doc must be reachable — proves outbound
 *    HTTPS to Google's auth surface works.
 *
 * We can't actually validate the client_secret without an authorization
 * code, and that requires a real user round-trip. Reaching the discovery
 * doc + having both creds set catches the common breakages: missing env,
 * blocked egress, expired DNS, etc.
 */
class GoogleOAuthCheck implements DiagnosticCheck
{
    public function id(): string
    {
        return 'google-oauth';
    }

    public function name(): string
    {
        return 'Google OAuth';
    }

    public function description(): string
    {
        return 'Verify creds present + reach the OpenID discovery doc.';
    }

    public function run(): CheckResult
    {
        $clientId = (string) config('services.google.client_id');
        $clientSecret = (string) config('services.google.client_secret');

        if ($clientId === '' || $clientSecret === '') {
            return CheckResult::fail(
                'Missing GOOGLE_CLIENT_ID or GOOGLE_CLIENT_SECRET',
                'Set both in .env to enable Google login.',
            );
        }

        $start = microtime(true);
        try {
            $response = Http::timeout(8)->get('https://accounts.google.com/.well-known/openid-configuration');
            $ms = (int) ((microtime(true) - $start) * 1000);

            if ($response->failed()) {
                return CheckResult::fail("Discovery doc HTTP {$response->status()}", null, $ms);
            }

            $issuer = (string) ($response->json('issuer') ?? '?');
            $shortClient = strlen($clientId) > 12 ? substr($clientId, 0, 8).'…' : $clientId;

            return CheckResult::ok("Reachable · client {$shortClient} · {$issuer}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Discovery request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
