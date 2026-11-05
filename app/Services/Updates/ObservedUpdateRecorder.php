<?php

namespace App\Services\Updates;

use App\Models\ActionLog;
use App\Models\PluginUpdateJob;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notes the plugin, theme and WordPress core updates that happened on a site
 * without Control: WordPress's own auto-updates, another management tool, or
 * someone in wp-admin. Control only logs the updates it runs, so a monthly
 * report built from those logs undercounts, or claims nothing was updated,
 * on sites where something else updates first.
 *
 * Each time a new Companion snapshot replaces the previous one (the Site
 * model's updated hook calls recordSnapshotChange()), the installed versions
 * are compared, and every version that went UP gets one action log:
 * plugin_update_observed / theme_update_observed / core_update_observed,
 * details {slug, name, from_version, to_version, observed_at,
 * previous_snapshot_at}, actor "observed", ran_at = the new snapshot's time.
 * The change happened somewhere between previous_snapshot_at and
 * observed_at.
 *
 * Skipped:
 *   - changes Control made itself: a plugin_update_jobs row for that site
 *     and slug (any core job for core) created in the last
 *     CONTROL_WINDOW_HOURS, or since the previous snapshot if that is
 *     longer ago, that completed, is still running, or ended on exactly
 *     the new version;
 *   - downgrades (a rollback or a restored backup is not an update);
 *   - items added or removed between the snapshots;
 *   - a change already logged (same type, slug and new version), so a
 *     snapshot that flips back and forth (a stale edge cache) logs once.
 *
 * Informational only: the rows are local-only (ActionLog::LOCAL_ONLY_TYPES,
 * never mirrored to the client's wp-admin), and nothing here touches update
 * jobs, failure streaks, auto-pauses or chat.
 */
class ObservedUpdateRecorder
{
    public const CONTROL_WINDOW_HOURS = 48;

    public const ACTOR = 'observed';

    public const CORE_SLUG = 'wordpress';

    /**
     * Compare the snapshot a save is replacing with the one it wrote. Call
     * from the Site model's updated hook, where getOriginal() still holds
     * the previous values. Never throws.
     */
    public function recordSnapshotChange(Site $site): int
    {
        try {
            $previous = $site->getOriginal('companion_snapshot');
            $current = $site->companion_snapshot;
            if (! is_array($previous) || ! is_array($current)) {
                return 0;
            }

            $previousAt = $site->getOriginal('companion_snapshot_at');
            $observedAt = $site->wasChanged('companion_snapshot_at') && $site->companion_snapshot_at !== null
                ? $site->companion_snapshot_at
                : Carbon::now();

            return $this->record(
                $site,
                $previous,
                $current,
                $previousAt instanceof \DateTimeInterface ? Carbon::instance($previousAt) : null,
                Carbon::instance($observedAt),
            );
        } catch (Throwable $e) {
            Log::warning('updates.observed_update_record_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return int rows written
     */
    public function record(Site $site, array $previous, array $current, ?Carbon $previousAt, Carbon $observedAt): int
    {
        $changes = [];
        foreach ($this->kinds() as $kind => [$actionType, $extract]) {
            $before = $extract($previous);
            $after = $extract($current);
            foreach ($after as $slug => $item) {
                $from = $before[$slug]['version'] ?? '';
                $to = $item['version'];
                if ($from === '' || $to === '' || version_compare($to, $from, '<=')) {
                    continue;
                }
                $changes[] = [
                    'kind' => $kind,
                    'action_type' => $actionType,
                    'slug' => (string) $slug,
                    'name' => $item['name'] !== '' ? $item['name'] : ($before[$slug]['name'] ?? (string) $slug),
                    'from' => $from,
                    'to' => $to,
                ];
            }
        }

        if ($changes === []) {
            return 0;
        }

        $windowStart = $observedAt->copy()->subHours(self::CONTROL_WINDOW_HOURS);
        if ($previousAt !== null && $previousAt->lessThan($windowStart)) {
            $windowStart = $previousAt->copy();
        }

        $controlJobs = PluginUpdateJob::query()
            ->where('site_id', $site->id)
            ->where('created_at', '>=', $windowStart)
            ->get(['target_kind', 'target_slug', 'status', 'after_version']);

        $alreadyLogged = ActionLog::query()
            ->where('site_id', $site->id)
            ->whereIn('action_type', ActionLog::OBSERVED_UPDATE_TYPES)
            ->get(['action_type', 'target', 'details'])
            ->map(fn (ActionLog $row) => $row->action_type.'|'.$row->target.'|'.($row->details['to_version'] ?? ''))
            ->flip();

        $written = 0;
        foreach ($changes as $c) {
            if ($this->controlMadeIt($controlJobs, $c['kind'], $c['slug'], $c['to'])) {
                continue;
            }
            if ($alreadyLogged->has($c['action_type'].'|'.$c['slug'].'|'.$c['to'])) {
                continue;
            }

            $label = match ($c['kind']) {
                PluginUpdateJob::KIND_THEME => "Theme updated outside Control: {$c['name']}",
                PluginUpdateJob::KIND_CORE => 'WordPress core updated outside Control:',
                default => "Plugin updated outside Control: {$c['name']}",
            };

            $row = app(ActionLogger::class)->record(
                actionType: $c['action_type'],
                summary: "{$label} {$c['from']} → {$c['to']}",
                site: $site,
                target: $c['slug'],
                details: [
                    'slug' => $c['slug'],
                    'name' => $c['name'],
                    'from_version' => $c['from'],
                    'to_version' => $c['to'],
                    'observed_at' => $observedAt->toIso8601String(),
                    'previous_snapshot_at' => $previousAt?->toIso8601String(),
                ],
                ok: true,
                actor: self::ACTOR,
                ranAt: $observedAt,
            );

            if ($row !== null) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * @param  Collection<int, PluginUpdateJob>  $jobs
     */
    private function controlMadeIt(Collection $jobs, string $kind, string $slug, string $toVersion): bool
    {
        return $jobs->contains(function (PluginUpdateJob $job) use ($kind, $slug, $toVersion) {
            if ($job->target_kind !== $kind) {
                return false;
            }
            if ($kind !== PluginUpdateJob::KIND_CORE && $job->target_slug !== $slug) {
                return false;
            }

            return in_array($job->status, [PluginUpdateJob::STATUS_COMPLETE, ...PluginUpdateJob::LIVE_STATUSES], true)
                || ($job->after_version !== null && (string) $job->after_version === $toVersion);
        });
    }

    /**
     * Per kind: the action type and how to read slug => {name, version}
     * from a snapshot.
     *
     * @return array<string, array{0: string, 1: callable(array<string, mixed>): array<string, array{name: string, version: string}>}>
     */
    private function kinds(): array
    {
        $list = fn (string $group, string $key) => function (array $snapshot) use ($group, $key): array {
            $items = $snapshot[$group][$key] ?? null;
            if (! is_array($items)) {
                return [];
            }
            $out = [];
            foreach ($items as $item) {
                if (! is_array($item) || ! isset($item['slug']) || ! is_scalar($item['slug']) || (string) $item['slug'] === '') {
                    continue;
                }
                $out[(string) $item['slug']] = [
                    'name' => is_scalar($item['name'] ?? null) ? trim((string) $item['name']) : '',
                    'version' => is_scalar($item['version'] ?? null) ? trim((string) $item['version']) : '',
                ];
            }

            return $out;
        };

        $core = function (array $snapshot): array {
            $version = $snapshot['wp_core']['current_version'] ?? null;

            return is_scalar($version) && trim((string) $version) !== ''
                ? [self::CORE_SLUG => ['name' => 'WordPress', 'version' => trim((string) $version)]]
                : [];
        };

        return [
            PluginUpdateJob::KIND_PLUGIN => [ActionLog::TYPE_PLUGIN_UPDATE_OBSERVED, $list('plugins', 'plugins')],
            PluginUpdateJob::KIND_THEME => [ActionLog::TYPE_THEME_UPDATE_OBSERVED, $list('themes', 'items')],
            PluginUpdateJob::KIND_CORE => [ActionLog::TYPE_CORE_UPDATE_OBSERVED, $core],
        ];
    }
}
