<?php

namespace Modules\TurnkeyWp;

use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for TurnkeyWP's REST API v1 (Clockwork's own self-hosted hosting
 * control plane). Auth is a Sanctum personal access token issued under
 * Settings -> API tokens in TurnkeyWP.
 *
 * Collections use Laravel's standard paginator envelope — `data`,
 * `links.next`, `meta.current_page` / `meta.last_page` — with `limit`
 * capped at 100 per page. List endpoints only return what the token's user
 * can see (TurnkeyWP team roles), so a non-Owner token sees part of the fleet.
 *
 * Mutating endpoints are async on TurnkeyWP's side: they answer with an
 * `event_id` that can be polled at GET /events/{id}.
 */
class TurnkeyWpClient
{
    public const DEFAULT_BASE_URL = 'https://app.turnkeywp.com/api/v1';

    /** TurnkeyWP caps `limit` at 100. */
    public const PAGE_SIZE = 100;

    protected int $retryAttempts;

    /**
     * Set when the most recent paginate() call stopped early after already
     * accumulating some pages. The importer refuses to sweep (null tracking
     * ids of rows missing from the response) when this is set, because a
     * truncated listing would otherwise look like mass deletion.
     */
    protected bool $partial = false;

    public function __construct(
        protected ?string $token = null,
        protected ?string $baseUrl = null,
        protected ?int $timeout = null,
        protected ?bool $viewOnly = null,
        ?int $retryAttempts = null,
    ) {
        $settings = function_exists('app') && app()->bound(Settings::class) ? app(Settings::class) : null;

        $this->token = $token ?? (string) config('clockwork.turnkeywp.token');
        $this->baseUrl = $baseUrl ?: (string) config('clockwork.turnkeywp.base_url', self::DEFAULT_BASE_URL);
        $this->timeout = $timeout ?? (int) ($settings?->get('services.turnkeywp.timeout') ?? config('clockwork.turnkeywp.timeout', 15));
        $this->viewOnly = $viewOnly ?? (bool) config('clockwork.turnkeywp.view_only', false);
        $this->retryAttempts = $retryAttempts ?? (int) ($settings?->get('services.turnkeywp.retry_attempts') ?? 2);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->token);
    }

    public function isViewOnly(): bool
    {
        return $this->viewOnly ?? false;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl ?: self::DEFAULT_BASE_URL;
    }

    public function getTimeout(): int
    {
        return (int) $this->timeout;
    }

    /**
     * The user the token belongs to (diagnostic check / test command).
     *
     * @return array<string, mixed>
     */
    public function user(): array
    {
        return $this->get('/user')->json() ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function servers(): array
    {
        return $this->paginate('/servers');
    }

    /**
     * @return array<string, mixed>
     */
    public function server(int|string $id): array
    {
        return $this->get("/servers/{$id}")->json('data') ?? [];
    }

    /**
     * @return list<array<string, mixed>>
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
        return $this->get("/sites/{$id}")->json('data') ?? [];
    }

    /**
     * First page of a site's events (TurnkeyWP tasks), newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function siteEvents(int|string $siteId, int $limit = 25): array
    {
        return $this->get("/sites/{$siteId}/events", ['limit' => $limit])->json('data') ?? [];
    }

    /**
     * One event (TurnkeyWP task) — poll this with the `event_id` a
     * mutating call returned.
     *
     * @return array<string, mixed>
     */
    public function event(int|string $eventId): array
    {
        return $this->get("/events/{$eventId}")->json('data') ?? [];
    }

    /**
     * Traffic rollup from the last $limit metric buckets of $period
     * (TurnkeyWP records `hourly` buckets; limit is capped at 168).
     *
     * @return array<string, mixed>
     */
    public function siteTraffic(int|string $siteId, string $period = 'hourly', int $limit = 24): array
    {
        return $this->get("/sites/{$siteId}/traffic", ['period' => $period, 'limit' => $limit])->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function serverTraffic(int|string $serverId, string $period = 'hourly', int $limit = 24): array
    {
        return $this->get("/servers/{$serverId}/traffic", ['period' => $period, 'limit' => $limit])->json() ?? [];
    }

    /**
     * Backup history for a site (real snapshot records, unlike the
     * `backups` block on the site payload, which is a fixed placeholder).
     *
     * @return list<array<string, mixed>>
     */
    public function siteBackups(int|string $siteId): array
    {
        return $this->paginate("/sites/{$siteId}/backups");
    }

    /**
     * Purge a site's page and object cache. Returns the response body,
     * which carries `event_id`.
     *
     * @return array<string, mixed>
     */
    public function purgeCache(int|string $siteId): array
    {
        return $this->post("/sites/{$siteId}/purge-cache")->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function rebootServer(int|string $serverId): array
    {
        return $this->post("/servers/{$serverId}/reboot")->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function restartService(int|string $serverId, string $service): array
    {
        return $this->post("/servers/{$serverId}/services/{$service}/restart")->json() ?? [];
    }

    /**
     * Add a public key to the server's `turnkey` sudo user. TurnkeyWP
     * deploys it in a queued job; the response carries `event_id`.
     *
     * @return array<string, mixed>
     */
    public function addServerSshKey(int|string $serverId, string $name, string $publicKey): array
    {
        return $this->post("/servers/{$serverId}/ssh-keys", [
            'name' => $name,
            'public_key' => $publicKey,
        ])->json() ?? [];
    }

    /**
     * Public keys TurnkeyWP knows for a server (its own keys plus
     * account-level ones).
     *
     * @return list<array<string, mixed>>
     */
    public function serverSshKeys(int|string $serverId): array
    {
        return $this->paginate("/servers/{$serverId}/ssh-keys");
    }

    public function get(string $path, array $query = []): Response
    {
        return $this->request()->get($this->url($path), $query)->throw();
    }

    public function post(string $path, array $data = []): Response
    {
        if ($this->isViewOnly()) {
            throw new TurnkeyWpReadOnlyException('TurnkeyWP', "POST [{$path}]");
        }

        return $this->request()->post($this->url($path), $data)->throw();
    }

    public function delete(string $path): Response
    {
        if ($this->isViewOnly()) {
            throw new TurnkeyWpReadOnlyException('TurnkeyWP', "DELETE [{$path}]");
        }

        return $this->request()->delete($this->url($path))->throw();
    }

    /**
     * Walk every page of a collection endpoint.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function paginate(string $path, array $query = []): array
    {
        $all = [];
        $page = 1;
        $safetyLimit = 200;
        $this->partial = false;

        do {
            if ($page > $safetyLimit) {
                throw new RuntimeException("TurnkeyWP pagination exceeded {$safetyLimit} pages on {$path}; aborting.");
            }

            try {
                $response = $this->get($path, array_merge($query, ['page' => $page, 'limit' => self::PAGE_SIZE]));
            } catch (\Throwable $e) {
                // Keep pages already fetched, but say the listing is incomplete.
                if ($all === []) {
                    throw $e;
                }

                report($e);
                $this->partial = true;
                break;
            }

            $body = $response->json();
            $items = is_array($body) ? ($body['data'] ?? null) : null;
            if (! is_array($items)) {
                // Not a paginated collection: treat as a broken listing, not an empty fleet.
                throw new RuntimeException("TurnkeyWP GET {$path} did not return a `data` array.");
            }

            $all = array_merge($all, array_values($items));

            $currentPage = (int) ($body['meta']['current_page'] ?? $page);
            $lastPage = (int) ($body['meta']['last_page'] ?? $currentPage);
            $hasNext = $items !== [] && ($currentPage < $lastPage || ! empty($body['links']['next']));

            $page++;
        } while ($hasNext);

        return $all;
    }

    public function wasPartial(): bool
    {
        return $this->partial;
    }

    protected function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('TurnkeyWP API token is not configured.');
        }

        $request = Http::withToken((string) $this->token)
            ->timeout((int) $this->timeout)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'ClockworkControl-TurnkeyWP/1.0']);

        if ($this->retryAttempts > 0) {
            // TurnkeyWP throttles at 60 requests/minute per user and sends
            // Retry-After on a 429. A 429 was never processed, so any method
            // may retry it. 5xx and connection errors retry only for GET: a
            // POST (reboot, restart, add key) may already have been accepted,
            // and sending it again could reboot a server twice. 401/403/404/422
            // are never retried; they won't change on a second attempt.
            $request->retry($this->retryAttempts, function (int $attempt, \Throwable $exception) {
                if ($exception instanceof RequestException && $exception->response->status() === 429) {
                    $after = (int) $exception->response->header('Retry-After');

                    return max(1, $after) * 1000;
                }

                return 500;
            }, function (\Throwable $exception, $request, ?string $method = null) {
                if ($exception instanceof RequestException && $exception->response->status() === 429) {
                    return true;
                }

                if (strtoupper((string) $method) !== 'GET') {
                    return false;
                }

                if ($exception instanceof RequestException) {
                    return $exception->response->status() >= 500;
                }

                return true; // connection errors on a GET
            }, throw: false);
        }

        return $request;
    }

    protected function url(string $path): string
    {
        return rtrim($this->baseUrl(), '/').'/'.ltrim($path, '/');
    }
}
