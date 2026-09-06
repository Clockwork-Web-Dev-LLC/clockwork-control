<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Pressable\PressableClient;
use Throwable;

/**
 * Re-pulls /health from each Companion-equipped site and refreshes
 * companion_capabilities + companion_version in the local DB.
 *
 * Capabilities are only updated by CompanionInstaller during install/upgrade,
 * which means a plugin release that adds a new capability (say `security-scans`
 * in v1.10.0) doesn't propagate to existing sites until they're individually
 * reinstalled. This command bridges that gap — one fleet-wide pull, no rsync,
 * no service restart, ~1s per site over the WP REST API.
 *
 * Safe to schedule daily after the snapshot refresh; cheap enough to run
 * on demand right after a Companion release ships.
 */
class RefreshCompanionCapabilities extends Command
{
    protected $signature = 'clockwork:refresh-companion-capabilities
        {--site= : Limit to a single site (id or domain)}
        {--server= : Limit to sites on one server (id, name, or hostname)}';

    protected $description = 'Pull /health from each Companion-equipped site and refresh companion_capabilities + companion_version.';

    public function handle(PressableClient $pressable): int
    {
        $sites = $this->resolveSites();
        if ($sites->isEmpty()) {
            $this->warn('No Companion-equipped sites matched.');

            return self::SUCCESS;
        }

        $stats = ['ok' => 0, 'failed' => 0, 'unchanged' => 0];

        foreach ($sites as $site) {
            // Pressable's edge cache can serve a stale cached /health
            // response indefinitely — this command's whole job is detecting
            // a version/capability bump, so a stale read here directly
            // undermines it. Best-effort; SpinupWP has no edge cache layer.
            if ($site->isPressable() && $site->pressable_site_id !== null) {
                try {
                    $pressable->purgeEdgeCache($site->pressable_site_id);
                } catch (Throwable) {
                    // non-fatal
                }
            }

            try {
                $health = (new ClockworkCompanionClient($site))->health();
            } catch (Throwable $e) {
                $stats['failed']++;
                Log::warning('companion.capabilities.health_failed', [
                    'site' => $site->domain,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("  [fail] {$site->domain}: {$e->getMessage()}");

                continue;
            }

            $newCaps = (array) ($health['capabilities'] ?? []);
            $newVersion = (string) ($health['version'] ?? '');
            // is_multisite was added in Companion 1.16.4; absent on older
            // releases, in which case keep whatever was already stored.
            // (Default false at install — see migration.)
            $newIsMultisite = array_key_exists('is_multisite', $health)
                ? (bool) $health['is_multisite']
                : (bool) ($site->is_multisite ?? false);
            $oldCaps = is_array($site->companion_capabilities) ? $site->companion_capabilities : [];
            $oldVersion = (string) ($site->companion_version ?? '');
            $oldIsMultisite = (bool) ($site->is_multisite ?? false);

            // Sort both sides before diffing so cap-list reordering doesn't
            // register as "changed" — what we care about is the set, not order.
            $oldSorted = $oldCaps;
            $newSorted = $newCaps;
            sort($oldSorted);
            sort($newSorted);

            if ($oldSorted === $newSorted && $oldVersion === $newVersion && $oldIsMultisite === $newIsMultisite) {
                $stats['unchanged']++;
                if ($this->getOutput()->isVerbose()) {
                    $this->line("  [same] {$site->domain} — v{$newVersion}, ".count($newCaps).' capabilities');
                }

                continue;
            }

            $site->forceFill([
                'companion_capabilities' => $newCaps,
                'companion_version' => $newVersion,
                'is_multisite' => $newIsMultisite,
                'companion_last_seen_at' => now(),
            ])->save();

            $added = array_values(array_diff($newCaps, $oldCaps));
            $removed = array_values(array_diff($oldCaps, $newCaps));
            $changeBits = [];
            if ($oldVersion !== $newVersion) {
                $changeBits[] = "v{$oldVersion} → v{$newVersion}";
            }
            if ($added !== []) {
                $changeBits[] = '+'.implode(',', $added);
            }
            if ($removed !== []) {
                $changeBits[] = '-'.implode(',', $removed);
            }
            if ($oldIsMultisite !== $newIsMultisite) {
                $changeBits[] = $newIsMultisite ? '+multisite' : '-multisite';
            }
            $stats['ok']++;
            $this->line("  [ok]   {$site->domain} — ".implode(' | ', $changeBits));
        }

        $msg = sprintf(
            'companion.capabilities.refresh complete: ok=%d unchanged=%d failed=%d',
            $stats['ok'],
            $stats['unchanged'],
            $stats['failed'],
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

        return $q->orderBy('domain')->get();
    }
}
