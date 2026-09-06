<?php

namespace Modules\Pressable;

use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for the Pressable Control Panel API.
 *
 * Auth: OAuth2 client_credentials. POST {auth_url} with form-encoded
 * {grant_type: client_credentials, client_id, client_secret} → an
 * access_token good for ~3599s (1hr), used as a Bearer token on every
 * subsequent call. Cached (see accessToken()) with a safety margin so we
 * don't re-auth on every request within the same job run.
 *
 * Unlike SpinupWpClient, Pressable has NO server concept — every operation
 * is addressed by site_id alone. There is nothing analogous to
 * SpinupWpClient::servers()/server().
 */
class PressableClient
{
    private const TOKEN_CACHE_KEY = 'pressable.access_token';

    /** Tokens live 3599s server-side; cache for 3300 (55 min) to give breathing room. */
    private const TOKEN_CACHE_TTL_SECONDS = 3300;

    protected int $retryAttempts;

    protected int $delayMs;

    public function __construct(
        protected ?string $clientId = null,
        protected ?string $clientSecret = null,
        protected ?string $authUrl = null,
        protected ?string $baseUrl = null,
        protected ?int $timeout = null,
        protected ?bool $viewOnly = null,
        ?int $retryAttempts = null,
        ?int $delayMs = null,
    ) {
        $settings = function_exists('app') && app()->bound(Settings::class) ? app(Settings::class) : null;

        $this->clientId ??= (string) config('clockwork.pressable.client_id');
        $this->clientSecret ??= (string) config('clockwork.pressable.client_secret');
        $this->authUrl ??= (string) config('clockwork.pressable.auth_url');
        $this->baseUrl ??= (string) config('clockwork.pressable.base_url');
        $this->timeout ??= (int) ($settings?->get('services.pressable.timeout') ?? config('clockwork.pressable.timeout', 15));
        $this->viewOnly = $viewOnly ?? (bool) config('clockwork.pressable.view_only', false);
        $this->retryAttempts = $retryAttempts ?? (int) ($settings?->get('services.pressable.retry_attempts') ?? 2);
        $this->delayMs = $delayMs ?? (int) ($settings?->get('services.pressable.delay_ms') ?? 0);
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
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function isViewOnly(): bool
    {
        return $this->viewOnly ?? false;
    }

    /**
     * Verify credentials work. Returns the account payload on success; throws on failure.
     *
     * @return array<string, mixed>
     */
    public function ping(): array
    {
        Cache::forget(self::TOKEN_CACHE_KEY);

        return $this->account();
    }

    /**
     * @return array<string, mixed>
     */
    public function account(): array
    {
        return $this->get('/account')->json('data', []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sites(): array
    {
        return $this->paginate('/sites');
    }

    /**
     * @return array<string, mixed>
     */
    public function site(int|string $id): array
    {
        return $this->get("/sites/{$id}")->json('data', []);
    }

    /**
     * Purge Pressable's edge cache for a site. Necessary before probing a
     * REST endpoint whose path may have been hit (and its response cached)
     * before the endpoint existed — confirmed live 2026-08-28: a pre-install
     * health-check 404 against perfcheck.example stayed cached at the edge and
     * kept failing post-install until purged, even though the real backend
     * had the route registered correctly all along.
     */
    public function purgeEdgeCache(int|string $siteId): void
    {
        if ($this->isViewOnly()) {
            throw new PressableReadOnlyException('Pressable', "DELETE [/sites/{$siteId}/edge-cache]");
        }

        $this->delete("/sites/{$siteId}/edge-cache");
    }

    /**
     * Flush the site's WordPress object cache (Redis/Memcached) — distinct
     * from purgeEdgeCache() above, which only clears the CDN/edge layer.
     * Useful after a direct DB write, a restored backup, or anything else
     * that bypasses the normal WordPress write path. Async server-side;
     * takes a few seconds to complete, no completion signal is returned.
     *
     * POST /sites/{id}/cache (the path a docs summary suggested) is a 404 —
     * confirmed live 2026-08-29 that the real route mirrors edge-cache's
     * own shape: DELETE /sites/{id}/object-cache.
     */
    public function flushObjectCache(int|string $siteId): void
    {
        if ($this->isViewOnly()) {
            throw new PressableReadOnlyException('Pressable', "DELETE [/sites/{$siteId}/object-cache]");
        }

        $this->delete("/sites/{$siteId}/object-cache");
    }

    /**
     * Schedule one or more raw bash commands on a site's SSH host. Fire-and
     * -forget: execution is asynchronous (Solid Queue on Pressable's side),
     * no job id is returned by the raw API (that's an MCP-layer convenience,
     * confirmed against the real REST response — only {message} comes
     * back). Poll activityLogs() for the result; see PressableCommandRunner.
     *
     * @param  list<string>  $commands
     */
    public function runBashCommands(int|string $siteId, array $commands): void
    {
        if ($this->isViewOnly()) {
            throw new PressableReadOnlyException('Pressable', 'Run bash commands');
        }

        $this->post("/sites/{$siteId}/wordpress/commands", ['commands' => $commands]);
    }

    /**
     * Same as runBashCommands() but each command is run through wp-cli
     * (Pressable prefixes "wp " server-side — pass just the rest, e.g.
     * "plugin list", not "wp plugin list").
     *
     * @param  list<string>  $commands
     */
    public function runWpCliCommands(int|string $siteId, array $commands): void
    {
        if ($this->isViewOnly()) {
            throw new PressableReadOnlyException('Pressable', 'Run WP-CLI commands');
        }

        $this->post("/sites/{$siteId}/wordpress/wpcli", ['commands' => $commands]);
    }

    /**
     * Scheduled backup points for a site — each entry pairs a timestamp with
     * the filesystem/database backup IDs taken at that point, newest first.
     * No file sizes or byte counts are exposed by this endpoint (unlike
     * SpinupWP's Spaces-derived history) — Companion's history table simply
     * shows "—" for those columns.
     *
     * Superseded by siteFilesystemBackups()/siteDatabaseBackups() below for
     * anything needing real history depth or sizes — kept only because
     * nothing else currently depends on removing it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function siteBackups(int|string $siteId): array
    {
        return $this->get("/sites/{$siteId}/backups")->json('data', []);
    }

    /**
     * Full filesystem backup history, newest first — daily cadence, tapering
     * to weekly as backups age (confirmed live against a long-established
     * site: daily for ~2 weeks, then weekly back for months). Each entry's
     * `title` embeds a human-readable size (e.g. "Sat, 29 Aug 2026 00:00:00
     * UTC - 272.92 MB") — there is no separate structured bytes field, so
     * callers parse it out of the title.
     *
     * @return array<int, array<string, mixed>>
     */
    public function siteFilesystemBackups(int|string $siteId): array
    {
        return $this->get("/sites/{$siteId}/backups/fs")->json('data', []);
    }

    /**
     * Full database backup history, newest first — hourly cadence. Same
     * size-embedded-in-title shape as siteFilesystemBackups().
     *
     * @return array<int, array<string, mixed>>
     */
    public function siteDatabaseBackups(int|string $siteId): array
    {
        return $this->get("/sites/{$siteId}/backups/db")->json('data', []);
    }

    /**
     * Latest Lighthouse-based performance report (desktop_report +
     * mobile_report), Pressable's own built-in equivalent of PSI — richer
     * than our PSI scrape (accessibility/best-practices/SEO scores plus
     * FCP/TTI/CLS/LCP/TBT), verified live against perfcheck.example 2026-08-29.
     *
     * @return array<string, mixed>
     */
    public function sitePerformanceReport(int|string $siteId): array
    {
        return $this->get("/sites/{$siteId}/reports/performance/latest")->json('data', []);
    }

    /**
     * Page-view counts (today/yesterday/current+last month/1yr/2yr) plus
     * database/filesystem storage usage. Coarser than our nginx-log traffic
     * rollup (no top-paths/referrers) but the only traffic signal available
     * for a site with no SSH access.
     *
     * @return array<string, mixed>
     */
    public function siteStatistics(int|string $siteId): array
    {
        return $this->get("/sites/{$siteId}/statistics")->json('data', []);
    }

    /**
     * Current edge-cache state, including Defensive Mode's on/off status and
     * expiry timestamp. Read-only counterpart to purgeEdgeCache() — used to
     * show clients an honest "on/off" status without exposing the toggle
     * itself (Defensive Mode is ops-triggered only).
     *
     * @return array{active: bool, defensive_mode: array{active: bool, active_until: int}}
     */
    public function siteEdgeCacheStatus(int|string $siteId): array
    {
        return $this->get("/sites/{$siteId}/edge-cache")->json('data', []);
    }

    /**
     * Plugins with known CVEs currently installed, per Pressable's own
     * vulnerability feed. Returns [] (not the raw null Pressable sends for a
     * clean site) so callers never need a null-check.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sitePluginSecurityAlerts(int|string $siteId): array
    {
        return $this->get("/sites/{$siteId}/security-alerts/plugins")->json('data.active') ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function siteThemeSecurityAlerts(int|string $siteId): array
    {
        return $this->get("/sites/{$siteId}/security-alerts/themes")->json('data.active') ?? [];
    }

    /**
     * Time-series metrics — traffic (requests, uniques, views, status
     * codes, top paths, crawler ratio), PHP/CPU (php_cpu_time,
     * cgroup_cpu_usage), and MySQL (connections, queries, rows). Resolution
     * is auto-selected by the API based on $startAt and DIFFERS BY METRIC
     * FAMILY for the same range (confirmed live: Uniques & Views gives
     * daily buckets over "Past 1 month", Edge Logs metrics give 8-hour
     * buckets over the same range) — callers aggregate as needed.
     *
     * $metrics and $dimensions must come from the same family (Edge Logs,
     * PHP Logs, Uniques & Views, MySQL/CGroup) — mixing families silently
     * returns an empty result rather than an error. Full metric/dimension
     * catalogue: see get_site_metrics in the Pressable MCP tool list.
     *
     * @param  list<string>  $metrics
     * @param  list<string>  $dimensions
     * @return array<int, array<string, mixed>> the `periods` array — each
     *                                          entry has a `timestamp` (unix seconds) and either a
     *                                          `dimension` key (single metric requested) or a key named
     *                                          after the dimension containing one sub-array per metric
     *                                          (multiple metrics requested)
     */
    public function siteMetrics(int|string $siteId, array $metrics, array $dimensions, string $startAt): array
    {
        $response = $this->post("/sites/{$siteId}/metrics", [
            'metrics' => $metrics,
            'dimensions' => $dimensions,
            'start_at' => $startAt,
        ]);

        return $response->json('data.periods', []);
    }

    /**
     * Most recent activity log entries for a site, newest first. The
     * server-side `filters` param exists in the OpenAPI schema
     * ({field,operator,value}) but was observed NOT to actually restrict
     * results when tested live (2026-08-28) — so this always filters
     * client-side on `name` instead of trusting the server-side filter.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activityLogs(int|string $siteId, ?string $name = null, int $perPage = 50): array
    {
        $response = $this->post("/sites/{$siteId}/logs/activity", [
            'page' => 1,
            'per_page' => $perPage,
        ]);

        $rows = $response->json('data', []);
        if ($name === null) {
            return $rows;
        }

        return array_values(array_filter($rows, fn (array $row) => ($row['name'] ?? null) === $name));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function paginate(string $path, int $perPage = 100): array
    {
        $all = [];
        $page = 1;
        $safetyLimit = 200; // 200 pages × 100 = 20k rows

        do {
            if ($page > $safetyLimit) {
                throw new RuntimeException("Pressable pagination exceeded {$safetyLimit} pages on {$path}; aborting.");
            }

            $response = $this->get($path, [
                'page' => $page,
                'per_page' => $perPage,
            ]);

            $body = $response->json();
            $all = array_merge($all, $body['data'] ?? []);

            // Compare currentPage/lastPage rather than trusting nextPage to
            // be null on the final page — the docs don't guarantee that.
            $currentPage = (int) ($body['page']['currentPage'] ?? $page);
            $lastPage = (int) ($body['page']['lastPage'] ?? $currentPage);
            $page = $currentPage + 1;
        } while ($currentPage < $lastPage);

        return $all;
    }

    /**
     * Cached OAuth2 client_credentials access token. Re-authenticates once
     * on a 401 (see get()) in case the token was revoked server-side before
     * its cached TTL expired.
     */
    protected function accessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('Pressable API credentials are not configured. Set CLOCKWORK_PRESSABLE_CLIENT_ID / CLOCKWORK_PRESSABLE_CLIENT_SECRET in .env.');
        }

        $response = Http::timeout($this->timeout)
            ->asForm()
            ->post($this->authUrl, [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

        if ($response->failed()) {
            $excerpt = mb_strimwidth((string) $response->body(), 0, 300, '…');
            throw new RuntimeException("Pressable auth failed: HTTP {$response->status()} {$excerpt}");
        }

        $token = (string) $response->json('access_token');
        if ($token === '') {
            throw new RuntimeException('Pressable auth response had no access_token.');
        }

        Cache::put(self::TOKEN_CACHE_KEY, $token, self::TOKEN_CACHE_TTL_SECONDS);

        return $token;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken();
    }

    protected function client(): PendingRequest
    {
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }

        $request = Http::baseUrl($this->baseUrl)
            ->withToken($this->accessToken())
            ->acceptJson()
            ->timeout($this->timeout);

        if ($this->retryAttempts > 0) {
            $request->retry($this->retryAttempts, 500, throw: false);
        }

        return $request;
    }

    protected function get(string $path, array $query = []): Response
    {
        $response = $this->client()->get($path, $query);

        // Token may have been revoked server-side before our cached TTL
        // expired — re-auth once and retry before giving up.
        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $response = $this->client()->get($path, $query);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "Pressable GET {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function post(string $path, array $body = []): Response
    {
        $response = $this->client()->post($path, $body);

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $response = $this->client()->post($path, $body);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "Pressable POST {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }

    protected function delete(string $path): Response
    {
        $response = $this->client()->delete($path);

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $response = $this->client()->delete($path);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "Pressable DELETE {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }
}
