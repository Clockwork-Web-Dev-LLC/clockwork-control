<?php

namespace Modules\Linode;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use Throwable;

/**
 * Diagnostic probe for Linode API v4.
 * Hits GET /v4/account as a read-only token check.
 */
class LinodeCheck implements DiagnosticCheck
{
    public function id(): string
    {
        return 'linode';
    }

    public function name(): string
    {
        return 'Linode API';
    }

    public function description(): string
    {
        return 'Verify the personal access token via GET /v4/account.';
    }

    public function run(): CheckResult
    {
        $client = app(LinodeClient::class);
        if (! $client->isConfigured()) {
            return CheckResult::skipped('No CLOCKWORK_LINODE_TOKEN set');
        }

        $start = microtime(true);
        try {
            $account = $client->account();
            $ms = (int) ((microtime(true) - $start) * 1000);

            $email = (string) ($account['email'] ?? '?');
            $company = (string) ($account['company'] ?? '');

            $info = $company !== '' ? "{$email} ({$company})" : $email;

            return CheckResult::ok("Token valid · Authed as {$info}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
