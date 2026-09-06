<?php

namespace Modules\Cloudways;

use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Modules\Core\Contracts\CloudProvider;
use Throwable;

/**
 * CloudProvider half of the Cloudways module. Cloudways provisions real
 * servers (on DO/AWS/GCP/Vultr/Linode, per the customer's choice) that this
 * app never holds native-cloud credentials for — all metrics and
 * alive/dead state here come from Cloudways' own API, not the underlying
 * cloud's. See CloudwaysClient's docblock for the shape assumptions this
 * class's calls rest on.
 */
class CloudwaysCloudProvider implements CloudProvider
{
    public function __construct(
        private readonly CloudwaysClient $client,
        private readonly CloudwaysMetricsParser $parser,
    ) {}

    public function id(): string
    {
        return Server::PROVIDER_CLOUDWAYS;
    }

    public function label(): string
    {
        return 'Cloudways server';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function instanceNoun(): string
    {
        return 'server';
    }

    public function iconClass(): string
    {
        // No Font Awesome brand mark exists for Cloudways (unlike
        // DigitalOcean's fa-brands fa-digital-ocean) — reuse the generic
        // server icon, same convention HetznerCloudProvider follows.
        return 'fa-solid fa-server';
    }

    public function iconColor(): ?string
    {
        // Cloudways' brand accent (their marketing site's primary red-orange).
        return '#E44C4C';
    }

    /**
     * No confirmed Cloudways size-tier naming convention to map (its
     * servers are labeled by the underlying cloud's own instance sizes,
     * e.g. DigitalOcean droplet sizes or AWS instance types, chosen at
     * provisioning time) — returning the raw slug unchanged is the honest
     * choice here, same reasoning AzureClient/AzureCloudProvider uses for
     * VM SKU names.
     */
    public function sizeTier(?string $sizeSlug): ?string
    {
        return $sizeSlug;
    }

    public function metrics(Server $server, int $start, int $end): array
    {
        $providerId = (string) $server->provider_id;

        $cpuPct = null;
        $memoryPct = null;
        $diskPct = null;
        $load1 = null;

        try {
            $cpuPct = $this->parser->latestPercent(
                $this->client->serverMonitorSummary($providerId, 'cpu', $start, $end)
            );
        } catch (Throwable) {
            // best-effort
        }
        try {
            $memoryPct = $this->parser->latestPercent(
                $this->client->serverMonitorSummary($providerId, 'ram', $start, $end)
            );
        } catch (Throwable) {
            // best-effort
        }
        try {
            $load1 = $this->parser->latestRawValue(
                $this->client->serverMonitorSummary($providerId, 'load', $start, $end)
            );
        } catch (Throwable) {
            // best-effort
        }
        try {
            $diskPct = $this->parser->diskPercent(
                $this->client->serverDiskUsage($providerId)
            );
        } catch (Throwable) {
            // best-effort
        }

        return [
            'cpu_pct' => $cpuPct,
            'memory_pct' => $memoryPct,
            'disk_pct' => $diskPct,
            'load_1' => $load1,
        ];
    }

    public function aliveProviderIds(): ?array
    {
        if (! $this->client->isConfigured()) {
            return null;
        }
        try {
            return array_map(fn ($s) => (string) $s['id'], $this->client->servers());
        } catch (Throwable $e) {
            Log::warning('cloud_provider.cloudways.alive_ids_fetch_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Cloudways servers are ephemeral/deletable instances the same way
     * DigitalOcean droplets and Hetzner servers are (unlike Azure's stable
     * ARM resource-ID paths, which never get reassigned) — so this follows
     * the DO/Hetzner alive-ID-membership pattern, not Azure's always-false
     * one.
     */
    public function isDeletedAtProvider(Server $server, ?array $aliveIds): bool
    {
        $providerId = (string) $server->provider_id;
        if ($providerId === '') {
            return false;
        }

        return $aliveIds !== null && ! in_array($providerId, $aliveIds, true);
    }

    public function instancesByIp(): array
    {
        if (! $this->client->isConfigured()) {
            return [];
        }

        $byIp = [];
        foreach ($this->client->servers() as $s) {
            // Shape assumption: each server entry carries public_ip, and
            // size/vcpu/memory/disk fields named as below — modeled on
            // Cloudways' v1 "server list" response fields. Not confirmed
            // against a live v2 payload.
            $summary = [
                'id' => (string) ($s['id'] ?? ''),
                'size_slug' => $s['size'] ?? $s['instance_size'] ?? null,
                'vcpus' => isset($s['vcpus']) ? (int) $s['vcpus'] : null,
                'memory_mb' => isset($s['memory_mb']) ? (int) $s['memory_mb'] : null,
                'disk_gb' => isset($s['disk_gb']) ? (int) $s['disk_gb'] : null,
            ];

            $ip = $s['public_ip'] ?? null;
            if ($ip) {
                $byIp[$ip] = $summary;
            }
        }

        return $byIp;
    }
}
