<?php

namespace Modules\Hetzner;

use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mirror of DigitalOceanClient for Hetzner Cloud (api.hetzner.cloud/v1).
 * Method names intentionally diverge from DO ("server" vs "droplet") to
 * match the upstream API vocabulary; PollServers branches on
 * Server::provider and calls the right client.
 *
 * Hetzner only exposes CPU, disk, and network metrics — there is no
 * memory metric. The SSH-collected memory sample remains the source for
 * Hetzner servers until the provider ships one.
 */
class HetznerClient
{
    protected int $retryAttempts;

    protected int $delayMs;

    public function __construct(
        protected ?string $token = null,
        protected ?string $baseUrl = null,
        protected ?int $timeout = null,
        ?int $retryAttempts = null,
        ?int $delayMs = null,
    ) {
        $settings = function_exists('app') && app()->bound(Settings::class) ? app(Settings::class) : null;

        $this->token ??= (string) config('clockwork.hetzner.token');
        $this->baseUrl ??= (string) config('clockwork.hetzner.base_url');
        $this->timeout ??= (int) ($settings?->get('services.hetzner.timeout') ?? config('clockwork.hetzner.timeout', 15));
        $this->retryAttempts = $retryAttempts ?? (int) ($settings?->get('services.hetzner.retry_attempts') ?? 2);
        $this->delayMs = $delayMs ?? (int) ($settings?->get('services.hetzner.delay_ms') ?? 0);
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
        return $this->token !== '';
    }

    /**
     * Cheap auth-check. Hetzner has no /account endpoint; /locations is
     * read-only, ungated, and returns a small payload, so it serves as a
     * "does this token work" probe (DO uses /account for the same purpose).
     */
    public function account(): array
    {
        return $this->get('/locations')->json('locations', []);
    }

    /**
     * GET /v1/servers — paginated. Each row carries server_type with name,
     * cores, memory, disk; status; public_net.ipv4.ip; etc.
     */
    public function servers(int $perPage = 50): array
    {
        $all = [];
        $page = 1;

        do {
            $response = $this->get('/servers', [
                'per_page' => $perPage,
                'page' => $page,
            ]);

            $body = $response->json();
            $all = array_merge($all, $body['servers'] ?? []);

            $next = $body['meta']['pagination']['next_page'] ?? null;
            $page = $next ?? null;
        } while ($page);

        return $all;
    }

    /**
     * GET /v1/servers/{id}/metrics?type=cpu&start=...&end=...
     * Hetzner expects RFC-3339 timestamps; we accept Unix seconds and
     * convert here so callers match the DO client signature.
     *
     * Response shape: time_series.cpu.values is [[unix_ts, "value"], ...]
     */
    public function serverCpuMetrics(string $serverId, int $start, int $end): array
    {
        return $this->serverMetrics($serverId, 'cpu', $start, $end);
    }

    public function serverDiskMetrics(string $serverId, int $start, int $end): array
    {
        return $this->serverMetrics($serverId, 'disk', $start, $end);
    }

    public function serverNetworkMetrics(string $serverId, int $start, int $end): array
    {
        return $this->serverMetrics($serverId, 'network', $start, $end);
    }

    protected function serverMetrics(string $serverId, string $type, int $start, int $end): array
    {
        return $this->get("/servers/{$serverId}/metrics", [
            'type' => $type,
            'start' => gmdate('Y-m-d\TH:i:s\Z', $start),
            'end' => gmdate('Y-m-d\TH:i:s\Z', $end),
        ])->json('metrics', []);
    }

    protected function client(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Hetzner Cloud API token is not configured (CLOCKWORK_HETZNER_TOKEN).');
        }

        $request = Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->timeout($this->timeout);

        if ($this->retryAttempts > 0) {
            $request->retry($this->retryAttempts, 500);
        }

        return $request;
    }

    protected function get(string $path, array $query = []): Response
    {
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }

        $response = $this->client()->get($path, $query);

        if ($response->failed()) {
            throw new RuntimeException(
                "Hetzner GET {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }
}
