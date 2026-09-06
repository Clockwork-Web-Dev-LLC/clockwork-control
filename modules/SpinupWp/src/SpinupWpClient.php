<?php

namespace Modules\SpinupWp;

use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SpinupWpClient
{
    protected int $retryAttempts;

    protected int $delayMs;

    public function __construct(
        protected ?string $token = null,
        protected ?string $baseUrl = null,
        protected ?int $timeout = null,
        protected ?bool $viewOnly = null,
        ?int $retryAttempts = null,
        ?int $delayMs = null,
    ) {
        $settings = function_exists('app') && app()->bound(Settings::class) ? app(Settings::class) : null;

        $this->token ??= (string) config('clockwork.spinupwp.token');
        $this->baseUrl ??= (string) config('clockwork.spinupwp.base_url');
        $this->timeout ??= (int) ($settings?->get('services.spinupwp.timeout') ?? config('clockwork.spinupwp.timeout', 15));
        $this->viewOnly = $viewOnly ?? (bool) config('clockwork.spinupwp.view_only', false);
        $this->retryAttempts = $retryAttempts ?? (int) ($settings?->get('services.spinupwp.retry_attempts') ?? 2);
        $this->delayMs = $delayMs ?? (int) ($settings?->get('services.spinupwp.delay_ms') ?? 0);
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

    public function isViewOnly(): bool
    {
        return $this->viewOnly ?? false;
    }

    public function servers(): array
    {
        return $this->paginate('/servers');
    }

    public function sites(): array
    {
        return $this->paginate('/sites');
    }

    public function server(int|string $id): array
    {
        return $this->get("/servers/{$id}")->json('data', []);
    }

    public function site(int|string $id): array
    {
        return $this->get("/sites/{$id}")->json('data', []);
    }

    /**
     * Returns just the `backups` sub-object from a site response — files/db
     * enabled flags, retention, next_run_time, storage_provider. SpinupWP
     * does NOT currently expose a backup-history endpoint; callers should
     * not expect run-by-run records here.
     *
     * @return array<string, mixed>
     */
    public function siteBackupConfig(int|string $id): array
    {
        $site = $this->site($id);

        return is_array($site['backups'] ?? null) ? $site['backups'] : [];
    }

    /**
     * Best-effort fetch of a site's recent events. SpinupWP exposes events
     * underneath each resource as `/sites/{id}/events`. The endpoint is paginated;
     * we just return the first page (newest first), which is plenty for polling
     * "is the site ready yet?" questions. If the endpoint returns a 404 (older
     * accounts didn't expose it), return an empty array rather than throwing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function siteEvents(int|string $id, int $limit = 25): array
    {
        try {
            $response = $this->get("/sites/{$id}/events", ['limit' => $limit]);
        } catch (\Throwable $e) {
            // Endpoint may not exist on every plan — caller can fall back to site() polling.
            return [];
        }

        return $response->json('data', []);
    }

    protected function paginate(string $path, int $limit = 100): array
    {
        $all = [];
        $page = 1;

        do {
            $response = $this->get($path, [
                'page' => $page,
                'limit' => $limit,
            ]);

            $body = $response->json();
            $all = array_merge($all, $body['data'] ?? []);

            $hasNext = ! empty($body['pagination']['next']);
            $page++;
        } while ($hasNext);

        return $all;
    }

    protected function client(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('SpinupWP API token is not configured (CLOCKWORK_SPINUPWP_TOKEN).');
        }

        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
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
        $response = $this->client()->get($path, $query);

        if ($response->failed()) {
            throw new RuntimeException(
                "SpinupWP GET {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }

    public function post(string $path, array $data = []): Response
    {
        if ($this->isViewOnly()) {
            throw new SpinupWpReadOnlyException('SpinupWP', "POST [{$path}]");
        }

        $response = $this->client()->post($path, $data);

        if ($response->failed()) {
            throw new RuntimeException(
                "SpinupWP POST {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }

    public function put(string $path, array $data = []): Response
    {
        if ($this->isViewOnly()) {
            throw new SpinupWpReadOnlyException('SpinupWP', "PUT [{$path}]");
        }

        $response = $this->client()->put($path, $data);

        if ($response->failed()) {
            throw new RuntimeException(
                "SpinupWP PUT {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }

    public function delete(string $path, array $data = []): Response
    {
        if ($this->isViewOnly()) {
            throw new SpinupWpReadOnlyException('SpinupWP', "DELETE [{$path}]");
        }

        $response = $this->client()->delete($path, $data);

        if ($response->failed()) {
            throw new RuntimeException(
                "SpinupWP DELETE {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }
}
