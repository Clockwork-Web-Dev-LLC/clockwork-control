<?php

namespace App\Services\Security;

use App\Models\IgnoredIssue;
use App\Models\PluginDirectoryStatus;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Matches a site's active installed plugins (from companion_snapshot) against
 * the local WordPress.org plugin directory status mirror.
 *
 * Only activated plugins that are verified closed on WordPress.org are flagged.
 * Premium or custom plugins marked 'not_found' are never flagged.
 */
class ClosedPluginAuditor
{
    /** @var array<string, PluginDirectoryStatus>|null */
    private ?array $closedBySlug = null;

    /**
     * Findings for a single site: list of active plugins whose slug is closed on WP.org.
     *
     * @return list<array{
     *   slug: string,
     *   name: string,
     *   version: string,
     *   active: bool,
     *   reason: ?string,
     *   closed_date: ?string,
     * }>
     */
    public function forSite(Site $site): array
    {
        $snapshot = is_array($site->companion_snapshot)
            ? $site->companion_snapshot
            : json_decode((string) $site->companion_snapshot, true);

        if (! is_array($snapshot)) {
            return [];
        }

        $plugins = $snapshot['plugins']['plugins'] ?? null;
        if (! is_array($plugins)) {
            return [];
        }

        $closedBySlug = $this->closedBySlug();
        if (empty($closedBySlug)) {
            return [];
        }

        $results = [];

        foreach ($plugins as $plugin) {
            if (! is_array($plugin)) {
                continue;
            }

            // Only flag active (activated) plugins.
            if (empty($plugin['active'])) {
                continue;
            }

            $rawSlug = (string) ($plugin['slug'] ?? '');
            if ($rawSlug === '') {
                continue;
            }

            $dirSlug = strtok($rawSlug, '/');
            if ($dirSlug === false) {
                continue;
            }

            if (isset($closedBySlug[$dirSlug])) {
                $status = $closedBySlug[$dirSlug];
                $results[] = [
                    'slug' => $dirSlug,
                    'name' => (string) ($plugin['name'] ?? $dirSlug),
                    'version' => (string) ($plugin['version'] ?? ''),
                    'active' => true,
                    'reason' => $status->reason,
                    'closed_date' => $status->closed_date,
                ];
            }
        }

        usort($results, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $results;
    }

    /**
     * Match closed plugins across multiple sites, keyed by site ID.
     *
     * @param  iterable<Site>  $sites
     * @return array<int, list<array{slug: string, name: string, version: string, active: bool, reason: ?string, closed_date: ?string}>>
     */
    public function forSites(iterable $sites): array
    {
        $byId = [];
        foreach ($sites as $site) {
            $findings = $this->forSite($site);
            if (! empty($findings)) {
                $byId[$site->id] = $findings;
            }
        }

        return $byId;
    }

    /**
     * Return active monitored sites with at least one unignored closed plugin finding.
     *
     * @return array{
     *   sites: Collection<int, Site>,
     *   findings_by_site: array<int, list<array{slug: string, name: string, version: string, active: bool, reason: ?string, closed_date: ?string}>>,
     * }
     */
    public function findingsForMonitoredSites(): array
    {
        $ignoredSiteIds = IgnoredIssue::query()
            ->where('issue_type', IgnoredIssue::TYPE_PLUGIN_CLOSED)
            ->pluck('site_id')
            ->all();

        $sites = Site::query()
            ->where('is_inactive', false)
            ->whereNotIn('id', $ignoredSiteIds)
            ->whereNotNull('companion_snapshot')
            ->hostMonitored()
            ->with(['server:id,name,is_ignored'])
            ->orderBy('domain')
            ->get();

        $findingsBySite = $this->forSites($sites);
        $flaggedSiteIds = array_keys($findingsBySite);

        $flaggedSites = $sites->filter(fn (Site $s) => in_array($s->id, $flaggedSiteIds, true))->values();

        return [
            'sites' => $flaggedSites,
            'findings_by_site' => $findingsBySite,
        ];
    }

    /**
     * Count of monitored, active sites with at least one closed plugin.
     * Used by IssueCounter::calculateTotal().
     */
    public function flaggedSiteCount(): int
    {
        $ignoredSiteIds = IgnoredIssue::query()
            ->where('issue_type', IgnoredIssue::TYPE_PLUGIN_CLOSED)
            ->pluck('site_id')
            ->all();

        $sites = Site::query()
            ->where('is_inactive', false)
            ->whereNotIn('id', $ignoredSiteIds)
            ->whereNotNull('companion_snapshot')
            ->hostMonitored()
            ->select(['id', 'companion_snapshot', 'hosting_provider', 'server_id'])
            ->get();

        $count = 0;
        foreach ($sites as $site) {
            if ($this->forSite($site) !== []) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<string, PluginDirectoryStatus>
     */
    private function closedBySlug(): array
    {
        if ($this->closedBySlug !== null) {
            return $this->closedBySlug;
        }

        $this->closedBySlug = PluginDirectoryStatus::query()
            ->where('status', PluginDirectoryStatus::STATUS_CLOSED)
            ->get()
            ->keyBy('slug')
            ->all();

        return $this->closedBySlug;
    }
}
