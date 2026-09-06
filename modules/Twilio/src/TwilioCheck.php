<?php

namespace Modules\Twilio;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Throwable;
use Twilio\Rest\Client;

/**
 * Fetches the account resource via the official SDK — read-only, never
 * sends an SMS. TwilioClient itself only exposes sms() (send), so this
 * check talks to the SDK directly rather than routing through it.
 */
class TwilioCheck implements DiagnosticCheck
{
    public function __construct(private readonly CredentialResolver $resolver) {}

    public function id(): string
    {
        return 'twilio';
    }

    public function name(): string
    {
        return 'Twilio API';
    }

    public function description(): string
    {
        return 'Fetch the account resource via the SDK — never sends an SMS.';
    }

    public function run(): CheckResult
    {
        $sid = (string) $this->resolver->get('twilio.account_sid');
        $token = (string) $this->resolver->get('twilio.auth_token');
        $from = (string) $this->resolver->get('twilio.from');
        if (! (bool) $this->resolver->get('twilio.enabled', false) || $sid === '' || $token === '' || $from === '') {
            return CheckResult::skipped('TWILIO_ENABLED/ACCOUNT_SID/AUTH_TOKEN/FROM_NUMBER not fully set');
        }

        $start = microtime(true);
        try {
            $account = (new Client($sid, $token))->api->v2010->accounts($sid)->fetch();
            $ms = (int) ((microtime(true) - $start) * 1000);

            $status = (string) ($account->status ?? '?');

            return CheckResult::ok("Authed · account status: {$status}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
