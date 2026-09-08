<?php

namespace App\Services\Sites;

use App\Models\Site;
use Illuminate\Support\Collection;
use Modules\SpinupWp\SpinupWpClient;

/**
 * Finds local Site rows that have lost their SpinupWP linkage and classifies
 * them as either:
 *   - "consolidated" — the domain is now an additional_domain on another live
 *                      SpinupWP site (likely a redirect / alias), so the
 *                      orphan can be safely archived.
 *   - "unknown"     — SpinupWP doesn't know about this domain at all anymore.
 *                     Could be: hand-rolled site, customer-managed, or actually
 *                     gone. Needs human review.
 *
 * Why an orphan can exist: `clockwork:import-spinupwp` is additive-only by
 * design (avoids surprise data loss). When a SpinupWP site is deleted or its
 * primary domain is reassigned, the local Site row keeps existing with
 * spinupwp_id nulled out.
 *
 * The classifier writes its result to:
 *   - sites.consolidated_into_site_id (set when the domain matched another
 *     live site's additional_domains)
 *
 * Reads are cheap because we hit SpinupWP's /sites once and build an in-memory
 * domain → parent map for the duration of the find() call.
 */
class OrphanSiteFinder
{
    public function __construct(
        private readonly SpinupWpClient $spinup,
    ) {}

    /**
     * @return Collection<int, array{
     *     site: Site,
     *     classification: string,
     *     parent: ?Site,
     *     matched_domain: ?string,
     * }>
     */
    public function find(): Collection
    {
        $orphans = $this->orphanSites();
        if ($orphans->isEmpty()) {
            return collect();
        }

        // Sites hosted on GridPane, Cloudways, or any other provider are
        // naturally never known to SpinupWP's /sites endpoint — calling it
        // here would either crash (token unconfigured) or waste a request
        // whose result can never match a non-SpinupWP domain. An empty map
        // makes every orphan fall through to 'unknown' below, same as if
        // SpinupWP genuinely had no record of any of these domains.
        $domainMap = $this->spinup->isConfigured() ? $this->buildDomainToParentMap() : [];

        return $orphans->values()->map(function (Site $orphan) use ($domainMap) {
            $domains = $this->candidateDomains($orphan);
            foreach ($domains as $d) {
                if (isset($domainMap[$d])) {
                    $parent = Site::query()
                        ->where('spinupwp_id', $domainMap[$d])
                        ->first();
                    if ($parent !== null) {
                        return [
                            'site' => $orphan,
                            'classification' => 'consolidated',
                            'parent' => $parent,
                            'matched_domain' => $d,
                        ];
                    }
                }
            }

            return [
                'site' => $orphan,
                'classification' => 'unknown',
                'parent' => null,
                'matched_domain' => null,
            ];
        });
    }

    /**
     * Persist classification results to the sites table. Returns how many rows
     * were updated. Idempotent — only writes when the value actually changes.
     *
     * @param  Collection<int, array{site: Site, classification: string, parent: ?Site, matched_domain: ?string}>  $results
     */
    public function persist(Collection $results): int
    {
        $changed = 0;
        foreach ($results as $r) {
            $site = $r['site'];
            $newParentId = $r['parent']?->id;
            if ($site->consolidated_into_site_id === $newParentId) {
                continue;
            }
            $site->forceFill(['consolidated_into_site_id' => $newParentId])->save();
            $changed++;
        }

        return $changed;
    }

    /**
     * Convenience: find + archive every confirmed-consolidated orphan in one call.
     * Returns how many were archived.
     */
    public function autoArchiveConsolidated(Collection $results): int
    {
        $count = 0;
        foreach ($results as $r) {
            if ($r['classification'] !== 'consolidated' || $r['site']->archived_at !== null) {
                continue;
            }
            $r['site']->forceFill(['archived_at' => now()])->save();
            $count++;
        }

        return $count;
    }

    private function orphanSites(): Collection
    {
        // notArchived global scope is already on the model. We override it here
        // by withTrashed-equivalent if that scope had soft-delete semantics —
        // but it's a where-null scope, so just query directly without it.
        //
        // Scoped strictly to SpinupWP sites — a GridPane, Cloudways, or
        // custom-VPS site naturally has spinupwp_id = null forever; that's
        // not a lost linkage, it's just a different provider.
        return Site::query()
            ->withoutGlobalScopes()
            ->where('hosting_provider', Site::HOSTING_PROVIDER_SPINUPWP)
            ->whereNull('spinupwp_id')
            ->whereNull('archived_at')
            ->whereHas('server', fn ($q) => $q->monitored())
            ->orderBy('domain')
            ->get();
    }

    /**
     * @return array<string, int> domain (lowercased) → parent spinupwp_id
     */
    private function buildDomainToParentMap(): array
    {
        $sites = $this->spinup->sites();
        $map = [];
        foreach ($sites as $site) {
            $parentId = (int) ($site['id'] ?? 0);
            if ($parentId === 0) {
                continue;
            }
            // The primary domain is its OWN site, but we still record it so an
            // orphan whose domain matches a current primary maps to the live row.
            $primary = strtolower((string) ($site['domain'] ?? ''));
            if ($primary !== '') {
                $map[$primary] = $parentId;
            }
            foreach (($site['additional_domains'] ?? []) as $ad) {
                $d = strtolower((string) ($ad['domain'] ?? ''));
                if ($d !== '') {
                    $map[$d] = $parentId;
                }
            }
        }

        return $map;
    }

    /**
     * Domains an orphan should be matched against. Includes the bare domain
     * AND a www-stripped variant (because some SpinupWP additional_domain
     * entries record both bare + www).
     *
     * @return array<int, string>
     */
    private function candidateDomains(Site $site): array
    {
        $primary = strtolower((string) $site->domain);
        $candidates = [$primary];
        if (str_starts_with($primary, 'www.')) {
            $candidates[] = substr($primary, 4);
        } else {
            $candidates[] = 'www.'.$primary;
        }

        return array_values(array_unique($candidates));
    }
}
