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
 * Any successful update (nightly or manual) resets the streak.
 */
class UpdateFailureStreakRecorder
{
    public const DEFAULT_THRESHOLD = 5;

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

        $this->incrementStreak($job);
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
            ->update(['consecutive_failures' => 0]);
    }

    private function incrementStreak(PluginUpdateJob $job): void
    {
        $streak = PluginUpdateFailureStreak::firstOrNew([
            'site_id' => $job->site_id,
            'target_kind' => $job->target_kind,
            'target_slug' => $job->target_slug,
        ]);

        $streak->consecutive_failures = (int) $streak->consecutive_failures + 1;
        $streak->last_target_version = $job->target_version;
        $streak->last_from_version = $job->before_version;
        $streak->last_error = mb_strimwidth((string) ($job->error ?? 'unknown error'), 0, 500, '…');
        $streak->last_failed_at = Carbon::now();
        $streak->last_job_id = $job->id;
        $streak->save();

        $settings = app(Settings::class);
        $threshold = max(self::MIN_THRESHOLD, min(self::MAX_THRESHOLD, (int) $settings->get('updates.auto_ignore_after_failures', self::DEFAULT_THRESHOLD)));
        $enabled = (bool) $settings->get('updates.auto_ignore_enabled', true);

        if ($enabled && $streak->consecutive_failures >= $threshold) {
            $this->applyAutoIgnore($job, $streak);
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

        // Operator already paused this slug by hand — do not rewrite it as
        // auto_failure or push it into wp-admin.
        if ($existingIgnore && $existingIgnore->isManual()) {
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

            $count = $ignore->failure_count ?? ($streak?->consecutive_failures ?? 5);
            $kindLabel = $ignore->target_kind === PluginUpdateJob::KIND_THEME ? 'theme' : 'plugin';

            $items[] = [
                'kind' => $ignore->target_kind,
                'slug' => $ignore->target_slug,
                'name' => (string) $name,
                'stopped_at' => $ignore->ignored_at?->toDateString() ?? Carbon::now()->toDateString(),
                'failure_count' => $count,
                'from_version' => $streak?->last_from_version ?? '?',
                'attempted_version' => $streak?->last_target_version ?? '?',
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
