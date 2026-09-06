<?php

namespace App\Services\Fail2ban;

use App\Models\Server;
use App\Services\Cloudflare\CloudflareDetector;

/**
 * Produces the value of fail2ban's `ignoreip` directive — IPs/CIDRs that
 * the daemon must never ban regardless of how many lockouts they trigger.
 *
 * Two sources, both load-bearing:
 *
 * 1. Cloudflare's published edge IP ranges. Without this, CF-proxied sites
 *    end up with their CF edge IPs banned (LLAR sees REMOTE_ADDR which is
 *    the CF edge, not the visitor) and the site flaps with 521s.
 * 2. Every server in our own fleet's public IP. Without this, one of our
 *    servers calling another (wp-cron callback, internal API, etc.) can
 *    rack up lockouts and ban itself out of the fleet.
 *
 * NOTE: we do NOT whitelist all of DigitalOcean. Attackers rent DO droplets
 * too, so a blanket DO whitelist would be dangerous. Only IPs we own.
 *
 * Always includes the standard loopback entries (127.0.0.1/8 + ::1/128)
 * which fail2ban normally has by default but we set explicitly so the
 * directive is self-contained.
 */
class IgnoreIpListBuilder
{
    public function __construct(private readonly CloudflareDetector $cf) {}

    /**
     * Build the full whitelist as a flat array of CIDRs and IPs.
     *
     * @return array<int, string>
     */
    public function build(): array
    {
        $entries = ['127.0.0.1/8', '::1'];

        foreach ($this->cf->ranges() as $cidr) {
            $entries[] = $cidr;
        }
        foreach ($this->cf->rangesV6() as $cidr) {
            $entries[] = $cidr;
        }

        // Every non-ignored server's public IPv4. We use hostname (which is the
        // public IP for SpinupWP-managed boxes per architecture decision #1).
        // Filter to valid IPs only — sometimes hostname is a DNS name, not an IP.
        $serverIps = Server::query()
            ->monitored()
            ->pluck('hostname')
            ->filter(fn ($h) => $h && filter_var($h, FILTER_VALIDATE_IP))
            ->unique()
            ->values()
            ->all();

        foreach ($serverIps as $ip) {
            $entries[] = $ip;
        }

        return $entries;
    }

    /**
     * Render as the single space-separated string that goes into the
     * `ignoreip = ...` line of the jail config.
     */
    public function render(): string
    {
        return implode(' ', $this->build());
    }
}
