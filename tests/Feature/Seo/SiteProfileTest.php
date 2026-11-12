<?php

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Seo\Jobs\RefreshGooglePropertiesJob;
use Modules\Seo\Jobs\SyncSiteGoogleDataJob;
use Modules\Seo\Models\Annotation;
use Modules\Seo\Models\GoogleProperty;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Support\GscDates;
use Tests\Fixtures\GoogleOAuthFixtures;

beforeEach(function () {
    mockIssueCounterZero();
    Http::preventStrayRequests();
    Queue::fake();
    $this->user = User::factory()->operator()->create();
    $this->site = Site::factory()->create(['domain' => 'acme.com']);
});

function seedGscRows(int $siteId, string $date = '2026-10-15'): void
{
    DB::table('seo_gsc_daily')->insert(['site_id' => $siteId, 'date' => $date, 'segment' => 'all', 'clicks' => 5, 'impressions' => 50]);
    DB::table('seo_gsc_month_rows')->insert(['site_id' => $siteId, 'month' => '2026-10-01', 'dimension' => 'query', 'query' => 'acme', 'row_hash' => sha1('q'), 'clicks' => 1, 'impressions' => 9]);
    DB::table('seo_gsc_months')->insert(['site_id' => $siteId, 'month' => '2026-10-01', 'is_final' => true]);
    DB::table('seo_ga4_months')->insert(['site_id' => $siteId, 'month' => '2026-10-01', 'channel_group' => 'Organic Search', 'sessions' => 10]);
}

function profileForm(array $overrides = []): array
{
    return $overrides + [
        'gsc_site_url' => 'https://www.acme.com/',
        'ga4_property_id' => '123456',
        'ga4_custom_primary_channel' => '0',
        'brand_terms' => 'acme',
        'key_events' => 'generate_lead, form_submit',
        'compare_yoy' => '0',
        'report_notes' => 'Keep it short; owner reads on a phone.',
        'is_enabled' => '1',
    ];
}

it('renders the profile form from stored properties without calling Google, flagging unseen ones', function () {
    $connection = GoogleOAuthFixtures::connection();
    GoogleProperty::query()->create(['connection_id' => $connection->id, 'kind' => 'gsc', 'property' => 'https://www.acme.com/', 'permission_level' => 'siteFullUser', 'last_seen_at' => now()]);
    GoogleProperty::query()->create(['connection_id' => $connection->id, 'kind' => 'gsc', 'property' => 'https://old-client.com/', 'permission_level' => 'siteFullUser', 'last_seen_at' => now()->subDays(3)]);

    $this->actingAs($this->user)->get(route('seo.sites.profile', $this->site))
        ->assertOk()
        ->assertSee('https://www.acme.com/ (siteFullUser) · matches this site', false)
        ->assertSee('no longer visible to Google connection');

    Http::assertNothingSent();
});

it('saving a new profile sets 16 months of history and queues the backfill', function () {
    $this->actingAs($this->user)->put(route('seo.sites.profile.update', $this->site), profileForm())
        ->assertRedirect(route('seo.sites.profile', $this->site));

    $profile = SiteProfile::query()->where('site_id', $this->site->id)->first();
    expect($profile->gsc_site_url)->toBe('https://www.acme.com/')
        ->and($profile->ga4_property_id)->toBe('123456')
        ->and($profile->brand_regex)->toBe('(?i)(acme)')
        ->and($profile->key_events)->toBe(['generate_lead', 'form_submit'])
        ->and($profile->report_notes)->toBe('Keep it short; owner reads on a phone.')
        ->and($profile->history_starts_on->toDateString())->toBe(GscDates::windowStart()->toDateString());

    Queue::assertPushed(SyncSiteGoogleDataJob::class, fn ($job) => $job->siteId === $this->site->id && $job->backfill);
});

it('accepts a GA4 ID pasted as properties/123', function () {
    $this->actingAs($this->user)->put(route('seo.sites.profile.update', $this->site), profileForm(['ga4_property_id' => 'properties/987']));

    expect(SiteProfile::query()->value('ga4_property_id'))->toBe('987');
});

it('asks before a GSC property change, then purges the GSC rows only and re-pulls', function () {
    SiteProfile::query()->create(['site_id' => $this->site->id, 'gsc_site_url' => 'https://www.acme.com/', 'ga4_property_id' => '123456', 'brand_terms' => 'acme', 'is_enabled' => true, 'history_starts_on' => '2020-01-01']);
    seedGscRows($this->site->id);

    $this->actingAs($this->user)->put(route('seo.sites.profile.update', $this->site), profileForm(['gsc_site_url' => 'sc-domain:acme.com']))
        ->assertSessionHasErrors('confirm_purge');
    expect(DB::table('seo_gsc_daily')->count())->toBe(1);
    Queue::assertNothingPushed();

    $this->actingAs($this->user)->put(route('seo.sites.profile.update', $this->site), profileForm(['gsc_site_url' => 'sc-domain:acme.com', 'confirm_purge' => '1']))
        ->assertSessionHasNoErrors();

    expect(DB::table('seo_gsc_daily')->count())->toBe(0)
        ->and(DB::table('seo_gsc_month_rows')->count())->toBe(0)
        ->and(DB::table('seo_gsc_months')->count())->toBe(0)
        ->and(DB::table('seo_ga4_months')->count())->toBe(1)
        ->and(SiteProfile::query()->first()->history_starts_on->toDateString())->toBe(GscDates::windowStart()->toDateString());
    Queue::assertPushed(SyncSiteGoogleDataJob::class, fn ($job) => $job->backfill);
});

it('purges the GA4 rows when the GA4 property or the channel flag changes', function () {
    SiteProfile::query()->create(['site_id' => $this->site->id, 'gsc_site_url' => 'https://www.acme.com/', 'ga4_property_id' => '123456', 'brand_terms' => 'acme', 'is_enabled' => true]);
    seedGscRows($this->site->id);

    $this->actingAs($this->user)->put(route('seo.sites.profile.update', $this->site), profileForm(['ga4_custom_primary_channel' => '1', 'confirm_purge' => '1']));

    expect(DB::table('seo_ga4_months')->count())->toBe(0)
        ->and(DB::table('seo_gsc_daily')->count())->toBe(1);
    Queue::assertPushed(SyncSiteGoogleDataJob::class, fn ($job) => $job->backfill);
});

it('a brand-terms change re-marks month rows, re-pulls only inside 16 months, keeps older days and annotates the boundary', function () {
    $profile = SiteProfile::query()->create(['site_id' => $this->site->id, 'gsc_site_url' => 'https://www.acme.com/', 'ga4_property_id' => '123456', 'brand_terms' => 'acme', 'is_enabled' => true]);
    $old = GscDates::windowStart()->subDays(10)->toDateString();
    DB::table('seo_gsc_daily')->insert(['site_id' => $this->site->id, 'date' => $old, 'segment' => 'nonbranded', 'clicks' => 3, 'impressions' => 30]);
    DB::table('seo_gsc_month_rows')->insert(['site_id' => $this->site->id, 'month' => '2026-10-01', 'dimension' => 'query', 'query' => 'roadrunner roofing', 'row_hash' => sha1('rr'), 'is_branded' => false, 'clicks' => 1, 'impressions' => 9]);

    $this->actingAs($this->user)->put(route('seo.sites.profile.update', $this->site), profileForm(['brand_terms' => 'acme, roadrunner', 'confirm_purge' => '1']))
        ->assertSessionHasNoErrors();

    $boundary = GscDates::windowStart()->toDateString();
    expect(DB::table('seo_gsc_month_rows')->where('query', 'roadrunner roofing')->value('is_branded'))->toBe(1)
        ->and(DB::table('seo_gsc_daily')->where('date', $old)->exists())->toBeTrue()
        ->and($profile->fresh()->brand_regex)->toBe('(?i)(acme|roadrunner)');

    $annotation = Annotation::query()->where('site_id', $this->site->id)->sole();
    expect($annotation->kind)->toBe(Annotation::KIND_METHODOLOGY)
        ->and($annotation->starts_on->toDateString())->toBe($boundary)
        ->and($annotation->suppresses)->toBe(['nonbranded']);

    Queue::assertPushed(SyncSiteGoogleDataJob::class, fn ($job) => $job->repullNonbrandedFrom === $boundary && ! $job->backfill);
});

it('saves report notes from the form without asking for confirmation', function () {
    SiteProfile::query()->create(['site_id' => $this->site->id, 'gsc_site_url' => 'https://www.acme.com/', 'ga4_property_id' => '123456', 'brand_terms' => 'acme', 'key_events' => ['generate_lead', 'form_submit'], 'is_enabled' => true]);

    $this->actingAs($this->user)->put(route('seo.sites.profile.update', $this->site), profileForm(['report_notes' => 'New angle']))
        ->assertSessionHasNoErrors();

    expect(SiteProfile::query()->value('report_notes'))->toBe('New angle');
    Queue::assertNothingPushed();
});

it('queues the property list refresh and a manual sync', function () {
    GoogleOAuthFixtures::connection();
    SiteProfile::query()->create(['site_id' => $this->site->id, 'is_enabled' => true]);

    $this->actingAs($this->user)->post(route('seo.sites.properties.refresh', $this->site))->assertSessionHas('status');
    $this->actingAs($this->user)->post(route('seo.sites.sync', $this->site))->assertSessionHas('status');

    Queue::assertPushed(RefreshGooglePropertiesJob::class);
    Queue::assertPushed(SyncSiteGoogleDataJob::class, fn ($job) => ! $job->backfill);
});

it('records typed AI impressions, or not available, never a guessed zero', function () {
    $this->actingAs($this->user)->post(route('seo.sites.ai-impressions.store', $this->site), ['month' => '2026-10', 'status' => 'entered', 'ai_impressions' => 1234])
        ->assertSessionHas('status');
    $this->actingAs($this->user)->post(route('seo.sites.ai-impressions.store', $this->site), ['month' => '2026-09', 'status' => 'not_available', 'ai_impressions' => ''])
        ->assertSessionHas('status');

    expect(DB::table('seo_ai_impressions')->where('month', '2026-10-01')->value('ai_impressions'))->toBe(1234)
        ->and(DB::table('seo_ai_impressions')->where('month', '2026-09-01')->value('ai_impressions'))->toBeNull()
        ->and(DB::table('seo_ai_impressions')->where('month', '2026-09-01')->value('source'))->toBe('manual');
});
