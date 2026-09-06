<?php

namespace App\Services\Domains;

use App\Support\SsrfGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RdapClient
{
    private const BAD_STATUSES = [
        'redemptionPeriod',
        'redemption period',
        'pendingDelete',
        'pending delete',
    ];

    public function __construct(
        private readonly int $timeout = 10,
        private readonly int $tldBackoffHours = 2,
    ) {}

    public static function make(): self
    {
        return new self(
            timeout: (int) config('clockwork.rdap.timeout', 10),
            tldBackoffHours: (int) config('clockwork.rdap.tld_backoff_hours', 2),
        );
    }

    /**
     * Query RDAP for domain registration and expiration information.
     * Never throws exceptions — returns null on network/parsing/validation errors.
     */
    public function lookup(string $domain): ?RdapDomainResult
    {
        $rootDomain = RootDomainResolver::resolve($domain);
        if ($rootDomain === '') {
            return null;
        }

        $suffix = RootDomainResolver::getSuffix($rootDomain) ?? '';
        if ($suffix !== '' && $this->isTldCoolingDown($suffix)) {
            Log::info("Skipping RDAP lookup for {$rootDomain}: TLD .{$suffix} is in backoff cooldown.");

            return null;
        }

        try {
            $url = 'https://rdap.org/domain/'.rawurlencode($rootDomain);
            SsrfGuard::assertPublic($url);
            $response = Http::timeout($this->timeout)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => false,
                        'protocols' => ['https'],
                        'on_redirect' => SsrfGuard::onRedirect(),
                    ],
                ])
                ->withHeaders([
                    'Accept' => 'application/rdap+json, application/json',
                    'User-Agent' => 'Clockwork-Control/1.0 (Domain Expiration Monitor)',
                ])
                ->get($url);

            if ($response->status() === 429 || $response->status() === 503) {
                if ($suffix !== '') {
                    $this->triggerTldBackoff($suffix);
                }

                return new RdapDomainResult(
                    expiresAt: null,
                    registrar: null,
                    status: null,
                    rawError: "HTTP {$response->status()} (rate limited or unavailable)",
                );
            }

            if ($response->status() === 404) {
                return new RdapDomainResult(
                    expiresAt: null,
                    registrar: null,
                    status: null,
                    rawError: 'HTTP 404 (domain not found in registry)',
                );
            }

            if (! $response->successful()) {
                return new RdapDomainResult(
                    expiresAt: null,
                    registrar: null,
                    status: null,
                    rawError: "HTTP {$response->status()}",
                );
            }

            $json = $response->json();
            if (! is_array($json)) {
                return new RdapDomainResult(
                    expiresAt: null,
                    registrar: null,
                    status: null,
                    rawError: 'Malformed JSON payload',
                );
            }

            $expiresAt = $this->parseExpirationDate($json);
            $registrar = $this->parseRegistrar($json);
            $status = $this->parseStatus($json);

            return new RdapDomainResult(
                expiresAt: $expiresAt,
                registrar: $registrar,
                status: $status,
                rawError: null,
            );
        } catch (Throwable $e) {
            Log::warning("RDAP lookup failed for {$rootDomain}: {$e->getMessage()}");

            return new RdapDomainResult(
                expiresAt: null,
                registrar: null,
                status: null,
                rawError: mb_strimwidth($e->getMessage(), 0, 250, '…'),
            );
        }
    }

    public function parseExpirationDate(array $rdapJson): ?CarbonImmutable
    {
        $events = $rdapJson['events'] ?? [];
        if (! is_array($events)) {
            return null;
        }

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $action = strtolower((string) ($event['eventAction'] ?? ''));
            if ($action === 'expiration' || str_contains($action, 'expiration')) {
                $dateStr = (string) ($event['eventDate'] ?? '');
                if ($dateStr !== '') {
                    try {
                        return CarbonImmutable::parse($dateStr);
                    } catch (Throwable) {
                        return null;
                    }
                }
            }
        }

        return null;
    }

    public function parseRegistrar(array $rdapJson): ?string
    {
        $entities = $rdapJson['entities'] ?? [];
        if (! is_array($entities)) {
            return null;
        }

        foreach ($entities as $entity) {
            if (! is_array($entity)) {
                continue;
            }

            $roles = array_map('strtolower', (array) ($entity['roles'] ?? []));
            if (in_array('registrar', $roles, true)) {
                // Check vcardArray for formatted name 'fn'
                $vcard = $entity['vcardArray'] ?? [];
                if (is_array($vcard) && isset($vcard[1]) && is_array($vcard[1])) {
                    foreach ($vcard[1] as $prop) {
                        if (is_array($prop) && isset($prop[0], $prop[3]) && strtolower((string) $prop[0]) === 'fn') {
                            $name = trim((string) $prop[3]);
                            if ($name !== '') {
                                return $name;
                            }
                        }
                    }
                }

                // Fallback to legalName or handle
                if (! empty($entity['legalName'])) {
                    return trim((string) $entity['legalName']);
                }
                if (! empty($entity['handle'])) {
                    return trim((string) $entity['handle']);
                }
            }
        }

        return null;
    }

    public function parseStatus(array $rdapJson): ?string
    {
        $statuses = $rdapJson['status'] ?? [];
        if (! is_array($statuses) || $statuses === []) {
            return null;
        }

        // Prioritize known dangerous or critical statuses
        foreach ($statuses as $status) {
            $s = (string) $status;
            foreach (self::BAD_STATUSES as $bad) {
                if (strcasecmp($s, $bad) === 0) {
                    return $s;
                }
            }
        }

        return (string) $statuses[0];
    }

    public function isTldCoolingDown(string $suffix): bool
    {
        return (bool) Cache::get("rdap.tld_backoff.{$suffix}", false);
    }

    public function triggerTldBackoff(string $suffix): void
    {
        Cache::put(
            "rdap.tld_backoff.{$suffix}",
            true,
            now()->addHours($this->tldBackoffHours)
        );
    }
}
