<?php

namespace App\Services\Cloudflare;

use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareDetector
{
    private const CF_IPV4_URL = 'https://www.cloudflare.com/ips-v4';

    private const CF_IPV6_URL = 'https://www.cloudflare.com/ips-v6';

    /**
     * Hard-coded snapshot of Cloudflare's IPv6 ranges, used as a fallback if the
     * fetch from cloudflare.com fails. Same fallback strategy as v4 above.
     */
    private const FALLBACK_RANGES_V6 = [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * Hard-coded snapshot of Cloudflare's IPv4 ranges, used as a fallback if the
     * fetch from cloudflare.com fails. Refreshed by the live fetch when possible.
     */
    private const FALLBACK_RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];

    /**
     * Detect the Cloudflare state for a site's domain.
     *
     * @return array{state: string, a_record: ?string, ns_record: ?string}
     */
    public function detect(string $domain): array
    {
        $aRecords = $this->lookupA($domain);
        $nsRecords = $this->lookupNs($domain);

        $proxied = false;
        foreach ($aRecords as $ip) {
            if ($this->isCloudflareIp($ip)) {
                $proxied = true;
                break;
            }
        }

        $cfNs = false;
        foreach ($nsRecords as $ns) {
            if (str_ends_with(strtolower($ns), 'ns.cloudflare.com')) {
                $cfNs = true;
                break;
            }
        }

        $state = match (true) {
            $proxied => Site::CF_PROXIED,
            $cfNs => Site::CF_DNS_ONLY,
            $aRecords !== [] => Site::CF_NOT_USING,
            default => Site::CF_UNKNOWN,
        };

        return [
            'state' => $state,
            'a_record' => $aRecords[0] ?? null,
            'ns_record' => $nsRecords[0] ?? null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function lookupA(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_A);
        if (! is_array($records)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? null, $records)));
    }

    /**
     * @return array<int, string>
     */
    private function lookupNs(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_NS);
        if (! is_array($records)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($r) => $r['target'] ?? null, $records)));
    }

    /**
     * Public so Phase 1 (CF-ban sweep) and Phase 2 (fail2ban ignoreip)
     * can reuse this same matcher — single source of truth for "is this CF?".
     */
    public function isCloudflareIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        foreach ($this->ranges() as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Public so the IgnoreIpListBuilder can read the live CF range list.
     *
     * @return array<int, string>
     */
    public function ranges(): array
    {
        return Cache::remember('clockwork.cf_ipv4_ranges', now()->addDay(), function () {
            try {
                $response = Http::timeout(5)->get(self::CF_IPV4_URL);
                if ($response->successful()) {
                    $lines = preg_split('/\r?\n/', trim((string) $response->body())) ?: [];
                    $clean = array_values(array_filter(array_map('trim', $lines)));
                    if ($clean !== []) {
                        return $clean;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Could not fetch Cloudflare IPv4 ranges, using fallback list.', [
                    'error' => $e->getMessage(),
                ]);
            }

            return self::FALLBACK_RANGES;
        });
    }

    /**
     * Public IPv6 range list, parallel to ranges(). Phase 2's fail2ban ignoreip
     * needs both v4 and v6 — fail2ban supports either family in the same directive.
     *
     * @return array<int, string>
     */
    public function rangesV6(): array
    {
        return Cache::remember('clockwork.cf_ipv6_ranges', now()->addDay(), function () {
            try {
                $response = Http::timeout(5)->get(self::CF_IPV6_URL);
                if ($response->successful()) {
                    $lines = preg_split('/\r?\n/', trim((string) $response->body())) ?: [];
                    $clean = array_values(array_filter(array_map('trim', $lines)));
                    if ($clean !== []) {
                        return $clean;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Could not fetch Cloudflare IPv6 ranges, using fallback list.', [
                    'error' => $e->getMessage(),
                ]);
            }

            return self::FALLBACK_RANGES_V6;
        });
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
