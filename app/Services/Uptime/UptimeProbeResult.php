<?php

namespace App\Services\Uptime;

/**
 * Value object capturing the outcome of one HTTP probe.
 *
 * - succeeded=true → server responded as alive: a 2xx/3xx, OR a 401 with a
 *   real auth challenge, OR a 403. All three confirm the origin is up; the
 *   `error` field carries a short reason for non-200 cases ("HTTP 401
 *   (auth required)") so the UI can label it.
 * - succeeded=false → status_code is set if we got a response (4xx/5xx),
 *   or null if the request never completed (timeout / DNS / refused / TLS fail).
 *   In either failure case, `error` carries a short human description.
 */
final class UptimeProbeResult
{
    public function __construct(
        public readonly bool $succeeded,
        public readonly ?int $statusCode,
        public readonly ?int $responseTimeMs,
        public readonly ?string $error,
        public readonly ?string $body = null,
        public readonly ?string $xRobotsTagHeader = null,
    ) {}

    public static function success(int $statusCode, int $responseTimeMs, ?string $body = null, ?string $xRobotsTagHeader = null): self
    {
        return new self(true, $statusCode, $responseTimeMs, null, $body, $xRobotsTagHeader);
    }

    /**
     * Auth-protected origin (401 w/ WWW-Authenticate, or 403). The server is
     * alive — it's just refusing anonymous access. We carry the status code
     * AND a short reason so the UI can render "Up — auth required" rather
     * than a plain green tick.
     */
    public static function authProtected(int $statusCode, int $responseTimeMs, string $error, ?string $body = null, ?string $xRobotsTagHeader = null): self
    {
        return new self(true, $statusCode, $responseTimeMs, $error, $body, $xRobotsTagHeader);
    }

    public static function badStatus(int $statusCode, int $responseTimeMs, string $error, ?string $body = null, ?string $xRobotsTagHeader = null): self
    {
        return new self(false, $statusCode, $responseTimeMs, $error, $body, $xRobotsTagHeader);
    }

    public static function transportFailed(string $error): self
    {
        return new self(false, null, null, mb_strimwidth($error, 0, 480, '…'));
    }

    /**
     * True for an "up" probe whose status was 401 or 403 — UI labels the
     * pill "Up — auth required" instead of a bare "Up".
     */
    public function isAuthProtected(): bool
    {
        return $this->succeeded && in_array($this->statusCode, [401, 403], true);
    }

    /**
     * Retained for the Mattermost notifier copy that still flags 401/403 as
     * ambiguous when they fail (e.g. a 401 without WWW-Authenticate, which
     * we now treat as a real failure).
     */
    public function isLikelyWafBlock(): bool
    {
        return in_array($this->statusCode, [401, 403], true);
    }
}
