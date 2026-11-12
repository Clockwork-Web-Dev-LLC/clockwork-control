<?php

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Mcp\Servers\ClockworkServer;
use Modules\Seo\DataForSeo\Feature;
use Modules\Seo\Jobs\FetchKeywordMetricsJob;
use Modules\Seo\Keywords\KeywordMetricsService;
use Modules\Seo\Keywords\Market;
use Modules\Seo\Mcp\Tools\SeoKeywordMetrics;
use Modules\Seo\Mcp\Tools\SeoKeywordMetricsFetch;
use Modules\Seo\Models\ApiUsage;
use Modules\Seo\Models\KeywordMetric;
use Tests\Fixtures\DataForSeoFixtures;
use Tests\Fixtures\SeoStoredData;

function kwTool(User $user, string $tool, array $arguments): array
{
    $response = ClockworkServer::actingAs($user)->tool($tool, $arguments)->assertOk();

    return (fn () => $this->response->toArray()['result']['structuredContent'])->call($response);
}

function storedMetric(string $keyword, array $overrides = []): void
{
    KeywordMetric::query()->create($overrides + [
        'keyword' => $keyword, 'market_key' => '2840-en', 'provider' => 'dataforseo',
        'volume' => 1300, 'cpc' => 12.5, 'difficulty' => 34, 'intent' => 'commercial', 'fetched_at' => now(),
    ]);
}

/** keyword_overview/live answer, in the shape of DataForSEO's published spec. */
function overviewResponse(array $keywords, float $cost = 0.0122): array
{
    return DataForSeoFixtures::envelope([DataForSeoFixtures::task('ov-1', [[
        'se_type' => 'google', 'location_code' => 2840, 'language_code' => 'en', 'items_count' => count($keywords),
        'items' => array_map(fn (array $k) => [
            'se_type' => 'google', 'keyword' => $k[0],
            'keyword_info' => ['search_volume' => $k[1], 'cpc' => $k[2], 'competition' => 0.41, 'monthly_searches' => [['year' => 2026, 'month' => 10, 'search_volume' => $k[1]]]],
            'keyword_properties' => ['keyword_difficulty' => $k[3]],
            'search_intent_info' => ['main_intent' => $k[4], 'foreign_intent' => ['informational']],
        ], $keywords),
    ]], $cost)]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['clockwork.seo.dataforseo_login' => 'api-login', 'clockwork.seo.dataforseo_password' => 'api-password']);
    $this->user = User::factory()->operator()->create();
});

describe('seo_keyword_metrics', function () {
    it('returns stored figures and a quote for the rest, without spending or calling out', function () {
        Http::fake();
        storedMetric('emergency plumber');
        storedMetric('old keyword', ['fetched_at' => now()->subDays(45)]);
        storedMetric('unknown thing', ['volume' => null, 'cpc' => null, 'difficulty' => null, 'intent' => null, 'no_data' => true]);

        $content = kwTool($this->user, SeoKeywordMetrics::class, [
            'keywords' => ['Emergency  Plumber', 'old keyword', 'unknown thing', 'water heater repair', 'emergency plumber', 'site:acme.com'],
        ]);
        $rows = collect($content['rows'])->map(fn ($r) => array_combine($content['columns'], $r))->keyBy('keyword');

        expect($rows['emergency plumber'])->toMatchArray(['volume' => 1300, 'cpc_usd' => 12.5, 'difficulty' => 34, 'difficulty_band' => 'Medium', 'intent' => 'commercial', 'state' => 'fresh'])
            ->and($rows['old keyword']['state'])->toBe('stale')
            // Nothing known is null with a state, never zero.
            ->and($rows['unknown thing'])->toMatchArray(['volume' => null, 'difficulty' => null, 'difficulty_band' => null, 'state' => 'no_data'])
            ->and($content['missing'])->toBe(['water heater repair'])
            ->and($content['stale'])->toBe(['old keyword'])
            ->and($content['rejected'][0]['keyword'])->toBe('site:acme.com')
            ->and($content['quote'])->toMatchArray(['keywords_to_buy' => 2, 'calls' => 1, 'estimate_usd' => 0.0122, 'would_be_refused' => null])
            ->and($content['summary'])->toContain('ask the person');

        Http::assertNothingSent();
        expect(ApiUsage::query()->count())->toBe(0);
    });

    it('offers no quote when everything is stored and fresh', function () {
        storedMetric('emergency plumber');

        $content = kwTool($this->user, SeoKeywordMetrics::class, ['keywords' => ['emergency plumber']]);

        expect($content['quote'])->toBeNull()->and($content['summary'])->toContain('Nothing to buy');
    });

    it('uses the site\'s market, and treats an unbought city as something to buy', function () {
        $profile = SeoStoredData::profile('acme.com', ['market_location_code' => 2124, 'market_language_code' => 'en', 'market_city' => 'Toronto,Ontario,Canada']);
        storedMetric('emergency plumber', ['market_key' => '2124-en']);

        $content = kwTool($this->user, SeoKeywordMetrics::class, ['keywords' => ['emergency plumber'], 'site' => 'acme.com']);

        expect($content['market'])->toBe(['location_code' => 2124, 'language_code' => 'en', 'city' => 'Toronto,Ontario,Canada'])
            ->and($content['stale'])->toBe(['emergency plumber'])
            ->and($content['quote']['includes_city_volume'])->toBe('Toronto,Ontario,Canada')
            // One overview call plus one city-volume call.
            ->and($content['quote']['calls'])->toBe(2);
    });

    it('says up front when the purchase would be refused', function () {
        DB::table('seo_api_usage')->insert(['provider' => 'dataforseo', 'feature' => Feature::KEYWORDS, 'endpoint' => '/x', 'tasks' => 1, 'cost_usd' => 19.999, 'status' => 'ok', 'created_at' => now()]);

        $content = kwTool($this->user, SeoKeywordMetrics::class, ['keywords' => ['water heater repair']]);

        expect($content['quote']['would_be_refused'])->toContain('cap');
    });

    it('refuses a bad list or market', function (array $arguments, string $message) {
        ClockworkServer::actingAs($this->user)->tool(SeoKeywordMetrics::class, $arguments)->assertHasErrors([$message]);
    })->with([
        [['keywords' => []], 'list of 1 to 700'],
        [['keywords' => array_fill(0, 701, 'x')], 'list of 1 to 700'],
        [['keywords' => ['a'], 'market' => ['location_code' => 'US']], 'numeric location_code'],
        [['keywords' => ['a'], 'site' => 'nowhere.test'], 'No site matches'],
    ]);
});

describe('seo_keyword_metrics_fetch', function () {
    beforeEach(function () {
        $this->quote = kwTool($this->user, SeoKeywordMetrics::class, ['keywords' => ['water heater repair', 'made up zzz']])['quote'];
    });

    it('buys what was quoted, stores it, and records the real cost against the person', function () {
        Http::fake([DataForSeoFixtures::API => Http::response(overviewResponse([['Water Heater Repair', 2400, 18.2, 41, 'transactional']], 0.01224))]);

        $content = kwTool($this->user, SeoKeywordMetricsFetch::class, ['quote_id' => $this->quote['quote_id'], 'confirm_cost_usd' => $this->quote['estimate_usd']]);

        expect($content['queued'])->toBeTrue()->and($content['keywords'])->toBe(2);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v3/dataforseo_labs/google/keyword_overview/live')
            && $request->data() === [['keywords' => ['water heater repair', 'made up zzz'], 'location_code' => 2840, 'language_code' => 'en']]);

        $bought = KeywordMetric::query()->where('keyword', 'water heater repair')->sole();
        expect($bought->volume)->toBe(2400)
            ->and($bought->cpc)->toBe(18.2)
            ->and($bought->difficulty)->toBe(41)
            ->and($bought->intent)->toBe('transactional')
            ->and($bought->secondary_intents)->toBe(['informational'])
            // Asked for, not returned: remembered as no data so it isn't bought again.
            ->and(KeywordMetric::query()->where('keyword', 'made up zzz')->sole()->no_data)->toBeTrue()
            ->and(KeywordMetric::query()->where('keyword', 'made up zzz')->sole()->volume)->toBeNull();

        $ledger = ApiUsage::query()->sole();
        expect($ledger->cost_usd)->toBe(0.01224)->and($ledger->user_id)->toBe($this->user->id)->and($ledger->feature)->toBe('keywords');

        // Now stored: asking again offers nothing to buy.
        expect(kwTool($this->user, SeoKeywordMetrics::class, ['keywords' => ['water heater repair', 'made up zzz']])['quote'])->toBeNull();
    });

    it('queues the purchase so the tool answers at once', function () {
        Queue::fake();

        kwTool($this->user, SeoKeywordMetricsFetch::class, ['quote_id' => $this->quote['quote_id'], 'confirm_cost_usd' => 1]);

        Queue::assertPushed(FetchKeywordMetricsJob::class, fn ($job) => $job->keywords === ['water heater repair', 'made up zzz'] && $job->userId === $this->user->id);
    });

    it('works once: a used quote cannot buy again', function () {
        Queue::fake();
        $arguments = ['quote_id' => $this->quote['quote_id'], 'confirm_cost_usd' => $this->quote['estimate_usd']];
        kwTool($this->user, SeoKeywordMetricsFetch::class, $arguments);

        ClockworkServer::actingAs($this->user)->tool(SeoKeywordMetricsFetch::class, $arguments)->assertHasErrors(['expired or was already used']);
        Queue::assertPushed(FetchKeywordMetricsJob::class, 1);
    });

    it('refuses without spending', function (Closure $arguments, string $message, ?Closure $before = null) {
        Queue::fake();
        Http::fake();
        if ($before) {
            $before->call($this);
        }

        ClockworkServer::actingAs($this->actor ?? $this->user)->tool(SeoKeywordMetricsFetch::class, $arguments->call($this))->assertHasErrors([$message]);

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    })->with([
        'a confirmed cost below the quote' => [fn () => ['quote_id' => $this->quote['quote_id'], 'confirm_cost_usd' => 0.001], 'was confirmed'],
        'someone else\'s quote' => [fn () => ['quote_id' => $this->quote['quote_id'], 'confirm_cost_usd' => 1], 'made for someone else', fn () => $this->actor = User::factory()->operator()->create()],
        'an expired quote' => [fn () => ['quote_id' => $this->quote['quote_id'], 'confirm_cost_usd' => 1], 'expired or was already used', fn () => $this->travel(16)->minutes()],
        'an unknown quote' => [fn () => ['quote_id' => 'nope', 'confirm_cost_usd' => 1], 'expired or was already used'],
        'a cap reached since the quote' => [fn () => ['quote_id' => $this->quote['quote_id'], 'confirm_cost_usd' => 1], 'cap', fn () => DB::table('seo_api_usage')->insert(['provider' => 'dataforseo', 'feature' => Feature::KEYWORDS, 'endpoint' => '/x', 'tasks' => 1, 'cost_usd' => 20, 'status' => 'ok', 'created_at' => now()])],
        'no DataForSEO login' => [fn () => ['quote_id' => $this->quote['quote_id'], 'confirm_cost_usd' => 1], 'not set up', fn () => config(['clockwork.seo.dataforseo_login' => null])],
    ]);

    it('keeps the quote usable after a low confirmation, so the person can agree to the real figure', function () {
        Queue::fake();
        ClockworkServer::actingAs($this->user)->tool(SeoKeywordMetricsFetch::class, ['quote_id' => $this->quote['quote_id'], 'confirm_cost_usd' => 0.001])->assertHasErrors();

        kwTool($this->user, SeoKeywordMetricsFetch::class, ['quote_id' => $this->quote['quote_id'], 'confirm_cost_usd' => $this->quote['estimate_usd']]);
        Queue::assertPushed(FetchKeywordMetricsJob::class, 1);
    });

    it('is marked as a spending tool that needs a person', function () {
        $tool = app(SeoKeywordMetricsFetch::class)->toArray();

        expect($tool['annotations'])->toMatchArray(['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false])
            ->and($tool['_meta']['anthropic/requiresUserInteraction'])->toBeTrue();
    });
});

describe('city volume', function () {
    it('buys city volume from the Google Ads endpoint and keeps a missing city figure null', function () {
        Http::fake([
            '*/keyword_overview/live' => Http::response(overviewResponse([['emergency plumber', 1300, 12.5, 34, 'commercial'], ['drain cleaning', 900, 8.0, 22, 'commercial']])),
            '*/google_ads/search_volume/live' => Http::response(DataForSeoFixtures::envelope([DataForSeoFixtures::task('ads-1', [
                ['keyword' => 'emergency plumber', 'search_volume' => 210, 'cpc' => 14.0],
            ], 0.09)])),
        ]);
        $market = new Market(2840, 'en', 'Phoenix,Arizona,United States');

        $summary = app(KeywordMetricsService::class)->fetch(['emergency plumber', 'drain cleaning'], $market);

        expect($summary['error'])->toBeNull()->and($summary['stored'])->toBe(2);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/google_ads/search_volume/live')
            && $request->data()[0]['location_name'] === 'Phoenix,Arizona,United States');

        expect(KeywordMetric::query()->where('keyword', 'emergency plumber')->sole()->local_volumes)->toBe(['Phoenix,Arizona,United States' => 210])
            // Asked, nothing returned: null for the city, and not asked again.
            ->and(KeywordMetric::query()->where('keyword', 'drain cleaning')->sole()->local_volumes)->toBe(['Phoenix,Arizona,United States' => null])
            ->and(app(KeywordMetricsService::class)->lookup(['emergency plumber', 'drain cleaning'], $market)['stale'])->toBe([])
            ->and(ApiUsage::query()->count())->toBe(2);
    });
});
