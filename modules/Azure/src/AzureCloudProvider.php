<?php

namespace Modules\Azure;

use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Modules\Core\Contracts\CloudProvider;
use Throwable;

class AzureCloudProvider implements CloudProvider
{
    public function __construct(
        private readonly AzureClient $client,
        private readonly AzureMetricsParser $parser,
    ) {}

    public function id(): string
    {
        return Server::PROVIDER_AZURE;
    }

    public function label(): string
    {
        return 'Azure VM';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function instanceNoun(): string
    {
        return 'VM';
    }

    public function iconClass(): string
    {
        // No Font Awesome brand mark for Azure either — same treatment as
        // Hetzner, generic server icon in Azure's brand blue.
        return 'fa-solid fa-server';
    }

    public function iconColor(): ?string
    {
        return '#0089D6';
    }

    /**
     * No Azure-specific tier map exists yet — VM size names like
     * "Standard_B2s" would need real family-prefix knowledge (B=Burstable,
     * D=General purpose, E=Memory-optimized, etc.) this app doesn't have
     * verified yet, and no Azure servers are live in the fleet as of this
     * writing. Returning the raw slug is honest about that gap — better
     * than silently running it through DigitalOcean's unrelated naming
     * scheme, which is what happened before this class existed (the old
     * SizeTierResolver's outer match had no Azure arm at all).
     */
    public function sizeTier(?string $sizeSlug): ?string
    {
        return $sizeSlug;
    }

    /**
     * Azure Monitor exposes Percentage CPU directly (0-100%) and Available
     * Memory Bytes. Disk % requires the Azure Monitor Agent; left null
     * until that's deployed.
     */
    public function metrics(Server $server, int $start, int $end): array
    {
        $resourceId = (string) $server->provider_id;

        $payload = $this->client->vmMetrics(
            $resourceId,
            ['Percentage CPU', 'Available Memory Bytes'],
            $start,
            $end,
        );

        return [
            'cpu_pct' => $this->parser->latestCpuPercent($payload),
            'memory_pct' => $this->parser->memoryUsedPercent($payload, $server->memory_mb),
            'disk_pct' => null,
            'load_1' => null,
        ];
    }

    /**
     * No live-ID list is fetched for Azure — unlike DO/Hetzner, Azure
     * resource IDs are stable long-lived ARM paths, not ephemeral instance
     * IDs, so there's nothing to cross-reference for a deletion check.
     */
    public function aliveProviderIds(): ?array
    {
        return null;
    }

    public function isDeletedAtProvider(Server $server, ?array $aliveIds): bool
    {
        return false;
    }

    /**
     * Azure doesn't expose public IPs directly on the VM object — they live
     * on Network Interface Cards (NICs), separate resources. Rather than
     * chasing the NIC->VM link (N extra API calls), this uses the public IP
     * resource's `ipConfiguration.id`, which contains the NIC resource
     * path, and extracts the VM name from it using a convention that holds
     * for standard SpinupWP-style VM deployments (NIC named after the VM).
     */
    public function instancesByIp(): array
    {
        if (! $this->client->isConfigured()) {
            return [];
        }

        try {
            $publicIps = $this->client->publicIpAddresses();
            $vms = $this->client->virtualMachines();
        } catch (Throwable $e) {
            Log::warning('cloud_provider.azure.instances_by_ip_fetch_failed', ['error' => $e->getMessage()]);

            return [];
        }

        $vmByName = [];
        foreach ($vms as $vm) {
            $name = strtolower($vm['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $size = $vm['properties']['hardwareProfile']['vmSize'] ?? null;
            $vmByName[$name] = [
                'id' => $vm['id'],
                'size_slug' => $size,
                'vcpus' => null,
                'memory_mb' => null,
                'disk_gb' => null,
            ];
        }

        $byIp = [];
        foreach ($publicIps as $pip) {
            $ip = $pip['properties']['ipAddress'] ?? null;
            if (! $ip) {
                continue;
            }

            // ipConfiguration.id looks like:
            // /subscriptions/{sub}/resourceGroups/{rg}/providers/Microsoft.Network/networkInterfaces/{nic-name}/ipConfigurations/ipconfig1
            $nicId = $pip['properties']['ipConfiguration']['id'] ?? null;
            if (! $nicId) {
                continue;
            }

            if (! preg_match('~/networkInterfaces/([^/]+)/~i', $nicId, $m)) {
                continue;
            }
            $nicName = strtolower($m[1]);

            // Common Azure naming conventions: {vmname}-nic, {vmname}VMNic, {vmname}_nic.
            $vmName = preg_replace('/[-_]?vm[-_]?nic$|[-_]?nic\d*$/i', '', $nicName);

            if (isset($vmByName[$vmName])) {
                $byIp[$ip] = $vmByName[$vmName];
            } elseif (isset($vmByName[$nicName])) {
                $byIp[$ip] = $vmByName[$nicName];
            }
        }

        return $byIp;
    }
}
