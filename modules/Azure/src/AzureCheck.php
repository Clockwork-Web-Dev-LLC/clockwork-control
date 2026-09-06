<?php

namespace Modules\Azure;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use Throwable;

/**
 * Verifies the OAuth 2.0 client-credentials flow (tenant/client id+secret)
 * by fetching the subscription resource. Skips cleanly (not a failure) when
 * any of the four required env vars is missing — Azure is the newest of the
 * three cloud-metrics providers and not every environment has credentials
 * provisioned for it yet.
 */
class AzureCheck implements DiagnosticCheck
{
    public function id(): string
    {
        return 'azure';
    }

    public function name(): string
    {
        return 'Azure API';
    }

    public function description(): string
    {
        return 'Verify the client-credentials token via GET /subscriptions/{id}.';
    }

    public function run(): CheckResult
    {
        $client = app(AzureClient::class);
        if (! $client->isConfigured()) {
            return CheckResult::skipped('No CLOCKWORK_AZURE_TENANT_ID/CLIENT_ID/CLIENT_SECRET/SUBSCRIPTION_ID set');
        }

        $start = microtime(true);
        try {
            $subscription = $client->subscription();
            $ms = (int) ((microtime(true) - $start) * 1000);

            $name = (string) ($subscription['displayName'] ?? $subscription['subscriptionId'] ?? '?');
            $state = (string) ($subscription['state'] ?? '?');

            return CheckResult::ok("Authed · {$name} · {$state}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
