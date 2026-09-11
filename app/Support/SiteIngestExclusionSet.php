<?php

namespace App\Support;

use App\Models\SiteIngestExclusion;
use Illuminate\Support\Collection;

/**
 * In-memory lookup for one import run: skip if the domain was dropped, or
 * if this provider's host site id was dropped (covers a domain rename).
 */
final class SiteIngestExclusionSet
{
    /**
     * @param  array<string, true>  $domains
     * @param  array<string, array<string, true>>  $idsByProvider
     */
    private function __construct(
        private readonly array $domains,
        private readonly array $idsByProvider,
    ) {}

    /**
     * @param  Collection<int, SiteIngestExclusion>  $exclusions
     */
    public static function fromExclusions(Collection $exclusions): self
    {
        $domains = [];
        $idsByProvider = [];

        foreach ($exclusions as $row) {
            $domain = strtolower(trim((string) $row->domain));
            if ($domain !== '') {
                $domains[$domain] = true;
            }

            $id = $row->provider_site_id !== null ? trim((string) $row->provider_site_id) : '';
            if ($id !== '') {
                $idsByProvider[(string) $row->hosting_provider][$id] = true;
            }
        }

        return new self($domains, $idsByProvider);
    }

    public function blocks(string $provider, int|string|null $providerSiteId, string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain !== '' && isset($this->domains[$domain])) {
            return true;
        }

        $id = $providerSiteId === null || $providerSiteId === '' ? '' : (string) $providerSiteId;

        return $id !== '' && isset($this->idsByProvider[$provider][$id]);
    }
}
