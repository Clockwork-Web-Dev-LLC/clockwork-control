<?php

namespace Modules\Pressable;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Throwable;

/**
 * GET /account via the real PressableClient — same OAuth2 client_credentials
 * flow every Pressable-hosted site's backups/traffic/security-summary push
 * and command-execution transport depends on. Read-only, no cache-bust
 * (PressableClient::ping() forgets the cached token first; we deliberately
 * don't do that here — no reason to force a fresh OAuth round-trip on every
 * diagnostics page load when a cached token is still valid).
 */
class PressableCheck implements DiagnosticCheck
{
    public function __construct(private readonly CredentialResolver $resolver) {}

    public function id(): string
    {
        return 'pressable';
    }

    public function name(): string
    {
        return 'Pressable API';
    }

    public function description(): string
    {
        return 'Verify OAuth2 client credentials via GET /account.';
    }

    public function run(): CheckResult
    {
        $clientId = (string) $this->resolver->get('pressable.client_id');
        $clientSecret = (string) $this->resolver->get('pressable.client_secret');
        if ($clientId === '' || $clientSecret === '') {
            return CheckResult::skipped('No CLOCKWORK_PRESSABLE_CLIENT_ID/SECRET set');
        }

        $start = microtime(true);
        try {
            $account = app(PressableClient::class)->account();
            $ms = (int) ((microtime(true) - $start) * 1000);

            $email = (string) ($account['email'] ?? $account['name'] ?? '?');

            $viewOnlyVal = $this->resolver->get('pressable.view_only');
            $isViewOnly = $viewOnlyVal !== null ? filter_var($viewOnlyVal, FILTER_VALIDATE_BOOLEAN) : (bool) config('clockwork.pressable.view_only', false);
            $modePart = $isViewOnly ? ' · Mode: View Only' : '';

            return CheckResult::ok("Authed as {$email}{$modePart}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
