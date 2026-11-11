<?php

namespace App\Support;

use Illuminate\Container\Container;
use InvalidArgumentException;
use RuntimeException;

/**
 * Best-effort SSRF mitigation for outbound requests to a URL this app
 * doesn't control (a client site's own domain, or a third-party RDAP
 * referral) — resolves the host ourselves and rejects it if it points at a
 * private/loopback/link-local/reserved address (e.g. cloud metadata
 * endpoints), matching the FILTER_FLAG_NO_PRIV_RANGE/NO_RES_RANGE convention
 * already used elsewhere in this codebase (see LinodeCloudProvider).
 *
 * This resolves DNS before the HTTP client connects, so a DNS-rebinding
 * attack (host resolves to a public IP at check time, then to a private one
 * at connect time) isn't closed — no pure-PHP HTTP client can close that
 * without controlling the socket connect step directly. Restricting the
 * request's own redirect `protocols` option to https (done at each call
 * site) plus re-checking every redirect hop via onRedirect() below closes
 * the much more likely vector: a malicious or compromised redirect/referral
 * chain simply pointing somewhere internal.
 */
final class SsrfGuard
{
    /**
     * A normal, publicly-routable IP (Google public DNS) used as the
     * default resolution for any host not explicitly mapped while faking —
     * see fake() below.
     */
    private const DEFAULT_FAKE_IP = '8.8.8.8';

    /**
     * @var array<string, list<string>>|null
     */
    private static ?array $fakeResolutions = null;

    /**
     * Test-only: makes resolve() return canned IPs instead of performing a
     * real DNS lookup, so tests never depend on real network access or on a
     * given test domain actually being registered. Enabled by default for
     * every Feature test via Tests\TestCase::setUp(). Pass a host => IPs map
     * to exercise a specific rejection scenario (e.g. a host "resolving" to
     * a private IP) — any host not in the map resolves to a normal public
     * IP. A literal IP given directly as the URL host bypasses this
     * entirely (see assertPublic() below), so real-rejection tests can also
     * just use an IP literal without needing to fake anything.
     *
     * @param  array<string, list<string>>  $hostToIps
     */
    public static function fake(array $hostToIps = []): void
    {
        self::$fakeResolutions = $hostToIps;
    }

    private static ?bool $allowPrivateHosts = null;

    /**
     * Test-only / programmatic override to permit private/loopback hosts.
     */
    public static function allowPrivateHosts(?bool $allow = true): void
    {
        self::$allowPrivateHosts = $allow;
    }

    /**
     * Reverts to real DNS resolution. Only relevant for tests that called
     * fake() directly (e.g. SsrfGuardTest) — Feature tests never need this,
     * since faking is process-local per test run.
     */
    public static function stopFaking(): void
    {
        self::$fakeResolutions = null;
        self::$allowPrivateHosts = null;
    }

    /**
     * @throws RuntimeException if the host can't be resolved, or resolves to
     *                          a private/loopback/link-local/reserved address
     */
    public static function assertPublic(string $url): void
    {
        if (self::$allowPrivateHosts ?? self::isConfiguredToAllowPrivateHosts()) {
            return;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException("Refusing to fetch URL with unsupported scheme: {$url}");
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException("Refusing to fetch a URL with no host: {$url}");
        }

        $cleanHost = str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;

        $ips = filter_var($cleanHost, FILTER_VALIDATE_IP) !== false
            ? [$cleanHost]
            : self::resolve($cleanHost);

        if ($ips === []) {
            throw new RuntimeException("Could not resolve host: {$host}");
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new RuntimeException("Refusing to fetch a private/reserved address ({$ip}) for host {$host}.");
            }
        }
    }

    private static function isPublicIp(string $ip): bool
    {
        $flags = FILTER_FLAG_NO_PRIV_RANGE
            | FILTER_FLAG_NO_RES_RANGE
            | (defined('FILTER_FLAG_GLOBAL_RANGE') ? FILTER_FLAG_GLOBAL_RANGE : 0);

        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return false;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        // Explicit IPv4 CGNAT check (100.64.0.0/10) in case FILTER_FLAG_GLOBAL_RANGE is unavailable.
        if (strlen($packed) === 4) {
            $b0 = ord($packed[0]);
            $b1 = ord($packed[1]);
            if ($b0 === 100 && ($b1 & 0xC0) === 64) {
                return false;
            }

            return true;
        }

        // IPv6 transition / private / link-local / NAT64 / IPv4-mapped ranges.
        if (strlen($packed) === 16) {
            // IPv4-mapped IPv6 ::ffff:0:0/96
            if (substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
                $mappedV4 = inet_ntop(substr($packed, 12, 4));

                return is_string($mappedV4) && self::isPublicIp($mappedV4);
            }

            // NAT64 well-known prefix 64:ff9b::/96 and local-use 64:ff9b:1::/48
            if (substr($packed, 0, 12) === "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00"
                || substr($packed, 0, 6) === "\x00\x64\xff\x9b\x00\x01") {
                return false;
            }

            // 6to4 2002::/16, Teredo 2001:0000::/32, Benchmarking 2001:2::/48, Documentation 2001:db8::/32
            if (substr($packed, 0, 2) === "\x20\x02"
                || substr($packed, 0, 4) === "\x20\x01\x00\x00"
                || substr($packed, 0, 6) === "\x20\x01\x00\x02\x00\x00"
                || substr($packed, 0, 4) === "\x20\x01\x0d\xb8") {
                return false;
            }

            // Unique Local fc00::/7 and Link-Local fe80::/10
            $first = ord($packed[0]);
            $second = ord($packed[1]);
            if (($first & 0xFE) === 0xFC || ($first === 0xFE && ($second & 0xC0) === 0x80)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A Guzzle `allow_redirects.on_redirect` callback that re-validates each
     * redirect hop the same way, so a chain can't bounce through a public
     * host and land on a private one after the initial check passed.
     */
    public static function onRedirect(): \Closure
    {
        return static function ($request, $response, $uri): void {
            self::assertPublic((string) $uri);
        };
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        if (self::$fakeResolutions !== null) {
            return self::$fakeResolutions[$host] ?? [self::DEFAULT_FAKE_IP];
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        ))));
    }

    private static function isConfiguredToAllowPrivateHosts(): bool
    {
        if (function_exists('config') && Container::getInstance()?->has('config')) {
            return (bool) config('clockwork.security.allow_private_hosts', false);
        }

        return false;
    }
}
