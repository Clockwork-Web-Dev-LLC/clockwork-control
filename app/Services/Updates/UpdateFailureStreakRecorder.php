<?php

namespace App\Services\Updates;

use App\Models\ActionLog;
use App\Models\PluginUpdateFailureStreak;
use App\Models\PluginUpdateIgnore;
use App\Models\PluginUpdateJob;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Chat\ChatNotifier;
use App\Services\Companion\ClockworkCompanionClient;
use App\Support\Settings;
use App\Support\UpgraderOutput;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tracks consecutive nightly update failures per (site, kind, slug).
 *
 * When a plugin or theme fails the nightly loop repeatedly and crosses the
 * threshold (default 5), this service pauses automatic updates for that
 * specific slug by adding it to plugin_update_ignores (source=auto_failure,
 * client_visible=true).
 *
 * Other plugins on the site continue their regular care-plan updates.
 * Any successful update (nightly or manual) resets the streak. A successful
 * update that reaches its target version also lifts an auto-failure pause
 * for that slug and re-pushes the exceptions list, so the wp-admin notice
 * clears. (The nightly loop retries auto-paused slugs when a new release or
 * a security fix is offered; see RunNightlyPluginUpdates::filterIgnored.)
 */
class UpdateFailureStreakRecorder
{
    public const DEFAULT_THRESHOLD = 5;

    /** Clears the run of file-permission failures along with a streak. */
    public const FS_RUN_RESET = ['consecutive_fs_failures' => 0, 'fs_streak_started_job_id' => null];

    public const MIN_THRESHOLD = 3;

    public const MAX_THRESHOLD = 20;

    public function record(PluginUpdateJob $job, ?array $result = null, ?Throwable $exception = null): void
    {
        // Only plugins and themes are managed in the nightly loop.
        if (! in_array($job->target_kind, [PluginUpdateJob::KIND_PLUGIN, PluginUpdateJob::KIND_THEME], true)) {
            return;
        }

        if ($job->target_slug === null || $job->target_slug === '') {
            return;
        }

        // On any success (nightly or manual), reset the streak to 0.
        if ($job->status === PluginUpdateJob::STATUS_COMPLETE) {
            $this->resetStreak($job->site_id, $job->target_kind, $job->target_slug);

            if ($this->reachedTargetVersion($job)) {
                $this->liftAutoPause($job);
            }

            return;
        }

        // For failures: only count nightly runs initiated by automation.
        if ($job->status !== PluginUpdateJob::STATUS_FAILED) {
            return;
        }

        $isNightly = is_string($job->batch_id)
            && str_starts_with($job->batch_id, 'nightly-')
            && $job->requested_by_user_id === null;

        if (! $isNightly) {
            return;
        }

        // Increment only on plugin-level failures: Companion returned a body
        // (ok=false), or an in-job classifier set an error (stalled no-op /
        // reactivation). Skip transport, DNS, TLS, timeouts, and the case
        // where Companion succeeded but a later step threw — those mark the
        // job failed without meaning this plugin is un-updatable.
        if (! $this->isPluginLevelFailure($result)) {
            return;
        }

        $this->incrementStreak($job, $result);
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function isPluginLevelFailure(?array $result): bool
    {
        if (! is_array($result)) {
            return false;
        }

        if (($result['ok'] ?? false) === true && empty($result['error'])) {
            return false;
        }

        return true;
    }

    public function resetStreak(int $siteId, string $targetKind, string $targetSlug): void
    {
        PluginUpdateFailureStreak::query()
            ->where('site_id', $siteId)
            ->where('target_kind', $targetKind)
            ->where('target_slug', $targetSlug)
            ->update(['consecutive_failures' => 0, ...self::FS_RUN_RESET]);
    }

    /**
     * Did the job land on (or past) the version it was queued for? Needs
     * both versions: a completed job without them proves nothing about
     * whether the version that kept failing is now installed.
     */
    private function reachedTargetVersion(PluginUpdateJob $job): bool
    {
        $target = trim((string) ($job->target_version ?? ''));
        $after = trim((string) ($job->after_version ?? ''));

        return $target !== '' && $after !== '' && version_compare($after, $target, '>=');
    }

    /**
     * Remove this slug's auto-failure pause after a successful update:
     * delete the ignore row (manual ignores are left alone), clear the
     * streak's pause markers, and push the shorter exceptions list so the
     * client's wp-admin stops showing the plugin as paused.
     */
    private function liftAutoPause(PluginUpdateJob $job): void
    {
        $deleted = PluginUpdateIgnore::query()
            ->where('site_id', $job->site_id)
            ->where('target_kind', $job->target_kind)
            ->where('target_slug', $job->target_slug)
            ->where('source', PluginUpdateIgnore::SOURCE_AUTO_FAILURE)
            ->delete();

        if ($deleted === 0) {
            return;
        }

        PluginUpdateFailureStreak::query()
            ->where('site_id', $job->site_id)
            ->where('target_kind', $job->target_kind)
            ->where('target_slug', $job->target_slug)
            ->update([
                'consecutive_failures' => 0,
                ...self::FS_RUN_RESET,
                'ignored_at' => null,
                'ignore_id' => null,
            ]);

        Log::info('updates.auto_pause_lifted', [
            'site_id' => $job->site_id,
            'kind' => $job->target_kind,
            'slug' => $job->target_slug,
            'version' => $job->after_version,
            'job_id' => $job->id,
        ]);

        $site = $job->site;
        if ($site instanceof Site) {
            $this->maybePushExceptions($site);
        }
    }

    /**
     * Count one more plugin-level nightly failure and, at the threshold,
     * pause it. A run of file-permission failures (reason_code
     * fs_permission) as long as the threshold that affects the whole site
     * pauses the site once instead of auto-ignoring the plugin — see
     * pauseSiteForFilesystemFailure(). Failures for any other reason break
     * that run, so a plugin paused for, say, licence failures doesn't pause
     * the whole site on its first file-permission failure.
     *
     * @param  array<string, mixed>|null  $result
     */
    private function incrementStreak(PluginUpdateJob $job, ?array $result = null): void
    {
        $streak = PluginUpdateFailureStreak::firstOrNew([
            'site_id' => $job->site_id,
            'target_kind' => $job->target_kind,
            'target_slug' => $job->target_slug,
        ]);

        $streak->consecutive_failures = (int) $streak->consecutive_failures + 1;
        $streak->last_target_version = $job->target_version;
        $streak->last_from_version = $job->before_version;
        // last_error is copied into the ignore row, the chat alerts and the
        // site pause log, so it gets the same redaction as the job's own
        // columns even if the job error was written by another path.
        $streak->last_error = mb_strimwidth(UpgraderOutput::redactText((string) ($job->error ?? 'unknown error')), 0, 500, '…');
        $streak->reason_code = $job->reason_code ?? ($result['reason_code'] ?? null);
        if ($streak->reason_code === UpgraderOutput::REASON_FS_PERMISSION) {
            if ((int) $streak->consecutive_fs_failures === 0) {
                $streak->fs_streak_started_job_id = $job->id;
            }
            $streak->consecutive_fs_failures = (int) $streak->consecutive_fs_failures + 1;
        } else {
            $streak->consecutive_fs_failures = 0;
            $streak->fs_streak_started_job_id = null;
        }
        $streak->last_failed_at = Carbon::now();
        $streak->last_job_id = $job->id;
        $streak->save();

        $settings = app(Settings::class);
        $threshold = max(self::MIN_THRESHOLD, min(self::MAX_THRESHOLD, (int) $settings->get('updates.auto_ignore_after_failures', self::DEFAULT_THRESHOLD)));
        $enabled = (bool) $settings->get('updates.auto_ignore_enabled', true);

        if ($enabled && $streak->consecutive_failures >= $threshold) {
            if (
                $streak->reason_code === UpgraderOutput::REASON_FS_PERMISSION
                && (int) $streak->consecutive_fs_failures >= $threshold
                && $this->isSiteWideFilesystemFailure($job, $streak)
            ) {
                $this->pauseSiteForFilesystemFailure($job, $streak);

                return;
            }

            $this->applyAutoIgnore($job, $streak);
        }
    }

    /**
     * A file-permission failure is a site problem when no plugin or theme
     * update on the site has succeeded since this run of file-permission
     * failures began (the job recorded when it did; uncounted transport or
     * reaped failures in between don't move it). If one has, the site's
     * files are writable in general and only this plugin's own files are
     * off, so the plugin keeps the normal per-plugin auto-ignore rather than
     * stopping updates for every other plugin on the site. A run with no
     * recorded start (from before it was kept) gets the per-plugin ignore.
     */
    private function isSiteWideFilesystemFailure(PluginUpdateJob $job, PluginUpdateFailureStreak $streak): bool
    {
        $runStartJobId = $streak->fs_streak_started_job_id;
        if ($runStartJobId === null) {
            return false;
        }

        return ! PluginUpdateJob::query()
            ->where('site_id', $job->site_id)
            ->whereIn('target_kind', [PluginUpdateJob::KIND_PLUGIN, PluginUpdateJob::KIND_THEME])
            ->where('status', PluginUpdateJob::STATUS_COMPLETE)
            ->where('id', '>', $runStartJobId)
            ->exists();
    }

    /**
     * WordPress cannot write the site's files ("Could not copy file",
     * "Could not create directory …/wp-content/upgrade/…"), so every plugin
     * on the site fails the same way, night after night. Pausing each plugin
     * as it crosses the threshold turns one site problem into a pile of
     * per-plugin pauses (each with its own chat alert and a client-visible
     * "paused" notice in wp-admin) and hides the real cause. Instead, pause
     * nightly updates for the whole site once, through the same
     * auto_updates_paused flag operators use, with one action log and one
     * ops alert. No per-plugin ignore is written and nothing is pushed to
     * wp-admin. Ops fix the file ownership/permissions and resume the site
     * with the existing per-site toggle.
     */
    private function pauseSiteForFilesystemFailure(PluginUpdateJob $job, PluginUpdateFailureStreak $streak): void
    {
        $site = $job->site;
        if (! $site instanceof Site) {
            return;
        }

        $reason = mb_strimwidth(sprintf(
            'Filesystem not writable: %d nightly updates in a row failed with a file-permission error. Fix file ownership/permissions, then resume.',
            $streak->consecutive_fs_failures,
        ), 0, 255, '…');

        // Conditional update: the recorder runs after the per-site update
        // lock is released, so two of the site's jobs can reach this point
        // together; only the one that flips the flag logs and alerts. A site
        // that is already paused (by an operator, or an earlier failure)
        // keeps its existing reason and gets no second alert.
        $flipped = Site::query()
            ->whereKey($site->id)
            ->where('auto_updates_paused', false)
            ->update([
                'auto_updates_paused' => true,
                'auto_updates_paused_reason' => $reason,
            ]);

        if ($flipped === 0) {
            return;
        }

        $site->refresh();

        $name = $job->target_name ?: $job->target_slug;
        $kindLabel = $job->target_kind === PluginUpdateJob::KIND_THEME ? 'theme' : 'plugin';

        try {
            app(ActionLogger::class)->record(
                actionType: ActionLog::TYPE_AUTO_UPDATES_TOGGLED,
                summary: "Paused nightly auto-updates on {$site->domain}: filesystem not writable ({$streak->consecutive_fs_failures} failed nightly updates in a row)",
                site: $site,
                target: 'paused',
                details: [
                    'previous' => false,
                    'now' => true,
                    'reason' => $reason,
                    'reason_code' => UpgraderOutput::REASON_FS_PERMISSION,
                    'target_kind' => $job->target_kind,
                    'target_slug' => $job->target_slug,
                    'streak' => $streak->consecutive_fs_failures,
                    'from_version' => $streak->last_from_version,
                    'target_version' => $streak->last_target_version,
                    'last_error' => $streak->last_error,
                    'job_id' => $job->id,
                ],
                ok: true,
                actor: 'auto'
            );
        } catch (Throwable $e) {
            Log::warning('updates.site_pause_action_log_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Same gate as the per-plugin alerts (ChatNotifierDispatcher::
        // dispatchForSite): inactive sites stay quiet.
        if ($site->is_inactive) {
            return;
        }

        try {
            $title = sprintf(':pause_button: Paused nightly auto-updates on %s: filesystem not writable', $site->domain);
            app(ChatNotifier::class)->send($title, [[
                'fallback' => $title,
                'color' => '#f59e0b',
                'title' => $title,
                'text' => sprintf(
                    '%d nightly updates in a row failed because WordPress could not write the files, so nightly updates are paused for the whole site instead of plugin by plugin. Make wp-content/plugins and wp-content/upgrade writable by the PHP user, then resume auto-updates for the site.',
                    $streak->consecutive_fs_failures,
                ),
                'fields' => [
                    ['title' => 'Site', 'value' => $site->domain, 'short' => true],
                    ['title' => 'Last failure', 'value' => sprintf('%s %s (%s → %s)', $kindLabel, $name, $streak->last_from_version ?: '?', $streak->last_target_version ?: '?'), 'short' => true],
                    ['title' => 'Last error', 'value' => mb_strimwidth((string) $streak->last_error, 0, 280, '…'), 'short' => false],
                ],
            ]]);
        } catch (Throwable $e) {
            Log::warning('chat_notifier.site_auto_updates_paused_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function applyAutoIgnore(PluginUpdateJob $job, PluginUpdateFailureStreak $streak): void
    {
        $site = $job->site;
        if (! $site instanceof Site) {
            return;
        }

        $existingIgnore = PluginUpdateIgnore::query()
            ->where('site_id', $job->site_id)
            ->where('target_kind', $job->target_kind)
            ->where('target_slug', $job->target_slug)
            ->first();

        // Operator already paused this slug by hand (or the nightly loop held
        // it as a different theme) — do not rewrite it as auto_failure or
        // push it into wp-admin.
        if ($existingIgnore && ! $existingIgnore->isAutoFailure()) {
            return;
        }

        // If already auto-ignored, simply keep error and failure count up-to-date.
        if ($existingIgnore && $streak->ignored_at !== null) {
            $existingIgnore->update([
                'failure_count' => $streak->consecutive_failures,
                'last_error' => $streak->last_error,
            ]);

            return;
        }

        $name = $job->target_name ?: $job->target_slug;
        $kindLabel = $job->target_kind === PluginUpdateJob::KIND_THEME ? 'theme' : 'plugin';

        $note = sprintf(
            'Automatically paused after %d consecutive nightly update failures (last: %s → %s).',
            $streak->consecutive_failures,
            $streak->last_from_version ?: '?',
            $streak->last_target_version ?: '?'
        );

        $ignore = PluginUpdateIgnore::updateOrCreate(
            [
                'site_id' => $job->site_id,
                'target_kind' => $job->target_kind,
                'target_slug' => $job->target_slug,
            ],
            [
                'source' => PluginUpdateIgnore::SOURCE_AUTO_FAILURE,
                'failure_count' => $streak->consecutive_failures,
                'last_error' => $streak->last_error,
                'client_visible' => true,
                'note' => $note,
                'ignored_at' => Carbon::now(),
                'ignored_by_user_id' => null,
            ]
        );

        $streak->update([
            'ignored_at' => Carbon::now(),
            'ignore_id' => $ignore->id,
        ]);

        $actionType = $job->target_kind === PluginUpdateJob::KIND_THEME
            ? ActionLog::TYPE_THEME_UPDATE_AUTO_IGNORED
            : ActionLog::TYPE_PLUGIN_UPDATE_AUTO_IGNORED;

        try {
            app(ActionLogger::class)->record(
                actionType: $actionType,
                summary: "Automatic updates paused for {$name} after {$streak->consecutive_failures} failures",
                site: $site,
                target: $job->target_slug,
                details: [
                    'streak' => $streak->consecutive_failures,
                    'from_version' => $streak->last_from_version,
                    'target_version' => $streak->last_target_version,
                    'last_error' => $streak->last_error,
                    'job_id' => $job->id,
                ],
                ok: true,
                actor: 'auto'
            );
        } catch (Throwable $e) {
            Log::warning('updates.auto_ignore_action_log_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            app(ChatNotifier::class)->pluginUpdateAutoIgnored($site, $job, $streak->consecutive_failures);
        } catch (Throwable $e) {
            Log::warning('chat_notifier.plugin_update_auto_ignored_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->maybePushExceptions($site);
    }

    /**
     * Push active update exceptions to WordPress wp-admin via Companion or Renegade.
     * Full-list replace: empty array clears any previous notice.
     */
    public function maybePushExceptions(Site $site): bool
    {
        if (! $site->companion_installed || $site->is_inactive) {
            return false;
        }

        $capabilities = is_array($site->companion_capabilities) ? $site->companion_capabilities : [];
        if (! in_array('update-exceptions', $capabilities, true)) {
            // Target site does not yet advertise the update-exceptions capability.
            // Pushes stay a no-op until Companion / Renegade is upgraded.
            return false;
        }

        $payload = $this->buildExceptionsPayload($site);

        try {
            $client = new ClockworkCompanionClient($site);
            $client->pushUpdateExceptions($payload);

            PluginUpdateFailureStreak::query()
                ->where('site_id', $site->id)
                ->whereNotNull('ignored_at')
                ->update(['companion_pushed_at' => Carbon::now()]);

            return true;
        } catch (Throwable $e) {
            Log::warning('updates.push_exceptions_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array{generated_at: string, site_domain: string, items: list<array<string, mixed>>}
     */
    public function buildExceptionsPayload(Site $site): array
    {
        $ignores = PluginUpdateIgnore::query()
            ->where('site_id', $site->id)
            ->where('client_visible', true)
            ->get();

        $streaks = PluginUpdateFailureStreak::query()
            ->where('site_id', $site->id)
            ->get()
            ->keyBy(fn ($s) => "{$s->target_kind}:{$s->target_slug}");

        $snapshotPlugins = collect($site->companion_snapshot['plugins']['plugins'] ?? [])
            ->keyBy(fn ($p) => (string) ($p['slug'] ?? ''));

        $snapshotThemes = collect($site->companion_snapshot['themes']['items'] ?? [])
            ->keyBy(fn ($t) => (string) ($t['slug'] ?? ''));

        $items = [];
        foreach ($ignores as $ignore) {
            $key = "{$ignore->target_kind}:{$ignore->target_slug}";
            $streak = $streaks->get($key);

            $name = $ignore->target_slug;
            if ($ignore->target_kind === PluginUpdateJob::KIND_THEME) {
                $name = $snapshotThemes->get($ignore->target_slug)['name'] ?? $ignore->target_slug;
            } else {
                $name = $snapshotPlugins->get($ignore->target_slug)['name'] ?? $ignore->target_slug;
            }

            $count = $ignore->failure_count ?? ($streak->consecutive_failures ?? 5);
            $kindLabel = $ignore->target_kind === PluginUpdateJob::KIND_THEME ? 'theme' : 'plugin';

            $items[] = [
                'kind' => $ignore->target_kind,
                'slug' => $ignore->target_slug,
                'name' => (string) $name,
                'stopped_at' => $ignore->ignored_at?->toDateString() ?? Carbon::now()->toDateString(),
                'failure_count' => $count,
                'from_version' => $streak->last_from_version ?? '?',
                'attempted_version' => $streak->last_target_version ?? '?',
                'reason_public' => sprintf(
                    'Automatic updates did not complete after %d attempts. Clockwork has paused automatic updates for this %s. Other plugins on this site are still updated automatically.',
                    $count,
                    $kindLabel
                ),
                'status' => 'paused',
            ];
        }

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'site_domain' => $site->domain,
            'items' => $items,
        ];
    }
}
