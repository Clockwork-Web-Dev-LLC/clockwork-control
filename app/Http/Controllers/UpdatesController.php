<?php

namespace App\Http\Controllers;

use App\Jobs\RunCoreUpdate;
use App\Jobs\RunPluginUpdate;
use App\Jobs\RunThemeUpdate;
use App\Jobs\RunTranslationsUpdate;
use App\Models\PluginUpdateIgnore;
use App\Models\PluginUpdateJob;
use App\Models\Site;
use App\Models\Tag;
use App\Services\Updates\UpdateGrouping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Fleet-wide Updates page. The /updates URL is a single page with four tabs
 * (Plugins / Themes / WP core / Translations). Each tab either groups by
 * slug across all sites (plugins/themes) or shows a per-site list (core /
 * translations).
 *
 * The actual update execution is async — bulkUpdate writes plugin_update_jobs
 * rows and dispatches one job per row onto the 'plugin-updates' queue.
 * AbstractRunUpdate (and its 4 concrete subclasses) drive the lifecycle.
 *
 * batchStatus is polled by the page every 3s while a batch is live.
 */
class UpdatesController extends Controller
{
    /**
     * Fleet-level care-plan auto-update curation page. Lists every site
     * marked care_plan_enabled with its current paused state, last-considered
     * timestamp, and a quick toggle that POSTs to sites.auto-updates.toggle.
     *
     * Default semantics: every care-plan site is auto-updated unless paused.
     * This page is the curation surface for "all-on except these N" — pause
     * a site here to take it off the nightly path without dropping it off
     * the care plan.
     */
    public function carePlan(Request $request): View
    {
        $sites = Site::query()
            ->where('care_plan_enabled', true)
            ->with('server:id,name')
            ->orderByRaw('auto_updates_paused asc') // active sites first
            ->orderBy('domain')
            ->get();

        $totals = [
            'total' => $sites->count(),
            'active' => $sites->where('auto_updates_paused', false)->count(),
            'paused' => $sites->where('auto_updates_paused', true)->count(),
        ];

        return view('dashboard.updates.care-plan', [
            'sites' => $sites,
            'totals' => $totals,
        ]);
    }

    public function index(Request $request, UpdateGrouping $grouping): View
    {
        $tagSlugs = $request->query('tags', []);
        $tagSlugs = is_array($tagSlugs) ? array_values(array_filter(array_map('strval', $tagSlugs))) : [];

        $filters = [
            'care_plan' => (string) $request->query('care_plan', 'on'),
            'tags' => $tagSlugs,
            'show_ignored' => (bool) $request->query('show_ignored', false),
        ];

        $built = $grouping->build($filters);
        $activeTab = $this->resolveTab((string) $request->query('tab', 'plugins'));

        $batchInProgress = PluginUpdateJob::query()
            ->live()
            ->latest('queued_at')
            ->value('batch_id');

        // Available tags for the filter chip strip — only those actually
        // applied to a server (no point offering an unused tag as a filter).
        $availableTags = Tag::query()
            ->whereHas('servers')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('dashboard.updates.index', [
            'activeTab' => $activeTab,
            'stats' => $built['stats'],
            'plugins' => $built['plugins'],
            'themes' => $built['themes'],
            'core' => $built['core'],
            'translations' => $built['translations'],
            'filters' => $filters,
            'availableTags' => $availableTags,
            'batchInProgress' => $request->query('batch') ?: $batchInProgress,
        ]);
    }

    /**
     * Bulk-enqueue updates from the page. Accepts an array of "kind:site_id:slug"
     * tuples. Slug is empty for core/translations.
     *
     * Validation:
     *   - kind must be one of the four enum values
     *   - site_id must reference a site with companion_installed
     *   - the path must currently appear as update_available in the snapshot
     *     (no enqueueing of stale targets)
     *   - existing live job for the same (site, kind, slug) is dedup'd out
     *   - ignored (site, kind, slug) pairs are dropped
     */
    public function bulkUpdate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'targets' => ['required', 'array', 'min:1', 'max:500'],
            'targets.*' => ['string'],
            'confirm_major' => ['nullable', 'boolean'],
        ]);

        $batchId = (string) Str::uuid();
        $userId = $request->user()?->id;
        $confirmMajor = (bool) ($data['confirm_major'] ?? false);

        $accepted = 0;
        $skippedIgnored = 0;
        $skippedDup = 0;
        $skippedStale = 0;

        foreach ($data['targets'] as $target) {
            $parsed = $this->parseTarget($target);
            if ($parsed === null) {
                continue;
            }
            [$kind, $siteId, $slug] = $parsed;

            $site = Site::query()->whereNotNull('companion_snapshot')->find($siteId);
            if (! $site || ! $site->companion_installed) {
                $skippedStale++;

                continue;
            }

            // Drop if currently ignored
            $isIgnored = PluginUpdateIgnore::query()
                ->where('site_id', $siteId)
                ->where('target_kind', $kind)
                ->where('target_slug', $slug)
                ->exists();
            if ($isIgnored) {
                $skippedIgnored++;

                continue;
            }

            // Drop if already a live job
            $hasLive = PluginUpdateJob::query()
                ->where('site_id', $siteId)
                ->where('target_kind', $kind)
                ->where('target_slug', $slug)
                ->whereIn('status', PluginUpdateJob::LIVE_STATUSES)
                ->exists();
            if ($hasLive) {
                $skippedDup++;

                continue;
            }

            // Verify the snapshot still says this is pending
            $snapshotEntry = $this->lookupSnapshotEntry($site, $kind, $slug);
            if ($snapshotEntry === null) {
                $skippedStale++;

                continue;
            }

            // Atomic create+dispatch: if dispatch throws, roll back the row.
            // Otherwise we'd leave an orphan plugin_update_jobs row at status
            // 'pending' with nothing in the `jobs` table to advance it — the
            // batch progress widget would then read N pending forever, since
            // ReapStaleUpdateJobs only reaps `running` rows.
            // Per-row scope (not per-batch) so one bad target doesn't kill the
            // rest of the batch.
            try {
                DB::transaction(function () use ($siteId, $kind, $slug, $snapshotEntry, $userId, $batchId, $confirmMajor) {
                    $row = PluginUpdateJob::create([
                        'site_id' => $siteId,
                        'target_kind' => $kind,
                        'target_slug' => $slug,
                        'target_name' => $snapshotEntry['name'] ?? null,
                        'status' => PluginUpdateJob::STATUS_PENDING,
                        'before_version' => $snapshotEntry['version'] ?? null,
                        'target_version' => $snapshotEntry['new_version'] ?? null,
                        'requested_by_user_id' => $userId,
                        'batch_id' => $batchId,
                        'queued_at' => Carbon::now(),
                    ]);

                    $this->dispatchJob($kind, $row->id, $confirmMajor);
                });
                $accepted++;
            } catch (\Throwable $e) {
                Log::error('plugin_update.dispatch_failed', [
                    'site_id' => $siteId,
                    'target_kind' => $kind,
                    'target_slug' => $slug,
                    'batch_id' => $batchId,
                    'error' => $e->getMessage(),
                ]);
                $skippedStale++;
            }
        }

        $bits = ["Queued {$accepted} update".($accepted === 1 ? '' : 's')];
        if ($skippedDup > 0) {
            $bits[] = "{$skippedDup} already running";
        }
        if ($skippedIgnored > 0) {
            $bits[] = "{$skippedIgnored} ignored";
        }
        if ($skippedStale > 0) {
            $bits[] = "{$skippedStale} no-longer-applicable";
        }

        return redirect()
            ->route('updates.index', ['tab' => $request->query('tab', 'plugins'), 'batch' => $batchId])
            ->with('flash', implode(' · ', $bits));
    }

    public function bulkIgnore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'targets' => ['required', 'array', 'min:1', 'max:500'],
            'targets.*' => ['string'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $userId = $request->user()?->id;
        $count = 0;
        foreach ($data['targets'] as $target) {
            $parsed = $this->parseTarget($target);
            if ($parsed === null) {
                continue;
            }
            [$kind, $siteId, $slug] = $parsed;

            PluginUpdateIgnore::updateOrCreate(
                [
                    'site_id' => $siteId,
                    'target_kind' => $kind,
                    'target_slug' => $slug,
                ],
                [
                    'note' => $data['note'] ?? null,
                    'ignored_by_user_id' => $userId,
                    'ignored_at' => Carbon::now(),
                ],
            );
            $count++;
        }

        return back()->with('flash', "Ignored {$count} update".($count === 1 ? '' : 's').'.');
    }

    public function bulkUnignore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'targets' => ['required', 'array', 'min:1', 'max:500'],
            'targets.*' => ['string'],
        ]);

        $count = 0;
        foreach ($data['targets'] as $target) {
            $parsed = $this->parseTarget($target);
            if ($parsed === null) {
                continue;
            }
            [$kind, $siteId, $slug] = $parsed;

            $deleted = PluginUpdateIgnore::query()
                ->where('site_id', $siteId)
                ->where('target_kind', $kind)
                ->where('target_slug', $slug)
                ->delete();
            $count += $deleted;
        }

        return back()->with('flash', "Removed {$count} ignore entr".($count === 1 ? 'y' : 'ies').'.');
    }

    /**
     * JSON status for a batch — polled every 3s by the page while live.
     */
    public function batchStatus(string $batchId): JsonResponse
    {
        $rows = PluginUpdateJob::query()
            ->forBatch($batchId)
            ->with('site:id,domain')
            ->orderBy('id')
            ->get();

        $byStatus = $rows->groupBy('status')->map->count();
        $kindBreakdown = $rows->groupBy('target_kind')->map(function ($byKind) {
            return $byKind->groupBy('status')->map->count();
        });

        $live = $byStatus->get(PluginUpdateJob::STATUS_PENDING, 0)
            + $byStatus->get(PluginUpdateJob::STATUS_RUNNING, 0);

        // What's currently spinning — the live progress indicator shows the
        // single in-flight job's plugin+site combo so the operator can see
        // forward motion (and which site is taking long).
        $running = $rows
            ->where('status', PluginUpdateJob::STATUS_RUNNING)
            ->sortByDesc('started_at')
            ->take(3)
            ->values()
            ->map(fn (PluginUpdateJob $r) => [
                'id' => $r->id,
                'kind' => $r->target_kind,
                'site_id' => $r->site_id,
                'site_domain' => $r->site?->domain,
                'name' => $r->target_name ?: $r->target_slug,
                'started_at' => $r->started_at?->toIso8601String(),
                'messages' => is_array($r->messages) ? $r->messages : [],
            ]);

        $recent = $rows
            ->whereIn('status', PluginUpdateJob::TERMINAL_STATUSES)
            ->sortByDesc('completed_at')
            ->take(10)
            ->values()
            ->map(fn (PluginUpdateJob $r) => [
                'id' => $r->id,
                'kind' => $r->target_kind,
                'site_id' => $r->site_id,
                'site_domain' => $r->site?->domain,
                'name' => $r->target_name ?: $r->target_slug,
                'before' => $r->before_version,
                'after' => $r->after_version,
                'status' => $r->status,
                'error' => $r->error,
                'elapsed_ms' => $r->elapsed_ms,
                'started_at' => $r->started_at?->toIso8601String(),
                'completed_at' => $r->completed_at?->toIso8601String(),
                'messages' => is_array($r->messages) ? $r->messages : [],
            ]);

        return response()->json([
            'batch_id' => $batchId,
            'total' => $rows->count(),
            'pending' => $byStatus->get(PluginUpdateJob::STATUS_PENDING, 0),
            // `running_count` is the integer status tally; the array of in-flight
            // job rows lives at `running_jobs` below. Older code overwrote both
            // under the same `running` key — caused silent data loss for any
            // consumer reading the count.
            'running_count' => $byStatus->get(PluginUpdateJob::STATUS_RUNNING, 0),
            'complete' => $byStatus->get(PluginUpdateJob::STATUS_COMPLETE, 0),
            'failed' => $byStatus->get(PluginUpdateJob::STATUS_FAILED, 0),
            'skipped' => $byStatus->get(PluginUpdateJob::STATUS_SKIPPED, 0),
            'cancelled' => $byStatus->get(PluginUpdateJob::STATUS_CANCELLED, 0),
            'kind_breakdown' => $kindBreakdown,
            'running_jobs' => $running,
            'recent' => $recent,
            'complete_flag' => $live === 0,
        ]);
    }

    /**
     * Parse "kind:site_id:slug" string. Slug may be empty for core/translations.
     * Returns null on any malformed input — caller drops it silently.
     *
     * @return array{0: string, 1: int, 2: ?string}|null
     */
    private function parseTarget(string $target): ?array
    {
        $parts = explode(':', $target, 3);
        if (count($parts) < 2) {
            return null;
        }
        $kind = $parts[0];
        if (! in_array($kind, PluginUpdateJob::KINDS, true)) {
            return null;
        }
        $siteId = (int) $parts[1];
        if ($siteId <= 0) {
            return null;
        }
        $slug = isset($parts[2]) && $parts[2] !== '' ? $parts[2] : null;

        // Plugins/themes require a slug; core/translations forbid one.
        if (in_array($kind, [PluginUpdateJob::KIND_PLUGIN, PluginUpdateJob::KIND_THEME], true) && $slug === null) {
            return null;
        }
        if (in_array($kind, [PluginUpdateJob::KIND_CORE, PluginUpdateJob::KIND_TRANSLATION], true)) {
            $slug = null;
        }

        return [$kind, $siteId, $slug];
    }

    /**
     * Look up the matching update entry in the cached snapshot. Returns null
     * when the target is no longer pending — protects against enqueueing
     * stale work after a snapshot refresh changed the picture.
     *
     * @return array<string, mixed>|null
     */
    private function lookupSnapshotEntry(Site $site, string $kind, ?string $slug): ?array
    {
        return match ($kind) {
            PluginUpdateJob::KIND_PLUGIN => $this->findInList($site, 'plugins.plugins', $slug),
            PluginUpdateJob::KIND_THEME => $this->findInList($site, 'themes.items', $slug),
            PluginUpdateJob::KIND_CORE => $site->core_update_available ? ($site->companion_snapshot['wp_core'] ?? []) : null,
            PluginUpdateJob::KIND_TRANSLATION => $site->translation_updates_count > 0 ? ($site->companion_snapshot['translations'] ?? []) : null,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findInList(Site $site, string $dotPath, ?string $slug): ?array
    {
        if ($slug === null) {
            return null;
        }
        $segments = explode('.', $dotPath);
        $cursor = $site->companion_snapshot;
        foreach ($segments as $seg) {
            if (! is_array($cursor) || ! array_key_exists($seg, $cursor)) {
                return null;
            }
            $cursor = $cursor[$seg];
        }
        if (! is_array($cursor)) {
            return null;
        }
        foreach ($cursor as $item) {
            if (is_array($item) && ($item['slug'] ?? null) === $slug && ($item['update_available'] ?? false)) {
                return $item;
            }
        }

        return null;
    }

    private function dispatchJob(string $kind, int $jobRowId, bool $confirmMajor): void
    {
        match ($kind) {
            PluginUpdateJob::KIND_PLUGIN => RunPluginUpdate::dispatch($jobRowId),
            PluginUpdateJob::KIND_THEME => RunThemeUpdate::dispatch($jobRowId),
            PluginUpdateJob::KIND_CORE => RunCoreUpdate::dispatch($jobRowId, $confirmMajor),
            PluginUpdateJob::KIND_TRANSLATION => RunTranslationsUpdate::dispatch($jobRowId),
            default => null, // parseTarget() already rejects anything else; this keeps PHPStan happy
        };
    }

    private function resolveTab(string $tab): string
    {
        return in_array($tab, ['plugins', 'themes', 'core', 'translations'], true) ? $tab : 'plugins';
    }
}
