<?php

namespace Modules\GridPane;

use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GridPaneClient
{
    public const DEFAULT_BASE_URL = 'https://my.gridpane.com/oauth/api/v1';

    protected int $retryAttempts;

    protected int $delayMs;

    public function __construct(
        protected ?string $apiKey = null,
        protected ?string $baseUrl = null,
        protected ?int $timeout = null,
        protected ?bool $viewOnly = null,
        ?int $retryAttempts = null,
        ?int $delayMs = null,
    ) {
        $settings = function_exists('app') && app()->bound(Settings::class) ? app(Settings::class) : null;

        $this->apiKey = $apiKey ?? (string) config('clockwork.gridpane.api_key');
        $this->baseUrl = $baseUrl ?? (string) config('clockwork.gridpane.base_url', self::DEFAULT_BASE_URL);
        $this->timeout = $timeout ?? (int) ($settings?->get('services.gridpane.timeout') ?? config('clockwork.gridpane.timeout', 15));
        $this->viewOnly = $viewOnly ?? (bool) config('clockwork.gridpane.view_only', true);
        $this->retryAttempts = $retryAttempts ?? (int) ($settings?->get('services.gridpane.retry_attempts') ?? 2);
        $this->delayMs = $delayMs ?? (int) ($settings?->get('services.gridpane.delay_ms') ?? 0);
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
        return ! empty($this->apiKey);
    }

    public function isViewOnly(): bool
    {
        return $this->viewOnly ?? true;
    }

    /**
     * Get authenticated user details (used for diagnostic check).
     *
     * @return array<string, mixed>
     */
    public function user(): array
    {
        return $this->get('/user')->json() ?? [];
    }

    /**
     * Fetch all servers belonging to the user.
     *
     * @return list<array<string, mixed>>
     */
    public function servers(): array
    {
        return $this->paginate('/server');
    }

    /**
     * Fetch a single server by ID.
     *
     * @return array<string, mixed>
     */
    public function server(int|string $id): array
    {
        $data = $this->get("/server/{$id}")->json();

        if (is_array($data)) {
            return $data['server'] ?? $data['data'] ?? $data;
        }

        return [];
    }

    /**
     * Fetch all sites belonging to the user.
     *
     * @return list<array<string, mixed>>
     */
    public function sites(): array
    {
        return $this->paginate('/site');
    }

    /**
     * Fetch a single site by ID.
     *
     * @return array<string, mixed>
     */
    public function site(int|string $id): array
    {
        $data = $this->get("/site/{$id}")->json();

        if (is_array($data)) {
            return $data['site'] ?? $data['data'] ?? $data;
        }

        return [];
    }

    /**
     * Fetch all system users.
     *
     * @return list<array<string, mixed>>
     */
    public function systemUsers(): array
    {
        $data = $this->get('/system-user')->json();

        if (is_array($data)) {
            if (isset($data['system_users']) && is_array($data['system_users'])) {
                return $data['system_users'];
            }
            if (isset($data['data']) && is_array($data['data'])) {
                return $data['data'];
            }
            if (array_is_list($data)) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Fetch site backup schedules.
     *
     * @return array<string, mixed>
     */
    public function backupSchedules(int|string $siteId): array
    {
        $data = $this->get("/backups/schedules/site/{$siteId}")->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Execute a WP-CLI command remotely on a site via GridPane API.
     *
     * @return array<string, mixed>
     */
    public function runWpCli(int|string $siteId, string $command): array
    {
        if ($this->isViewOnly()) {
            throw new GridPaneReadOnlyException('GridPane', 'Remote WP-CLI execution');
        }

        return $this->put("/site/run-wp-cli/{$siteId}", [
            'command' => $command,
        ])->json() ?? [];
    }

    public function get(string $path, array $query = []): Response
    {
        return $this->request()->get($this->url($path), $query)->throw();
    }

    public function post(string $path, array $data = []): Response
    {
        if ($this->isViewOnly()) {
            throw new GridPaneReadOnlyException('GridPane', "POST [{$path}]");
        }

        return $this->request()->post($this->url($path), $data)->throw();
    }

    public function put(string $path, array $data = []): Response
    {
        if ($this->isViewOnly()) {
            throw new GridPaneReadOnlyException('GridPane', "PUT [{$path}]");
        }

        return $this->request()->put($this->url($path), $data)->throw();
    }

    public function delete(string $path): Response
    {
        if ($this->isViewOnly()) {
            throw new GridPaneReadOnlyException('GridPane', "DELETE [{$path}]");
        }

        return $this->request()->delete($this->url($path))->throw();
    }

    /**
     * Auto-paginate through all pages of a GridPane API collection endpoint.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function paginate(string $path, array $query = []): array
    {
        $all = [];
        $page = 1;
        $safetyLimit = 200; // matches PressableClient::paginate()'s guard against degenerate pagination metadata

        do {
            if ($page > $safetyLimit) {
                throw new RuntimeException("GridPane pagination exceeded {$safetyLimit} pages on {$path}; aborting.");
            }

            $pageQuery = $query;
            if ($page > 1) {
                $pageQuery['page'] = $page;
            }

            $response = $this->get($path, $pageQuery);
            $data = $response->json();

            if (! is_array($data)) {
                break;
            }

            /** @var list<array<string, mixed>> $items */
            $items = $data['data'] ?? $data['servers'] ?? $data['sites'] ?? (array_is_list($data) ? $data : []);
            if (! is_array($items) || $items === []) {
                break;
            }

            $all = array_merge($all, $items);

            $hasNext = ! empty($data['links']['next'])
                || (isset($data['meta']['current_page'], $data['meta']['last_page']) && $data['meta']['current_page'] < $data['meta']['last_page']);

            $page++;

            if ($hasNext) {
                // Subtle delay between page requests to stay well within GridPane endpoint limits
                usleep(150000);
            }
        } while ($hasNext);

        return $all;
    }

    protected function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('GridPane API key is not configured.');
        }

        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }

        $request = Http::withToken($this->apiKey)
            ->timeout($this->timeout)
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => 'ClockworkControl-GridPane/1.0',
            ]);

        if ($this->retryAttempts > 0) {
            $request->retry($this->retryAttempts, function (int $attempt, \Throwable $exception) {
                if ($exception instanceof RequestException && $exception->response->status() === 429) {
                    // header() returns '' (not null) for a missing header, so
                    // a `??` chain never actually falls through to the
                    // default — check for a genuinely non-empty value instead.
                    $header = $exception->response->header('Retry-After');
                    $retryAfter = $header !== '' ? (int) $header : 3;

                    return max(1000, ($retryAfter + 1) * 1000);
                }

                return 500;
            }, throw: false);
        }

        return $request;
    }

    protected function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }
}
