<?php

namespace Modules\DigitalOcean;

use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DigitalOceanClient
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

        $this->token ??= (string) config('clockwork.digitalocean.token');
        $this->baseUrl ??= (string) config('clockwork.digitalocean.base_url');
        $this->timeout ??= (int) ($settings?->get('services.digitalocean.timeout') ?? config('clockwork.digitalocean.timeout', 15));
        $this->retryAttempts = $retryAttempts ?? (int) ($settings?->get('services.digitalocean.retry_attempts') ?? 2);
        $this->delayMs = $delayMs ?? (int) ($settings?->get('services.digitalocean.delay_ms') ?? 0);
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

    public function account(): array
    {
        return $this->get('/account')->json('account', []);
    }

    public function droplets(int $perPage = 200): array
    {
        $all = [];
        $page = 1;

        do {
            $response = $this->get('/droplets', [
                'per_page' => $perPage,
                'page' => $page,
            ]);

            $body = $response->json();
            $all = array_merge($all, $body['droplets'] ?? []);

            $hasNext = isset($body['links']['pages']['next']);
            $page++;
        } while ($hasNext);

        return $all;
    }

    public function dropletCpuMetrics(string $hostId, int $start, int $end): array
    {
        return $this->get('/monitoring/metrics/droplet/cpu', [
            'host_id' => $hostId,
            'start' => (string) $start,
            'end' => (string) $end,
        ])->json('data', []);
    }

    public function dropletLoad1Metrics(string $hostId, int $start, int $end): array
    {
        return $this->get('/monitoring/metrics/droplet/load_1', [
            'host_id' => $hostId,
            'start' => (string) $start,
            'end' => (string) $end,
        ])->json('data', []);
    }

    public function dropletMemoryFreeMetrics(string $hostId, int $start, int $end): array
    {
        return $this->get('/monitoring/metrics/droplet/memory_free', [
            'host_id' => $hostId,
            'start' => (string) $start,
            'end' => (string) $end,
        ])->json('data', []);
    }

    public function dropletMemoryTotalMetrics(string $hostId, int $start, int $end): array
    {
        return $this->get('/monitoring/metrics/droplet/memory_total', [
            'host_id' => $hostId,
            'start' => (string) $start,
            'end' => (string) $end,
        ])->json('data', []);
    }

    public function dropletMemoryAvailableMetrics(string $hostId, int $start, int $end): array
    {
        return $this->get('/monitoring/metrics/droplet/memory_available', [
            'host_id' => $hostId,
            'start' => (string) $start,
            'end' => (string) $end,
        ])->json('data', []);
    }

    public function dropletFilesystemFreeMetrics(string $hostId, int $start, int $end): array
    {
        return $this->get('/monitoring/metrics/droplet/filesystem_free', [
            'host_id' => $hostId,
            'start' => (string) $start,
            'end' => (string) $end,
        ])->json('data', []);
    }

    public function dropletFilesystemSizeMetrics(string $hostId, int $start, int $end): array
    {
        return $this->get('/monitoring/metrics/droplet/filesystem_size', [
            'host_id' => $hostId,
            'start' => (string) $start,
            'end' => (string) $end,
        ])->json('data', []);
    }

    protected function client(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('DigitalOcean API token is not configured (CLOCKWORK_DIGITALOCEAN_TOKEN).');
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
                "DigitalOcean GET {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }
}
