<?php

namespace Modules\Hetzner;

use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Modules\Core\Contracts\CloudProvider;
use Throwable;

class HetznerCloudProvider implements CloudProvider
{
    public function __construct(
        private readonly HetznerClient $client,
        private readonly HetznerMetricsParser $parser,
    ) {}

    public function id(): string
    {
        return Server::PROVIDER_HETZNER;
    }

    public function label(): string
    {
        return 'Hetzner server';
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
        // Hetzner doesn't have a Font Awesome brand mark, so we reuse the
        // generic server icon in their brand red (see iconColor()).
        return 'fa-solid fa-server';
    }

    public function iconColor(): ?string
    {
        return '#D50C2D';
    }

    /**
     * Hetzner's naming:
     *   cx*  — Shared AMD (x86, older Intel-equivalent line)
     *   cpx* — Shared AMD (newer EPYC-based)
     *   ccx* — Dedicated vCPU (no noisy-neighbor)
     *   cax* — Shared ARM (Ampere)
     */
    public function sizeTier(?string $sizeSlug): ?string
    {
        if (! $sizeSlug) {
            return null;
        }

        return match (true) {
            str_starts_with($sizeSlug, 'ccx') => 'Dedicated vCPU',
            str_starts_with($sizeSlug, 'cpx') => 'Shared AMD (EPYC)',
            str_starts_with($sizeSlug, 'cax') => 'Shared ARM',
            str_starts_with($sizeSlug, 'cx') => 'Shared AMD',
            default => $sizeSlug,
        };
    }

    /**
     * Hetzner's metrics API only exposes CPU (as a ready-made percentage),
     * disk IO rate (not capacity %), and network throughput. There is
     * currently no equivalent of memory_available, filesystem_free, or
     * load_1.
     */
    public function metrics(Server $server, int $start, int $end): array
    {
        $providerId = (string) $server->provider_id;

        $cpuPayload = $this->client->serverCpuMetrics($providerId, $start, $end);
        $cpuPct = $this->parser->latestCpuPercent($cpuPayload);

        // Hetzner reports CPU as the sum across all vCPUs (like `top` in SMP
        // mode), so a 2-core server fully loaded shows ~200. Divide by core
        // count to get a 0-100 percentage comparable to the DO path.
        $vcpus = max(1, (int) ($server->vcpus ?? 1));
        if ($cpuPct !== null) {
            $cpuPct = min(100.0, max(0.0, $cpuPct / $vcpus));
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
            return array_map(fn ($s) => (string) $s['id'], $this->client->servers());
        } catch (Throwable $e) {
            Log::warning('cloud_provider.hetzner.alive_ids_fetch_failed', ['error' => $e->getMessage()]);

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
        foreach ($this->client->servers() as $s) {
            $type = $s['server_type'] ?? [];
            $memoryGb = isset($type['memory']) ? (float) $type['memory'] : null;
            $summary = [
                'id' => (string) $s['id'],
                'size_slug' => $type['name'] ?? null,
                'vcpus' => isset($type['cores']) ? (int) $type['cores'] : null,
                'memory_mb' => $memoryGb !== null ? (int) round($memoryGb * 1024) : null,
                'disk_gb' => isset($type['disk']) ? (int) $type['disk'] : null,
            ];
            $ip = $s['public_net']['ipv4']['ip'] ?? null;
            if ($ip) {
                $byIp[$ip] = $summary;
            }
        }

        return $byIp;
    }
}
