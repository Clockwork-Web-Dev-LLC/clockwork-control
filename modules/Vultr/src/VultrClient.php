<?php

namespace Modules\Vultr;

use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for Vultr API v2 (api.vultr.com/v2).
 * Mirrors DigitalOceanClient and HetznerClient for Vultr cloud instances.
 */
class VultrClient
{
    protected int $retryAttempts;

    protected int $delayMs;

    public function __construct(
        protected ?string $apiKey = null,
        protected ?string $baseUrl = null,
        protected ?int $timeout = null,
        ?int $retryAttempts = null,
        ?int $delayMs = null,
    ) {
        $settings = function_exists('app') && app()->bound(Settings::class) ? app(Settings::class) : null;

        $this->apiKey ??= (string) config('clockwork.vultr.api_key');
        $this->baseUrl ??= (string) config('clockwork.vultr.base_url', 'https://api.vultr.com/v2');
        $this->timeout ??= (int) ($settings?->get('services.vultr.timeout') ?? config('clockwork.vultr.timeout', 15));
        $this->retryAttempts = $retryAttempts ?? (int) ($settings?->get('services.vultr.retry_attempts') ?? 2);
        $this->delayMs = $delayMs ?? (int) ($settings?->get('services.vultr.delay_ms') ?? 0);
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
        return $this->apiKey !== '';
    }

    /**
     * Read-only auth check via GET /v2/account.
     */
    public function account(): array
    {
        return $this->get('/account')->json('account', []);
    }

    /**
     * GET /v2/instances — cursor-paginated list of all instances on the account.
     */
    public function instances(int $perPage = 100): array
    {
        $all = [];
        $cursor = null;

        do {
            $query = ['per_page' => $perPage];
            if ($cursor) {
                $query['cursor'] = $cursor;
            }

            $response = $this->get('/instances', $query);
            $body = $response->json();
            $all = array_merge($all, $body['instances'] ?? []);

            $next = $body['meta']['links']['next'] ?? null;
            $cursor = (! empty($next) && is_string($next)) ? $next : null;
        } while ($cursor !== null);

        return $all;
    }

    /**
     * GET /v2/instances/{instance-id} — retrieve a single instance.
     */
    public function instance(string $id): array
    {
        return $this->get("/instances/{$id}")->json('instance', []);
    }

    protected function client(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Vultr API key is not configured (CLOCKWORK_VULTR_API_KEY).');
        }

        $request = Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
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
                "Vultr GET {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }
}
