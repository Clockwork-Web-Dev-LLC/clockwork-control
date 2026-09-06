<?php

namespace Modules\Linode;

use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for Linode (Akamai Cloud) API v4 (api.linode.com/v4).
 * Mirrors DigitalOceanClient and HetznerClient for Linode instances.
 */
class LinodeClient
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

        $this->token ??= (string) config('clockwork.linode.token');
        $this->baseUrl ??= (string) config('clockwork.linode.base_url', 'https://api.linode.com/v4');
        $this->timeout ??= (int) ($settings?->get('services.linode.timeout') ?? config('clockwork.linode.timeout', 15));
        $this->retryAttempts = $retryAttempts ?? (int) ($settings?->get('services.linode.retry_attempts') ?? 2);
        $this->delayMs = $delayMs ?? (int) ($settings?->get('services.linode.delay_ms') ?? 0);
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
     * Read-only auth check via GET /v4/account.
     */
    public function account(): array
    {
        return $this->get('/account')->json();
    }

    /**
     * GET /v4/linode/instances — paginated list of all linodes on the account.
     */
    public function instances(int $pageSize = 100): array
    {
        $all = [];
        $page = 1;

        do {
            $response = $this->get('/linode/instances', [
                'page' => $page,
                'page_size' => $pageSize,
            ]);

            $body = $response->json();
            $all = array_merge($all, $body['data'] ?? []);

            $totalPages = (int) ($body['pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages);

        return $all;
    }

    /**
     * GET /v4/linode/instances/{id}/stats — CPU, IO, and network usage series.
     */
    public function instanceStats(string $linodeId): array
    {
        return $this->get("/linode/instances/{$linodeId}/stats")->json('data', []);
    }

    protected function client(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Linode API token is not configured (CLOCKWORK_LINODE_TOKEN).');
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
                "Linode GET {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }
}
