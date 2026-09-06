<?php

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Control check — proves outbound HTTPS works at all. If this fails but
 * the integration-specific checks below also fail, the problem is almost
 * certainly the network (firewall, DNS, proxy), not anyone's API token.
 *
 * api.github.com is a stable, fast, anonymous endpoint that returns 200
 * with a tiny JSON body (no auth required for the root).
 */
class OutboundHttpCheck implements DiagnosticCheck
{
    public function id(): string
    {
        return 'outbound-https';
    }

    public function name(): string
    {
        return 'Outbound HTTPS';
    }

    public function description(): string
    {
        return 'Reach api.github.com — control check for general network egress.';
    }

    public function run(): CheckResult
    {
        $start = microtime(true);
        try {
            $response = Http::timeout(8)->get('https://api.github.com/');
            $ms = (int) ((microtime(true) - $start) * 1000);

            return $response->successful()
                ? CheckResult::ok("Reachable · HTTP {$response->status()}", null, $ms)
                : CheckResult::fail("HTTP {$response->status()}", substr((string) $response->body(), 0, 200), $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Network unreachable',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
