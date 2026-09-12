<?php

namespace App\Services\Updates;

use App\Models\PluginUpdateIgnore;
use App\Models\PluginUpdateJob;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Pure projection: walk every site's cached Companion snapshot, project to
 * a list of (site, target) pending updates, apply ignore + live-job filters,
 * group by plugin/theme slug for the Plugins / Themes tabs (and ungrouped
 * for Core / Translations).
 *
 * Single source of truth for the four tabs so they stay in agreement on
 * what counts as "pending" — same place that drives the nav-badge count.
 *
 * 3 queries total (sites + ignores + live jobs), no N+1.
 */
class UpdateGrouping
{
    /**
     * @param  array{care_plan?: string, server_tier?: string, show_ignored?: bool}  $filters
     * @return array{
     *   plugins: array{groups: list<array{slug: string, name: string, count: int, sites: list<array<string, mixed>>}>, total: int},
     *   themes:  array{groups: list<array{slug: string, name: string, count: int, sites: list<array<string, mixed>>}>, total: int},
     *   core:    array{rows: list<array<string, mixed>>, total: int},
     *   translations: array{rows: list<array<string, mixed>>, total: int},
     *   stats: array{plugins: int, themes: int, core: int, translations: int, total: int},
     * }
     */
    public function build(array $filters = []): array
    {
        $sites = Site::query()
            ->with('server.tags')
            ->whereNotNull('companion_snapshot')
            ->hostMonitored()
            ->get();

        $sites = $this->applyFilters($sites, $filters);

        $ignores = PluginUpdateIgnore::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->get()
            ->groupBy('site_id');

        $liveJobs = PluginUpdateJob::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->whereIn('status', PluginUpdateJob::LIVE_STATUSES)
            ->get()
            ->groupBy('site_id');

        $showIgnored = (bool) ($filters['show_ignored'] ?? false);

        $plugins = [];
        $themes = [];
        $core = [];
        $translations = [];

        foreach ($sites as $site) {
            $siteIgnores = $ignores->get($site->id, collect());
            $siteJobs = $liveJobs->get($site->id, collect());

            // Plugins
            foreach ($this->snapshotItems($site, 'plugins.plugins') as $item) {
                if (! ($item['update_available'] ?? false)) {
                    continue;
                }
                $slug = (string) ($item['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $isIgnored = $this->isIgnored($siteIgnores, PluginUpdateJob::KIND_PLUGIN, $slug);
                if ($isIgnored && ! $showIgnored) {
                    continue;
                }
                $plugins[$slug]['name'] ??= (string) ($item['name'] ?? $slug);
                $plugins[$slug]['sites'][] = $this->siteRow($site, $item, $siteJobs, PluginUpdateJob::KIND_PLUGIN, $slug, $isIgnored);
            }

            // Themes
            foreach ($this->snapshotItems($site, 'themes.items') as $item) {
                if (! ($item['update_available'] ?? false)) {
                    continue;
                }
                $slug = (string) ($item['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $isIgnored = $this->isIgnored($siteIgnores, PluginUpdateJob::KIND_THEME, $slug);
                if ($isIgnored && ! $showIgnored) {
                    continue;
                }
                $themes[$slug]['name'] ??= (string) ($item['name'] ?? $slug);
                $themes[$slug]['sites'][] = $this->siteRow($site, $item, $siteJobs, PluginUpdateJob::KIND_THEME, $slug, $isIgnored);
            }

            // Core
            if ($site->core_update_available) {
                $isIgnored = $this->isIgnored($siteIgnores, PluginUpdateJob::KIND_CORE, null);
                if ($isIgnored && ! $showIgnored) {
                    // skip
                } else {
                    $core[] = $this->siteRow($site, $site->companion_snapshot['wp_core'] ?? [], $siteJobs, PluginUpdateJob::KIND_CORE, null, $isIgnored);
                }
            }

            // Translations
            if ($site->translation_updates_count > 0) {
                $isIgnored = $this->isIgnored($siteIgnores, PluginUpdateJob::KIND_TRANSLATION, null);
                if ($isIgnored && ! $showIgnored) {
                    // skip
                } else {
                    $core_data = $site->companion_snapshot['translations'] ?? [];
                    $translations[] = $this->siteRow($site, $core_data, $siteJobs, PluginUpdateJob::KIND_TRANSLATION, null, $isIgnored);
                }
            }
        }

        $pluginGroups = $this->finaliseGroups($plugins);
        $themeGroups = $this->finaliseGroups($themes);

        $pluginTotal = array_sum(array_map(fn ($g) => $g['count'], $pluginGroups));
        $themeTotal = array_sum(array_map(fn ($g) => $g['count'], $themeGroups));

        return [
            'plugins' => ['groups' => $pluginGroups, 'total' => $pluginTotal],
            'themes' => ['groups' => $themeGroups, 'total' => $themeTotal],
            'core' => ['rows' => $core, 'total' => count($core)],
            'translations' => ['rows' => $translations, 'total' => count($translations)],
            'stats' => [
                'plugins' => $pluginTotal,
                'themes' => $themeTotal,
                'core' => count($core),
                'translations' => count($translations),
                'total' => $pluginTotal + $themeTotal + count($core) + count($translations),
            ],
        ];
    }

    /**
     * Fleet-wide pending count for the nav badge. Cheap read — pulls 3 columns
     * per site and computes the sum in PHP. Cached at the call site if needed.
     */
    public function pendingCount(): int
    {
        $built = $this->build();

        return (int) $built['stats']['total'];
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Site>
     */
    private function applyFilters(Collection $sites, array $filters): Collection
    {
        if (Site::areCarePlansEnabled()) {
            $carePlan = (string) ($filters['care_plan'] ?? 'on');
            if ($carePlan === 'on') {
                $sites = $sites->where('care_plan_enabled', true);
            } elseif ($carePlan === 'off') {
                $sites = $sites->where('care_plan_enabled', false);
            }
        }
        // 'all' = no filter

        // Multi-select server tags. A site matches if its server has ANY of
        // the requested tags (OR semantics). Empty array = no tag filter.
        $tagSlugs = $filters['tags'] ?? [];
        if (is_array($tagSlugs) && $tagSlugs !== []) {
            $tagSlugs = array_map('strval', $tagSlugs);
            $sites = $sites->filter(function (Site $s) use ($tagSlugs) {
                $server = $s->server;
                if (! $server) {
                    return false;
                }

                return $server->tags->contains(fn ($t) => in_array((string) $t->slug, $tagSlugs, true));
            });
        }

        return $sites->values();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshotItems(Site $site, string $dotPath): array
    {
        $segments = explode('.', $dotPath);
        $cursor = $site->companion_snapshot;
        foreach ($segments as $seg) {
            if (! is_array($cursor) || ! array_key_exists($seg, $cursor)) {
                return [];
            }
            $cursor = $cursor[$seg];
        }

        return is_array($cursor) ? array_values(array_filter($cursor, 'is_array')) : [];
    }

    /**
     * @param  Collection<int, PluginUpdateIgnore>  $siteIgnores
     */
    private function isIgnored(Collection $siteIgnores, string $kind, ?string $slug): bool
    {
        return $siteIgnores->contains(function (PluginUpdateIgnore $ig) use ($kind, $slug) {
            if ($ig->target_kind !== $kind) {
                return false;
            }

            return $ig->target_slug === $slug;
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<int, PluginUpdateJob>  $siteJobs
     * @return array<string, mixed>
     */
    private function siteRow(Site $site, array $item, Collection $siteJobs, string $kind, ?string $slug, bool $isIgnored): array
    {
        $live = $siteJobs->first(function (PluginUpdateJob $j) use ($kind, $slug) {
            return $j->target_kind === $kind && $j->target_slug === $slug;
        });

        $serverTier = null;
        if ($site->server) {
            $serverTier = $site->server->tags
                ->whereIn('name', ['Dedicated', 'Shared'])
                ->first()?->name;
        }

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            // Plugin/theme items key the installed version as 'version'; the
            // wp_core snapshot section instead uses 'current_version' — this
            // fallback chain covers both without a kind-specific branch.
            'before_version' => (string) ($item['version'] ?? $item['current_version'] ?? ''),
            'target_version' => (string) ($item['new_version'] ?? ''),
            'active' => (bool) ($item['active'] ?? false),
            'server_id' => $site->server_id,
            'server_name' => $site->server?->name,
            'server_tier' => $serverTier,
            'care_plan_enabled' => (bool) $site->care_plan_enabled,
            'auto_updates_paused' => (bool) $site->auto_updates_paused,
            'auto_updates_paused_reason' => $site->auto_updates_paused_reason,
            'has_live_job' => $live !== null,
            'live_job_status' => $live?->status,
            'is_ignored' => $isIgnored,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $accumulator
     * @return list<array<string, mixed>>
     */
    private function finaliseGroups(array $accumulator): array
    {
        $groups = [];
        foreach ($accumulator as $slug => $data) {
            $groups[] = [
                'slug' => $slug,
                'name' => (string) ($data['name'] ?? $slug),
                'count' => count($data['sites'] ?? []),
                'sites' => $data['sites'] ?? [],
            ];
        }

        // Sort by site-count desc (most widespread updates first), tiebreak by name.
        usort($groups, function ($a, $b) {
            return [$b['count'], strtolower($a['name'])] <=> [$a['count'], strtolower($b['name'])];
        });

        return $groups;
    }
}
