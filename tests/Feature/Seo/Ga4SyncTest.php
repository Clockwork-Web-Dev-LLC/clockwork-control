<?php

use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Modules\Seo\Google\AnalyticsDataClient;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Services\GoogleSyncService;
use Tests\Fixtures\GoogleAnalyticsFixtures;
use Tests\Fixtures\GoogleOAuthFixtures;
use Tests\Fixtures\SearchConsoleFixtures;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    GoogleOAuthFixtures::configureClient();
    $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00', 'UTC'));
    GoogleOAuthFixtures::connection();
    $this->site = Site::factory()->create(['domain' => 'acme.com']);
    $this->profile = SiteProfile::query()->create([
        'site_id' => $this->site->id,
        'ga4_property_id' => '123456',
        'key_events' => ['generate_lead', 'form_submit', 'contact_link_click'],
        'is_enabled' => true,
        'history_starts_on' => '2026-10-01',
    ]);
});

function ga4Requests(): array
{
    $out = [];
    foreach (Http::recorded() as [$request]) {
        if (str_starts_with($request->url(), AnalyticsDataClient::BASE_URL)) {
            foreach ($request['requests'] as $r) {
                $out[] = $r;
            }
        }
    }

    return $out;
}

it('the daily batch filters to medium ai-assistant OR an AI source regex', function () {
    SearchConsoleFixtures::fakeGoogle();

    app(GoogleSyncService::class)->syncSite($this->profile);

    $ai = collect(ga4Requests())->first(fn ($r) => array_column($r['dimensions'], 'name') === ['date', 'sessionSource', 'sessionMedium']);
    $expressions = $ai['dimensionFilter']['orGroup']['expressions'];

    expect($expressions[0]['filter'])->toBe(['fieldName' => 'sessionMedium', 'stringFilter' => ['matchType' => 'EXACT', 'value' => 'ai-assistant']])
        ->and($expressions[1]['filter']['fieldName'])->toBe('sessionSource')
        ->and($expressions[1]['filter']['stringFilter']['matchType'])->toBe('PARTIAL_REGEXP')
        ->and($expressions[1]['filter']['stringFilter']['value'])->toContain('chatgpt\.com')
        ->and(DB::table('seo_ga4_daily')->where('dimension', 'ai_source')->value('value'))->toBe('chatgpt.com / referral');
});

it('the month batch uses the calendar month with no date dimension and the key-event list', function () {
    SearchConsoleFixtures::fakeGoogle();

    app(GoogleSyncService::class)->syncSite($this->profile);

    $monthRequests = collect(ga4Requests())->filter(fn ($r) => ! in_array('date', array_column($r['dimensions'], 'name'), true))->values();
    $october = $monthRequests->filter(fn ($r) => $r['dateRanges'][0]['startDate'] === '2026-10-01')->values();

    expect($october)->toHaveCount(2)
        ->and($october[0]['dateRanges'][0])->toBe(['startDate' => '2026-10-01', 'endDate' => '2026-10-31'])
        ->and($october[0]['returnPropertyQuota'])->toBeTrue()
        ->and($october[1]['dimensionFilter']['filter']['inListFilter']['values'])->toBe(['generate_lead', 'form_submit', 'contact_link_click']);

    // November is open: it ends yesterday, not at month end.
    expect($monthRequests->first(fn ($r) => $r['dateRanges'][0]['startDate'] === '2026-11-01')['dateRanges'][0]['endDate'])->toBe('2026-11-05');
});

it('stores month figures from the month response, not summed days', function () {
    SearchConsoleFixtures::fakeGoogle();

    app(GoogleSyncService::class)->syncSite($this->profile);

    $summedDays = (int) DB::table('seo_ga4_daily')->where('dimension', 'channel')->where('value', 'Organic Search')
        ->whereBetween('date', ['2026-10-01', '2026-10-31'])->sum('sessions');
    $month = DB::table('seo_ga4_months')->where('month', '2026-10-01')->where('channel_group', 'Organic Search')->first();

    expect($summedDays)->toBe(31 * GoogleAnalyticsFixtures::DAILY_ORGANIC_SESSIONS)
        ->and((int) $month->sessions)->toBe(GoogleAnalyticsFixtures::MONTH_ORGANIC_SESSIONS)
        ->and((int) $month->engaged_sessions)->toBe(600)
        ->and((bool) $month->is_final)->toBeTrue();
});

it('stores subjectToThresholding and a zero row for key events with no data', function () {
    SearchConsoleFixtures::fakeGoogle();

    app(GoogleSyncService::class)->syncSite($this->profile);

    expect((bool) DB::table('seo_ga4_months')->where('month', '2026-10-01')->value('subject_to_thresholding'))->toBeTrue()
        ->and(DB::table('seo_ga4_key_event_months')->where('month', '2026-10-01')->orderBy('event_name')->pluck('key_events', 'event_name')->map(fn ($v) => (float) $v)->all())
        ->toBe(['contact_link_click' => 0.0, 'form_submit' => 6.0, 'generate_lead' => 9.0]);
});

it('queries sessionPrimaryChannelGroup when the profile flag is set', function () {
    $this->profile->update(['ga4_custom_primary_channel' => true]);
    SearchConsoleFixtures::fakeGoogle();

    app(GoogleSyncService::class)->syncSite($this->profile->fresh());

    $names = collect(ga4Requests())->flatMap(fn ($r) => array_column($r['dimensions'], 'name'))->unique()->values()->all();
    expect($names)->toContain('sessionPrimaryChannelGroup')->not->toContain('sessionDefaultChannelGroup');
});

it('stops the property when few hourly tokens remain', function () {
    SearchConsoleFixtures::fakeGoogle(ga4Override: fn (Request $r) => Http::response(['reports' => [
        ['propertyQuota' => ['tokensPerHour' => ['remaining' => 400]]],
        ['propertyQuota' => ['tokensPerHour' => ['remaining' => 400]]],
    ]]));

    $outcome = app(GoogleSyncService::class)->syncSite($this->profile);

    expect($outcome->ok)->toBeFalse()
        ->and($outcome->errors[0])->toContain('400 GA4 hourly tokens');
    expect(count(ga4Requests()))->toBe(2);
});
