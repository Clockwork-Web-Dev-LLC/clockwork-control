<?php

use App\Models\Site;
use App\Models\SiteUptimeEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Mcp\Servers\ClockworkServer;
use Modules\Seo\Mcp\Tools\SeoDailySeries;
use Modules\Seo\Mcp\Tools\SeoListSites;
use Modules\Seo\Mcp\Tools\SeoMonthNumbers;
use Modules\Seo\Mcp\Tools\SeoSearchRows;
use Modules\Seo\Models\AiImpressionEntry;
use Modules\Seo\Models\GoogleConnection;
use Modules\Seo\Services\SearchNumbers;
use Tests\Fixtures\SeoStoredData;

/** Call a tool and return its structured content. */
function seoTool(User $user, string $tool, array $arguments): array
{
    $response = ClockworkServer::actingAs($user)->tool($tool, $arguments)->assertOk();

    return (fn () => $this->response->toArray()['result']['structuredContent'])->call($response);
}

/** A tool answer's rows as a list of column => value arrays. */
function seoRows(array $content): array
{
    return array_map(fn (array $row) => array_combine($content['columns'], $row), $content['rows']);
}

beforeEach(function () {
    // No tool may call Google or anything else.
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00:00', 'UTC'));
    $this->user = User::factory()->operator()->create();
    $this->profile = SeoStoredData::profile('acme.com', ['report_notes' => 'Plain tone. Never promise rankings.']);
    $this->siteId = $this->profile->site_id;
});

describe('seo_list_sites', function () {
    it('lists profiles with setup, sync state and month status', function () {
        SeoStoredData::gscMonth($this->siteId, '2026-09', 80, 1600, 9.0);
        SeoStoredData::gscMonth($this->siteId, '2026-10', 100, 2000, 8.0, final: false);
        AiImpressionEntry::query()->create(['site_id' => $this->siteId, 'month' => '2026-09-01', 'status' => 'entered', 'ai_impressions' => 5, 'source' => 'manual']);
        SeoStoredData::profile('broken.test', ['last_sync_error' => 'Search Console: quota']);
        GoogleConnection::query()->create(['account_email' => 'systems@example.com', 'refresh_token' => 'refresh-canary', 'status' => 'active']);

        $content = seoTool($this->user, SeoListSites::class, []);
        $rows = collect(seoRows($content))->keyBy('domain');

        expect($content['total'])->toBe(2)
            ->and($content['connection'])->toMatchArray(['status' => 'active', 'account' => 'systems@example.com'])
            ->and($rows['acme.com'])->toMatchArray([
                'id' => $this->siteId,
                'state' => 'ok',
                'gsc_property' => 'https://www.acme.com/',
                'ga4_property_id' => '123456',
                'brand_terms' => 'acme',
                'key_events' => ['generate_lead', 'form_submit', 'contact_link_click'],
                'report_notes' => 'Plain tone. Never promise rankings.',
                'latest_final_month' => '2026-09',
                'provisional_months' => ['2026-10'],
                'months_missing_ai_impressions' => ['2026-10'],
            ])
            ->and($rows['broken.test']['state'])->toBe('sync_error')
            ->and(json_encode($content))->not->toContain('refresh-canary');
    });

    it('filters by search, state and site, and pages', function () {
        SeoStoredData::profile('beta.test', ['last_sync_error' => 'GA4: boom']);
        SeoStoredData::profile('gamma.test');

        expect(array_column(seoRows(seoTool($this->user, SeoListSites::class, ['search' => 'BETA'])), 'domain'))->toBe(['beta.test'])
            ->and(array_column(seoRows(seoTool($this->user, SeoListSites::class, ['state' => 'sync_error'])), 'domain'))->toBe(['beta.test'])
            ->and(array_column(seoRows(seoTool($this->user, SeoListSites::class, ['site' => 'https://www.gamma.test/pricing'])), 'domain'))->toBe(['gamma.test']);

        $first = seoTool($this->user, SeoListSites::class, ['limit' => 2]);
        $second = seoTool($this->user, SeoListSites::class, ['limit' => 2, 'cursor' => $first['next_cursor']]);

        expect(array_column(seoRows($first), 'domain'))->toBe(['acme.com', 'beta.test'])
            ->and($first['next_cursor'])->toBe('2')
            ->and(array_column(seoRows($second), 'domain'))->toBe(['gamma.test'])
            ->and($second['next_cursor'])->toBeNull();
    });
});

describe('seo_month_numbers', function () {
    beforeEach(function () {
        SeoStoredData::gscMonth($this->siteId, '2026-10', 100, 2000, 8.0, nonbrandedClicks: 40);
        SeoStoredData::gscMonth($this->siteId, '2026-09', 80, 1600, 9.0, nonbrandedClicks: 30);
        SeoStoredData::ga4Month($this->siteId, '2026-10');
        SeoStoredData::ga4Month($this->siteId, '2026-09', ['Organic Search' => 400, 'Direct' => 100]);
    });

    it('returns exactly what SearchNumbers computes, inside the envelope', function () {
        $expected = app(SearchNumbers::class)->month($this->profile, CarbonImmutable::parse('2026-10-01'));

        $content = seoTool($this->user, SeoMonthNumbers::class, ['site' => 'acme.com', 'month' => '2026-10']);

        expect($content['gsc'])->toBe($expected['gsc'])
            ->and($content['ga4'])->toEqual($expected['ga4'])
            ->and($content['ai'])->toBe($expected['ai'])
            ->and($content['meta']['caveats'])->toBe($expected['caveats'])
            ->and($content['meta']['data_state'])->toBe('final')
            ->and($content['meta']['final_after'])->toBe('2026-11-05')
            ->and($content['meta']['site'])->toBe(['id' => $this->siteId, 'domain' => 'acme.com', 'client' => null])
            ->and($content['meta']['period'])->toBe(['month' => '2026-10', 'previous_month' => '2026-09', 'yoy_month' => '2025-10'])
            ->and($content['meta']['source'])->toBe('stored')
            ->and($content['meta']['freshness']['gsc_latest_date'])->toBe('2026-10-15')
            ->and($content['summary'])->toContain('acme.com, October 2026: final. 100 clicks.')
            ->and($content['report_notes'])->toBe('Plain tone. Never promise rankings.');
    });

    it('defaults to the last complete month and takes a site id', function () {
        $content = seoTool($this->user, SeoMonthNumbers::class, ['site' => (string) $this->siteId]);

        expect($content['meta']['period']['month'])->toBe('2026-10');
    });

    it('keeps suppressed and missing values null, never zero', function () {
        // Last year's October baseline does not exist; September 2025 would
        // straddle the num=100 change.
        SeoStoredData::gscMonth($this->siteId, '2025-10', 50, 1000, 10.0);
        SeoStoredData::gscMonth($this->siteId, '2025-09', 50, 1000, 30.0);

        $october2025 = seoTool($this->user, SeoMonthNumbers::class, ['site' => 'acme.com', 'month' => '2025-10']);

        expect($october2025['gsc']['position']['previous_comparable'])->toBeFalse()
            ->and($october2025['gsc']['position']['previous_change'])->toBeNull()
            ->and(array_column($october2025['meta']['caveats'], 'code'))->toContain('position_previous')
            // GA4 has nothing stored for 2025.
            ->and($october2025['ga4']['organic_sessions']['value'])->toBeNull()
            ->and($october2025['ai']['ai_impressions'])->toBeNull();
    });

    it('says a provisional month is provisional, first', function () {
        DB::table('seo_gsc_months')->where('site_id', $this->siteId)->where('month', '2026-10-01')->update(['is_final' => false]);

        $content = seoTool($this->user, SeoMonthNumbers::class, ['site' => 'acme.com', 'month' => '2026-10']);

        expect($content['meta']['data_state'])->toBe('provisional')
            ->and($content['summary'])->toContain('PROVISIONAL until 2026-11-05');
    });

    it('adds the sections asked for', function () {
        SeoStoredData::row($this->siteId, '2026-10', 'page', 'https://www.acme.com/', null, 60, 900);
        SeoStoredData::row($this->siteId, '2026-10', 'page', 'https://www.acme.com/new/', null, 9, 100);
        SeoStoredData::row($this->siteId, '2026-09', 'page', 'https://www.acme.com/', null, 45, 800);
        SeoStoredData::row($this->siteId, '2026-10', 'query', null, 'emergency plumber', 12, 300);
        SeoStoredData::row($this->siteId, '2026-10', 'query', null, 'site:acme.com', 50, 60);

        $bare = seoTool($this->user, SeoMonthNumbers::class, ['site' => 'acme.com', 'month' => '2026-10']);
        $full = seoTool($this->user, SeoMonthNumbers::class, [
            'site' => 'acme.com', 'month' => '2026-10',
            'include' => ['top_pages', 'top_queries', 'trend', 'channels'], 'trend_months' => 3,
        ]);

        expect($bare)->not->toHaveKeys(['top_pages', 'top_queries', 'trend', 'channels'])
            ->and($full['top_pages'][0])->toMatchArray(['page' => 'https://www.acme.com/', 'clicks' => 60, 'prev_clicks' => 45, 'change' => 15])
            // Synced last month but absent: a real zero then.
            ->and($full['top_pages'][1])->toMatchArray(['prev_clicks' => 0, 'change' => 9])
            // Junk queries are left out.
            ->and(array_column($full['top_queries'], 'query'))->toBe(['emergency plumber'])
            ->and(array_column($full['trend'], 'month'))->toBe(['2026-08', '2026-09', '2026-10'])
            ->and($full['trend'][0]['clicks'])->toBeNull()
            ->and($full['trend'][2])->toMatchArray(['clicks' => 100, 'organic_sessions' => 500, 'data_state' => 'final'])
            ->and($full['channels'][0])->toMatchArray(['channel' => 'Organic Search', 'sessions' => 500, 'prev_sessions' => 400])
            ->and(collect($full['channels'])->firstWhere('channel', 'AI Assistant')['prev_sessions'])->toBe(0);
    });

    it('explains how to fix a site it cannot use', function (array $arguments, string $message) {
        Site::factory()->create(['domain' => 'noprofile.test']);

        ClockworkServer::actingAs($this->user)->tool(SeoMonthNumbers::class, $arguments)->assertHasErrors([$message]);
    })->with([
        'unknown site' => [['site' => 'nowhere.test'], 'No site matches'],
        'no profile' => [['site' => 'noprofile.test'], 'has no SEO profile yet'],
        'bad month' => [['site' => 'acme.com', 'month' => 'October'], 'month must look like 2026-10'],
    ]);
});

describe('seo_daily_series', function () {
    beforeEach(function () {
        foreach (['2026-11-03' => 10, '2026-11-04' => 2, '2026-11-08' => 12] as $date => $clicks) {
            DB::table('seo_gsc_daily')->insert(['site_id' => $this->siteId, 'date' => $date, 'segment' => 'all', 'clicks' => $clicks, 'impressions' => 200, 'ctr' => 0, 'position' => 7.26]);
        }
        DB::table('seo_gsc_daily')->insert(['site_id' => $this->siteId, 'date' => '2025-11-03', 'segment' => 'all', 'clicks' => 6, 'impressions' => 100, 'ctr' => 0, 'position' => 9]);
        DB::table('seo_ga4_daily')->insert([
            ['site_id' => $this->siteId, 'date' => '2026-11-03', 'dimension' => 'channel', 'value' => 'Organic Search', 'sessions' => 40, 'engaged_sessions' => 20],
            ['site_id' => $this->siteId, 'date' => '2026-11-03', 'dimension' => 'channel', 'value' => 'AI Assistant', 'sessions' => 3, 'engaged_sessions' => 1],
            ['site_id' => $this->siteId, 'date' => '2026-11-03', 'dimension' => 'ai_source', 'value' => 'chatgpt.com / referral', 'sessions' => 99, 'engaged_sessions' => 1],
            ['site_id' => $this->siteId, 'date' => '2026-11-04', 'dimension' => 'channel', 'value' => 'Direct', 'sessions' => 9, 'engaged_sessions' => 1],
        ]);
    });

    it('returns one row per day, null where nothing is stored, and flags days that can still change', function () {
        $content = seoTool($this->user, SeoDailySeries::class, [
            'site' => 'acme.com', 'start' => '2026-11-03', 'end' => '2026-11-06',
            'series' => ['clicks', 'ctr', 'position', 'organic_sessions', 'ai_assistant_sessions'],
        ]);
        $rows = collect(seoRows($content))->keyBy('date');

        expect($content['columns'])->toBe(['date', 'clicks', 'ctr', 'position', 'organic_sessions', 'ai_assistant_sessions', 'may_change'])
            ->and($rows)->toHaveCount(4)
            ->and($rows['2026-11-03'])->toMatchArray(['clicks' => 10, 'ctr' => 5.0, 'position' => 7.3, 'organic_sessions' => 40, 'ai_assistant_sessions' => 3, 'may_change' => false])
            // GA4 synced that day with no organic row: a real zero.
            ->and($rows['2026-11-04'])->toMatchArray(['clicks' => 2, 'organic_sessions' => 0])
            // Nothing stored at all: unknown, not zero.
            ->and($rows['2026-11-05'])->toMatchArray(['clicks' => null, 'organic_sessions' => null])
            // Within the last 5 days (today is 11 November, Pacific).
            ->and($rows['2026-11-06']['may_change'])->toBeTrue()
            ->and(array_column($content['meta']['caveats'], 'code'))->toContain('ga4_days_do_not_sum');
    });

    it('defaults to 90 days ending on the latest stored day', function () {
        $content = seoTool($this->user, SeoDailySeries::class, ['site' => 'acme.com']);

        expect($content['meta']['period'])->toMatchArray(['start' => '2026-08-10', 'end' => '2026-11-08'])
            ->and($content['columns'])->toBe(['date', 'clicks', 'impressions', 'may_change']);
    });

    it('adds the same dates a year earlier', function () {
        $content = seoTool($this->user, SeoDailySeries::class, ['site' => 'acme.com', 'start' => '2026-11-03', 'end' => '2026-11-03', 'series' => ['clicks'], 'yoy' => true]);

        expect(seoRows($content)[0])->toMatchArray(['clicks' => 10, 'clicks_yoy' => 6]);
    });

    it('overlays annotations and outages, with times only', function () {
        SiteUptimeEvent::query()->create(['site_id' => $this->siteId, 'event_type' => 'down', 'event_at' => '2026-11-04 03:00:00', 'error' => 'db-password-canary', 'diagnosis' => ['secret' => 'diagnosis-canary']]);
        SiteUptimeEvent::query()->create(['site_id' => $this->siteId, 'event_type' => 'up', 'event_at' => '2026-11-04 05:30:00']);

        $content = seoTool($this->user, SeoDailySeries::class, ['site' => 'acme.com', 'start' => '2026-09-01', 'end' => '2026-11-08']);

        expect($content['outages'])->toHaveCount(1)
            ->and(array_keys($content['outages'][0]))->toBe(['down_at', 'up_at'])
            ->and($content['outages'][0]['down_at'])->toStartWith('2026-11-04T03:00:00')
            // The seeded multimodal split falls inside the range.
            ->and(array_column($content['annotations'], 'starts_on'))->toContain('2026-09-24')
            ->and(json_encode($content))->not->toContain('canary');
    });

    it('refuses a range that is too long or backwards', function (array $range, string $message) {
        ClockworkServer::actingAs($this->user)->tool(SeoDailySeries::class, ['site' => 'acme.com'] + $range)->assertHasErrors([$message]);
    })->with([
        [['start' => '2025-01-01', 'end' => '2026-11-01'], '490 days at most'],
        [['start' => '2026-11-05', 'end' => '2026-11-01'], 'on or before end'],
        [['start' => 'last week'], 'must look like'],
    ]);
});

describe('seo_search_rows', function () {
    beforeEach(function () {
        SeoStoredData::gscMonth($this->siteId, '2026-10', 100, 2000, 8.0);
        SeoStoredData::gscMonth($this->siteId, '2026-09', 80, 1600, 9.0);

        foreach (['2026-10' => 1, '2026-09' => 0] as $month => $extra) {
            SeoStoredData::row($this->siteId, $month, 'query', null, 'acme plumbing', 40 + $extra * 10, 200, 1.5, branded: true);
            SeoStoredData::row($this->siteId, $month, 'query', null, 'emergency plumber', 10 + $extra * 20, 400, 11.0);
            SeoStoredData::row($this->siteId, $month, 'page', 'https://www.acme.com/', null, 50, 500, 3.0);
            SeoStoredData::row($this->siteId, $month, 'page_query', 'https://www.acme.com/', 'acme plumbing', 40, 200, 1.5, branded: true);
            SeoStoredData::row($this->siteId, $month, 'page_query', 'https://www.acme.com/', 'emergency plumber', 4, 100, 12.0);
            SeoStoredData::row($this->siteId, $month, 'page_query', 'https://www.acme.com/services/', 'emergency plumber', 6, 300, 10.0);
        }
        SeoStoredData::row($this->siteId, '2026-10', 'query', null, 'site:acme.com', 3, 5);
        SeoStoredData::row($this->siteId, '2026-10', 'query', null, 'drain cleaning', 2, 90, 20.0);
    });

    it('lists a month of queries by clicks, without junk', function () {
        $content = seoTool($this->user, SeoSearchRows::class, ['site' => 'acme.com', 'dimension' => 'query', 'end_month' => '2026-10']);

        expect($content['columns'])->toBe(['query', 'clicks', 'impressions', 'ctr', 'position'])
            ->and(array_column(seoRows($content), 'query'))->toBe(['acme plumbing', 'emergency plumber', 'drain cleaning'])
            ->and(seoRows($content)[0])->toMatchArray(['clicks' => 50, 'impressions' => 200, 'ctr' => 25.0, 'position' => 1.5])
            ->and($content['total_rows'])->toBe(3)
            ->and($content['meta']['period']['months'])->toBe([['month' => '2026-10', 'state' => 'final', 'possibly_truncated' => false]]);

        $withJunk = seoTool($this->user, SeoSearchRows::class, ['site' => 'acme.com', 'dimension' => 'query', 'end_month' => '2026-10', 'exclude_junk' => false]);
        expect(array_column(seoRows($withJunk), 'query'))->toContain('site:acme.com');
    });

    it('adds months up and weights position by impressions', function () {
        SeoStoredData::row($this->siteId, '2026-08', 'query', null, 'emergency plumber', 0, 1200, 31.0);
        SeoStoredData::gscMonth($this->siteId, '2026-08', 10, 1300, 20.0);

        $content = seoTool($this->user, SeoSearchRows::class, ['site' => 'acme.com', 'dimension' => 'query', 'end_month' => '2026-10', 'months' => 3, 'query' => ['value' => 'Emergency']]);
        $row = seoRows($content)[0];

        // 30 + 10 + 0 clicks; (400*11 + 400*11 + 1200*31) / 2000 = 23.
        expect($row)->toMatchArray(['query' => 'emergency plumber', 'clicks' => 40, 'impressions' => 2000, 'position' => 23.0])
            ->and($content['total_rows'])->toBe(1);
    });

    it('sums non-branded page x query rows for a page and says they are known queries only', function () {
        $content = seoTool($this->user, SeoSearchRows::class, ['site' => 'acme.com', 'dimension' => 'page', 'end_month' => '2026-10', 'segment' => 'nonbranded']);
        $rows = collect(seoRows($content))->keyBy('page');

        expect($rows['https://www.acme.com/'])->toMatchArray(['clicks' => 4, 'impressions' => 100])
            ->and($rows['https://www.acme.com/services/'])->toMatchArray(['clicks' => 6, 'impressions' => 300])
            ->and(array_column($content['meta']['caveats'], 'code'))->toContain('nonbranded_known_queries_only');

        $queries = seoTool($this->user, SeoSearchRows::class, ['site' => 'acme.com', 'dimension' => 'query', 'end_month' => '2026-10', 'segment' => 'nonbranded']);
        expect(array_column(seoRows($queries), 'query'))->not->toContain('acme plumbing');
    });

    it('compares with the previous period and sorts by the change', function () {
        $content = seoTool($this->user, SeoSearchRows::class, [
            'site' => 'acme.com', 'dimension' => 'query', 'end_month' => '2026-10', 'compare' => 'previous_period', 'sort' => 'click_change',
        ]);
        $rows = seoRows($content);

        expect(array_column($rows, 'query'))->toBe(['emergency plumber', 'acme plumbing', 'drain cleaning'])
            ->and($rows[0])->toMatchArray(['clicks' => 30, 'prev_clicks' => 10, 'click_change' => 20])
            // New this month, and last month was synced: zero then.
            ->and($rows[2])->toMatchArray(['prev_clicks' => 0, 'click_change' => 2])
            ->and($content['meta']['period']['baseline_months'])->toBe(['2026-09']);
    });

    it('leaves a comparison null when the baseline was never synced', function () {
        $content = seoTool($this->user, SeoSearchRows::class, ['site' => 'acme.com', 'dimension' => 'query', 'end_month' => '2026-10', 'compare' => 'yoy']);

        expect(seoRows($content)[0])->toMatchArray(['prev_clicks' => null, 'click_change' => null])
            ->and(array_column($content['meta']['caveats'], 'code'))->toContain('baseline_not_synced');
    });

    it('filters by page, by minimum impressions, and pages with a cursor', function () {
        $byPage = seoTool($this->user, SeoSearchRows::class, [
            'site' => 'acme.com', 'dimension' => 'page_query', 'end_month' => '2026-10',
            'page' => ['value' => 'https://www.acme.com/services', 'match' => 'prefix'],
        ]);
        expect(seoRows($byPage))->toHaveCount(1)->and(seoRows($byPage)[0]['query'])->toBe('emergency plumber');

        $big = seoTool($this->user, SeoSearchRows::class, ['site' => 'acme.com', 'dimension' => 'query', 'end_month' => '2026-10', 'min_impressions' => 150]);
        expect(array_column(seoRows($big), 'query'))->toBe(['acme plumbing', 'emergency plumber']);

        $first = seoTool($this->user, SeoSearchRows::class, ['site' => 'acme.com', 'dimension' => 'query', 'end_month' => '2026-10', 'limit' => 2]);
        $second = seoTool($this->user, SeoSearchRows::class, ['site' => 'acme.com', 'dimension' => 'query', 'end_month' => '2026-10', 'limit' => 2, 'cursor' => $first['next_cursor']]);
        expect($first['next_cursor'])->toBe('2')
            ->and(array_column(seoRows($second), 'query'))->toBe(['drain cleaning'])
            ->and($second['next_cursor'])->toBeNull();
    });

    it('flags months with no stored data', function () {
        $content = seoTool($this->user, SeoSearchRows::class, ['site' => 'acme.com', 'dimension' => 'query', 'end_month' => '2026-10', 'months' => 3]);

        expect(array_column($content['meta']['period']['months'], 'state'))->toBe(['not_synced', 'final', 'final'])
            ->and(array_column($content['meta']['caveats'], 'code'))->toContain('months_not_synced');
    });

    it('refuses inputs that cannot work', function (array $arguments, string $message) {
        $this->profile->update(['brand_terms' => null, 'brand_regex' => null]);

        ClockworkServer::actingAs($this->user)->tool(SeoSearchRows::class, ['site' => 'acme.com'] + $arguments)->assertHasErrors([$message]);
    })->with([
        [['dimension' => 'country'], 'dimension must be one of'],
        [['dimension' => 'query', 'sort' => 'click_change'], 'needs compare'],
        [['dimension' => 'query', 'segment' => 'nonbranded'], 'No brand terms are set'],
    ]);
});
