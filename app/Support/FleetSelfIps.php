<?php

namespace App\Support;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

/**
 * Collect every IPv4 stored on a fleet server's `hostname` column. WordPress's
 * core loopback (Site Health scraper, wp-cron, plugin updaters phoning admin-ajax)
 * resolves the site's public hostname to the public IP and egresses out the NIC,
 * so nginx logs the server's own IP as the client. Excluding these from
 * top-IP aggregations stops self-loopback from masquerading as suspicious traffic.
 *
 * IPv6 hostnames are skipped — `threat_logs.ip` records are IPv4 in practice.
 */
class FleetSelfIps
{
    private const CACHE_KEY = 'fleet_self_ips_v1';

    private const CACHE_TTL_SECONDS = 60;

    /**
     * @return list<string>
     */
    public function list(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            return Server::query()
                ->whereNotNull('hostname')
                ->pluck('hostname')
                ->filter(fn ($h) => filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
                ->unique()
                ->values()
                ->all();
        });
    }
}
