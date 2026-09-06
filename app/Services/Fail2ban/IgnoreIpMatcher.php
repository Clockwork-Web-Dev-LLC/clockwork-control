<?php

namespace App\Services\Fail2ban;

/**
 * Decides whether an IP is on fail2ban's `ignoreip` whitelist — Cloudflare edge
 * ranges (v4 + v6), our own fleet's public IPs, and loopback. Used at ingest
 * to drop protected IPs before they reach the review queue, and inside
 * Fail2banClient as a last-line refusal so no code path can ever ban one.
 *
 * fail2ban itself silently no-ops bans against ignoreip entries, so the visitor
 * impact is zero either way — but writing a phantom "banned" row to our DB is
 * misleading and pollutes the dashboards. This class makes the local state
 * match reality.
 */
class IgnoreIpMatcher
{
    /** @var array{v4: string[], v6: string[], exact: string[]}|null */
    private ?array $loaded = null;

    public function __construct(private readonly IgnoreIpListBuilder $builder) {}

    public function matches(string $ip): bool
    {
        return $this->reason($ip) !== null;
    }

    /**
     * Human-readable reason a given IP is protected, or null if it isn't.
     * The string is suitable for surfacing in error messages and logs.
     */
    public function reason(string $ip): ?string
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $this->load();

        if (in_array($ip, $this->loaded['exact'], true)) {
            return 'fleet IP / loopback';
        }

        $isV4 = (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

        if ($isV4) {
            foreach ($this->loaded['v4'] as $cidr) {
                if ($this->ipv4InCidr($ip, $cidr)) {
                    return "matches Cloudflare/loopback v4 range {$cidr}";
                }
            }
        } else {
            foreach ($this->loaded['v6'] as $cidr) {
                if ($this->ipv6InCidr($ip, $cidr)) {
                    return "matches Cloudflare v6 range {$cidr}";
                }
            }
        }

        return null;
    }

    private function load(): void
    {
        if ($this->loaded !== null) {
            return;
        }

        $v4 = $v6 = $exact = [];
        foreach ($this->builder->build() as $entry) {
            if (str_contains($entry, '/')) {
                str_contains($entry, ':') ? $v6[] = $entry : $v4[] = $entry;
            } else {
                $exact[] = $entry;
            }
        }

        $this->loaded = ['v4' => $v4, 'v6' => $v6, 'exact' => $exact];
    }

    private function ipv4InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr, 2);
        $ipL = ip2long($ip);
        $subL = ip2long($subnet);
        if ($ipL === false || $subL === false) {
            return false;
        }
        $bits = (int) $mask;
        if ($bits <= 0) {
            return true;
        }
        $maskL = -1 << (32 - $bits);

        return ($ipL & $maskL) === ($subL & $maskL);
    }

    private function ipv6InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr, 2);
        $ipBin = @inet_pton($ip);
        $subBin = @inet_pton($subnet);
        if ($ipBin === false || $subBin === false || strlen($ipBin) !== 16 || strlen($subBin) !== 16) {
            return false;
        }

        $bits = (int) $mask;
        $bytes = intdiv($bits, 8);
        $remBits = $bits % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
            return false;
        }
        if ($remBits === 0) {
            return true;
        }
        $maskByte = chr((0xFF << (8 - $remBits)) & 0xFF);

        return (ord($ipBin[$bytes]) & ord($maskByte)) === (ord($subBin[$bytes]) & ord($maskByte));
    }
}
