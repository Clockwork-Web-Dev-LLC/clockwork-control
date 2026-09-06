<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Force-release the per-site Cache::lock("site_update:{id}", 300) used by
 * AbstractRunUpdate. Needed when a worker died holding the lock without
 * release — subsequent jobs for that site would re-queue every 15s
 * indefinitely (release(15)) and the batch would never drain.
 *
 * Companion command to ReapStaleUpdateJobs: the reaper handles `running`
 * rows; this handles the lock that may outlive a `failed` row's release.
 *
 * Audit trail: writes one action_logs row tagged
 * `plugin_update.lock_cleared` so we can see manual clears alongside the
 * automated reaper output and the per-update history.
 */
class ClearSiteUpdateLock extends Command
{
    protected $signature = 'clockwork:clear-site-update-lock {site_id : Site primary key}';

    protected $description = 'Force-release the site_update:{id} cache lock for one site.';

    public function handle(ActionLogger $logger): int
    {
        $siteId = (int) $this->argument('site_id');
        if ($siteId <= 0) {
            $this->error('site_id must be a positive integer.');

            return self::INVALID;
        }

        $site = Site::query()->find($siteId);
        if ($site === null) {
            $this->error("No site with id={$siteId}.");

            return self::FAILURE;
        }

        $key = "site_update:{$siteId}";
        $cleared = Cache::forget($key);

        $logger->record(
            actionType: 'plugin_update.lock_cleared',
            summary: "Manually cleared site_update lock for site #{$siteId}",
            site: $site,
            target: $key,
            details: ['cache_forget_returned' => $cleared],
            ok: true,
            actor: 'manual',
        );

        $this->info("Cleared lock {$key} (Cache::forget returned ".($cleared ? 'true' : 'false').').');

        return self::SUCCESS;
    }
}
