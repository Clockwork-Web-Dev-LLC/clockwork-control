<?php

namespace Modules\Sucuri;

use App\Models\Site;
use App\Models\SiteSecurityScan;
use App\Services\Security\SecurityScanResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Thin wrapper around Sucuri's public SiteCheck v3 API. No auth — Sucuri
 * intentionally exposes the same scanner that ManageWP, Wordfence Central,
 * and many other dashboards consume. Free tier is rate-limited around 30
 * req/min, so callers are expected to space scans by ~250ms.
 *
 * Endpoint shape (v3, observed 2026-05):
 *   GET https://sitecheck.sucuri.net/api/v3/?scan=<url>
 *   200 with JSON body. Clean sites return {site, scan, recommendations, tls, ratings};
 *   blacklisted/infected sites add top-level `blacklist` and/or `malware` keys.
 *   Unreachable sites return {warnings.scan_failed: [...]}.
 *
 * Mirrors the read-only shape of CloudflareClient: Http::baseUrl + acceptJson +
 * timeout + retry(2, 500, throw: false). Caller decides what to do with failures.
 */
class SucuriSiteCheckClient
{
    public function __construct(
        protected ?string $baseUrl = null,
        protected ?int $timeout = null,
    ) {
        $this->baseUrl ??= (string) config('clockwork.sucuri.base_url', 'https://sitecheck.sucuri.net');
        $this->timeout ??= (int) config('clockwork.sucuri.timeout', 30);
    }

    /**
     * Run a scan against a public URL and return the parsed JSON body.
     *
     * Returns the full payload so the caller can record the raw response
     * for forensics. Throws only on configuration errors; HTTP failures
     * surface in the response shape (see `wasFailed()`-style helpers).
     *
     * @return array<string, mixed>
     */
    public function scan(string $url): array
    {
        $response = $this->client()->get('/api/v3/', ['scan' => $url]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Sucuri SiteCheck scan failed for {$url}: HTTP {$response->status()} {$response->body()}"
            );
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException(
                "Sucuri SiteCheck returned non-JSON body for {$url}: {$response->body()}"
            );
        }

        return $body;
    }

    /**
     * One-shot helper: scan the site, time the round-trip, and return a
     * SecurityScanResult ready for the recorder. Failures (network, HTTP,
     * non-JSON, Sucuri-side scan_failed) all collapse into status='failed'
     * with the cause captured in error/details.
     */
    public function scanSite(Site $site): SecurityScanResult
    {
        $url = 'https://'.$site->domain;
        $started = (int) (microtime(true) * 1000);
        try {
            $payload = $this->scan($url);
        } catch (Throwable $e) {
            return new SecurityScanResult(
                site: $site,
                scanType: SiteSecurityScan::TYPE_SITECHECK,
                status: SiteSecurityScan::STATUS_FAILED,
                summary: 'SiteCheck request failed.',
                error: substr($e->getMessage(), 0, 480),
                elapsedMs: (int) (microtime(true) * 1000) - $started,
            );
        }

        return $this->interpret($site, $payload, (int) (microtime(true) * 1000) - $started);
    }

    /**
     * Convert a raw v3 payload into a SecurityScanResult. Public so unit tests
     * can pin the parser without making real HTTP calls.
     *
     * Decision tree (observed v3 shape, 2026-05):
     *   - top-level `warnings.scan_failed` non-empty   → status='failed'
     *   - `blacklist.flagged` true OR `blacklist.warnings` non-empty
     *                                                  → blacklist_hit=true
     *   - `malware.found`    true OR `malware.warnings` non-empty
     *                                                  → has_malware_hit=true
     *   - either of the above                          → status='issues_found'
     *   - otherwise                                    → status='clean'
     *
     * @param  array<string, mixed>  $payload
     */
    public function interpret(Site $site, array $payload, ?int $elapsedMs = null): SecurityScanResult
    {
        $scanFailures = data_get($payload, 'warnings.scan_failed');
        if (is_array($scanFailures) && $scanFailures !== []) {
            $first = $scanFailures[0] ?? [];
            $msg = trim((string) (($first['msg'] ?? '').' '.($first['details'] ?? '')));

            return new SecurityScanResult(
                site: $site,
                scanType: SiteSecurityScan::TYPE_SITECHECK,
                status: SiteSecurityScan::STATUS_FAILED,
                summary: 'Sucuri could not reach the site.',
                details: ['scan_failed' => $scanFailures],
                error: $msg !== '' ? substr($msg, 0, 480) : 'scan_failed',
                elapsedMs: $elapsedMs,
            );
        }

        $blacklistHit = (bool) data_get($payload, 'blacklist.flagged', false)
            || $this->nonEmptyList(data_get($payload, 'blacklist.warnings'))
            || $this->nonEmptyList(data_get($payload, 'BLACKLIST.WARN'));

        $malwareHit = (bool) data_get($payload, 'malware.found', false)
            || $this->nonEmptyList(data_get($payload, 'malware.warnings'))
            || $this->nonEmptyList(data_get($payload, 'MALWARE.WARN'));

        if ($blacklistHit || $malwareHit) {
            $bits = [];
            if ($malwareHit) {
                $bits[] = 'malware detected';
            }
            if ($blacklistHit) {
                $bits[] = 'on a blacklist';
            }

            return new SecurityScanResult(
                site: $site,
                scanType: SiteSecurityScan::TYPE_SITECHECK,
                status: SiteSecurityScan::STATUS_ISSUES_FOUND,
                hasMalwareHit: $malwareHit,
                blacklistHit: $blacklistHit,
                summary: 'Sucuri SiteCheck — '.implode(', ', $bits).'.',
                details: $payload,
                elapsedMs: $elapsedMs,
            );
        }

        $rating = data_get($payload, 'ratings.security.rating');
        $passed = data_get($payload, 'ratings.security.passed');
        $summaryBits = ['Sucuri SiteCheck — clean'];
        if (is_string($rating) && $rating !== '') {
            $summaryBits[] = "security {$rating}";
        }
        if (is_string($passed) && $passed !== '') {
            $summaryBits[] = "{$passed} checks";
        }

        return new SecurityScanResult(
            site: $site,
            scanType: SiteSecurityScan::TYPE_SITECHECK,
            status: SiteSecurityScan::STATUS_CLEAN,
            summary: implode(', ', $summaryBits).'.',
            details: $payload,
            elapsedMs: $elapsedMs,
        );
    }

    private function nonEmptyList(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout($this->timeout)
            ->retry(2, 500, throw: false);
    }
}
