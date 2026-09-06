<?php

namespace Modules\WPEngine;

use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for the WP Engine REST API (api.wpengine.com/v1, per WP Engine's
 * published API reference — version 1.21.2 as of this writing).
 *
 * Auth: HTTP Basic, using an API User ID / Password pair minted at
 * my.wpengine.com/api_access — NOT the account's portal login, and NOT the
 * per-install SSH keypair (see WPEngineSshCommandRunner/
 * WPEngineCompanionInstaller for that separate credential).
 *
 * Unlike PressableClient/SpinupWpClient, this module has not yet been
 * exercised against a live WP Engine account — every endpoint shape below
 * is built from WP Engine's published API documentation, not confirmed via
 * a real response. Treat method-level "needs live-account confirmation"
 * notes as genuine unknowns, not hedging. See WPEngineServiceProvider's
 * manifest description for the same caveat surfaced to operators.
 */
class WPEngineClient
{
    protected int $retryAttempts;

    protected int $delayMs;

    public function __construct(
        protected ?string $apiUserId = null,
        protected ?string $apiPassword = null,
        protected ?string $baseUrl = null,
        protected ?int $timeout = null,
        protected ?bool $viewOnly = null,
        ?int $retryAttempts = null,
        ?int $delayMs = null,
    ) {
        $settings = function_exists('app') && app()->bound(Settings::class) ? app(Settings::class) : null;

        $this->apiUserId ??= (string) config('clockwork.wpengine.api_user_id');
        $this->apiPassword ??= (string) config('clockwork.wpengine.api_password');
        $this->baseUrl ??= (string) config('clockwork.wpengine.base_url');
        $this->timeout ??= (int) ($settings?->get('services.wpengine.timeout') ?? config('clockwork.wpengine.timeout', 15));
        $this->viewOnly = $viewOnly ?? (bool) config('clockwork.wpengine.view_only', true);
        $this->retryAttempts = $retryAttempts ?? (int) ($settings?->get('services.wpengine.retry_attempts') ?? 2);
        $this->delayMs = $delayMs ?? (int) ($settings?->get('services.wpengine.delay_ms') ?? 0);
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
        return $this->apiUserId !== '' && $this->apiPassword !== '';
    }

    public function isViewOnly(): bool
    {
        return $this->viewOnly ?? true;
    }

    /**
     * List installs (WP Engine's term for a single WordPress site instance).
     *
     * Pagination: WP Engine's documented API list endpoints use `limit`/
     * `offset` query params with a response envelope of
     * {previous, next, count, results} — `next`/`previous` are full URLs
     * (DRF-style), not page numbers. That shape is documented but NOT
     * confirmed against a live account by this module, so this method
     * follows `next` defensively (stops as soon as it's missing/malformed
     * rather than assuming a particular URL form) instead of trusting it
     * blindly the way DigitalOceanClient::droplets() trusts DO's confirmed
     * pagination shape.
     *
     * @return array<int, array<string, mixed>>
     */
    public function installs(int $perPage = 100): array
    {
        $all = [];
        $offset = 0;
        $safetyLimit = 200; // 200 pages worth, same guard rail as PressableClient::paginate()
        $iterations = 0;

        $path = '/installs';
        $query = ['limit' => $perPage, 'offset' => $offset];

        while (true) {
            if (++$iterations > $safetyLimit) {
                throw new RuntimeException("WP Engine installs() pagination exceeded {$safetyLimit} pages; aborting.");
            }

            $response = $this->get($path, $query);
            $body = $response->json();
            $all = array_merge($all, $body['results'] ?? []);

            $next = $body['next'] ?? null;
            if (! is_string($next) || $next === '') {
                break;
            }

            // `next` is documented as a full URL; extract just the query
            // string and re-issue against our own base URL/auth rather than
            // trusting an arbitrary absolute URL from the response.
            $parts = parse_url($next);
            if (! isset($parts['query'])) {
                break;
            }
            parse_str($parts['query'], $query);
            $path = '/installs';
        }

        return $all;
    }

    /**
     * @return array<string, mixed>
     */
    public function install(string $installName): array
    {
        return $this->get("/installs/{$installName}")->json() ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function domains(string $installName): array
    {
        return $this->get("/installs/{$installName}/domains")->json('results', []);
    }

    /**
     * Backup history for an install.
     *
     * NEEDS LIVE-ACCOUNT CONFIRMATION: WP Engine's public API reference
     * documents a `/installs/{id}/backups` resource for triggering and
     * listing backups, but this module has not verified the response
     * envelope shape (whether results are flat or wrapped the same
     * {previous,next,count,results} way installs()/domains() are) — parse
     * defensively at call sites rather than assuming either shape.
     *
     * @return array<int, array<string, mixed>>
     */
    public function backups(string $installName): array
    {
        $body = $this->get("/installs/{$installName}/backups")->json();

        return $body['results'] ?? (is_array($body) ? $body : []);
    }

    /**
     * SSL certificates currently associated with an install.
     *
     * NEEDS LIVE-ACCOUNT CONFIRMATION: no dedicated SSL endpoint appears in
     * WP Engine's published API reference as of this writing — this path is
     * this module's best guess at where such a resource would live if/when
     * exposed, modeled on domains()'s shape. Do not treat CAP_CERT_SYNC as
     * load-bearing for WP Engine until this has been checked against a real
     * account; WPEngineCheck deliberately does NOT call this method for
     * that reason (see its own docblock).
     *
     * @return array<int, array<string, mixed>>
     */
    public function sslCertificates(string $installName): array
    {
        $body = $this->get("/installs/{$installName}/ssl_certificates")->json();

        return $body['results'] ?? (is_array($body) ? $body : []);
    }

    /**
     * Request a Let's Encrypt certificate for a domain on an install.
     *
     * NEEDS LIVE-ACCOUNT CONFIRMATION: same caveat as sslCertificates() —
     * the request shape below ({domain}) is a best guess, not a documented
     * or confirmed contract.
     *
     * @return array<string, mixed>
     */
    public function requestSslCertificate(string $installName, string $domain): array
    {
        return $this->post("/installs/{$installName}/ssl_certificates", [
            'domain' => $domain,
        ])->json() ?? [];
    }

    /**
     * Register an SSH public key account-wide. This is an operator setup
     * action (done once, out of band, likely via the WP Engine portal
     * directly rather than this method) — nothing in this module calls it
     * automatically. Implemented here for completeness/future use only.
     *
     * @return array<string, mixed>
     */
    public function registerSshKey(string $publicKey, string $label): array
    {
        return $this->post('/ssh_keys', [
            'public_key' => $publicKey,
            'label' => $label,
        ])->json() ?? [];
    }

    /**
     * Cheapest available call for a "does auth work" probe — used by
     * WPEngineCheck. GET /installs with a tiny page size rather than a
     * dedicated /account endpoint, since none is documented.
     *
     * @return array<string, mixed> the raw pagination envelope (we only care about `count`)
     */
    public function probe(): array
    {
        return $this->get('/installs', ['limit' => 1, 'offset' => 0])->json() ?? [];
    }

    protected function client(): PendingRequest
    {
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }

        $request = Http::baseUrl($this->baseUrl)
            ->withBasicAuth((string) $this->apiUserId, (string) $this->apiPassword)
            ->acceptJson()
            ->timeout($this->timeout);

        if ($this->retryAttempts > 0) {
            $request->retry($this->retryAttempts, 500, throw: false);
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function get(string $path, array $query = []): Response
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('WP Engine API credentials are not configured. Set CLOCKWORK_WPENGINE_API_USER_ID / CLOCKWORK_WPENGINE_API_PASSWORD in .env.');
        }

        $response = $this->client()->get($path, $query);

        if ($response->failed()) {
            throw new RuntimeException(
                "WP Engine GET {$path} failed: HTTP {$response->status()} {$response->body()}"
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
            throw new WPEngineReadOnlyException('WP Engine', "POST [{$path}]");
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('WP Engine API credentials are not configured. Set CLOCKWORK_WPENGINE_API_USER_ID / CLOCKWORK_WPENGINE_API_PASSWORD in .env.');
        }

        $response = $this->client()->post($path, $body);

        if ($response->failed()) {
            throw new RuntimeException(
                "WP Engine POST {$path} failed: HTTP {$response->status()} {$response->body()}"
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
            throw new WPEngineReadOnlyException('WP Engine', "PUT [{$path}]");
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('WP Engine API credentials are not configured. Set CLOCKWORK_WPENGINE_API_USER_ID / CLOCKWORK_WPENGINE_API_PASSWORD in .env.');
        }

        $response = $this->client()->put($path, $body);

        if ($response->failed()) {
            throw new RuntimeException(
                "WP Engine PUT {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }

    protected function delete(string $path): Response
    {
        if ($this->isViewOnly()) {
            throw new WPEngineReadOnlyException('WP Engine', "DELETE [{$path}]");
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('WP Engine API credentials are not configured. Set CLOCKWORK_WPENGINE_API_USER_ID / CLOCKWORK_WPENGINE_API_PASSWORD in .env.');
        }

        $response = $this->client()->delete($path);

        if ($response->failed()) {
            throw new RuntimeException(
                "WP Engine DELETE {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }
}
