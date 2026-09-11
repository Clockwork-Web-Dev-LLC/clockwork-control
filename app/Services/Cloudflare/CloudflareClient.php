<?php

namespace App\Services\Cloudflare;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around Cloudflare's v4 API. Read-focused for now — exists so we can
 * diagnose redirect/transform/WAF rules without leaving the terminal.
 *
 * Phase IDs map to where in the request lifecycle a rule fires:
 *   - http_request_dynamic_redirect → Redirect Rules (the modern "Bulk Redirects"-adjacent UI)
 *   - http_request_transform        → URL Rewrite / Transform Rules
 *   - http_request_firewall_custom  → Custom WAF rules
 *   - http_config_settings          → Configuration Rules (the new "Page Rules" replacement)
 */
class CloudflareClient
{
    public function __construct(
        protected ?string $token = null,
        protected ?string $baseUrl = null,
        protected ?int $timeout = null,
        // Separate token for write operations. Read token stays read-only by
        // design (per CLAUDE.md security posture) — write capabilities require
        // a deliberately scoped second token (Zone.DNS:Edit on the specific
        // zones we manage). If unset, write methods throw.
        protected ?string $writeToken = null,
    ) {
        $this->token ??= (string) config('clockwork.cloudflare.api_token');
        $this->baseUrl ??= (string) config('clockwork.cloudflare.base_url');
        $this->timeout ??= (int) config('clockwork.cloudflare.timeout', 15);
        $this->writeToken ??= (string) config('clockwork.cloudflare.write_token', '');
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    public function isWriteConfigured(): bool
    {
        return $this->writeToken !== '';
    }

    /**
     * Look up a zone by exact name (e.g. "cfclient.example"). Returns the zone array or null.
     */
    public function zoneByName(string $name): ?array
    {
        $zones = $this->get('/zones', ['name' => $name])->json('result', []);

        return $zones[0] ?? null;
    }

    /**
     * List rules in a given phase for a zone. Returns an empty list if the phase has
     * no entrypoint configured (Cloudflare returns 404 for that — we treat it as "no rules").
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * @return array{rules: array<int, array<string, mixed>>, error: ?string}
     *                                                                        error is set when the token lacks permission for this phase, so the
     *                                                                        command can show "missing perms" without aborting the whole dump.
     */
    public function phaseRules(string $zoneId, string $phase): array
    {
        $response = $this->client()->get("/zones/{$zoneId}/rulesets/phases/{$phase}/entrypoint");

        if ($response->status() === 404) {
            return ['rules' => [], 'error' => null];
        }

        if ($response->status() === 403) {
            return ['rules' => [], 'error' => 'token lacks permission for this phase'];
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "Cloudflare phase fetch failed ({$phase}): HTTP {$response->status()} {$response->body()}"
            );
        }

        return ['rules' => $response->json('result.rules', []) ?? [], 'error' => null];
    }

    /**
     * Legacy Page Rules. Still in use on lots of zones — worth surfacing alongside
     * the modern Rulesets API output.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pageRules(string $zoneId): array
    {
        $response = $this->client()->get("/zones/{$zoneId}/pagerules");

        if ($response->failed()) {
            return [];
        }

        return $response->json('result', []) ?? [];
    }

    protected function client(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Cloudflare API token not configured (CLOCKWORK_CLOUDFLARE_API_TOKEN).');
        }

        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->timeout($this->timeout)
            ->retry(2, 500, throw: false);
    }

    protected function get(string $path, array $query = []): Response
    {
        $response = $this->client()->get($path, $query);

        if ($response->failed()) {
            throw new RuntimeException(
                "Cloudflare GET {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }

    /**
     * Fetch existing rules in the http_ratelimit phase for a zone.
     * Returns an empty array if no entrypoint exists yet.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Replace the full http_request_firewall_custom phase ruleset.
     * Uses the single API token (reads and writes share the same token).
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    public function putCustomFirewallRules(string $zoneId, array $rules): array
    {
        $response = $this->client()->put(
            "/zones/{$zoneId}/rulesets/phases/http_request_firewall_custom/entrypoint",
            ['rules' => $rules]
        );

        if ($response->failed()) {
            throw new RuntimeException(
                "Cloudflare custom firewall PUT failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response->json('result', []) ?? [];
    }

    /**
     * Replace the full http_request_dynamic_redirect phase ruleset (Cloudflare's
     * "Redirect Rules"). Uses the single API token, same as putCustomFirewallRules.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    public function putRedirectRules(string $zoneId, array $rules): array
    {
        $response = $this->client()->put(
            "/zones/{$zoneId}/rulesets/phases/http_request_dynamic_redirect/entrypoint",
            ['rules' => $rules]
        );

        if ($response->failed()) {
            throw new RuntimeException(
                "Cloudflare redirect rules PUT failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response->json('result', []) ?? [];
    }

    public function getRateLimitRules(string $zoneId): array
    {
        $response = $this->client()->get("/zones/{$zoneId}/rulesets/phases/http_ratelimit/entrypoint");

        if ($response->status() === 404) {
            return [];
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "Cloudflare rate limit fetch failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response->json('result.rules', []) ?? [];
    }

    /**
     * Replace the full http_ratelimit phase ruleset with the given rules.
     * Callers should fetch existing rules first and append/merge before calling this.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, mixed> The updated ruleset result.
     */
    public function putRateLimitRules(string $zoneId, array $rules): array
    {
        $response = $this->writeClient()->put(
            "/zones/{$zoneId}/rulesets/phases/http_ratelimit/entrypoint",
            ['rules' => $rules]
        );

        if ($response->failed()) {
            throw new RuntimeException(
                "Cloudflare rate limit PUT failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response->json('result', []) ?? [];
    }

    /**
     * Delete a single rate limit rule by ID from the http_ratelimit phase.
     * Fetches existing rules, removes the matching one, then PUTs the remainder.
     */
    public function deleteRateLimitRule(string $zoneId, string $ruleId): void
    {
        $rules = $this->getRateLimitRules($zoneId);
        $filtered = array_values(array_filter($rules, fn ($r) => ($r['id'] ?? '') !== $ruleId));

        if (count($filtered) === count($rules)) {
            throw new RuntimeException("Rate limit rule {$ruleId} not found in zone {$zoneId}.");
        }

        $this->putRateLimitRules($zoneId, $filtered);
    }

    /**
     * Purge specific URLs from a zone's edge cache. Requires Cache Purge on
     * the write token. Never call this with the read-only token.
     *
     * @param  list<string>  $files
     */
    public function purgeCacheFiles(string $zoneId, array $files): void
    {
        $response = $this->writeClient()->post("/zones/{$zoneId}/purge_cache", [
            'files' => array_values($files),
        ]);

        if (! $response->successful() || $response->json('success') !== true) {
            $errors = $response->json('errors.0.message') ?? $response->body();

            throw new RuntimeException('Cloudflare cache purge failed: '.$errors);
        }
    }

    protected function writeClient(): PendingRequest
    {
        if (! $this->isWriteConfigured()) {
            throw new RuntimeException(
                'Cloudflare write token is not configured (CLOCKWORK_CLOUDFLARE_WRITE_TOKEN). '
                .'Generate a token with Zone.DNS:Edit scope on the specific zones you manage.'
            );
        }

        return Http::baseUrl($this->baseUrl)
            ->withToken($this->writeToken)
            ->acceptJson()
            ->timeout($this->timeout)
            ->retry(2, 500, throw: false);
    }
}
