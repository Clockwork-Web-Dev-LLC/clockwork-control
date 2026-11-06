<?php

namespace Modules\Seo\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Services\GoogleSyncService;

/**
 * "Sync now", the backfill after a profile is saved or a property changes,
 * and the non-branded re-pull after a brand-terms change.
 *
 * The database queue's retry_after (90s) is shorter than a backfill, so a
 * second worker can pick up the same job; the per-site lock makes that copy
 * exit at once. The lock outlives $timeout slightly, so a killed run clears
 * itself, and failed() never releases it (the first copy may still be
 * working).
 */
class SyncSiteGoogleDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public const LOCK_SECONDS = 920;

    public function __construct(
        public readonly int $siteId,
        public readonly bool $backfill = false,
        public readonly ?string $repullNonbrandedFrom = null,
    ) {}

    public static function lockKey(int $siteId): string
    {
        return 'seo.sync.site.'.$siteId;
    }

    public function handle(GoogleSyncService $sync): void
    {
        $lock = Cache::lock(self::lockKey($this->siteId), self::LOCK_SECONDS);
        if (! $lock->get()) {
            return;
        }

        try {
            $profile = SiteProfile::query()->where('site_id', $this->siteId)->first();
            if ($profile === null || ! $profile->is_enabled) {
                return;
            }

            if ($this->repullNonbrandedFrom !== null) {
                $sync->repullNonbrandedDaily($profile, CarbonImmutable::parse($this->repullNonbrandedFrom));

                return;
            }

            $sync->syncSite($profile, $this->backfill ? $profile->history_starts_on?->toImmutable() : null);
        } finally {
            $lock->release();
        }
    }
}
