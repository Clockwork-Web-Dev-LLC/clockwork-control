<?php

namespace Modules\Vultr;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use Throwable;

/**
 * Diagnostic probe for the Vultr API v2.
 * Hits GET /v2/account as a read-only token check.
 */
class VultrCheck implements DiagnosticCheck
{
    public function id(): string
    {
        return 'vultr';
    }

    public function name(): string
    {
        return 'Vultr API';
    }

    public function description(): string
    {
        return 'Verify the API key via GET /v2/account.';
    }

    public function run(): CheckResult
    {
        $client = app(VultrClient::class);
        if (! $client->isConfigured()) {
            return CheckResult::skipped('No CLOCKWORK_VULTR_API_KEY set');
        }

        $start = microtime(true);
        try {
            $account = $client->account();
            $ms = (int) ((microtime(true) - $start) * 1000);

            $name = (string) ($account['name'] ?? '');
            $email = (string) ($account['email'] ?? '?');

            $info = $name !== '' ? "{$email} ({$name})" : $email;

            return CheckResult::ok("API key valid · Authed as {$info}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
