<?php

use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Services\SearchNumbers;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00', 'UTC'));
    $this->site = Site::factory()->create(['domain' => 'acme.com']);
    $this->profile = SiteProfile::query()->create([
        'site_id' => $this->site->id, 'gsc_site_url' => 'https://www.acme.com/', 'ga4_property_id' => '1',
        'brand_terms' => 'acme', 'brand_regex' => '(?i)(acme)', 'is_enabled' => true,
        'key_events' => ['generate_lead', 'form_submit'],
    ]);
});

/** Two days of GSC totals in a month, plus finality. */
function gscMonth(int $siteId, string $month, int $clicks, int $impressions, float $position, bool $final = true, ?int $nbClicks = null): void
{
    foreach (['01', '15'] as $day) {
        DB::table('seo_gsc_daily')->insert(['site_id' => $siteId, 'date' => "{$month}-{$day}", 'segment' => 'all', 'clicks' => $clicks / 2, 'impressions' => $impressions / 2, 'ctr' => 0, 'position' => $position]);
        if ($nbClicks !== null) {
            DB::table('seo_gsc_daily')->insert(['site_id' => $siteId, 'date' => "{$month}-{$day}", 'segment' => 'nonbranded', 'clicks' => $nbClicks / 2, 'impressions' => $impressions / 4, 'ctr' => 0, 'position' => $position]);
        }
    }
    DB::table('seo_gsc_months')->insert(['site_id' => $siteId, 'month' => "{$month}-01", 'is_final' => $final]);
    DB::table('seo_gsc_month_rows')->insert([
        ['site_id' => $siteId, 'month' => "{$month}-01", 'dimension' => 'query', 'query' => 'a', 'row_hash' => sha1($month.'a'), 'impressions' => 5],
        ['site_id' => $siteId, 'month' => "{$month}-01", 'dimension' => 'query', 'query' => 'b', 'row_hash' => sha1($month.'b'), 'impressions' => 0],
    ]);
}

function ga4Month(int $siteId, string $month, int $organic, int $engaged, bool $final = true, array $events = ['generate_lead' => 4, 'form_submit' => 0]): void
{
    DB::table('seo_ga4_months')->insert([
        ['site_id' => $siteId, 'month' => "{$month}-01", 'channel_group' => 'Organic Search', 'sessions' => $organic, 'engaged_sessions' => $engaged, 'key_events' => 3, 'is_final' => $final],
        ['site_id' => $siteId, 'month' => "{$month}-01", 'channel_group' => 'AI Assistant', 'sessions' => 7, 'engaged_sessions' => 5, 'key_events' => 0, 'is_final' => $final],
    ]);
    foreach ($events as $name => $count) {
        DB::table('seo_ga4_key_event_months')->insert(['site_id' => $siteId, 'month' => "{$month}-01", 'event_name' => $name, 'key_events' => $count, 'event_count' => $count, 'is_final' => $final]);
    }
}

it('computes the month, the previous month and YoY from stored rows', function () {
    gscMonth($this->site->id, '2026-10', 100, 2000, 8.0, nbClicks: 40);
    gscMonth($this->site->id, '2026-09', 80, 1600, 9.0, nbClicks: 30);
    gscMonth($this->site->id, '2025-10', 50, 1000, 10.0);
    ga4Month($this->site->id, '2026-10', 500, 300);
    ga4Month($this->site->id, '2026-09', 400, 200);

    $n = app(SearchNumbers::class)->month($this->profile, CarbonImmutable::parse('2026-10-01'));

    expect($n['month'])->toBe('2026-10')
        ->and($n['data_state'])->toBe('final')
        ->and($n['gsc']['clicks'])->toMatchArray(['value' => 100, 'previous' => 80, 'previous_change' => 20, 'previous_change_pct' => 25.0, 'yoy' => 50, 'yoy_change_pct' => 100.0])
        ->and($n['gsc']['ctr']['value'])->toBe(5.0)
        ->and($n['gsc']['position']['value'])->toBe(8.0)
        ->and($n['gsc']['nonbranded_clicks']['value'])->toBe(40)
        ->and($n['gsc']['organic_keywords']['value'])->toBe(1)
        ->and($n['ga4']['organic_sessions']['value'])->toBe(500)
        ->and($n['ga4']['organic_engagement_rate']['value'])->toBe(60.0)
        ->and($n['ga4']['ai_assistant_sessions']['value'])->toBe(7)
        ->and($n['ga4']['key_events']['generate_lead']['value'])->toBe(4.0)
        ->and($n['ga4']['key_events']['form_submit']['value'])->toBe(0.0)
        ->and(collect($n['caveats'])->pluck('code'))->toContain('nonbranded_known_queries_only');
});

it('hides position when the baseline starts on or before 2025-09-14: Aug and Sept 2025 suppressed, Oct 2025 shown', function (string $current, string $baseline, bool $comparable) {
    gscMonth($this->site->id, $current, 100, 2000, 8.0);
    gscMonth($this->site->id, $baseline, 100, 2000, 12.0);
    $key = CarbonImmutable::parse($current.'-01')->subYear()->format('Y-m') === $baseline ? 'yoy' : 'previous';

    $n = app(SearchNumbers::class)->month($this->profile, CarbonImmutable::parse($current.'-01'));

    expect($n['gsc']['position'][$key.'_comparable'])->toBe($comparable)
        ->and($n['gsc']['position'][$key.'_change'])->toBe($comparable ? -4.0 : null)
        // Impressions stay comparable; they only get a caveat.
        ->and($n['gsc']['impressions'][$key.'_comparable'])->toBeTrue();

    if (! $comparable) {
        expect(collect($n['caveats'])->pluck('code'))->toContain('position_'.$key)->toContain('impressions_'.$key);
    }
})->with([
    'Aug 2025 → Aug 2026 YoY' => ['2026-08', '2025-08', false],
    'Sept 2025 → Sept 2026 YoY' => ['2026-09', '2025-09', false],
    'Oct 2025 → Oct 2026 YoY' => ['2026-10', '2025-10', true],
    'Sept 2025 → Oct 2025' => ['2025-10', '2025-09', false],
    'Oct 2025 → Nov 2025' => ['2025-11', '2025-10', true],
]);

it('marks a month provisional until month end plus five days', function () {
    gscMonth($this->site->id, '2026-10', 100, 2000, 8.0, final: false);

    $n = app(SearchNumbers::class)->month($this->profile, CarbonImmutable::parse('2026-10-01'));

    expect($n['data_state'])->toBe('provisional')
        ->and($n['final_after'])->toBe('2026-11-05')
        ->and(collect($n['caveats'])->pluck('code'))->toContain('provisional');
});

it('computes AI share, and "not available" never becomes 0', function () {
    gscMonth($this->site->id, '2026-10', 100, 2000, 8.0);
    DB::table('seo_ai_impressions')->insert(['site_id' => $this->site->id, 'month' => '2026-10-01', 'status' => 'entered', 'ai_impressions' => 300, 'source' => 'manual']);
    DB::table('seo_ai_impressions')->insert(['site_id' => $this->site->id, 'month' => '2026-09-01', 'status' => 'not_available', 'ai_impressions' => null, 'source' => 'manual']);

    $oct = app(SearchNumbers::class)->month($this->profile, CarbonImmutable::parse('2026-10-01'));
    $sep = app(SearchNumbers::class)->month($this->profile, CarbonImmutable::parse('2026-09-01'));
    $aug = app(SearchNumbers::class)->month($this->profile, CarbonImmutable::parse('2026-08-01'));

    expect($oct['ai'])->toMatchArray(['status' => 'entered', 'ai_impressions' => 300, 'ai_share_pct' => 15.0])
        ->and($sep['ai'])->toMatchArray(['status' => 'not_available', 'ai_impressions' => null, 'ai_share_pct' => null])
        ->and($aug['ai'])->toMatchArray(['status' => 'missing', 'ai_impressions' => null]);
});

it('returns null, not 0, for a month that was never synced', function () {
    $n = app(SearchNumbers::class)->month($this->profile, CarbonImmutable::parse('2026-10-01'));

    expect($n['data_state'])->toBe('not_synced')
        ->and($n['gsc']['clicks']['value'])->toBeNull()
        ->and($n['ga4']['organic_sessions']['value'])->toBeNull()
        ->and($n['ga4']['key_events']['generate_lead']['value'])->toBeNull();
});

it('labels AI assistant sessions before the GA4 channel as an estimate from referral sources', function () {
    ga4Month($this->site->id, '2026-04', 500, 300);
    DB::table('seo_ga4_daily')->insert([
        ['site_id' => $this->site->id, 'date' => '2026-04-03', 'dimension' => 'ai_source', 'value' => 'chatgpt.com / referral', 'sessions' => 4],
        ['site_id' => $this->site->id, 'date' => '2026-04-20', 'dimension' => 'ai_source', 'value' => 'perplexity.ai / referral', 'sessions' => 2],
    ]);

    $n = app(SearchNumbers::class)->month($this->profile, CarbonImmutable::parse('2026-04-01'));

    expect($n['ga4']['ai_assistant_sessions']['value'])->toBe(6)
        ->and(collect($n['caveats'])->pluck('code'))->toContain('ai_sessions_estimate');
});
