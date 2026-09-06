<?php

namespace App\Services\Uptime;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Pure HTTP probe — given a URL, returns an UptimeProbeResult. No DB writes,
 * no notifications, no state. Caller (UptimeStateUpdater) decides what to do
 * with the result.
 *
 * Defaults:
 *   - 10s timeout (caller can override)
 *   - GET / (HEAD is faster but a non-trivial fraction of WAFs reject it)
 *   - Up to 5 redirects (WP sites legitimately do `https://foo.com` → `https://www.foo.com`)
 *   - Custom UA: `Clockwork-Uptime/1.0` plus the operator's contact email if
 *     `CLOCKWORK_OPERATOR_CONTACT_EMAIL` is set (see config/clockwork.php)
 *     — lets a monitored site's admin identify the bot in nginx logs and
 *     whitelist it in Cloudflare WAF rules, or reach out, if a site starts
 *     rate-limiting probes.
 *   - `verify => false` — does NOT validate the cert chain. Browsers do AIA
 *     fetching for missing intermediates (common with GoDaddy / Sectigo
 *     installs) so a site can be perfectly reachable for real visitors but
 *     fail strict curl validation. The TLS handshake still has to succeed
 *     (a genuinely broken handshake — wrong protocol, refused, no listener
 *     — still fails). Cert chain validity is `clockwork:check-ssl-certs`'s
 *     job; uptime is reachability only.
 *
 * Treats 2xx, 3xx, and auth-protected 4xx (401 with WWW-Authenticate, 403)
 * as success; everything else as failure.
 */
class UptimeProber
{
    public static function userAgent(): string
    {
        $contactEmail = config('clockwork.operator.contact_email');

        return 'Clockwork-Uptime/1.0'.($contactEmail ? " (+{$contactEmail})" : '');
    }

    public function probe(string $url, int $timeoutSec = 10): UptimeProbeResult
    {
        $started = microtime(true);

        try {
            $response = Http::timeout($timeoutSec)
                ->withUserAgent(self::userAgent())
                ->withOptions([
                    'allow_redirects' => ['max' => 5, 'strict' => false, 'protocols' => ['http', 'https']],
                    // See class docblock — chain validation is not our job.
                    'verify' => false,
                ])
                ->get($url);
        } catch (Throwable $e) {
            return UptimeProbeResult::transportFailed($e->getMessage());
        }

        $elapsed = (int) round((microtime(true) - $started) * 1000);
        $status = $response->status();
        $body = $response->body();
        $xRobotsTag = $response->header('X-Robots-Tag') ?: null;

        // 2xx OR 3xx → up. We follow redirects so 3xx as the final response is
        // unusual (server didn't honor allow_redirects), but treat as success.
        if ($status >= 200 && $status < 400) {
            return UptimeProbeResult::success($status, $elapsed, $body, $xRobotsTag);
        }

        // Auth-protected sites (HTTP basic auth via .htaccess, IP allowlists,
        // staging gates, WAF challenges) are ALIVE — the server is responding,
        // it just refuses anonymous probes. Treat as up.
        //   - 401: must carry WWW-Authenticate per RFC 7235 §3.1. Servers that
        //     emit 401 without it are malformed; treat those as down so we still
        //     flag a genuinely broken backend.
        //   - 403: no required header. Trust it — the server chose to refuse,
        //     which means it's alive.
        if ($status === 401 && $response->header('WWW-Authenticate') !== '') {
            return UptimeProbeResult::authProtected($status, $elapsed, 'HTTP 401 (auth required)', $body, $xRobotsTag);
        }
        if ($status === 403) {
            return UptimeProbeResult::authProtected($status, $elapsed, 'HTTP 403 (forbidden — likely WAF or allowlist)', $body, $xRobotsTag);
        }

        return UptimeProbeResult::badStatus($status, $elapsed, "HTTP {$status}", $body, $xRobotsTag);
    }
}
