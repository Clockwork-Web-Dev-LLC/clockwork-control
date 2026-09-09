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

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException("Refusing to fetch a URL with no host: {$url}");
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);

        if ($ips === []) {
            throw new RuntimeException("Could not resolve host: {$host}");
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException("Refusing to fetch a private/reserved address ({$ip}) for host {$host}.");
            }
        }
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
