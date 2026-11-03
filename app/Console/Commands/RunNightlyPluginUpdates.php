<?php

namespace App\Console\Commands;

use App\Jobs\RunPluginUpdate;
use App\Jobs\RunThemeUpdate;
use App\Models\PluginUpdateFailureStreak;
use App\Models\PluginUpdateIgnore;
use App\Models\PluginUpdateJob;
use App\Models\Site;
use App\Services\Security\PluginVulnerabilityMatcher;
use App\Support\UpgraderOutput;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

#[Signature('clockwork:run-nightly-plugin-updates {--dry-run : print candidates only, no DB writes / dispatch} {--site= : limit to one site (id or domain)} {--force : queue even if a fleet-wide nightly batch already ran for today\'s local date}')]
#[Description('Queue plugin AND theme updates for every care-plan site that has either pending. Fired nightly at 02:00 ET; workers chew through the queue during the 4-hour window. Name predates theme support (added 2026-08-31) — kept as-is to avoid renaming a live scheduled job.')]
class RunNightlyPluginUpdates extends Command
{
    /**
     * Timezone the nightly is scheduled in (routes/console.php). "Today"
     * for the once-per-night guard and the date in the batch id both use
     * this zone, so a night maps to exactly one local calendar date.
     */
    public const LOCAL_TIMEZONE = 'America/New_York';

    /**
     * Every nightly batch id starts with this. UpdateFailureStreakRecorder,
     * AbstractRunUpdate's chat ping, the stale-job reaper and the summary
     * mail all key off the prefix, so it must not change.
     */
    public const BATCH_PREFIX = 'nightly-';

    /**
     * Marks a batch queued by an operator's --site run, so it never counts
     * as (or blocks) the fleet-wide nightly for that date.
     */
    public const SITE_BATCH_MARKER = 'site-';

    public const BYPASS_NEW_VERSION = 'new_version';

    public const BYPASS_SECURITY_FIX = 'security_fix';

    /**
     * A security fix whose version already failed (or was already retried)
     * is retried at most once in this many days, so a pause that a retry
     * cannot clear doesn't become a nightly failure ping again.
     */
    public const SECURITY_RETRY_BACKOFF_DAYS = 7;

    /**
     * Failures a retry of the same version can't fix: the licence or the
     * update offer needs an operator. Only a new version is tried again.
     */
    public const OPERATOR_ONLY_REASONS = [
        UpgraderOutput::REASON_DOWNLOAD_UNAUTHORIZED,
        UpgraderOutput::REASON_NO_PACKAGE,
        UpgraderOutput::REASON_NOT_OFFERED,
    ];

    public function handle(PluginVulnerabilityMatcher $vulnMatcher): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $siteFilter = $this->option('site');

        // Once per local night. The chained schedule fires at 01:30
        // America/New_York, and on the DST fall-back day 01:30 happens
        // twice, so the whole refresh + update chain ran twice and every
        // failure was counted twice toward the auto-pause threshold.
        // Operator --site runs are explicit and are not guarded; --force
        // overrides the guard for a deliberate fleet-wide re-run.
        if (! $siteFilter && ! $this->option('force')) {
            $existingBatch = $this->fleetBatchForLocalDate();
            if ($existingBatch !== null) {
                $localDate = Carbon::now(self::LOCAL_TIMEZONE)->format('Y-m-d');
                if ($isDryRun) {
                    $this->warn("Nightly batch {$existingBatch} already ran for {$localDate}; a real run would be skipped (use --force to override).");
                } else {
                    $this->info("Nightly batch {$existingBatch} already ran for {$localDate}. Skipping (use --force to override).");
                    Log::info('plugin_update.nightly_already_ran', [
                        'local_date' => $localDate,
                        'batch_id' => $existingBatch,
                    ]);

                    return self::SUCCESS;
                }
            }
        }

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
                // The snapshot slug is "directory/file.php"; the matcher keys
                // on the directory only, so compare on that.
                $dirSlug = self::directorySlug($slug);
                $isVulnerable = array_key_exists($dirSlug, $vulnSlugs);
                $tuples[] = [
                    'site' => $site,
                    'kind' => PluginUpdateJob::KIND_PLUGIN,
                    'slug' => $slug,
                    'name' => $entry['name'] ?? null,
                    'before_version' => $entry['version'] ?? null,
                    'target_version' => $entry['new_version'] ?? null,
                    'is_vulnerable' => $isVulnerable,
                    'patched_in' => $isVulnerable ? $vulnSlugs[$dirSlug] : null,
                    'pause_bypass' => null,
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
                    'patched_in' => null,
                    'pause_bypass' => null,
                ];
            }
        }

        if ($tuples === []) {
            $this->info('No (site, kind, slug) tuples qualified after filtering.');

            return self::SUCCESS;
        }

        // Drop tuples in PluginUpdateIgnore (auto-failure pauses can be let
        // through for a new release or a security fix; see filterIgnored).
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
        $retried = array_values(array_filter($tuples, fn ($t) => $t['pause_bypass'] !== null));

        $this->info(sprintf(
            'Queue: %d tuples (%d vulnerable-first) across %d site%s.',
            count($tuples),
            $vulnerableCount,
            $sitesTouched,
            $sitesTouched === 1 ? '' : 's',
        ));

        if ($retried !== []) {
            $this->info(sprintf(
                'Retrying %d auto-paused update%s (%d new release, %d security fix).',
                count($retried),
                count($retried) === 1 ? '' : 's',
                count(array_filter($retried, fn ($t) => $t['pause_bypass'] === self::BYPASS_NEW_VERSION)),
                count(array_filter($retried, fn ($t) => $t['pause_bypass'] === self::BYPASS_SECURITY_FIX)),
            ));
        }

        if ($isDryRun) {
            // Print the first ~25 tuples so the operator can sanity-check
            // ordering without flooding stdout on a 200-row night.
            foreach (array_slice($tuples, 0, 25) as $t) {
                $this->line(sprintf(
                    '  %s %s [%s] %s (%s → %s)%s',
                    $t['is_vulnerable'] ? '!CVE' : '    ',
                    str_pad((string) $t['site']->domain, 36),
                    $t['kind'],
                    $t['slug'],
                    $t['before_version'] ?: '?',
                    $t['target_version'] ?: '?',
                    $t['pause_bypass'] !== null ? " [auto-paused, retry: {$t['pause_bypass']}]" : '',
                ));
            }
            if (count($tuples) > 25) {
                $this->line(sprintf('  … and %d more.', count($tuples) - 25));
            }

            return self::SUCCESS;
        }

        // Real run. One batch_id for the whole night so the summary command
        // can find them with a LIKE 'nightly-%' query and the dashboard can
        // group them as a single batch. The date is the local night's date
        // (same zone as the once-per-night guard above).
        $batchId = self::BATCH_PREFIX
            .Carbon::now(self::LOCAL_TIMEZONE)->format('Y-m-d')
            .'-'.($siteFilter ? self::SITE_BATCH_MARKER : '')
            .Str::random(8);
        $accepted = 0;
        $failed = 0;

        foreach ($retried as $t) {
            Log::info('plugin_update.nightly_retrying_auto_paused', [
                'site_id' => $t['site']->id,
                'kind' => $t['kind'],
                'slug' => $t['slug'],
                'target_version' => $t['target_version'],
                'reason' => $t['pause_bypass'],
                'batch_id' => $batchId,
            ]);
        }

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

                    // A retry of an auto-paused slug counts as that version's
                    // attempt from now, however the job ends: a timeout, the
                    // reaper or a transport error never reaches the streak
                    // recorder, and the pause must still hold for it tomorrow.
                    if ($t['pause_bypass'] !== null) {
                        PluginUpdateFailureStreak::updateOrCreate(
                            ['site_id' => $t['site']->id, 'target_kind' => $t['kind'], 'target_slug' => $t['slug']],
                            [
                                'last_target_version' => $t['target_version'],
                                'last_from_version' => $t['before_version'],
                                'last_retry_queued_at' => Carbon::now(),
                            ],
                        );
                    }

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
            ->carePlanEligible()
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
     * Plugins on this site whose installed version has a known
     * vulnerability, keyed by directory slug (the matcher's plugin_slug).
     * The value is the highest patched_in among those advisories that
     * have a fix newer than the installed version, or null when none do.
     *
     * @return array<string, string|null> directory slug => highest patched_in
     */
    private function vulnerableSlugsForSite(Site $site, PluginVulnerabilityMatcher $matcher): array
    {
        $hits = [];
        try {
            foreach ($matcher->forSite($site) as $row) {
                $slug = (string) ($row['plugin_slug'] ?? '');
                if ($slug === '') {
                    continue;
                }

                $highest = $hits[$slug] ?? null;
                $patchedIn = ($row['patch_available'] ?? false)
                    ? trim((string) ($row['vulnerability']->patched_in ?? ''))
                    : '';
                if ($patchedIn !== '' && ($highest === null || version_compare($patchedIn, $highest, '>'))) {
                    $highest = $patchedIn;
                }

                $hits[$slug] = $highest;
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
            ->carePlanEligible()
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
     * Drop tuples whose (site, kind, slug) is in PluginUpdateIgnore.
     *
     * Manual ignores always win. An auto-failure pause (the slug failed the
     * nightly loop N times in a row) is let through, and the tuple tagged
     * with why, when either:
     *   - the offered version differs from the version last tried (a new
     *     release since the pause). One attempt per new version: queueing
     *     the retry records it as the last target, however the job ends.
     *   - the installed version is vulnerable and the offered version is
     *     at or above the highest patched_in, so the update is the fix. A
     *     fix version not tried yet is tried at once; one that was tried is
     *     retried at most once every SECURITY_RETRY_BACKOFF_DAYS, and never
     *     when it last failed for a licence or offer reason (an operator
     *     has to sort that out first).
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
        $ignores = PluginUpdateIgnore::query()
            ->where(fn ($q) => $this->whereAnyTriple($q, $triples))
            ->get(['site_id', 'target_kind', 'target_slug', 'source'])
            ->keyBy(fn ($row) => "{$row->target_kind}:{$row->site_id}:{$row->target_slug}");

        if ($ignores->isEmpty()) {
            return $tuples;
        }

        $autoPaused = $ignores->filter(fn (PluginUpdateIgnore $row) => $row->isAutoFailure());
        $streaks = $autoPaused->isEmpty()
            ? collect()
            : PluginUpdateFailureStreak::query()
                ->where(fn ($q) => $this->whereAnyTriple(
                    $q,
                    $autoPaused->map(fn ($row) => [$row->site_id, $row->target_kind, $row->target_slug])->values()->all(),
                ))
                ->get(['site_id', 'target_kind', 'target_slug', 'last_target_version', 'reason_code', 'last_failed_at', 'last_retry_queued_at'])
                ->keyBy(fn ($row) => "{$row->target_kind}:{$row->site_id}:{$row->target_slug}");

        $kept = [];
        foreach ($tuples as $t) {
            $key = "{$t['kind']}:{$t['site']->id}:{$t['slug']}";
            $ignore = $ignores->get($key);
            if ($ignore === null) {
                $kept[] = $t;

                continue;
            }

            if (! $ignore->isAutoFailure()) {
                continue;
            }

            $bypass = $this->autoPauseBypassReason($t, $streaks->get($key));
            if ($bypass !== null) {
                $t['pause_bypass'] = $bypass;
                $kept[] = $t;
            }
        }

        return $kept;
    }

    /**
     * Why an auto-failure pause should not hold this tuple back tonight,
     * or null to keep it paused.
     *
     * @param  array<string, mixed>  $tuple
     */
    private function autoPauseBypassReason(array $tuple, ?PluginUpdateFailureStreak $streak): ?string
    {
        $offered = trim((string) ($tuple['target_version'] ?? ''));
        if ($offered === '') {
            return null;
        }

        // Without a recorded version there is nothing to compare against,
        // so a new release can't be told apart and the pause stands.
        $triedVersion = trim((string) ($streak?->last_target_version ?? ''));
        $isNewVersion = $triedVersion !== '' && $offered !== $triedVersion;

        $patchedIn = (string) ($tuple['patched_in'] ?? '');
        if ($tuple['is_vulnerable'] && $patchedIn !== '' && version_compare($offered, $patchedIn, '>=')) {
            if ($isNewVersion) {
                return self::BYPASS_SECURITY_FIX;
            }

            if (in_array($streak?->reason_code, self::OPERATOR_ONLY_REASONS, true)) {
                return null;
            }

            $lastTried = collect([$streak?->last_failed_at, $streak?->last_retry_queued_at])->filter()->max();

            return $lastTried === null || $lastTried->lessThanOrEqualTo(Carbon::now()->subDays(self::SECURITY_RETRY_BACKOFF_DAYS))
                ? self::BYPASS_SECURITY_FIX
                : null;
        }

        return $isNewVersion ? self::BYPASS_NEW_VERSION : null;
    }

    /**
     * The first fleet-wide nightly batch already queued during the current
     * local (LOCAL_TIMEZONE) calendar day, or null. Looks at created_at
     * rather than the date in the batch id, so it is right on 23- and
     * 25-hour DST days and for batches named before the id used the local
     * date. --site batches are excluded so an operator's single-site run
     * never stops the fleet's nightly.
     */
    private function fleetBatchForLocalDate(): ?string
    {
        $appTimezone = (string) config('app.timezone');
        $localStart = Carbon::now(self::LOCAL_TIMEZONE)->startOfDay();
        $localEnd = $localStart->copy()->addDay();

        $batchId = PluginUpdateJob::query()
            ->where('batch_id', 'like', self::BATCH_PREFIX.'%')
            ->where('batch_id', 'not like', self::BATCH_PREFIX.'%-'.self::SITE_BATCH_MARKER.'%')
            ->where('created_at', '>=', $localStart->copy()->setTimezone($appTimezone))
            ->where('created_at', '<', $localEnd->copy()->setTimezone($appTimezone))
            ->orderBy('id')
            ->value('batch_id');

        return is_string($batchId) ? $batchId : null;
    }

    /**
     * Directory part of a Companion plugin slug ("dir/file.php" → "dir");
     * a single-file plugin ("hello.php") is returned as-is. Same rule as
     * PluginVulnerabilityMatcher.
     */
    private static function directorySlug(string $slug): string
    {
        $dir = strtok($slug, '/');

        return $dir === false ? $slug : $dir;
    }

    /**
     * OR together one (site_id, target_kind, target_slug) match per triple.
     *
     * @param  list<array{0: int, 1: string, 2: string}>  $triples
     */
    private function whereAnyTriple(QueryBuilder $query, array $triples): void
    {
        foreach ($triples as [$siteId, $kind, $slug]) {
            $query->orWhere(function ($qq) use ($siteId, $kind, $slug) {
                $qq->where('site_id', $siteId)->where('target_kind', $kind)->where('target_slug', $slug);
            });
        }
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
