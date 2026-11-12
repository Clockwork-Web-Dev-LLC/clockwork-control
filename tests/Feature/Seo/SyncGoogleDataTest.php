<?php

use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Modules\Seo\Models\GoogleConnection;
use Modules\Seo\Models\GoogleProperty;
use Modules\Seo\Models\SiteProfile;
use Tests\Fixtures\GoogleOAuthFixtures;
use Tests\Fixtures\SearchConsoleFixtures;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    GoogleOAuthFixtures::configureClient();
    // 2026-11-06 04:00 Pacific: yesterday is Nov 5, October is final (Oct 31 + 5 days).
    $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00', 'UTC'));
    $this->connection = GoogleOAuthFixtures::connection();
    $this->site = Site::factory()->create(['domain' => 'acme.com']);
    $this->profile = SiteProfile::query()->create([
        'site_id' => $this->site->id,
        'gsc_site_url' => 'https://www.acme.com/',
        'ga4_property_id' => '123456',
        'brand_terms' => 'acme',
        'brand_regex' => '(?i)(acme)',
        'is_enabled' => true,
        'history_starts_on' => '2026-09-01',
    ]);
});

it('stores daily totals, month rows, finality and branded flags', function () {
    SearchConsoleFixtures::fakeGoogle();

    $this->artisan('clockwork:seo-sync-google')->assertSuccessful();

    expect(DB::table('seo_gsc_daily')->where('segment', 'all')->count())->toBe(66) // Sep 1 .. Nov 5
        ->and(DB::table('seo_gsc_daily')->where('segment', 'nonbranded')->count())->toBe(66)
        ->and(DB::table('seo_gsc_months')->orderBy('month')->pluck('is_final', 'month')->map(fn ($v) => (bool) $v)->all())
        ->toBe(['2026-09-01' => true, '2026-10-01' => true, '2026-11-01' => false])
        ->and(DB::table('seo_gsc_month_rows')->where('month', '2026-10-01')->where('dimension', 'query')->where('query', 'acme roofing')->value('is_branded'))->toBe(1)
        ->and(DB::table('seo_gsc_month_rows')->where('month', '2026-10-01')->where('dimension', 'query')->where('query', 'roof repair phoenix')->value('is_branded'))->toBe(0)
        ->and(DB::table('seo_gsc_month_rows')->where('dimension', 'page')->value('is_branded'))->toBeNull()
        ->and($this->profile->fresh()->last_gsc_synced_at)->not->toBeNull()
        ->and($this->profile->fresh()->last_sync_error)->toBeNull();
});

it('is idempotent, and the trailing re-pull overwrites changed numbers', function () {
    $scale = 1;
    SearchConsoleFixtures::fakeGoogle(function () use (&$scale) {
        return $scale;
    });
    $this->artisan('clockwork:seo-sync-google')->assertSuccessful();
    $counts = [DB::table('seo_gsc_daily')->count(), DB::table('seo_gsc_month_rows')->count(), DB::table('seo_ga4_months')->count()];

    $scale = 2;
    $this->artisan('clockwork:seo-sync-google')->assertSuccessful();

    expect([DB::table('seo_gsc_daily')->count(), DB::table('seo_gsc_month_rows')->count(), DB::table('seo_ga4_months')->count()])->toBe($counts)
        // Inside the 5-day trailing window: re-pulled.
        ->and(DB::table('seo_gsc_daily')->where('segment', 'all')->where('date', '2026-11-03')->value('clicks'))->toBe(6)
        // Older than that: kept.
        ->and(DB::table('seo_gsc_daily')->where('segment', 'all')->where('date', '2026-10-01')->value('clicks'))->toBe(3)
        // The open month is re-pulled, final months are not.
        ->and(DB::table('seo_gsc_month_rows')->where('month', '2026-11-01')->where('dimension', 'page')->max('clicks'))->toBe(80)
        ->and(DB::table('seo_gsc_month_rows')->where('month', '2026-10-01')->where('dimension', 'page')->max('clicks'))->toBe(40);
});

it('turns a month final only on or after month end plus five days', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00:00', 'UTC'));
    SearchConsoleFixtures::fakeGoogle();
    $this->artisan('clockwork:seo-sync-google')->assertSuccessful();
    expect((bool) DB::table('seo_gsc_months')->where('month', '2026-10-01')->value('is_final'))->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC'));
    $this->artisan('clockwork:seo-sync-google')->assertSuccessful();
    expect((bool) DB::table('seo_gsc_months')->where('month', '2026-10-01')->value('is_final'))->toBeTrue();
});

it('one site\'s quota error doesn\'t stop the next, and the command exits failed', function () {
    $beta = Site::factory()->create(['domain' => 'beta.com']);
    SiteProfile::query()->create(['site_id' => $beta->id, 'gsc_site_url' => 'https://www.beta.com/', 'is_enabled' => true, 'history_starts_on' => '2026-09-01']);

    SearchConsoleFixtures::fakeGoogle(gscOverride: fn (Request $r) => str_contains($r->url(), 'acme.com')
        ? Http::response(['error' => ['code' => 429, 'message' => 'Quota exceeded', 'status' => 'RESOURCE_EXHAUSTED']], 429)
        : null);

    $this->artisan('clockwork:seo-sync-google')->assertFailed();

    expect($this->profile->fresh()->last_sync_error)->toContain('Quota exceeded')
        ->and(DB::table('seo_gsc_daily')->where('site_id', $beta->id)->count())->toBeGreaterThan(0)
        ->and(DB::table('seo_gsc_daily')->where('site_id', $this->site->id)->count())->toBe(0);
});

it('exits failed without calling the APIs when the connection is revoked', function () {
    $this->connection->update(['status' => GoogleConnection::STATUS_REVOKED]);
    Http::fake();

    $this->artisan('clockwork:seo-sync-google')->assertFailed();

    Http::assertNothingSent();
});

it('refreshes the property list first; properties no longer seen keep their old last_seen_at', function () {
    $old = now()->subWeek();
    GoogleProperty::query()->create(['connection_id' => $this->connection->id, 'kind' => 'gsc', 'property' => 'https://gone.com/', 'last_seen_at' => $old]);
    SearchConsoleFixtures::fakeGoogle();

    $this->artisan('clockwork:seo-sync-google')->assertSuccessful();

    expect(GoogleProperty::query()->where('property', 'https://www.acme.com/')->value('last_seen_at'))->not->toBeNull()
        ->and(GoogleProperty::query()->where('property', 'https://gone.com/')->first()->last_seen_at->timestamp)->toBe($old->timestamp);
});

it('backfills from the profile history start, starting at the first full month', function () {
    $this->profile->update(['history_starts_on' => '2025-07-06']);
    SearchConsoleFixtures::fakeGoogle();

    $this->artisan('clockwork:seo-sync-google', ['--backfill' => true])->assertSuccessful();

    expect(DB::table('seo_gsc_months')->min('month'))->toBe('2025-08-01')
        ->and(DB::table('seo_gsc_months')->count())->toBe(16)
        ->and(DB::table('seo_gsc_daily')->where('segment', 'all')->min('date'))->toBe('2025-07-06');
});

it('skips disabled profiles and can sync one site', function () {
    $beta = Site::factory()->create(['domain' => 'beta.com']);
    SiteProfile::query()->create(['site_id' => $beta->id, 'gsc_site_url' => 'https://www.beta.com/', 'is_enabled' => false, 'history_starts_on' => '2026-09-01']);
    SearchConsoleFixtures::fakeGoogle();

    $this->artisan('clockwork:seo-sync-google', ['--site' => 'acme.com'])->assertSuccessful();
    $this->artisan('clockwork:seo-sync-google')->assertSuccessful();

    expect(DB::table('seo_gsc_daily')->where('site_id', $beta->id)->count())->toBe(0);
});
