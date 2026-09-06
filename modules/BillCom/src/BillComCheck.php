<?php

namespace Modules\BillCom;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Throwable;

/**
 * Bill.com login is the auth probe — successful Login.json returns a
 * sessionId we can use, and the client's ping() forces a fresh login (it
 * clears the cached session first). Read-only.
 */
class BillComCheck implements DiagnosticCheck
{
    public function __construct(private readonly CredentialResolver $resolver) {}

    public function id(): string
    {
        return 'bill-com';
    }

    public function name(): string
    {
        return 'Bill.com API';
    }

    public function description(): string
    {
        return 'Force-login via /Login.json to verify all four credentials.';
    }

    public function run(): CheckResult
    {
        if (! (bool) $this->resolver->get('bill_com.enabled', false)) {
            return CheckResult::skipped('Bill.com sync is disabled');
        }

        $required = ['username', 'password', 'org_id', 'dev_key'];
        foreach ($required as $key) {
            if ((string) $this->resolver->get("bill_com.{$key}") === '') {
                return CheckResult::fail("Missing config: bill_com.{$key}");
            }
        }

        $start = microtime(true);
        try {
            $client = app(BillComClient::class);
            $sessionId = $client->ping();
            $ms = (int) ((microtime(true) - $start) * 1000);

            $masked = strlen($sessionId) > 8 ? substr($sessionId, 0, 4).'…'.substr($sessionId, -4) : '****';

            return CheckResult::ok("Login OK · session {$masked}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Login failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
