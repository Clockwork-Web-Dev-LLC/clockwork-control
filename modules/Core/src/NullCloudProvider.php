<?php

namespace Modules\Core;

use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Modules\Core\Contracts\CloudProvider;

/**
 * Returned by CloudProviderRegistry::resolve() for a servers.provider value
 * no registered module claims. Every call site (PollServers,
 * ReconcileProvider, blade views, ServersController) expects a usable
 * CloudProvider back, not a nullable one to guard against everywhere — this
 * keeps that contract true for unrecognized/legacy provider strings without
 * silently misrouting them to whichever provider happens to be first.
 *
 * Before Phase 4, an unrecognized provider silently defaulted to the
 * DigitalOcean adapter. That default is now gone — replaced by this null
 * object plus UnregisteredCloudProviderCheck, which surfaces the gap on the
 * diagnostics page instead of masking it.
 */
class NullCloudProvider implements CloudProvider
{
    public function id(): string
    {
        return 'unknown';
    }

    public function label(): string
    {
        return 'Unrecognized provider';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function instanceNoun(): string
    {
        return 'instance';
    }

    public function iconClass(): string
    {
        return 'fa-solid fa-circle-question';
    }

    public function iconColor(): ?string
    {
        return null;
    }

    public function sizeTier(?string $sizeSlug): ?string
    {
        return $sizeSlug;
    }

    public function metrics(Server $server, int $start, int $end): array
    {
        Log::warning('cloud_provider.null.metrics_requested', [
            'server_id' => $server->id,
            'provider' => $server->provider,
        ]);

        return ['cpu_pct' => null, 'memory_pct' => null, 'disk_pct' => null, 'load_1' => null];
    }

    public function aliveProviderIds(): ?array
    {
        return null;
    }

    public function isDeletedAtProvider(Server $server, ?array $aliveIds): bool
    {
        return false;
    }

    public function instancesByIp(): array
    {
        return [];
    }
}
