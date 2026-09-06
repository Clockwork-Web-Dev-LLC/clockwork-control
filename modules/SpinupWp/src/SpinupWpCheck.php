<?php

namespace Modules\SpinupWp;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * SpinupWP doesn't publish a "verify token" endpoint, so we hit GET /servers
 * with per_page=1 — minimal payload that still proves the bearer token is
 * valid. 200 OK = good, 401 = bad token.
 */
class SpinupWpCheck implements DiagnosticCheck
{
    public function __construct(private readonly CredentialResolver $resolver) {}

    public function id(): string
    {
        return 'spinupwp';
    }

    public function name(): string
    {
        return 'SpinupWP API';
    }

    public function description(): string
    {
        return 'Verify the API token via GET /servers?per_page=1.';
    }

    public function run(): CheckResult
    {
        $token = (string) $this->resolver->get('spinupwp.token');
        if ($token === '') {
            return CheckResult::skipped('No CLOCKWORK_SPINUPWP_TOKEN set');
        }

        $baseUrl = (string) $this->resolver->get('spinupwp.base_url', 'https://api.spinupwp.app/v1');

        $start = microtime(true);
        try {
            $response = Http::baseUrl($baseUrl)
                ->withToken($token)
                ->timeout(8)
                ->get('/servers', ['per_page' => 1]);

            $ms = (int) ((microtime(true) - $start) * 1000);
            if ($response->failed()) {
                return CheckResult::fail(
                    "HTTP {$response->status()}",
                    substr((string) $response->body(), 0, 500),
                    $ms,
                );
            }

            $total = $response->json('meta.total');
            $totalPart = is_numeric($total) ? " · {$total} servers visible" : '';

            $viewOnlyVal = $this->resolver->get('spinupwp.view_only');
            $isViewOnly = $viewOnlyVal !== null ? filter_var($viewOnlyVal, FILTER_VALIDATE_BOOLEAN) : (bool) config('clockwork.spinupwp.view_only', false);
            $modePart = $isViewOnly ? ' · Mode: View Only' : '';

            return CheckResult::ok("Token valid{$totalPart}{$modePart}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
