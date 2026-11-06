<?php

namespace Modules\BillCom;

use App\Models\Site;
use App\Support\DomainNormalizer;

/**
 * Lookup of Sites by normalized domain, built once per Bill.com sync.
 *
 * Invoice lines name a site however the person typing the invoice wrote it
 * ("http://www.example.com", "example.com"), while a Site row may be stored
 * as either "example.com" or "www.example.com". Both sides go through
 * DomainNormalizer so they compare equal.
 *
 * Two non-archived sites can normalize to the same key (an apex site and its
 * www twin both left live). find() then prefers the site whose stored domain
 * matches exactly, falls back to the lowest id, and records the key in
 * collisions() so the sync can report it instead of choosing silently.
 *
 * Archived sites are never returned by find() (they must not be linked or
 * flagged), but their keys are kept so a sync can tell "this domain belongs
 * to an archived site" apart from "this domain isn't in Clockwork at all".
 */
final class SiteDomainIndex
{
    /** @var array<string, list<Site>> normalized key => non-archived sites, lowest id first */
    private array $sitesByKey = [];

    /** @var array<string, true> normalized keys of archived sites */
    private array $archivedKeys = [];

    /** @var array<string, true> */
    private array $tlds = [];

    /** @var array<string, int> normalized key => number of non-archived sites sharing it */
    private array $collisions = [];

    /**
     * @param  iterable<Site>  $sites  non-archived sites
     * @param  iterable<string>  $archivedDomains  domains of archived sites
     */
    public function __construct(iterable $sites, iterable $archivedDomains = [])
    {
        foreach ($sites as $site) {
            $key = DomainNormalizer::normalize((string) $site->domain);
            if ($key === '') {
                continue;
            }
            $this->sitesByKey[$key][] = $site;

            $tld = DomainNormalizer::tld($key);
            if ($tld !== null) {
                $this->tlds[$tld] = true;
            }
        }

        foreach ($archivedDomains as $domain) {
            $key = DomainNormalizer::normalize((string) $domain);
            if ($key !== '') {
                $this->archivedKeys[$key] = true;
            }
        }
    }

    public static function build(): self
    {
        return new self(
            Site::query()->orderBy('id')->get(),
            Site::query()
                ->withoutGlobalScope('notArchived')
                ->whereNotNull('archived_at')
                ->pluck('domain')
                ->map(fn ($domain) => (string) $domain),
        );
    }

    /**
     * The non-archived site a domain refers to, or null when there is none.
     */
    public function find(string $domain): ?Site
    {
        $key = DomainNormalizer::normalize($domain);
        $candidates = $this->sitesByKey[$key] ?? [];

        if (count($candidates) <= 1) {
            return $candidates[0] ?? null;
        }

        $this->collisions[$key] = count($candidates);

        foreach (array_unique([strtolower(trim($domain)), $key]) as $wanted) {
            foreach ($candidates as $site) {
                if (strtolower(trim((string) $site->domain)) === $wanted) {
                    return $site;
                }
            }
        }

        return $candidates[0];
    }

    /**
     * True when no non-archived site has this domain but an archived one does.
     */
    public function belongsToArchivedSite(string $domain): bool
    {
        $key = DomainNormalizer::normalize($domain);

        return ! isset($this->sitesByKey[$key]) && isset($this->archivedKeys[$key]);
    }

    /**
     * Distinct TLDs of the non-archived sites, for InvoiceDomainExtractor's
     * `$extraTlds` argument.
     *
     * @return array<int, string>
     */
    public function tlds(): array
    {
        return array_keys($this->tlds);
    }

    /**
     * Keys find() had to choose between more than one non-archived site for,
     * with the number of sites sharing each key.
     *
     * @return array<string, int>
     */
    public function collisions(): array
    {
        return $this->collisions;
    }
}
