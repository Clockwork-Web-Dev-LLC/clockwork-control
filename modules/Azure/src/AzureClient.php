<?php

namespace Modules\Azure;

use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the Azure Resource Manager REST API and Azure Monitor
 * Metrics API. Mirrors the HetznerClient / DigitalOceanClient surface so
 * PollServers and ReconcileProvider can follow the same branch pattern.
 *
 * Authentication uses the OAuth 2.0 client-credentials flow against
 * login.microsoftonline.com — i.e. a Service Principal with at minimum
 * Reader access on the target subscription or resource group.
 *
 * Required config keys (via .env):
 *   CLOCKWORK_AZURE_TENANT_ID       – Azure AD / Entra ID tenant GUID
 *   CLOCKWORK_AZURE_CLIENT_ID       – Service Principal application GUID
 *   CLOCKWORK_AZURE_CLIENT_SECRET   – Service Principal client secret
 *   CLOCKWORK_AZURE_SUBSCRIPTION_ID – Subscription that owns the VMs
 */
class AzureClient
{
    protected ?string $cachedToken = null;

    protected ?int $tokenExpiresAt = null;

    protected int $retryAttempts;

    protected int $delayMs;

    public function __construct(
        protected ?string $tenantId = null,
        protected ?string $clientId = null,
        protected ?string $clientSecret = null,
        protected ?string $subscriptionId = null,
        protected ?string $baseUrl = null,
        protected ?string $loginUrl = null,
        protected ?int $timeout = null,
        ?int $retryAttempts = null,
        ?int $delayMs = null,
    ) {
        $settings = function_exists('app') && app()->bound(Settings::class) ? app(Settings::class) : null;

        $this->tenantId ??= (string) config('clockwork.azure.tenant_id');
        $this->clientId ??= (string) config('clockwork.azure.client_id');
        $this->clientSecret ??= (string) config('clockwork.azure.client_secret');
        $this->subscriptionId ??= (string) config('clockwork.azure.subscription_id');
        $this->baseUrl ??= (string) config('clockwork.azure.base_url', 'https://management.azure.com');
        $this->loginUrl ??= (string) config('clockwork.azure.login_url', 'https://login.microsoftonline.com');
        $this->timeout ??= (int) ($settings?->get('services.azure.timeout') ?? config('clockwork.azure.timeout', 15));
        $this->retryAttempts = $retryAttempts ?? (int) ($settings?->get('services.azure.retry_attempts') ?? 3);
        $this->delayMs = $delayMs ?? (int) ($settings?->get('services.azure.delay_ms') ?? 0);
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
        return $this->tenantId !== ''
            && $this->clientId !== ''
            && $this->clientSecret !== ''
            && $this->subscriptionId !== '';
    }

    /**
     * Verify credentials by fetching the subscription details.
     * Used by the `clockwork:azure-test` command.
     */
    public function subscription(): array
    {
        return $this->get("/subscriptions/{$this->subscriptionId}", ['api-version' => '2022-12-01'])->json();
    }

    /**
     * List all Azure VMs in the subscription. Each item includes the full
     * resource ID (used as provider_id), name, location, hardware profile
     * (vmSize), and network interface references.
     */
    public function virtualMachines(int $perPage = 100): array
    {
        return $this->getPaginated(
            "/subscriptions/{$this->subscriptionId}/providers/Microsoft.Compute/virtualMachines",
            ['api-version' => '2024-03-01', '$top' => $perPage],
        );
    }

    /**
     * List all public IP addresses in the subscription. Each item exposes
     * `properties.ipAddress` so ReconcileProvider can match by the IP stored
     * in Server::hostname, then trace back to the associated VM resource ID
     * via the NIC linkage.
     */
    public function publicIpAddresses(): array
    {
        return $this->getPaginated(
            "/subscriptions/{$this->subscriptionId}/providers/Microsoft.Network/publicIPAddresses",
            ['api-version' => '2024-03-01'],
        );
    }

    /**
     * Fetch Azure Monitor metrics for a VM identified by its full resource ID.
     *
     * @param  string  $resourceId  Full ARM resource ID, e.g.
     *                              /subscriptions/{sub}/resourceGroups/{rg}/providers/Microsoft.Compute/virtualMachines/{name}
     * @param  string[]  $metricNames  e.g. ['Percentage CPU', 'Available Memory Bytes']
     * @param  int  $start  Unix timestamp (window start)
     * @param  int  $end  Unix timestamp (window end)
     */
    public function vmMetrics(string $resourceId, array $metricNames, int $start, int $end): array
    {
        $timespan = gmdate('Y-m-d\TH:i:s\Z', $start).'/'.gmdate('Y-m-d\TH:i:s\Z', $end);

        return $this->get(trim($resourceId, '/').'/providers/microsoft.insights/metrics', [
            'api-version' => '2018-01-01',
            'metricnames' => implode(',', $metricNames),
            'timespan' => $timespan,
            'aggregation' => 'Average',
            'interval' => 'PT1M',
        ])->json();
    }

    /**
     * Retrieve a bearer token via the OAuth 2.0 client-credentials flow.
     * The token is cached in-memory for its lifetime (typically 1 hour).
     */
    public function token(): string
    {
        if ($this->cachedToken && $this->tokenExpiresAt && time() < $this->tokenExpiresAt - 60) {
            return $this->cachedToken;
        }

        $response = Http::asForm()
            ->timeout($this->timeout)
            ->post("{$this->loginUrl}/{$this->tenantId}/oauth2/v2.0/token", [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'https://management.azure.com/.default',
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Azure auth failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        $data = $response->json();
        $this->cachedToken = $data['access_token'] ?? throw new RuntimeException('Azure token response missing access_token.');
        $this->tokenExpiresAt = time() + (int) ($data['expires_in'] ?? 3600);

        return $this->cachedToken;
    }

    // -------------------------------------------------------------------------

    protected function client(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Azure credentials are not configured. Set CLOCKWORK_AZURE_TENANT_ID, CLOCKWORK_AZURE_CLIENT_ID, CLOCKWORK_AZURE_CLIENT_SECRET, and CLOCKWORK_AZURE_SUBSCRIPTION_ID.');
        }

        $request = Http::baseUrl($this->baseUrl)
            ->withToken($this->token())
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
                "Azure GET {$path} failed: HTTP {$response->status()} {$response->body()}"
            );
        }

        return $response;
    }

    /**
     * Follow Azure's nextLink pagination and return the merged value array.
     */
    protected function getPaginated(string $path, array $query = []): array
    {
        $all = [];

        $response = $this->get($path, $query)->json();
        $all = array_merge($all, $response['value'] ?? []);

        while ($nextLink = ($response['nextLink'] ?? null)) {
            // nextLink is an absolute URL — strip the base so Http::baseUrl works.
            $relative = str_replace($this->baseUrl, '', $nextLink);
            $response = $this->get($relative)->json();
            $all = array_merge($all, $response['value'] ?? []);
        }

        return $all;
    }
}
