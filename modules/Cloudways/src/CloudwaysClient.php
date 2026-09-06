<?php

namespace Modules\Cloudways;

use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the Cloudways Platform API v2 (https://api.cloudways.com/api/v2).
 *
 * ASSUMPTION FLAG: Cloudways' v1 API reached end-of-life 2026-03-31; v2 is
 * the only version this client targets. The specific request/response
 * shapes below (endpoint paths, field names inside each payload) are
 * modeled on Cloudways' publicly documented v1 API — the closest thing to
 * a spec available while building this without a live account — and have
 * NOT been verified against a real v2 response. Anything under a "shape
 * assumption" comment in this file needs confirming against a real
 * Cloudways account before this module is trusted in production.
 *
 * Authentication is OAuth2-shaped but Cloudways-specific: POST
 * /oauth/access_token with { email, api_key } returns a short-lived bearer
 * access_token (Cloudways' docs describe ~24h validity). This mirrors
 * AzureClient::token()'s cache-until-expiry pattern, adapted to Cloudways'
 * simpler two-field credential exchange (no tenant/client-secret/scope).
 */
class CloudwaysClient
{
    protected ?string $cachedToken = null;

    protected ?int $tokenExpiresAt = null;

    protected int $retryAttempts;

    protected int $delayMs;

    public function __construct(
        protected ?string $apiKey = null,
        protected ?string $email = null,
        protected ?string $baseUrl = null,
        protected ?int $timeout = null,
        protected ?bool $viewOnly = null,
        ?int $retryAttempts = null,
        ?int $delayMs = null,
    ) {
        $settings = function_exists('app') && app()->bound(Settings::class) ? app(Settings::class) : null;

        $this->apiKey ??= (string) config('clockwork.cloudways.api_key');
        $this->email ??= (string) config('clockwork.cloudways.email');
        $this->baseUrl ??= (string) config('clockwork.cloudways.base_url', 'https://api.cloudways.com/api/v2');
        $this->timeout ??= (int) ($settings?->get('services.cloudways.timeout') ?? config('clockwork.cloudways.timeout', 15));
        $this->viewOnly = $viewOnly ?? (bool) config('clockwork.cloudways.view_only', true);
        $this->retryAttempts = $retryAttempts ?? (int) ($settings?->get('services.cloudways.retry_attempts') ?? 2);
        $this->delayMs = $delayMs ?? (int) ($settings?->get('services.cloudways.delay_ms') ?? 0);
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getRetryAttempts(): int
    {
        return $this->retryAttempts;
    }

    public function getDelayMs(): int
    {
        return $this->delayMs;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->email !== '';
    }

    public function isViewOnly(): bool
    {
        return $this->viewOnly ?? true;
    }

    /**
     * Exchange (email, api_key) for a bearer access token via OAuth2-shaped
     * client-credentials exchange, caching it in-memory for this instance's
     * lifetime and refreshing on expiry — mirrors AzureClient::token().
     *
     * Shape assumption: POST /oauth/access_token accepts form-encoded
     * { email, api_key } and returns { access_token, expires_in }, matching
     * Cloudways' documented v1 auth flow carried forward to v2. Not
     * confirmed against a live v2 response.
     */
    public function token(): string
    {
        if ($this->cachedToken && $this->tokenExpiresAt && time() < $this->tokenExpiresAt - 60) {
            return $this->cachedToken;
        }

        $response = Http::asForm()
            ->timeout($this->timeout)
            ->baseUrl($this->baseUrl)
            ->post('/oauth/access_token', [
                'email' => $this->email,
                'api_key' => $this->apiKey,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Cloudways auth failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        $data = $response->json();
        $this->cachedToken = $data['access_token'] ?? throw new RuntimeException('Cloudways token response missing access_token.');
        // Shape assumption: expires_in in seconds, as in Cloudways' v1 docs (~24h). Falls
        // back to 1 hour if absent so a missing field fails safe toward re-authing often
        // rather than caching a possibly-stale token for a full day.
        $this->tokenExpiresAt = time() + (int) ($data['expires_in'] ?? 3600);

        return $this->cachedToken;
    }

    /**
     * List all servers on the account. Shape assumption: GET /server
     * returns { servers: [...] }, each server entry carrying its own nested
     * 'apps' array (Cloudways' v1 docs describe servers as owning apps this
     * way) — so a single call here also yields every app without a
     * separate per-server round trip in the common case. apps() below
     * exists for the case where a caller wants just one server's apps
     * refreshed without the account-wide list.
     */
    public function servers(): array
    {
        return $this->get('/server')->json('servers', []);
    }

    /**
     * Fetch one server by id. Shape assumption: GET /server/{id} returns
     * { server: {...} } (singular key), including the nested 'apps' array
     * as in servers() above.
     */
    public function server(string $serverId): array
    {
        return $this->get("/server/{$serverId}")->json('server', []);
    }

    /**
     * Apps hosted on a given server. Shape assumption: Cloudways' v1 API
     * has no standalone "list apps for server" endpoint — apps are always
     * nested under their server. This extracts that nested array from
     * server() rather than hitting a dedicated endpoint, since that's the
     * shape most consistent with the documented v1 behavior. If v2 does
     * expose a flatter /app?server_id= endpoint, this should be switched
     * to call it directly instead.
     */
    public function apps(string $serverId): array
    {
        return $this->server($serverId)['apps'] ?? [];
    }

    /**
     * Fetch one app. Shape assumption: GET /app/{appId}?server_id={serverId}
     * returns { app: {...} } — Cloudways' v1 app-detail endpoint is scoped
     * by server_id as a query param since app ids are not globally unique
     * across servers in the documented model.
     */
    public function app(string $serverId, string $appId): array
    {
        return $this->get("/app/{$appId}", ['server_id' => $serverId])->json('app', []);
    }

    /**
     * Trigger an on-demand backup for an app. Shape assumption: POST
     * /app/manage/takeBackup with { server_id, app_id } returns an
     * operation_id the caller can poll (Cloudways models most mutating
     * calls as async "operations" per its v1 docs).
     */
    public function takeBackup(string $serverId, string $appId): array
    {
        return $this->post('/app/manage/takeBackup', [
            'server_id' => $serverId,
            'app_id' => $appId,
        ])->json();
    }

    /**
     * Restore an app from a prior backup. Shape assumption: POST
     * /app/manage/restoreBackup with { server_id, app_id } plus optional
     * component flags (application_data, database) restores the most
     * recent backup — Cloudways' v1 docs don't expose picking an arbitrary
     * historical backup by id, only "restore latest per component".
     */
    public function restoreBackup(string $serverId, string $appId, array $components = []): array
    {
        return $this->post('/app/manage/restoreBackup', array_merge([
            'server_id' => $serverId,
            'app_id' => $appId,
        ], $components))->json();
    }

    /**
     * Fetch SSL certificate state for an app. Shape assumption: GET
     * /ssl_certificate/{appId}?server_id={serverId} returns the cert's
     * current state — installed CN, issuer, and expiry, if any.
     */
    public function sslCertificate(string $serverId, string $appId): array
    {
        return $this->get("/ssl_certificate/{$appId}", ['server_id' => $serverId])->json();
    }

    /**
     * Install a Let's Encrypt certificate for an app. Shape assumption:
     * POST /ssl_certificate/install/letsEncrypt with { server_id, app_id,
     * email, dns_names } (email is the notification contact, dns_names the
     * domains to cover) returns an operation_id, same async-operation
     * pattern as takeBackup().
     */
    public function installLetsEncrypt(string $serverId, string $appId, string $email, array $dnsNames): array
    {
        return $this->post('/ssl_certificate/install/letsEncrypt', [
            'server_id' => $serverId,
            'app_id' => $appId,
            'email' => $email,
            'dns_names' => $dnsNames,
        ])->json();
    }

    /**
     * Server-level monitoring graph data (CPU/memory/disk/load) feeding
     * CloudwaysCloudProvider::metrics() via CloudwaysMetricsParser. Shape
     * assumption: GET /server/monitorSummary?server_id={id}&type={metric}
     * &start_date={..}&end_date={..} returns a time-series payload —
     * modeled on Cloudways' v1 "server monitor" widget endpoint, which
     * exposes cpu, ram, disk, and iops as separately-typed graphs rather
     * than one combined payload (hence one call per metric type, similar
     * to DigitalOceanClient's per-metric methods).
     *
     * $metric is one of: 'cpu', 'ram', 'disk', 'load' (assumption: these
     * are the type values Cloudways' v1 monitor endpoint accepts; not
     * confirmed for v2).
     */
    public function serverMonitorSummary(string $serverId, string $metric, int $start, int $end): array
    {
        return $this->get('/server/monitorSummary', [
            'server_id' => $serverId,
            'type' => $metric,
            'start_date' => gmdate('Y-m-d\TH:i:s\Z', $start),
            'end_date' => gmdate('Y-m-d\TH:i:s\Z', $end),
        ])->json();
    }

    /**
     * Server disk usage snapshot (current, not time-series) — a fallback
     * data source for CloudwaysMetricsParser::diskPercent() when
     * serverMonitorSummary()'s 'disk' graph doesn't carry an absolute
     * percentage. Shape assumption: GET /server/diskUsage?server_id={id}
     * returns { used_mb, total_mb } for the server's primary volume.
     */
    public function serverDiskUsage(string $serverId): array
    {
        return $this->get('/server/diskUsage', ['server_id' => $serverId])->json();
    }

    protected function client(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Cloudways credentials are not configured (CLOCKWORK_CLOUDWAYS_API_KEY / CLOCKWORK_CLOUDWAYS_EMAIL).');
        }

        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }

        $request = Http::baseUrl($this->baseUrl)
            ->withToken($this->token())
            ->acceptJson()
            ->timeout($this->timeout);

        if ($this->retryAttempts > 0) {
            $request->retry($this->retryAttempts, 500);
        }

        return $request;
    }

    protected function get(string $path, array $query = []): Response
    {
        $response = $this->client()->get($path, $query);

        if ($response->failed()) {
            throw new RuntimeException(
                "Cloudways GET {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function post(string $path, array $body = []): Response
    {
        if ($this->isViewOnly()) {
            throw new CloudwaysReadOnlyException('Cloudways', "POST [{$path}]");
        }

        $response = $this->client()->post($path, $body);

        if ($response->failed()) {
            throw new RuntimeException(
                "Cloudways POST {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function put(string $path, array $body = []): Response
    {
        if ($this->isViewOnly()) {
            throw new CloudwaysReadOnlyException('Cloudways', "PUT [{$path}]");
        }

        $response = $this->client()->put($path, $body);

        if ($response->failed()) {
            throw new RuntimeException(
                "Cloudways PUT {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }

    protected function delete(string $path): Response
    {
        if ($this->isViewOnly()) {
            throw new CloudwaysReadOnlyException('Cloudways', "DELETE [{$path}]");
        }

        $response = $this->client()->delete($path);

        if ($response->failed()) {
            throw new RuntimeException(
                "Cloudways DELETE {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }
}
