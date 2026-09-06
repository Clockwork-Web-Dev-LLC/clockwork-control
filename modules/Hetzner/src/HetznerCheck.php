<?php

namespace Modules\Hetzner;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use Throwable;

/**
 * Hetzner has no /account endpoint, so HetznerClient::account() hits the
 * read-only, ungated /locations list as a "does this token work" probe —
 * same shape as DigitalOceanCheck using /account.
 */
class HetznerCheck implements DiagnosticCheck
{
    public function id(): string
    {
        return 'hetzner';
    }

    public function name(): string
    {
        return 'Hetzner Cloud API';
    }

    public function description(): string
    {
        return 'Verify the API token via GET /locations (Hetzner has no dedicated account endpoint).';
    }

    public function run(): CheckResult
    {
        $client = app(HetznerClient::class);
        if (! $client->isConfigured()) {
            return CheckResult::skipped('No CLOCKWORK_HETZNER_TOKEN set');
        }

        $start = microtime(true);
        try {
            $locations = $client->account();
            $ms = (int) ((microtime(true) - $start) * 1000);

            $count = is_array($locations) ? count($locations) : 0;

            return CheckResult::ok("Token valid · {$count} location(s) visible", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
