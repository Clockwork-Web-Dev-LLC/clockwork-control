<?php

namespace Modules\DigitalOcean;

use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Modules\Core\Contracts\CloudProvider;
use Throwable;

class DigitalOceanCloudProvider implements CloudProvider
{
    public function __construct(
        private readonly DigitalOceanClient $client,
        private readonly DigitalOceanMetricsParser $parser,
    ) {}

    public function id(): string
    {
        return Server::PROVIDER_DIGITALOCEAN;
    }

    public function label(): string
    {
        return 'DigitalOcean droplet';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function instanceNoun(): string
    {
        return 'droplet';
    }

    public function iconClass(): string
    {
        return 'fa-brands fa-digital-ocean';
    }

    public function iconColor(): ?string
    {
        return '#0080FF';
    }

    public function sizeTier(?string $sizeSlug): ?string
    {
        if (! $sizeSlug) {
            return null;
        }

        return match (true) {
            str_starts_with($sizeSlug, 's-') => 'Basic',
            str_starts_with($sizeSlug, 'g-') => 'General Purpose',
            str_starts_with($sizeSlug, 'gd-') => 'General Purpose (storage-opt)',
            str_starts_with($sizeSlug, 'c-'), str_starts_with($sizeSlug, 'c2-') => 'CPU-Optimized',
            str_starts_with($sizeSlug, 'm-') => 'Memory-Optimized',
            str_starts_with($sizeSlug, 'm3-') => 'Memory-Optimized (NVMe)',
            str_starts_with($sizeSlug, 'so-') => 'Storage-Optimized',
            default => $sizeSlug,
        };
    }

    public function metrics(Server $server, int $start, int $end): array
    {
        $providerId = (string) $server->provider_id;

        $cpuData = $this->client->dropletCpuMetrics($providerId, $start, $end);
        $cpuPct = $this->parser->percentCpuUsed($cpuData);

        $load1 = null;
        $memoryPct = null;
        $diskPct = null;

        try {
            $load1 = $this->parser->latestSingleValue(
                $this->client->dropletLoad1Metrics($providerId, $start, $end)
            );
        } catch (Throwable) {
            // best-effort
        }
        try {
            // Use memory_available (not memory_free) — Linux's "free" excludes the page cache
            // and reclaimable buffers, so on any active server it looks ~5% even when there's
            // plenty of real headroom. memory_available is what `top` and `free -h` show as
            // "available" and matches operator intuition.
            $memAvail = $this->client->dropletMemoryAvailableMetrics($providerId, $start, $end);
            $memTotal = $this->client->dropletMemoryTotalMetrics($providerId, $start, $end);
            $memoryPct = $this->parser->percentUsed($memAvail, $memTotal);
        } catch (Throwable) {
            // best-effort
        }
        try {
            $diskFree = $this->client->dropletFilesystemFreeMetrics($providerId, $start, $end);
            $diskTotal = $this->client->dropletFilesystemSizeMetrics($providerId, $start, $end);
            $diskPct = $this->parser->percentUsed($diskFree, $diskTotal);
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
            return array_map(fn ($d) => (string) $d['id'], $this->client->droplets());
        } catch (Throwable $e) {
            Log::warning('cloud_provider.digitalocean.alive_ids_fetch_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

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
        foreach ($this->client->droplets() as $d) {
            $summary = [
                'id' => (string) $d['id'],
                'size_slug' => $d['size_slug'] ?? null,
                'vcpus' => isset($d['vcpus']) ? (int) $d['vcpus'] : null,
                'memory_mb' => isset($d['memory']) ? (int) $d['memory'] : null,
                'disk_gb' => isset($d['disk']) ? (int) $d['disk'] : null,
            ];
            foreach (($d['networks']['v4'] ?? []) as $iface) {
                if (($iface['type'] ?? null) === 'public' && ! empty($iface['ip_address'])) {
                    $byIp[$iface['ip_address']] = $summary;
                }
            }
        }

        return $byIp;
    }
}
