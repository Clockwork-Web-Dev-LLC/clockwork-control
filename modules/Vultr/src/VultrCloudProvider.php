<?php

namespace Modules\Vultr;

use App\Models\Server;
use App\Services\Ssh\SshClient;
use Illuminate\Support\Facades\Log;
use Modules\Core\Contracts\CloudProvider;
use Throwable;

class VultrCloudProvider implements CloudProvider
{
    public function __construct(
        private readonly VultrClient $client,
        private readonly ?SshClient $ssh = null,
    ) {}

    public function id(): string
    {
        return Server::PROVIDER_VULTR;
    }

    public function label(): string
    {
        return 'Vultr instance';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function instanceNoun(): string
    {
        return 'instance';
    }

    public function iconClass(): string
    {
        // No Font Awesome brand mark exists for Vultr — reuse generic server icon
        // with Vultr's brand blue accent.
        return 'fa-solid fa-server';
    }

    public function iconColor(): ?string
    {
        return '#007BFC';
    }

    /**
     * Vultr's plan naming:
     *   vc2-*  — Regular Cloud Compute (Shared AMD/Intel)
     *   vhf-*  — High Frequency Compute (3GHz+ NVMe)
     *   vhp-*  — High Performance (AMD EPYC or Intel Xeon)
     *   voc-*  — Optimized Cloud Compute (Dedicated vCPU)
     *   vdc-*  — Dedicated Cloud
     *   vbm-*  — Bare Metal
     */
    public function sizeTier(?string $sizeSlug): ?string
    {
        if (! $sizeSlug) {
            return null;
        }

        return match (true) {
            str_starts_with($sizeSlug, 'vhf-') => 'High Frequency',
            str_starts_with($sizeSlug, 'vhp-') => 'High Performance',
            str_starts_with($sizeSlug, 'voc-') => 'Optimized Cloud (Dedicated)',
            str_starts_with($sizeSlug, 'vdc-') => 'Dedicated Cloud',
            str_starts_with($sizeSlug, 'vbm-') => 'Bare Metal',
            str_starts_with($sizeSlug, 'vc2-') => 'Cloud Compute',
            default => $sizeSlug,
        };
    }

    /**
     * Vultr does not expose real-time CPU/memory time series via its REST API v2.
     * SSH metrics collector provides live CPU/memory/disk/load stats for Vultr instances.
     */
    public function metrics(Server $server, int $start, int $end): array
    {
        if ($this->ssh && $server->hostname !== '' && $server->ssh_user !== '') {
            try {
                return $this->metricsViaSsh($server);
            } catch (Throwable $e) {
                Log::debug('vultr.ssh_metrics_failed', ['server' => $server->name, 'error' => $e->getMessage()]);
            }
        }

        return [
            'cpu_pct' => null,
            'memory_pct' => null,
            'disk_pct' => null,
            'load_1' => null,
        ];
    }

    /**
     * Fast single-shot SSH probe for CPU, memory, disk, and load_1.
     *
     * @return array{cpu_pct: ?float, memory_pct: ?float, disk_pct: ?float, load_1: ?float}
     */
    protected function metricsViaSsh(Server $server): array
    {
        $script = "vmstat 1 2 | tail -1 | awk '{print 100 - $15}'; free -m | awk '/Mem:/ {printf \"%.1f\", ($2-$7)/$2 * 100}'; echo; df -P / | awk 'NR==2 {print substr($5, 1, length($5)-1)}'; cat /proc/loadavg | awk '{print $1}'";
        $output = $this->ssh->exec($server, $script, 5);

        $lines = array_map('trim', explode("\n", trim($output)));

        return [
            'cpu_pct' => isset($lines[0]) && is_numeric($lines[0]) ? (float) $lines[0] : null,
            'memory_pct' => isset($lines[1]) && is_numeric($lines[1]) ? (float) $lines[1] : null,
            'disk_pct' => isset($lines[2]) && is_numeric($lines[2]) ? (float) $lines[2] : null,
            'load_1' => isset($lines[3]) && is_numeric($lines[3]) ? (float) $lines[3] : null,
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
            Log::warning('cloud_provider.vultr.alive_ids_fetch_failed', ['error' => $e->getMessage()]);

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
            $vcpus = $inst['vcpus'] ?? $inst['vcpu_count'] ?? null;
            $summary = [
                'id' => (string) $inst['id'],
                'size_slug' => $inst['plan'] ?? null,
                'vcpus' => $vcpus !== null ? (int) $vcpus : null,
                'memory_mb' => isset($inst['ram']) ? (int) $inst['ram'] : null,
                'disk_gb' => isset($inst['disk']) ? (int) $inst['disk'] : null,
            ];

            if (! empty($inst['main_ip'])) {
                $byIp[$inst['main_ip']] = $summary;
            }

            // Also map any secondary public v4 IPs if present
            foreach (($inst['v4'] ?? []) as $net) {
                $ip = $net['ip'] ?? null;
                if ($ip && ! empty($net['main_ip']) && empty($byIp[$ip])) {
                    $byIp[$ip] = $summary;
                }
            }
        }

        return $byIp;
    }
}
