<?php

namespace App\Jobs;

use App\Models\PluginUpdateJob;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Chat\ChatNotifier;
use App\Services\Companion\ClockworkCompanionClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base class for all four update-runner jobs (plugin/theme/core/translation).
 *
 * Why a base class instead of one job per row: the lifecycle is identical —
 * load row, acquire site lock, mark running, call Companion, persist result,
 * write action_log, release lock. The only delta is which Companion method
 * gets called and how the response shape maps to plugin_update_jobs columns.
 * Subclasses implement run(Site, PluginUpdateJob): array (the raw Companion
 * response, normalized to a flat associative array). The base class drives
 * everything else.
 *
 * Per-site Cache::lock: prevents two concurrent updates targeting the same
 * site (e.g. plugin update + theme update would both run wp_filesystem and
 * race on wp-content/upgrade/). On contention the job re-queues with
 * release(15) so the second one waits behind the first.
 *
 * tries=1: never auto-retry an upgrade. WP file replacement is partially
 * idempotent at best. Failed jobs land in failed_jobs for the operator to
 * inspect.
 *
 * **Timeout hazard (found 2026-08-28, fixed here + in failed()).** A job
 * that exceeds $timeout is killed by Laravel's SIGALRM handler, which calls
 * posix_kill(SIGKILL) + exit() on the worker process — the handle() method's
 * `finally { $lock->release(); }` never runs, because the process dies
 * mid-instruction rather than unwinding normally. The lock (previously TTL
 * 300s, well past the 180s job timeout) was then stuck for its full
 * remaining life, and every other queued update for that site piled up in
 * 'pending' re-queuing every 15s against a lock nobody would ever release —
 * exactly the pattern behind dozens of sites' worth of "orphaned in pending"
 * reaper failures. Laravel DOES call $job->fail($e) — and therefore this
 * class's failed() below — synchronously in the SIGALRM handler BEFORE the
 * kill, in the same still-alive process, specifically because tries=1 means
 * attempts() >= maxTries is already true on the very first timeout. That's
 * a real hook, not a race: failed() force-releases the lock and marks the
 * row failed there. The TTL is also tightened to just past $timeout (rather
 * than an independent, much longer value) as defense-in-depth for the rarer
 * case of a true unrecoverable crash (e.g. OOM) where failed() itself can't
 * run.
 */
abstract class AbstractRunUpdate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public int $backoff = 30;

    /**
     * Lock TTL, seconds. Kept just past $timeout (not an independent, much
     * longer value) so a lock that survives to expiry naturally — because
     * failed() below couldn't run, e.g. a true OOM kill — self-heals within
     * seconds of when the job should have finished, not minutes after.
     */
    private const LOCK_TTL_SECONDS = 200;

    public function __construct(public readonly int $jobRowId)
    {
        $this->onQueue('plugin-updates');
    }

    public function handle(ActionLogger $logger): void
    {
        $row = PluginUpdateJob::with('site.server')->find($this->jobRowId);
        if (! $row) {
            return;
        }
        $site = $row->site;
        if (! $site instanceof Site) {
            return;
        }
        if ($row->status !== PluginUpdateJob::STATUS_PENDING) {
            return;
        }

        $lock = Cache::lock("site_update:{$row->site_id}", self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            // Another update is already running on this site — re-queue
            // behind it. The 15s delay is empirical: long enough that the
            // worker doesn't burn CPU spinning, short enough that the user
            // sees movement on the page.
            $this->release(15);

            return;
        }

        try {
            $row->forceFill([
                'status' => PluginUpdateJob::STATUS_RUNNING,
                'started_at' => now(),
            ])->save();

            // Capture the site's active state from the local snapshot cache
            // before touching the filesystem. Zero latency — reads from DB.
            $stateBefore = $this->captureStateBefore($site);
            if ($stateBefore !== null) {
                $row->forceFill(['state_before' => $stateBefore])->save();
            }

            $client = new ClockworkCompanionClient($site);
            $result = $this->run($site, $row, $client);

            $ok = (bool) ($result['ok'] ?? false);

            // A "stalled no-op" masquerading as success: Companion re-checks
            // WordPress's own update transient fresh right before applying
            // the update (bypassing our cached snapshot, which may be
            // several hours stale) — if THAT check no longer offers an
            // update, Companion correctly reports ok=true/no-op from its own
            // point of view. But if we specifically queued this job to reach
            // a known target_version and the site is still on the same
            // version it started at, nothing actually changed and reporting
            // it as a clean success is misleading — the fleet page still
            // shows the update as pending (it reads our separately-cached
            // snapshot), so an operator sees a green "complete" job sitting
            // right next to a still-pending update for the exact same
            // plugin. Confirmed live 2026-09-01: js_composer (WPBakery, a
            // premium/licensed plugin — exactly the kind whose own update
            // source is prone to going stale between our snapshot poll and
            // the moment we act on it) reported ok=true, before=after=8.7.2,
            // while target_version was 9.0.1. Only applies when we had a
            // real target_version to compare against (core/plugin/theme;
            // translations has none, so this never fires for that kind).
            $afterVersion = $result['after_version'] ?? null;
            $beforeVersion = $result['before_version'] ?? $row->before_version;
            if (
                $ok
                && $row->target_version !== null
                && $afterVersion !== null
                && $afterVersion === $beforeVersion
                && $afterVersion !== $row->target_version
            ) {
                $ok = false;
                $result['error'] = sprintf(
                    "WordPress's own update-checker no longer offered this update when the job ran (still on %s, expected %s). Common for premium/licensed plugins whose update source went stale or unreachable between our last check and now. Nothing was changed on the site.",
                    $afterVersion,
                    $row->target_version,
                );
            }

            // 'upgrade_completed' (Companion >= 1.30.2) tells us the file
            // swap itself went through even when the overall call reports
            // ok=false (e.g. the plugin's own reactivation failed). We still
            // want to run verify in that case — it checks the whole site's
            // active-plugin list, so it can catch and repair OTHER plugins
            // knocked out as collateral damage even when this one can't be
            // saved. Older Companion versions don't send this key; ?? $ok
            // preserves the previous behavior for them — including the
            // stalled-no-op check above, which by this point has already
            // corrected $ok to false for a true no-op (nothing to verify).
            $upgradeCompleted = (bool) ($result['upgrade_completed'] ?? $ok);

            // Post-update verification: if the update succeeded and the site
            // has Companion >= 1.21.3, ask Companion to check that active
            // plugins and the active theme still match what they were before.
            // Any discrepancies are repaired in place; repairs are logged.
            // Best-effort — a verify failure never marks the update as failed.
            if ($upgradeCompleted && $stateBefore !== null
                && in_array('post-update-verify', $site->companion_capabilities ?? [], true)
            ) {
                try {
                    $verifyResult = $client->verifyAndRepair($stateBefore);
                    $result['repairs'] = $verifyResult['repairs'] ?? [];
                } catch (Throwable) {
                    // best-effort
                }
            }

            // Defense-in-depth for the fleet rollout window: a plugin that
            // was active before this update and isn't after it is a failure
            // regardless of what Companion's own `ok` said — sites running
            // a Companion older than 1.30.2 still have the bug where Runner
            // reports ok=true here (clockwork-companion Runner::run()).
            // Only exception: post-update-verify above already re-activated
            // this exact slug, in which case the site is actually fine.
            if (($result['was_active'] ?? false) && ! ($result['reactivated'] ?? true)) {
                $repairedTarget = collect($result['repairs'] ?? [])->contains(
                    fn ($r) => ($r['type'] ?? null) === 'plugin_reactivated'
                        && ($r['slug'] ?? null) === ($result['slug'] ?? null)
                );

                if ($repairedTarget) {
                    $ok = true;
                    $result['reactivated'] = true;
                } else {
                    $ok = false;
                    $result['error'] = $result['error'] ?? sprintf(
                        'reactivation_failed: %s was active before the update but is not active after it.',
                        $result['slug'] ?? (string) $row->target_slug,
                    );
                }
            }

            $row->forceFill([
                'status' => $ok ? PluginUpdateJob::STATUS_COMPLETE : PluginUpdateJob::STATUS_FAILED,
                'before_version' => $result['before_version'] ?? $row->before_version,
                'after_version' => $result['after_version'] ?? null,
                'was_active' => $result['was_active'] ?? null,
                'reactivated' => $result['reactivated'] ?? null,
                'elapsed_ms' => $result['elapsed_ms'] ?? null,
                'messages' => $result['messages'] ?? null,
                'repairs' => $result['repairs'] ?? null,
                'error' => $ok ? null : (string) ($result['error'] ?? 'unknown error'),
                'completed_at' => now(),
            ])->save();

            $summary = $this->actionLogSummary($row, $result);
            $repairs = $result['repairs'] ?? [];
            if (is_array($repairs) && $repairs !== []) {
                $repairCount = count($repairs);
                $repairDesc = implode(', ', array_map(
                    fn ($r) => ($r['type'] ?? '?').': '.($r['slug'] ?? '?'),
                    array_slice($repairs, 0, 3),
                ));
                if ($repairCount > 3) {
                    $repairDesc .= sprintf(' +%d more', $repairCount - 3);
                }
                $summary .= sprintf(' [%d repair%s: %s]', $repairCount, $repairCount === 1 ? '' : 's', $repairDesc);
            }

            $logger->record(
                actionType: $this->actionLogType(),
                summary: $summary,
                site: $site,
                target: $this->actionLogTarget($row),
                details: $result,
                ok: $ok,
                error: $ok ? null : (string) ($result['error'] ?? null),
                elapsedMs: $result['elapsed_ms'] ?? null,
                actor: $row->requested_by_user_id !== null ? "user:{$row->requested_by_user_id}" : 'auto',
            );
        } catch (Throwable $e) {
            $row->forceFill([
                'status' => PluginUpdateJob::STATUS_FAILED,
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ])->save();

            $logger->record(
                actionType: $this->actionLogType(),
                summary: "Update threw: {$e->getMessage()}",
                site: $site,
                target: $this->actionLogTarget($row),
                ok: false,
                error: $e->getMessage(),
                actor: $row->requested_by_user_id !== null ? "user:{$row->requested_by_user_id}" : 'auto',
            );

            // Don't rethrow — keeping the job out of failed_jobs because we
            // already wrote a failure row to plugin_update_jobs. The page
            // surfaces it from there.
        } finally {
            $lock->release();

            $this->maybeRefreshSnapshot($row);

            // Real-time Mattermost ping for nightly auto-update failures.
            // Manual bulk-update failures stay quiet — the operator is
            // already watching the /updates page and doesn't want their
            // channel buzzing on every retryable hiccup. The batch_id
            // prefix `nightly-` is the trigger; clockwork:run-nightly-
            // plugin-updates is the only writer.
            if (
                $row->status === PluginUpdateJob::STATUS_FAILED
                && is_string($row->batch_id)
                && str_starts_with($row->batch_id, 'nightly-')
            ) {
                try {
                    app(ChatNotifier::class)
                        ->pluginUpdateFailed($site, $row->refresh());
                } catch (Throwable $e) {
                    Log::warning('mattermost.plugin_update_failed_ping_threw', [
                        'job_id' => $row->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Laravel calls this synchronously — in the still-alive worker process,
     * before the SIGKILL — whenever a queued attempt is about to exceed
     * $tries. With tries=1 that includes every timeout, which is exactly
     * the case handle()'s own finally{} can never reach (see class
     * docblock). This is the real cleanup path for that case: force-release
     * the per-site lock (we don't hold the original lock instance here, so
     * a normal ->release() ownership check isn't possible — forceRelease()
     * is the correct call), and mark the row failed if handle() never got
     * the chance to.
     *
     * Deliberately narrow: only touches the lock + this row. Does not retry,
     * does not fire Mattermost, does not trigger a snapshot refresh — those
     * stay in handle()'s finally{} for the normal-completion path. A timed-
     * out row is still visible on the Updates page as failed either way.
     */
    public function failed(?Throwable $exception): void
    {
        $row = PluginUpdateJob::find($this->jobRowId);

        if ($row && in_array($row->status, PluginUpdateJob::LIVE_STATUSES, true)) {
            $row->forceFill([
                'status' => PluginUpdateJob::STATUS_FAILED,
                'error' => 'Job timed out after '.$this->timeout.'s (or otherwise failed before completing): '
                    .($exception?->getMessage() ?? 'no exception captured'),
                'completed_at' => now(),
            ])->save();
        }

        if ($row) {
            Cache::lock("site_update:{$row->site_id}", self::LOCK_TTL_SECONDS)->forceRelease();
        }
    }

    /**
     * Subclass calls Companion and returns the response array.
     *
     * @return array<string, mixed>
     */
    abstract protected function run(Site $site, PluginUpdateJob $row, ClockworkCompanionClient $client): array;

    abstract protected function actionLogType(): string;

    abstract protected function actionLogTarget(PluginUpdateJob $row): ?string;

    /**
     * @param  array<string, mixed>  $result
     */
    abstract protected function actionLogSummary(PluginUpdateJob $row, array $result): string;

    /**
     * Extract the site's active-plugin list and active theme from the locally-
     * cached companion_snapshot. Returns null when no snapshot is available.
     *
     * Using the local cache avoids an extra Companion API call on every update
     * job (which would add 1-60s latency). For nightly runs the snapshot is
     * ~30 min old (refreshed at 01:30 ET, updates start at 02:00 ET) — fresh
     * enough to be authoritative. For manual daytime runs it may be older;
     * snapshot_age_minutes is included so the caller can decide how to weight
     * the data.
     *
     * @return array{captured_at: string, snapshot_age_minutes: int, active_plugins: list<string>, network_active_plugins: list<string>, stylesheet: string, template: string}|null
     */
    private function captureStateBefore(Site $site): ?array
    {
        $snapshot = $site->companion_snapshot;
        if (! is_array($snapshot)) {
            return null;
        }

        // Split active plugins into per-site vs network-active sets. On multisite,
        // a network-active plugin (Beaver Builder on a multisite design, etc.)
        // lives in `wp_sitemeta.active_sitewide_plugins`, NOT `active_plugins` on
        // the main blog — so the post-update verifier needs to re-activate it via
        // `activate_plugin($slug, '', true)` (the network-wide branch) if WordPress's
        // filesystem-swap quietly deactivates it during an upgrade.
        //
        // Companion 1.22.2+ surfaces a `network_active` flag per plugin in the
        // snapshot. Older snapshots don't have it — those sites continue to use
        // only the per-site list, which is the pre-fix behavior (i.e. multisite
        // network-active plugins stay unprotected until the snapshot refreshes
        // post-Companion-upgrade).
        $activePlugins = [];
        $networkActivePlugins = [];
        foreach ($snapshot['plugins']['plugins'] ?? [] as $p) {
            if (! is_array($p) || empty($p['active']) || ! isset($p['slug'])) {
                continue;
            }
            $slug = (string) $p['slug'];
            if (! empty($p['network_active'])) {
                $networkActivePlugins[] = $slug;
            } else {
                $activePlugins[] = $slug;
            }
        }

        // Active theme slug and parent-theme slug.
        $stylesheet = '';
        $template = '';
        foreach ($snapshot['themes']['items'] ?? [] as $t) {
            if (is_array($t) && ! empty($t['active'])) {
                $stylesheet = (string) ($t['slug'] ?? '');
                // The snapshot shape doesn't include a separate template field
                // (parent theme slug) — that's the same as stylesheet for non-
                // child themes. For child themes the parent is tracked separately
                // in WordPress's get_template() / 'template' option, which we
                // don't currently pull into the snapshot. Default to stylesheet
                // so the verify step at least protects the child-theme slug.
                $template = $stylesheet;
                break;
            }
        }

        $snapshotAt = $site->companion_snapshot_at;
        $ageMinutes = $snapshotAt !== null
            ? (int) round(now()->diffInMinutes($snapshotAt))
            : 0;

        return [
            'captured_at' => $snapshotAt?->toIso8601String() ?? '',
            'snapshot_age_minutes' => $ageMinutes,
            'active_plugins' => $activePlugins,
            'network_active_plugins' => $networkActivePlugins,
            'stylesheet' => $stylesheet,
            'template' => $template,
        ];
    }

    /**
     * When this job is the last live row in its batch, kick off snapshot
     * refreshes for all affected sites so the page reflects the new versions
     * within ~30s instead of waiting for the 15-min scheduled refresh.
     */
    private function maybeRefreshSnapshot(PluginUpdateJob $row): void
    {
        $stillLive = PluginUpdateJob::where('batch_id', $row->batch_id)
            ->whereIn('status', PluginUpdateJob::LIVE_STATUSES)
            ->exists();

        if ($stillLive) {
            return;
        }

        $siteIds = PluginUpdateJob::where('batch_id', $row->batch_id)
            ->pluck('site_id')
            ->unique()
            ->values();

        // Fire-and-forget per-site refresh. The refresh command itself is
        // idempotent and short (~1-2s), so dispatching synchronously through
        // Artisan::call would be fine — but using the queue keeps the worker
        // free immediately.
        foreach ($siteIds as $siteId) {
            $site = Site::find($siteId);
            if (! $site || ! $site->companion_installed) {
                continue;
            }
            Artisan::queue('clockwork:refresh-companion-snapshot', [
                '--site' => $site->domain,
            ]);
        }
    }
}
