<?php

namespace Modules\Linode;

use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Modules\Core\Contracts\CloudProvider;
use Throwable;

class LinodeCloudProvider implements CloudProvider
{
    public function __construct(
        private readonly LinodeClient $client,
        private readonly LinodeMetricsParser $parser,
    ) {}

    public function id(): string
    {
        return Server::PROVIDER_LINODE;
    }

    public function label(): string
    {
        return 'Linode instance';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function instanceNoun(): string
    {
        return 'linode';
    }

    public function iconClass(): string
    {
        return 'fa-brands fa-linode';
    }

    public function iconColor(): ?string
    {
        return '#02B159';
    }

    /**
     * Linode's plan naming:
     *   g6-standard-*  — Shared CPU
     *   g6-dedicated-* — Dedicated CPU
     *   g6-nanode-*    — Nanode (Entry level)
     *   g6-highmem-*   — High Memory
     *   g6-gpu-*       — Dedicated GPU
     */
    public function sizeTier(?string $sizeSlug): ?string
    {
        if (! $sizeSlug) {
            return null;
        }

        return match (true) {
            str_contains($sizeSlug, 'dedicated') => 'Dedicated CPU',
            str_contains($sizeSlug, 'highmem') => 'High Memory',
            str_contains($sizeSlug, 'nanode') => 'Nanode',
            str_contains($sizeSlug, 'gpu') => 'Dedicated GPU',
            str_contains($sizeSlug, 'standard') => 'Shared CPU',
            default => $sizeSlug,
        };
    }

    public function metrics(Server $server, int $start, int $end): array
    {
        $providerId = (string) $server->provider_id;
        if ($providerId === '') {
            return [
                'cpu_pct' => null,
                'memory_pct' => null,
                'disk_pct' => null,
                'load_1' => null,
            ];
        }

        $cpuPct = null;
        try {
            $stats = $this->client->instanceStats($providerId);
            $cpuPct = $this->parser->latestCpuPercent($stats);

            // Linode reports CPU summed across all vCPUs (e.g. ~150% on a
            // 2-vCPU box under heavy load on one core), not normalized like
            // DO/Hetzner. Divide by vcpus to get a comparable 0-100 figure.
            $vcpus = max(1, (int) ($server->vcpus ?? 1));
            if ($cpuPct !== null) {
                $cpuPct = min(100.0, max(0.0, $cpuPct / $vcpus));
            }
        } catch (Throwable) {
            // best-effort
        }

        return [
            'cpu_pct' => $cpuPct,
            'memory_pct' => null,
            'disk_pct' => null,
            'load_1' => null,
        ];
    }

    public function aliveProviderIds(): ?array
    {
        if (! $this->client->isConfigured()) {
            return null;
        }

        try {
            return array_map(fn ($i) => (string) $i['id'], $this->client->instances());
        } catch (Throwable $e) {
            Log::warning('cloud_provider.linode.alive_ids_fetch_failed', ['error' => $e->getMessage()]);

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
        foreach ($this->client->instances() as $inst) {
            $specs = $inst['specs'] ?? [];
            $diskMb = isset($specs['disk']) ? (int) $specs['disk'] : null;
            $summary = [
                'id' => (string) $inst['id'],
                'size_slug' => $inst['type'] ?? null,
                'vcpus' => isset($specs['vcpus']) ? (int) $specs['vcpus'] : null,
                'memory_mb' => isset($specs['memory']) ? (int) $specs['memory'] : null,
                'disk_gb' => $diskMb !== null ? (int) round($diskMb / 1024) : null,
            ];

            foreach (($inst['ipv4'] ?? []) as $ip) {
                // Ignore private IP space if public is available
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $byIp[$ip] = $summary;
                } elseif (empty($byIp[$ip])) {
                    $byIp[$ip] = $summary;
                }
            }
        }

        return $byIp;
    }
}
