<?php

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Proves the configured Google threat-intel key works. Prefers Cloud Web Risk
 * (`uris:search`) when CLOCKWORK_GOOGLE_WEB_RISK_KEY is set; otherwise hits
 * legacy Safe Browsing v4 (`threatMatches:find`). A 200 — even with an empty
 * threat/matches body — means the key is valid. Feeds the daily blacklist
 * scan (`clockwork:check-blacklists`).
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
        return 'Google Web Risk / Safe Browsing';
    }

    public function description(): string
    {
        return 'Lookup a known-clean URL to verify the Web Risk (or legacy Safe Browsing v4) API key.';
    }

    public function run(): CheckResult
    {
        $webRiskKey = trim((string) $this->resolver->get('security_scans.google_web_risk_key', ''));
        $gsbKey = trim((string) $this->resolver->get('security_scans.google_safe_browsing_key', ''));

        if ($webRiskKey !== '') {
            return $this->checkWebRisk($webRiskKey);
        }

        if ($gsbKey !== '') {
            return $this->checkSafeBrowsingV4($gsbKey);
        }

        return CheckResult::skipped('No CLOCKWORK_GOOGLE_WEB_RISK_KEY or CLOCKWORK_GOOGLE_SAFE_BROWSING_KEY set');
    }

    private function checkWebRisk(string $key): CheckResult
    {
        $start = microtime(true);
        try {
            $url = 'https://webrisk.googleapis.com/v1/uris:search?'.http_build_query([
                'uri' => 'https://www.google.com/',
                'key' => $key,
            ]).'&threatTypes=MALWARE';

            $response = Http::timeout(8)->acceptJson()->get($url);
            $ms = (int) ((microtime(true) - $start) * 1000);

            if ($response->failed()) {
                return CheckResult::fail(
                    "HTTP {$response->status()}",
                    substr((string) $response->body(), 0, 500),
                    $ms,
                );
            }

            return CheckResult::ok("Web Risk key valid · HTTP {$response->status()}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }

    private function checkSafeBrowsingV4(string $key): CheckResult
    {
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

            return CheckResult::ok("Safe Browsing v4 key valid · HTTP {$response->status()}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
