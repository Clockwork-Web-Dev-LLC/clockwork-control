<?php

namespace Modules\WPEngine;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Throwable;

/**
 * GET /installs (limit=1) via the real WPEngineClient, Basic-Auth'd with the
 * API User ID/Password pair — same credential every WP Engine install-list/
 * domain/backup/cert call in this module depends on. Deliberately probes
 * installs() rather than sslCertificates()/backups() — those two endpoints'
 * shapes are unconfirmed against a live account (see WPEngineClient's
 * docblocks), and a diagnostic check should not depend on the very thing
 * it can't yet vouch for.
 */
class WPEngineCheck implements DiagnosticCheck
{
    public function __construct(private readonly CredentialResolver $resolver) {}

    public function id(): string
    {
        return 'wpengine';
    }

    public function name(): string
    {
        return 'WP Engine API';
    }

    public function description(): string
    {
        return 'Verify Basic Auth (API User ID/Password) via GET /installs.';
    }

    public function run(): CheckResult
    {
        $apiUserId = (string) $this->resolver->get('wpengine.api_user_id');
        $apiPassword = (string) $this->resolver->get('wpengine.api_password');
        if ($apiUserId === '' || $apiPassword === '') {
            return CheckResult::skipped('No CLOCKWORK_WPENGINE_API_USER_ID/PASSWORD set');
        }

        $start = microtime(true);
        try {
            $body = app(WPEngineClient::class)->probe();
            $ms = (int) ((microtime(true) - $start) * 1000);

            $count = $body['count'] ?? '?';

            $viewOnlyVal = $this->resolver->get('wpengine.view_only');
            $isViewOnly = $viewOnlyVal !== null ? filter_var($viewOnlyVal, FILTER_VALIDATE_BOOLEAN) : (bool) config('clockwork.wpengine.view_only', true);
            $modePart = $isViewOnly ? ' · Mode: View Only' : '';

            return CheckResult::ok("Authed; {$count} install(s) visible{$modePart}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
