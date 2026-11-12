<?php

use App\Models\Site;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Seo\Jobs\SyncSiteGoogleDataJob;
use Modules\Seo\Models\GoogleConnection;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Services\GoogleSyncService;
use Tests\Fixtures\GoogleOAuthFixtures;

function seoSyncEvent(): Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $e) => str_contains((string) $e->command, 'clockwork:seo-sync-google'));
}

it('runs nightly at 08:10 UTC without overlapping, on one server, in the background', function () {
    $event = seoSyncEvent();

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('10 8 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue();
});

it('does not run when no connection was ever made', function () {
    expect(seoSyncEvent()->filtersPass(app()))->toBeFalse();
});

it('runs, and the command exits failed, when the connection is revoked', function () {
    GoogleOAuthFixtures::connection(['status' => GoogleConnection::STATUS_REVOKED]);
    Http::preventStrayRequests();

    expect(seoSyncEvent()->filtersPass(app()))->toBeTrue();
    $this->artisan('clockwork:seo-sync-google')->assertFailed();
});

it('a second copy of the sync job exits at once while the site lock is held', function () {
    $site = Site::factory()->create();
    SiteProfile::query()->create(['site_id' => $site->id, 'gsc_site_url' => 'https://www.acme.com/', 'is_enabled' => true]);
    $sync = Mockery::mock(GoogleSyncService::class);
    $sync->shouldNotReceive('syncSite');

    $lock = Cache::lock(SyncSiteGoogleDataJob::lockKey($site->id), SyncSiteGoogleDataJob::LOCK_SECONDS);
    $lock->get();

    (new SyncSiteGoogleDataJob($site->id, backfill: true))->handle($sync);

    $lock->release();
});
