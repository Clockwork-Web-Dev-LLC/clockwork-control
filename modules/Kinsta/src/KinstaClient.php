<?php

namespace Modules\Kinsta;

use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for the Kinsta API (https://api.kinsta.com/v2).
 *
 * Auth: a single static Bearer API key (created once in the MyKinsta
 * dashboard under Company Settings > API Keys) — unlike Pressable's OAuth2
 * client_credentials dance, there's no token exchange or expiry to manage.
 *
 * Kinsta's resource hierarchy is Company -> Site -> Environment. A "site" is
 * the logical WordPress project; each site has one or more "environments"
 * (live, staging, ...) and an environment is the thing that actually has
 * files, a database, and (per this module) SSH access. Clockwork's
 * sites.kinsta_environment_id column addresses an environment directly —
 * there is no server concept at all, same shape as Pressable and WP Engine.
 *
 * Unverified-endpoint disclosure: everything in this class is built from
 * Kinsta's publicly documented API URL structure plus prior research this
 * session that confirmed the `ssh/set-status`, `ssh/generate-password`, and
 * `ssh/set-allowed-ips` endpoints exist under `/sites/environments/{id}/`.
 * The one method that is a genuine best-effort GUESS rather than a
 * confirmed path is sshConnectionInfo() — see its docblock. Every other
 * method mirrors Kinsta's documented REST shape, but none of this has been
 * exercised against a live Kinsta account from this codebase; treat paths
 * and response-envelope field names as needing live-account confirmation
 * before first production use, same posture as WPEngineCompanionInstaller's
 * docroot assumption.
 */
class KinstaClient
{
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

        $this->apiKey ??= (string) config('clockwork.kinsta.api_key');
        $this->baseUrl ??= (string) config('clockwork.kinsta.base_url', 'https://api.kinsta.com/v2');
        $this->timeout ??= (int) ($settings?->get('services.kinsta.timeout') ?? config('clockwork.kinsta.timeout', 15));
        $this->viewOnly = $viewOnly ?? (bool) config('clockwork.kinsta.view_only', true);
        $this->retryAttempts = $retryAttempts ?? (int) ($settings?->get('services.kinsta.retry_attempts') ?? 2);
        $this->delayMs = $delayMs ?? (int) ($settings?->get('services.kinsta.delay_ms') ?? 0);
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
        return $this->apiKey !== '' && $this->apiKey !== null;
    }

    public function isViewOnly(): bool
    {
        return $this->viewOnly ?? true;
    }

    /**
     * Confirms the API key authenticates, without requiring a company ID
     * (which isn't part of this module's credential fields today). Kinsta's
     * site-listing endpoint documented below requires a `company` query
     * param, so a bare GET /sites will come back 400/422 for a VALID key —
     * only a 401 means the key itself was rejected. That asymmetry is why
     * this isn't a simple isFailed() check like PressableClient::ping();
     * see KinstaCheck for how the distinction is used.
     *
     * @return array{status: int}
     */
    public function ping(): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Kinsta API key is not configured. Set CLOCKWORK_KINSTA_API_KEY in .env.');
        }

        $response = $this->rawClient()->get('/sites');

        if ($response->status() === 401) {
            throw new RuntimeException('Kinsta API key rejected (HTTP 401).');
        }

        return ['status' => $response->status()];
    }

    /**
     * List sites visible to this API key. $companyId is optional here only
     * because ping() above needs to call the same endpoint without one —
     * Kinsta's real API documents `company` as a required query param for
     * this route, so callers that actually want site data should pass it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sites(?string $companyId = null): array
    {
        $query = $companyId !== null ? ['company' => $companyId] : [];

        return $this->get('/sites', $query)->json('company.sites', []);
    }

    /**
     * Users belonging to a company — the other cheap-probe candidate
     * mentioned in this module's design brief. Kept alongside ping()/sites()
     * rather than used by KinstaCheck, since it needs a company ID we don't
     * collect as a credential; a future company_id credential field could
     * switch KinstaCheck to this instead for a richer probe.
     *
     * BEST-GUESS PATH: modeled on Kinsta's documented "Company" resource
     * group; not confirmed live.
     *
     * @return array<int, array<string, mixed>>
     */
    public function companyUsers(string $companyId): array
    {
        return $this->get("/company/{$companyId}/user")->json('company.users', []);
    }

    /**
     * Environments belonging to a site.
     *
     * @return array<int, array<string, mixed>>
     */
    public function environments(string $siteId): array
    {
        return $this->get("/sites/{$siteId}/environments")->json('site.environments', []);
    }

    /**
     * A single environment's details (name, primary domain, WordPress
     * version, container/hosting metadata, ...). This is also where
     * sshConnectionInfo() below looks for host/port/username — see its
     * docblock for why that's a guess, not a confirmed field shape.
     *
     * @return array<string, mixed>
     */
    public function environment(string $environmentId): array
    {
        return $this->get("/sites/environments/{$environmentId}")->json('site.environment', []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function domains(string $environmentId): array
    {
        return $this->get("/sites/environments/{$environmentId}/domains")->json('domains', []);
    }

    /**
     * Backup history for an environment, newest first per Kinsta's docs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function backups(string $environmentId): array
    {
        return $this->get("/sites/environments/{$environmentId}/backups")->json('environment.backups', []);
    }

    /**
     * Restore an environment from one of its own backups. Kinsta documents
     * this as an async "operation" — the response carries an operation_id to
     * poll, not a synchronous success/failure. Callers that need to know
     * when the restore finishes must poll Kinsta's operations endpoint
     * (not implemented here — no caller needs it yet).
     *
     * @return array<string, mixed>
     */
    public function restoreBackup(string $environmentId, string $backupId): array
    {
        return $this->post("/sites/environments/{$environmentId}/restore/{$backupId}")->json();
    }

    /**
     * Fetch the real SSH host/port/username for an environment.
     *
     * *** THIS IS THE BIGGEST UNVERIFIED ASSUMPTION IN THIS MODULE. ***
     *
     * Kinsta's SSH access is managed per-environment via the API (prior
     * research this session confirmed `/sites/environments/{id}/ssh/set-
     * status`, `.../ssh/generate-password`, and `.../ssh/set-allowed-ips`
     * all exist), which proves Kinsta's API has *some* notion of
     * per-environment SSH state — but no endpoint that returns the
     * connection host/port/username was confirmed live. Two plausible
     * shapes, in order of how likely they seem from Kinsta's documented URL
     * conventions:
     *
     *   1. (implemented below) The environment resource itself
     *      (GET /sites/environments/{id}) carries the SSH connection details
     *      as a sub-object — Kinsta's MyKinsta dashboard displays "SSH/SFTP"
     *      connection info on the same environment detail screen as domains
     *      and PHP version, which is suggestive but not proof the API
     *      exposes it the same way.
     *   2. A dedicated sibling read endpoint, e.g.
     *      GET /sites/environments/{id}/ssh, mirroring the ssh/set-status
     *      write endpoint's path shape.
     *
     * This method tries (1) and looks for a handful of plausible field
     * names. CONFIRM AGAINST A REAL ACCOUNT before relying on this in
     * production — if it's wrong, both KinstaSshCommandRunner and
     * KinstaCompanionInstaller will fail cleanly with a RuntimeException
     * (not silently connect to the wrong host), since neither has a
     * hardcoded fallback host to mask the gap.
     *
     * @return array{host: string, port: int, username: string}
     */
    public function sshConnectionInfo(string $environmentId): array
    {
        $env = $this->environment($environmentId);

        // Field names below are guesses at what Kinsta might nest this
        // under; unrecognized shapes fall through to the exception.
        $ssh = $env['ssh_connection'] ?? $env['ssh'] ?? $env['sftp'] ?? null;

        if (! is_array($ssh)) {
            throw new RuntimeException(
                "Kinsta environment {$environmentId}: could not find SSH connection details in the environment "
                .'payload. This module\'s sshConnectionInfo() guess at the response shape is unconfirmed against '
                .'a live account — see KinstaClient::sshConnectionInfo()\'s docblock.'
            );
        }

        $host = (string) ($ssh['host'] ?? $ssh['hostname'] ?? '');
        $username = (string) ($ssh['username'] ?? $ssh['user'] ?? '');
        $port = (int) ($ssh['port'] ?? 22);

        if ($host === '' || $username === '') {
            throw new RuntimeException(
                "Kinsta environment {$environmentId}: SSH connection payload was found but missing host/username."
            );
        }

        return ['host' => $host, 'port' => $port, 'username' => $username];
    }

    /**
     * Turn SSH access on/off for an environment. Confirmed to exist (prior
     * research this session); request/response body shape is still a
     * best-effort guess (`{"is_enabled": true}` mirrors Kinsta's other
     * boolean-toggle endpoints elsewhere in its API).
     */
    public function setSshStatus(string $environmentId, bool $enabled): void
    {
        $this->post("/sites/environments/{$environmentId}/ssh/set-status", ['is_enabled' => $enabled]);
    }

    /**
     * Generate a fresh one-time SSH password for an environment and return
     * it. Confirmed to exist (prior research this session); the exact
     * response field holding the password is a guess (`password` is the
     * obvious candidate name, checked first).
     */
    public function generateSshPassword(string $environmentId): string
    {
        $response = $this->post("/sites/environments/{$environmentId}/ssh/generate-password");

        $password = (string) ($response->json('password') ?? $response->json('environment.password') ?? '');
        if ($password === '') {
            throw new RuntimeException(
                "Kinsta environment {$environmentId}: generate-password call succeeded but no password was found "
                .'in the response (field-name guess unconfirmed — see KinstaClient::generateSshPassword()).'
            );
        }

        return $password;
    }

    /**
     * Restrict SSH access to a set of allowed IPs. Confirmed to exist
     * (prior research this session); not called by KinstaSshCommandRunner
     * or KinstaCompanionInstaller today — kept here for completeness /
     * future use (e.g. a setup command that allowlists this app server's
     * outbound IP once, up front).
     */
    public function setSshAllowedIps(string $environmentId, array $ips): void
    {
        $this->post("/sites/environments/{$environmentId}/ssh/set-allowed-ips", ['allowed_ips' => array_values($ips)]);
    }

    protected function rawClient(): PendingRequest
    {
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }

        $request = Http::baseUrl($this->baseUrl)
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->timeout($this->timeout);

        if ($this->retryAttempts > 0) {
            // throw: false — ping() deliberately reads a non-401 failed
            // response (a 422 from the missing `company` param) as a
            // successful auth check, not an error. retry()'s own default
            // (throw: true) would raise a RequestException on that same
            // response once retries are exhausted, which every other
            // caller here already guards against via its own explicit
            // $response->failed() check below.
            $request->retry($this->retryAttempts, 500, throw: false);
        }

        return $request;
    }

    protected function get(string $path, array $query = []): Response
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Kinsta API key is not configured. Set CLOCKWORK_KINSTA_API_KEY in .env.');
        }

        $response = $this->rawClient()->get($path, $query);

        if ($response->failed()) {
            throw new RuntimeException(
                "Kinsta GET {$path} failed: HTTP {$response->status()} {$response->body()}"
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
            throw new KinstaReadOnlyException('Kinsta', "POST [{$path}]");
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('Kinsta API key is not configured. Set CLOCKWORK_KINSTA_API_KEY in .env.');
        }

        $response = $this->rawClient()->post($path, $body);

        if ($response->failed()) {
            throw new RuntimeException(
                "Kinsta POST {$path} failed: HTTP {$response->status()} {$response->body()}"
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
            throw new KinstaReadOnlyException('Kinsta', "PUT [{$path}]");
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('Kinsta API key is not configured. Set CLOCKWORK_KINSTA_API_KEY in .env.');
        }

        $response = $this->rawClient()->put($path, $body);

        if ($response->failed()) {
            throw new RuntimeException(
                "Kinsta PUT {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }

    protected function delete(string $path): Response
    {
        if ($this->isViewOnly()) {
            throw new KinstaReadOnlyException('Kinsta', "DELETE [{$path}]");
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('Kinsta API key is not configured. Set CLOCKWORK_KINSTA_API_KEY in .env.');
        }

        $response = $this->rawClient()->delete($path);

        if ($response->failed()) {
            throw new RuntimeException(
                "Kinsta DELETE {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }
}
