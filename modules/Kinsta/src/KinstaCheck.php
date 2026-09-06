<?php

namespace Modules\Kinsta;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Throwable;

/**
 * Confirms the Kinsta API key authenticates via KinstaClient::ping(). Mirrors
 * PressableCheck's shape, but can't do a real "GET /account and show whose
 * credentials these are" probe the way Pressable's check does — Kinsta's
 * site-listing endpoint requires a company ID this module doesn't collect as
 * a credential, so ping() only distinguishes "key rejected" (401) from
 * "key accepted" (anything else); see KinstaClient::ping()'s docblock.
 */
class KinstaCheck implements DiagnosticCheck
{
    public function __construct(private readonly CredentialResolver $resolver) {}

    public function id(): string
    {
        return 'kinsta';
    }

    public function name(): string
    {
        return 'Kinsta API';
    }

    public function description(): string
    {
        return 'Verify the API key authenticates against GET /sites (401 vs. any other status).';
    }

    public function run(): CheckResult
    {
        $apiKey = (string) $this->resolver->get('kinsta.api_key');
        if ($apiKey === '') {
            return CheckResult::skipped('No CLOCKWORK_KINSTA_API_KEY set');
        }

        $start = microtime(true);
        try {
            $result = app(KinstaClient::class)->ping();
            $ms = (int) ((microtime(true) - $start) * 1000);
            $viewOnlyVal = $this->resolver->get('kinsta.view_only');
            $isViewOnly = $viewOnlyVal !== null ? filter_var($viewOnlyVal, FILTER_VALIDATE_BOOLEAN) : (bool) config('clockwork.kinsta.view_only', true);
            $modePart = $isViewOnly ? ' · Mode: View Only' : '';

            return CheckResult::ok("API key accepted (HTTP {$result['status']}){$modePart}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
