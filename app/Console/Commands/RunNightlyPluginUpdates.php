<?php

namespace App\Console\Commands;

use App\Jobs\RunPluginUpdate;
use App\Jobs\RunThemeUpdate;
use App\Models\PluginUpdateIgnore;
use App\Models\PluginUpdateJob;
use App\Models\Site;
use App\Services\Security\PluginVulnerabilityMatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

#[Signature('clockwork:run-nightly-plugin-updates {--dry-run : print candidates only, no DB writes / dispatch} {--site= : limit to one site (id or domain)}')]
#[Description('Queue plugin AND theme updates for every care-plan site that has either pending. Fired nightly at 02:00 ET; workers chew through the queue during the 4-hour window. Name predates theme support (added 2026-08-31) — kept as-is to avoid renaming a live scheduled job.')]
class RunNightlyPluginUpdates extends Command
{
    public function handle(PluginVulnerabilityMatcher $vulnMatcher): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $siteFilter = $this->option('site');

        $candidates = $this->loadCandidateSites($siteFilter);

        if ($candidates->isEmpty()) {
            $this->info('No care-plan sites with pending plugin or theme updates.');
            if (! $isDryRun) {
                $this->stampEligibleSites($siteFilter);
            }

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d candidate site%s found%s.',
            $candidates->count(),
            $candidates->count() === 1 ? '' : 's',
            $isDryRun ? ' (dry-run)' : '',
        ));

        // Collect every (site, kind, slug) tuple we want to queue, with
        // metadata for de-dup, ignore filtering, and CVE-priority sorting.
        // Themes have no vulnerability feed (PluginVulnerabilityMatcher is
        // plugin-only, per its own `software_type = 'plugin'` filter), so
        // theme tuples are always is_vulnerable=false — they sort after any
        // vulnerable plugin, alongside non-vulnerable plugins.
        $tuples = [];
        foreach ($candidates as $site) {
            $pluginsWithUpdates = $this->pendingPlugins($site);
            $themesWithUpdates = $this->pendingThemes($site);
            if ($pluginsWithUpdates === [] && $themesWithUpdates === []) {
                continue;
            }

            // Bulk-load vuln slugs for this site once — avoids hitting the
            // matcher per-plugin and saves a fan-out of queries.
            $vulnSlugs = $this->vulnerableSlugsForSite($site, $vulnMatcher);

            foreach ($pluginsWithUpdates as $entry) {
                $slug = (string) ($entry['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $tuples[] = [
                    'site' => $site,
                    'kind' => PluginUpdateJob::KIND_PLUGIN,
                    'slug' => $slug,
                    'name' => $entry['name'] ?? null,
                    'before_version' => $entry['version'] ?? null,
                    'target_version' => $entry['new_version'] ?? null,
                    'is_vulnerable' => isset($vulnSlugs[$slug]),
                ];
            }

            foreach ($themesWithUpdates as $entry) {
                $slug = (string) ($entry['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $tuples[] = [
                    'site' => $site,
                    'kind' => PluginUpdateJob::KIND_THEME,
                    'slug' => $slug,
                    'name' => $entry['name'] ?? null,
                    'before_version' => $entry['version'] ?? null,
                    'target_version' => $entry['new_version'] ?? null,
                    'is_vulnerable' => false,
                ];
            }
        }

        if ($tuples === []) {
            $this->info('No (site, kind, slug) tuples qualified after filtering.');

            return self::SUCCESS;
        }

        // Drop tuples in PluginUpdateIgnore.
        $tuples = $this->filterIgnored($tuples);

        // Drop tuples that already have a live PluginUpdateJob for this kind/slug.
        $tuples = $this->filterAlreadyLive($tuples);

        if ($tuples === []) {
            $this->info('All candidates filtered out (ignored or already live). Nothing to queue.');
            if (! $isDryRun) {
                $this->stampEligibleSites($siteFilter);
            }

            return self::SUCCESS;
        }

        // CVE-priority: vulnerable first, then alphabetical by domain.
        usort($tuples, function (array $a, array $b) {
            $vulnCmp = (int) $b['is_vulnerable'] - (int) $a['is_vulnerable'];
            if ($vulnCmp !== 0) {
                return $vulnCmp;
            }

            return strcmp($a['site']->domain, $b['site']->domain);
        });

        $vulnerableCount = collect($tuples)->where('is_vulnerable', true)->count();
        $sitesTouched = collect($tuples)->pluck('site.id')->unique()->count();

        $this->info(sprintf(
            'Queue: %d tuples (%d vulnerable-first) across %d site%s.',
            count($tuples),
            $vulnerableCount,
            $sitesTouched,
            $sitesTouched === 1 ? '' : 's',
        ));

        if ($isDryRun) {
            // Print the first ~25 tuples so the operator can sanity-check
            // ordering without flooding stdout on a 200-row night.
            foreach (array_slice($tuples, 0, 25) as $t) {
                $this->line(sprintf(
                    '  %s %s [%s] %s (%s → %s)',
                    $t['is_vulnerable'] ? '!CVE' : '    ',
                    str_pad((string) $t['site']->domain, 36),
                    $t['kind'],
                    $t['slug'],
                    $t['before_version'] ?: '?',
                    $t['target_version'] ?: '?',
                ));
            }
            if (count($tuples) > 25) {
                $this->line(sprintf('  … and %d more.', count($tuples) - 25));
            }

            return self::SUCCESS;
        }

        // Real run. One batch_id for the whole night so the summary command
        // can find them with a LIKE 'nightly-%' query and the dashboard can
        // group them as a single batch.
        $batchId = 'nightly-'.Carbon::now()->format('Y-m-d').'-'.Str::random(8);
        $accepted = 0;
        $failed = 0;

        foreach ($tuples as $t) {
            try {
                DB::transaction(function () use ($t, $batchId) {
                    $row = PluginUpdateJob::create([
                        'site_id' => $t['site']->id,
                        'target_kind' => $t['kind'],
                        'target_slug' => $t['slug'],
                        'target_name' => $t['name'],
                        'status' => PluginUpdateJob::STATUS_PENDING,
                        'before_version' => $t['before_version'],
                        'target_version' => $t['target_version'],
                        'requested_by_user_id' => null,
                        'batch_id' => $batchId,
                        'queued_at' => Carbon::now(),
                    ]);

                    match ($t['kind']) {
                        PluginUpdateJob::KIND_THEME => RunThemeUpdate::dispatch($row->id),
                        default => RunPluginUpdate::dispatch($row->id),
                    };
                });
                $accepted++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error('plugin_update.nightly_dispatch_failed', [
                    'site_id' => $t['site']->id,
                    'kind' => $t['kind'],
                    'slug' => $t['slug'],
                    'batch_id' => $batchId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Stamp every eligible care-plan site the loop ran against, not just
        // those with updates. This gives a daily heartbeat — "7 hours ago"
        // means the loop ran and found nothing to do, not that the site
        // was skipped.
        $this->stampEligibleSites($siteFilter);

        $this->info(sprintf(
            'Done. batch_id=%s accepted=%d failed=%d sites_touched=%d',
            $batchId,
            $accepted,
            $failed,
            $sitesTouched,
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Site>
     */
    private function loadCandidateSites(?string $siteFilter): Collection
    {
        $q = Site::query()
            ->where('care_plan_enabled', true)
            ->where('auto_updates_paused', false)
            ->where('companion_installed', true)
            ->whereNotNull('companion_snapshot')
            // Two truth sources per kind, same drift-tolerance reasoning for
            // both: sites.wp_plugin_updates / wp_theme_updates are
            // denormalised from SpinupWP's nightly WP poll, which runs less
            // often than Companion's own snapshot — the OR against the
            // snapshot's own counts keeps the loop honest when SpinupWP
            // staled. The inner per-site logic reads the snapshot to find
            // what to actually update, so a false-positive from either side
            // is a no-op. A site qualifies if EITHER plugins OR themes have
            // a pending update — themes were silently never included here
            // until 2026-08-31 (the nightly loop only ever queued plugins).
            ->where(function ($q) {
                $q->where('wp_plugin_updates', true)
                    ->orWhere('wp_theme_updates', true)
                    ->orWhereRaw(
                        "CAST(JSON_EXTRACT(companion_snapshot, '$.plugins.counts.updates_available') AS UNSIGNED) > 0"
                    )
                    ->orWhereRaw(
                        "CAST(JSON_EXTRACT(companion_snapshot, '$.themes.counts.updates_available') AS UNSIGNED) > 0"
                    );
            });

        if ($siteFilter) {
            // Accept either an id or a domain — same semantics other commands
            // use for their --site flag.
            if (ctype_digit((string) $siteFilter)) {
                $q->where('id', (int) $siteFilter);
            } else {
                $q->where('domain', $siteFilter);
            }
        }

        return $q->orderBy('domain')->get();
    }

    /**
     * Pull the subset of plugins from companion_snapshot whose
     * `update_available` flag is true.
     *
     * @return list<array<string, mixed>>
     */
    private function pendingPlugins(Site $site): array
    {
        $plugins = $site->companion_snapshot['plugins']['plugins'] ?? [];
        if (! is_array($plugins)) {
            return [];
        }

        return array_values(array_filter(
            $plugins,
            fn ($p) => is_array($p) && ! empty($p['update_available']) && ! empty($p['slug']),
        ));
    }

    /**
     * Pull the subset of themes from companion_snapshot whose
     * `update_available` flag is true. Same shape as pendingPlugins() but
     * themes.items instead of plugins.plugins — matches the path
     * UpdatesController::findInList() and UpdateGrouping::snapshotItems()
     * already use for the manual/dashboard theme-update path.
     *
     * @return list<array<string, mixed>>
     */
    private function pendingThemes(Site $site): array
    {
        $themes = $site->companion_snapshot['themes']['items'] ?? [];
        if (! is_array($themes)) {
            return [];
        }

        return array_values(array_filter(
            $themes,
            fn ($t) => is_array($t) && ! empty($t['update_available']) && ! empty($t['slug']),
        ));
    }

    /**
     * @return array<string, true> slug => true map (for fast lookup)
     */
    private function vulnerableSlugsForSite(Site $site, PluginVulnerabilityMatcher $matcher): array
    {
        $hits = [];
        try {
            foreach ($matcher->forSite($site) as $row) {
                $slug = (string) ($row['plugin_slug'] ?? '');
                if ($slug !== '') {
                    $hits[$slug] = true;
                }
            }
        } catch (\Throwable $e) {
            // Vuln matcher failure is not fatal for the auto-update loop —
            // we just lose the priority signal for this site.
            Log::warning('plugin_update.vuln_matcher_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $hits;
    }

    /**
     * Stamp auto_updates_last_run_at on every care-plan site the loop is
     * eligible to consider — regardless of whether that site had updates.
     * Gives a daily heartbeat so a stale timestamp means the loop stopped
     * running, not that the site was up to date.
     */
    private function stampEligibleSites(?string $siteFilter): void
    {
        $q = Site::query()
            ->where('care_plan_enabled', true)
            ->where('auto_updates_paused', false)
            ->where('companion_installed', true);

        if ($siteFilter) {
            if (ctype_digit((string) $siteFilter)) {
                $q->where('id', (int) $siteFilter);
            } else {
                $q->where('domain', $siteFilter);
            }
        }

        $q->update(['auto_updates_last_run_at' => Carbon::now()]);
    }

    /**
     * Drop tuples whose (site, plugin) is in PluginUpdateIgnore.
     *
     * @param  list<array<string, mixed>>  $tuples
     * @return list<array<string, mixed>>
     */
    private function filterIgnored(array $tuples): array
    {
        if ($tuples === []) {
            return [];
        }

        // Keyed by kind too, not just site+slug — a plugin and a theme could
        // in principle share a slug string, and an ignore entry for one
        // kind must never suppress the other.
        $triples = collect($tuples)->map(fn ($t) => [$t['site']->id, $t['kind'], $t['slug']])->all();
        $ignored = PluginUpdateIgnore::query()
            ->where(function ($q) use ($triples) {
                foreach ($triples as [$siteId, $kind, $slug]) {
                    $q->orWhere(function ($qq) use ($siteId, $kind, $slug) {
                        $qq->where('site_id', $siteId)->where('target_kind', $kind)->where('target_slug', $slug);
                    });
                }
            })
            ->get(['site_id', 'target_kind', 'target_slug'])
            ->mapWithKeys(fn ($row) => ["{$row->target_kind}:{$row->site_id}:{$row->target_slug}" => true])
            ->all();

        return array_values(array_filter(
            $tuples,
            fn ($t) => ! isset($ignored["{$t['kind']}:{$t['site']->id}:{$t['slug']}"]),
        ));
    }

    /**
     * Drop tuples that already have a live PluginUpdateJob for the same
     * (site, kind, slug). Live = pending OR running.
     *
     * @param  list<array<string, mixed>>  $tuples
     * @return list<array<string, mixed>>
     */
    private function filterAlreadyLive(array $tuples): array
    {
        if ($tuples === []) {
            return [];
        }

        $triples = collect($tuples)->map(fn ($t) => [$t['site']->id, $t['kind'], $t['slug']])->all();
        $live = PluginUpdateJob::query()
            ->whereIn('status', PluginUpdateJob::LIVE_STATUSES)
            ->where(function ($q) use ($triples) {
                foreach ($triples as [$siteId, $kind, $slug]) {
                    $q->orWhere(function ($qq) use ($siteId, $kind, $slug) {
                        $qq->where('site_id', $siteId)->where('target_kind', $kind)->where('target_slug', $slug);
                    });
                }
            })
            ->get(['site_id', 'target_kind', 'target_slug'])
            ->mapWithKeys(fn ($row) => ["{$row->target_kind}:{$row->site_id}:{$row->target_slug}" => true])
            ->all();

        return array_values(array_filter(
            $tuples,
            fn ($t) => ! isset($live["{$t['kind']}:{$t['site']->id}:{$t['slug']}"]),
        ));
    }
}
