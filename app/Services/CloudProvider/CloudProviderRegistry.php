<?php

namespace App\Services\CloudProvider;

use App\Models\Server;
use Modules\Core\Contracts\CloudProvider;
use Modules\Core\ModuleRegistry;
use Modules\Core\NullCloudProvider;

class CloudProviderRegistry
{
    /**
     * ReconcileProvider's historical IP-match precedence: Hetzner, then
     * Azure, then DigitalOcean. Sorted explicitly here rather than relying
     * on module-provider registration order in bootstrap/providers.php —
     * that order is about provider boot sequencing, not IP-match priority,
     * and shouldn't silently double as this.
     */
    private const PRECEDENCE = [
        Server::PROVIDER_HETZNER,
        Server::PROVIDER_AZURE,
        Server::PROVIDER_VULTR,
        Server::PROVIDER_LINODE,
        Server::PROVIDER_DIGITALOCEAN,
    ];

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly NullCloudProvider $null,
    ) {}

    /**
     * Resolve a server's `provider` string to its adapter. No registered
     * module claiming the string returns NullCloudProvider — as of Phase 4
     * this replaces the old silent "unrecognized falls through to
     * DigitalOcean" default (see UnregisteredCloudProviderCheck, which
     * surfaces this on the diagnostics page instead).
     */
    public function resolve(?string $providerId): CloudProvider
    {
        foreach ($this->modules->cloudProviders() as $provider) {
            if ($provider->id() === $providerId) {
                return $provider;
            }
        }

        return $this->null;
    }

    /**
     * @return list<CloudProvider>
     */
    public function all(): array
    {
        $providers = $this->modules->cloudProviders();

        usort($providers, function (CloudProvider $a, CloudProvider $b) {
            $ai = array_search($a->id(), self::PRECEDENCE, true);
            $bi = array_search($b->id(), self::PRECEDENCE, true);

            return ($ai === false ? 99 : $ai) <=> ($bi === false ? 99 : $bi);
        });

        return $providers;
    }
}
