<?php

namespace App\Services\Security;

use App\Models\Site;
use App\Models\SiteCoreChecksumAllowlist;
use App\Models\SiteSecurityScan;
use Illuminate\Support\Collection;

/**
 * Applies a site's allowlist to a core-checksums scan result. Single source
 * of truth for "which findings still count as issues?" — used both on the
 * per-site security tab and in the fleet-wide /issues counter so the two
 * stay in agreement.
 *
 * Filtering is bucket-aware: an entry with bucket='*' suppresses the path
 * regardless of which list it appears in; bucket='modified' only suppresses
 * 'modified' findings for that path. See SiteCoreChecksumAllowlist for the
 * bucket constants.
 */
class CoreChecksumAllowlist
{
    /**
     * Filter the scan's three buckets against the site's allowlist.
     *
     * @return array{
     *   modified: list<string>,
     *   missing: list<string>,
     *   should_not_exist: list<string>,
     *   allowlisted: array{modified: list<string>, missing: list<string>, should_not_exist: list<string>},
     *   has_unallowlisted: bool,
     *   total_unallowlisted: int,
     * }
     */
    public function filter(Site $site, ?SiteSecurityScan $scan): array
    {
        $modified = $this->bucketFromScan($scan, 'modified');
        $missing = $this->bucketFromScan($scan, 'missing');
        $unexpected = $this->bucketFromScan($scan, 'should_not_exist');

        $allowlist = SiteCoreChecksumAllowlist::query()
            ->where('site_id', $site->id)
            ->get();

        $allowedFor = function (string $bucket) use ($allowlist): array {
            return $allowlist
                ->filter(fn ($e) => $e->bucket === $bucket || $e->bucket === SiteCoreChecksumAllowlist::BUCKET_ANY)
                ->pluck('path')
                ->all();
        };

        $partition = function (array $paths, array $allowed): array {
            $out = ['kept' => [], 'allowlisted' => []];
            foreach ($paths as $p) {
                if (in_array($p, $allowed, true)) {
                    $out['allowlisted'][] = $p;
                } else {
                    $out['kept'][] = $p;
                }
            }

            return $out;
        };

        $m = $partition($modified, $allowedFor(SiteCoreChecksumAllowlist::BUCKET_MODIFIED));
        $miss = $partition($missing, $allowedFor(SiteCoreChecksumAllowlist::BUCKET_MISSING));
        $u = $partition($unexpected, $allowedFor(SiteCoreChecksumAllowlist::BUCKET_UNEXPECTED));

        $totalKept = count($m['kept']) + count($miss['kept']) + count($u['kept']);

        return [
            'modified' => $m['kept'],
            'missing' => $miss['kept'],
            'should_not_exist' => $u['kept'],
            'allowlisted' => [
                'modified' => $m['allowlisted'],
                'missing' => $miss['allowlisted'],
                'should_not_exist' => $u['allowlisted'],
            ],
            'has_unallowlisted' => $totalKept > 0,
            'total_unallowlisted' => $totalKept,
        ];
    }

    /**
     * The site IDs whose latest core_checksums scan is "issues_found" but every
     * finding is allowlisted. These rows should be filtered out of the global
     * Issues page so the operator only sees actionable findings.
     *
     * @param  iterable<SiteSecurityScan>  $scans  latest checksum scan per site
     * @return Collection<int, int> site IDs to suppress
     */
    public function suppressedSiteIds(iterable $scans): Collection
    {
        $siteIds = collect($scans)->pluck('site_id')->unique()->values();

        $allowlistBySite = SiteCoreChecksumAllowlist::query()
            ->whereIn('site_id', $siteIds)
            ->get()
            ->groupBy('site_id');

        return collect($scans)
            ->filter(function (SiteSecurityScan $scan) use ($allowlistBySite) {
                $entries = $allowlistBySite->get($scan->site_id, collect());
                $allowedAny = $entries->where('bucket', SiteCoreChecksumAllowlist::BUCKET_ANY)->pluck('path')->all();
                $allowedFor = fn (string $b) => $entries
                    ->whereIn('bucket', [$b, SiteCoreChecksumAllowlist::BUCKET_ANY])
                    ->pluck('path')
                    ->all();

                $unallowlistedExists = false;
                foreach (['modified' => 'modified', 'missing' => 'missing', 'should_not_exist' => 'unexpected'] as $detailKey => $bucket) {
                    foreach ($this->bucketFromScan($scan, $detailKey) as $path) {
                        $allowed = array_merge($allowedAny, $allowedFor($bucket));
                        if (! in_array($path, $allowed, true)) {
                            $unallowlistedExists = true;
                            break 2;
                        }
                    }
                }

                return ! $unallowlistedExists;
            })
            ->pluck('site_id');
    }

    /**
     * Is this exact (site, path, bucket) in the allowlist? Used to dedupe before
     * inserting a new row and to render the "already allowlisted" badge.
     */
    public function isAllowlisted(Site $site, string $path, string $bucket): bool
    {
        return SiteCoreChecksumAllowlist::query()
            ->where('site_id', $site->id)
            ->where('path', $path)
            ->whereIn('bucket', [$bucket, SiteCoreChecksumAllowlist::BUCKET_ANY])
            ->exists();
    }

    /**
     * Validates that `path` was actually flagged in the site's latest
     * core_checksums scan. Prevents an attacker (or a mis-typed URL) from
     * coercing the SSH viewer into reading arbitrary files. Returns the
     * matched bucket name on success, null if the path isn't in the scan.
     */
    public function bucketForPathInLatestScan(Site $site, string $path): ?string
    {
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            return null;
        }

        $scan = $site->latestChecksumScan;
        if (! $scan) {
            return null;
        }

        $checks = [
            'modified' => SiteCoreChecksumAllowlist::BUCKET_MODIFIED,
            'missing' => SiteCoreChecksumAllowlist::BUCKET_MISSING,
            'should_not_exist' => SiteCoreChecksumAllowlist::BUCKET_UNEXPECTED,
        ];

        foreach ($checks as $detailKey => $bucket) {
            if (in_array($path, $this->bucketFromScan($scan, $detailKey), true)) {
                return $bucket;
            }
        }

        return null;
    }

    /**
     * Read one of the three buckets out of the scan's `details` JSON column.
     * Defensive — the column might be missing or null on older rows.
     *
     * @return list<string>
     */
    private function bucketFromScan(?SiteSecurityScan $scan, string $key): array
    {
        if (! $scan) {
            return [];
        }
        $details = $scan->details ?? [];
        $values = $details[$key] ?? [];

        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }
}
