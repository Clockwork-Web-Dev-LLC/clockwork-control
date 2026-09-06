<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Pressable\PressableClient;
use Throwable;

class RefreshCompanionSnapshot extends Command
{
    protected $signature = 'clockwork:refresh-companion-snapshot
        {--site= : Limit to a single site (id or domain)}
        {--server= : Limit to sites on one server (id, name, or hostname)}
        {--pending-updates-only : Only refresh sites where SpinupWP reports pending plugin/theme/core updates but the cached snapshot shows none — used after import-spinupwp to reconcile the two sources}';

    protected $description = 'Pull /snapshot from each Companion-equipped site and cache it in sites.companion_snapshot.';

    public function handle(PressableClient $pressable): int
    {
        $sites = $this->resolveSites();
        if ($sites->isEmpty()) {
            $this->warn('No Companion-equipped sites matched.');

            return self::SUCCESS;
        }

        $stats = ['ok' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($sites as $site) {
            // Snapshot capability was added in plugin v1.2.0. Skip sites that
            // haven't refreshed their /health since then to avoid wasted 404s.
            $caps = $site->companion_capabilities ?? [];
            if (! in_array('snapshot', $caps, true)) {
                $stats['skipped']++;

                continue;
            }

            // Pressable's edge cache serves stale GET responses indefinitely
            // once a URL is cached — confirmed live 2026-08-29: /snapshot kept
            // returning a plugin's pre-update version for a site that had
            // genuinely already been upgraded, until the edge cache was
            // purged. SpinupWP has no such layer, so this only applies here.
            // Best-effort: a purge failure shouldn't block the refresh itself.
            if ($site->isPressable() && $site->pressable_site_id !== null) {
                try {
                    $pressable->purgeEdgeCache($site->pressable_site_id);
                } catch (Throwable) {
                    // non-fatal
                }
            }

            $client = new ClockworkCompanionClient($site);

            // /snapshot only ever reads whatever WordPress's own ~12-hour
            // cron cycle last put in the update_plugins/update_themes
            // transients — it never forces a real wordpress.org check.
            // Confirmed live 2026-08-31: forcing this fleet-wide moved the
            // real pending-plugin-update total from 9 to 25 across 80 sites
            // (15 sites had been silently undercounting). Companion's own
            // /plugins route already does this forcing (TransientRefresher,
            // rate-limited to once per 30 min per site, refreshes BOTH the
            // plugin and theme transients in one loopback call) — calling
            // it here, right before /snapshot, means the nightly auto-update
            // loop (02:00 ET, reads this same cached snapshot) always sees
            // genuinely fresh data instead of "call trigger separately and
            // hope someone remembers." Best-effort: a failure here must not
            // block the snapshot pull itself, same as the edge-cache purge
            // above — worst case we fall back to whatever WP's own cron had.
            if (in_array('plugins', $caps, true)) {
                try {
                    $client->plugins();
                } catch (Throwable $e) {
                    Log::warning('companion.snapshot.force_refresh_failed', [
                        'site' => $site->domain,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            try {
                $payload = $client->snapshot();
            } catch (Throwable $e) {
                $stats['failed']++;
                Log::warning('companion.snapshot.failed', [
                    'site' => $site->domain,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("  [fail] {$site->domain}: {$e->getMessage()}");

                continue;
            }

            $site->forceFill([
                'companion_snapshot' => $payload,
                'companion_snapshot_at' => Carbon::now(),
                'companion_last_seen_at' => Carbon::now(),
            ])->save();

            $stats['ok']++;
            $counts = $payload['plugins']['counts'] ?? [];
            $admins = $payload['admins']['count'] ?? null;
            $cron = $payload['wp_cron']['counts']['overdue'] ?? null;
            $this->line(sprintf(
                '  [ok]   %s — plugins=%d (updates=%d) admins=%s overdue_cron=%s',
                $site->domain,
                (int) ($counts['total'] ?? 0),
                (int) ($counts['updates_available'] ?? 0),
                $admins ?? '?',
                $cron ?? '?',
            ));
        }

        $msg = sprintf(
            'companion.snapshot.refresh complete: ok=%d failed=%d skipped=%d',
            $stats['ok'],
            $stats['failed'],
            $stats['skipped'],
        );
        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Site>
     */
    private function resolveSites(): Collection
    {
        $q = Site::query()
            ->where('companion_installed', true)
            ->whereNotNull('companion_secret')
            ->hostMonitored();

        if ($needle = $this->option('site')) {
            $q->where(function ($q) use ($needle) {
                $q->where('id', $needle)->orWhere('domain', $needle);
            });
        }

        if ($needle = $this->option('server')) {
            $q->whereHas('server', function ($q) use ($needle) {
                $q->where('id', $needle)
                    ->orWhere('name', $needle)
                    ->orWhere('hostname', $needle);
            });
        }

        if ($this->option('pending-updates-only')) {
            // SpinupWP says updates exist but the cached snapshot disagrees.
            // Targets the gap where SpinupWP detects an update (import runs at
            // 04:15 UTC) AFTER the Companion's internal WP-cron update check
            // already ran for the night, leaving the snapshot stale by morning.
            $q->where(function ($q) {
                $q->where('wp_plugin_updates', true)
                    ->orWhere('wp_theme_updates', true)
                    ->orWhere('wp_core_update', true);
            })->where(function ($q) {
                $q->whereNull('companion_snapshot')
                    ->orWhereRaw("JSON_EXTRACT(companion_snapshot, '$.plugins.counts.updates_available') = 0")
                    ->orWhereRaw("JSON_EXTRACT(companion_snapshot, '$.plugins.counts.updates_available') IS NULL");
            });
        }

        return $q->orderBy('domain')->get();
    }
}
