<?php

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * threatMatches:find has no dedicated "verify key" endpoint, so this sends
 * the same minimal, real request shape BlacklistChecker uses (see
 * app/Services/Security/BlacklistChecker.php), checking a single
 * known-clean URL (google.com). A 200 — even with zero matches — proves the
 * key is valid; Google returns 400 with an explicit "API key not valid"
 * message for a bad key. Feeds the daily blacklist scan
 * (clockwork:check-blacklists).
 */
class GoogleSafeBrowsingCheck implements DiagnosticCheck
{
    public function __construct(private readonly CredentialResolver $resolver) {}

    public function id(): string
    {
        return 'google-safe-browsing';
    }

    public function name(): string
    {
        return 'Google Safe Browsing API';
    }

    public function description(): string
    {
        return 'POST a minimal threatMatches:find request for a known-clean URL to verify the key.';
    }

    public function run(): CheckResult
    {
        $key = (string) $this->resolver->get('security_scans.google_safe_browsing_key', '');
        if ($key === '') {
            return CheckResult::skipped('No CLOCKWORK_GOOGLE_SAFE_BROWSING_KEY set');
        }

        $start = microtime(true);
        try {
            $response = Http::timeout(8)->asJson()->acceptJson()->post(
                'https://safebrowsing.googleapis.com/v4/threatMatches:find?key='.urlencode($key),
                [
                    'client' => ['clientId' => 'clockwork', 'clientVersion' => '1.0'],
                    'threatInfo' => [
                        'threatTypes' => ['MALWARE'],
                        'platformTypes' => ['ANY_PLATFORM'],
                        'threatEntryTypes' => ['URL'],
                        'threatEntries' => [['url' => 'https://www.google.com/']],
                    ],
                ],
            );
            $ms = (int) ((microtime(true) - $start) * 1000);

            if ($response->failed()) {
                return CheckResult::fail(
                    "HTTP {$response->status()}",
                    substr((string) $response->body(), 0, 500),
                    $ms,
                );
            }

            return CheckResult::ok("Key valid · HTTP {$response->status()}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
