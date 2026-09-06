<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Policy denylist for Companion deployment. Some domains must never receive
 * the Companion mu-plugin regardless of care-plan or enrollment state — e.g.
 * government / compliance-restricted sites such as *.stateschools.example (a
 * state department of education — illustrative, genericized), where dropping
 * a custom mu-plugin isn't permitted.
 *
 * Backed by the `companion.excluded_domain_suffixes` setting so the list is
 * editable without a code change. A suffix matches the exact domain OR any
 * subdomain of it: 'stateschools.example' excludes stateschools.example, community.stateschools.example, and
 * www.careerpipeline.stateschools.example alike. Every deploy path (install-companion
 * --site / --all-enabled / --all-installed, and companion-fleet-deploy) runs
 * candidates through here first.
 */
class CompanionExclusion
{
    public function __construct(private readonly Settings $settings) {}

    /** @return array<int, string> */
    public function suffixes(): array
    {
        $raw = $this->settings->get('companion.excluded_domain_suffixes', []);
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($s) => strtolower(trim((string) $s)),
            $raw,
        )));
    }

    public function isExcluded(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        foreach ($this->suffixes() as $suffix) {
            if ($domain === $suffix || str_ends_with($domain, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split a site collection into [allowed, excluded] by the policy denylist.
     *
     * @param  Collection<int, Site>  $sites
     * @return array{0: Collection<int, Site>, 1: Collection<int, Site>}
     */
    public function partition(Collection $sites): array
    {
        $excluded = $sites->filter(fn (Site $s) => $this->isExcluded((string) $s->domain))->values();
        $allowed = $sites->reject(fn (Site $s) => $this->isExcluded((string) $s->domain))->values();

        return [$allowed, $excluded];
    }
}
