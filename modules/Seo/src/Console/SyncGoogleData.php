<?php

namespace Modules\Seo\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Modules\Seo\Google\GoogleApiResult;
use Modules\Seo\Google\PropertyDiscovery;
use Modules\Seo\Jobs\SyncSiteGoogleDataJob;
use Modules\Seo\Models\GoogleConnection;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Services\GoogleSyncService;

/**
 * Nightly Search Console + GA4 sync, one site at a time. Exits failed when
 * the connection is revoked or any site errored, so the Scheduled Jobs page
 * shows it.
 */
class SyncGoogleData extends Command
{
    protected $signature = 'clockwork:seo-sync-google
        {--site= : Only this site (ID or domain)}
        {--backfill : Re-pull everything from the profile\'s history start (16 months)}
        {--from= : Re-pull from this date (YYYY-MM-DD), including months already final}';

    protected $description = 'Sync Search Console and GA4 data for every enabled SEO profile';

    public function handle(GoogleSyncService $sync, PropertyDiscovery $discovery): int
    {
        $connection = GoogleConnection::current();
        if ($connection === null) {
            $this->error('Google is not connected. Connect it on /seo/settings.');

            return self::FAILURE;
        }
        if ($connection->isRevoked()) {
            $this->error('The Google connection was revoked. Reconnect on /seo/settings.');

            return self::FAILURE;
        }

        $from = null;
        if ($this->option('from')) {
            try {
                $from = CarbonImmutable::createFromFormat('Y-m-d', (string) $this->option('from'))?->startOfDay();
            } catch (\Throwable) {
                $from = null;
            }
            if ($from === null) {
                $this->error('--from must be a date like 2026-01-31.');

                return self::FAILURE;
            }
        }

        $refreshed = $discovery->refresh($connection);
        if ($refreshed instanceof GoogleApiResult) {
            $this->warn('Could not refresh the property list: '.$refreshed->error);
            if ($refreshed->isRevoked()) {
                return self::FAILURE;
            }
        }

        $profiles = SiteProfile::query()->with('site')->where('is_enabled', true)
            ->when($this->option('site'), function ($q, $site) {
                $q->whereHas('site', fn ($s) => is_numeric($site) ? $s->whereKey((int) $site) : $s->where('domain', $site));
            })
            ->orderBy('site_id')
            ->get();

        if ($profiles->isEmpty()) {
            $this->info('No enabled SEO profiles to sync.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($profiles as $profile) {
            $domain = $profile->site->domain ?? ('site '.$profile->site_id);

            $lock = Cache::lock(SyncSiteGoogleDataJob::lockKey($profile->site_id), SyncSiteGoogleDataJob::LOCK_SECONDS);
            if (! $lock->get()) {
                $this->line("{$domain}: skipped, a sync for this site is already running.");

                continue;
            }

            try {
                $siteFrom = $this->option('backfill') ? $profile->history_starts_on?->toImmutable() : $from;
                $outcome = $sync->syncSite($profile, $siteFrom);
            } finally {
                $lock->release();
            }

            if ($outcome->ok) {
                $this->line("{$domain}: ok ({$outcome->gscMonths} GSC months, {$outcome->ga4Months} GA4 months)");

                continue;
            }

            $failed++;
            $this->error("{$domain}: ".implode(' | ', $outcome->errors));

            if ($outcome->revoked) {
                $this->error('The Google connection was revoked; stopping.');

                return self::FAILURE;
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
