<?php

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sucuri SiteCheck has no auth — it's a public scanner. We GET the homepage
 * with a short timeout to confirm the host is up and reachable; security
 * scans depend on this. Skipped when scans are disabled fleet-wide.
 */
class SucuriCheck implements DiagnosticCheck
{
    public function id(): string
    {
        return 'sucuri';
    }

    public function name(): string
    {
        return 'Sucuri SiteCheck';
    }

    public function description(): string
    {
        return 'Reach sitecheck.sucuri.net (no auth required).';
    }

    public function run(): CheckResult
    {
        if (! config('clockwork.sucuri.enabled', true)) {
            return CheckResult::skipped('Sucuri scans disabled');
        }

        $baseUrl = (string) config('clockwork.sucuri.base_url', 'https://sitecheck.sucuri.net');

        $start = microtime(true);
        try {
            $response = Http::timeout(8)->get($baseUrl);
            $ms = (int) ((microtime(true) - $start) * 1000);

            return $response->status() < 500
                ? CheckResult::ok("Reachable · HTTP {$response->status()}", null, $ms)
                : CheckResult::fail("Server error · HTTP {$response->status()}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
