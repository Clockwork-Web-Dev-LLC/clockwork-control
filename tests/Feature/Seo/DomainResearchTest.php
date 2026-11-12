<?php

use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Mcp\Servers\ClockworkServer;
use Modules\Seo\DataForSeo\BudgetGuard;
use Modules\Seo\Keywords\Market;
use Modules\Seo\Mcp\Tools\SeoDomainOverview;
use Modules\Seo\Mcp\Tools\SeoDomainResearchFetch;
use Modules\Seo\Mcp\Tools\SeoRankedKeywords;
use Modules\Seo\Models\ApiUsage;
use Modules\Seo\Models\DomainSnapshot;
use Modules\Seo\Models\RankedKeyword;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Research\DomainResearch;
use Tests\Fixtures\DataForSeoFixtures;

function labsMetrics(int $count = 480): array
{
    return ['organic' => [
        'pos_1' => 5, 'pos_2_3' => 12, 'pos_4_10' => 40, 'pos_11_20' => 60, 'pos_21_30' => 50, 'pos_31_40' => 50, 'pos_41_50' => 50,
        'pos_51_60' => 50, 'pos_61_70' => 50, 'pos_71_80' => 50, 'pos_81_90' => 40, 'pos_91_100' => 23,
        'etv' => 1234.567, 'count' => $count, 'estimated_paid_traffic_cost' => 9876.5, 'is_new' => 30, 'is_up' => 80, 'is_down' => 60, 'is_lost' => 12,
    ], 'paid' => ['count' => 0]];
}

function overviewResponseFor(string $domain, ?array $metrics): array
{
    return DataForSeoFixtures::envelope([DataForSeoFixtures::task('ov', [[
        'se_type' => 'google', 'target' => $domain, 'location_code' => 2840, 'language_code' => 'en', 'total_count' => $metrics ? 1 : 0, 'items_count' => $metrics ? 1 : 0,
        'items' => $metrics ? [['se_type' => 'google', 'location_code' => 2840, 'language_code' => 'en', 'metrics' => $metrics]] : null,
    ]], 0.01212)]);
}

function rankedItem(string $keyword, int $position, ?int $volume, float $etv, ?int $previous = null): array
{
    return [
        'se_type' => 'google',
        'keyword_data' => ['keyword' => $keyword, 'keyword_info' => ['search_volume' => $volume, 'cpc' => 4.256], 'keyword_properties' => ['keyword_difficulty' => 31], 'search_intent_info' => ['main_intent' => 'commercial']],
        'ranked_serp_element' => ['serp_item' => ['type' => 'organic', 'rank_group' => $position, 'rank_absolute' => $position + 2, 'url' => "https://www.acme.com/{$position}", 'etv' => $etv, 'rank_changes' => ['previous_rank_absolute' => $previous, 'is_new' => $previous === null]], 'last_updated_time' => '2026-10-20 03:00:00 +00:00'],
    ];
}

function rankedResponse(array $items, int $total = 480): array
{
    return DataForSeoFixtures::envelope([DataForSeoFixtures::task('rk', [[
        'se_type' => 'google', 'target' => 'acme.com', 'total_count' => $total, 'items_count' => count($items), 'metrics' => labsMetrics($total), 'items' => $items,
    ]], 0.01236)]);
}

function researchTool(User $user, string $tool, array $arguments): array
{
    $response = ClockworkServer::actingAs($user)->tool($tool, $arguments)->assertOk();

    return (fn () => $this->response->toArray()['result']['structuredContent'])->call($response);
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['clockwork.seo.dataforseo_login' => 'api-login', 'clockwork.seo.dataforseo_password' => 'api-password-canary']);
    $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00:00', 'UTC'));
    $this->user = User::factory()->operator()->create();
    $this->site = Site::factory()->create(['domain' => 'www.acme.com']);
    SiteProfile::create(['site_id' => $this->site->id, 'is_enabled' => true, 'competitors' => ['rival.com']]);
    $this->research = app(DomainResearch::class);
});

it('stores a month count with buckets added up, and unknown when the provider has nothing', function () {
    Http::fake(['*/domain_rank_overview/live' => Http::sequence()
        ->push(overviewResponseFor('acme.com', labsMetrics()))
        ->push(overviewResponseFor('tiny.com', null))]);

    $result = $this->research->snapshot('https://WWW.acme.com/', new Market, $this->site->id);
    $snapshot = $result['snapshot'];

    expect($result['ok'])->toBeTrue()
        ->and($snapshot->domain)->toBe('acme.com')
        ->and($snapshot->month->toDateString())->toBe('2026-11-01')
        ->and($snapshot->keywords)->toBe(480)
        ->and($snapshot->top3)->toBe(17)
        ->and($snapshot->top10)->toBe(57)
        ->and($snapshot->top20)->toBe(117)
        ->and($snapshot->top100)->toBe(480)
        ->and($snapshot->estimated_traffic)->toBe(1234.57)
        ->and($snapshot->lost_keywords)->toBe(12)
        ->and(ApiUsage::query()->sole()->feature)->toBe('research');

    Http::assertSent(fn (Request $r) => $r->data() === [['target' => 'acme.com', 'location_code' => 2840, 'language_code' => 'en']]);

    $empty = $this->research->snapshot('tiny.com', new Market)['snapshot'];
    expect($empty->keywords)->toBeNull()->and($empty->top10)->toBeNull()->and($empty->buckets)->toBeNull();
});

it('replaces the stored keyword list on each pull and refreshes the count from the same call', function () {
    RankedKeyword::create(['domain' => 'acme.com', 'market_key' => '2840-en', 'provider' => 'dataforseo', 'keyword' => 'old keyword', 'position' => 9]);

    Http::fake(['*/ranked_keywords/live' => Http::response(rankedResponse([
        rankedItem('Plumber Austin', 3, 1300, 210.4, 6),
        rankedItem('drain cleaning', 14, 880, 12.0),
        rankedItem('plumber austin', 8, 1300, 1.0),
        rankedItem('unknown volume', 40, null, 0.2),
    ]))]);

    $result = $this->research->pullRankedKeywords('acme.com', new Market, 500, $this->site->id);

    expect($result)->toMatchArray(['ok' => true, 'stored' => 3, 'total' => 480])
        ->and(RankedKeyword::query()->pluck('keyword')->sort()->values()->all())->toBe(['drain cleaning', 'plumber austin', 'unknown volume']);

    $top = RankedKeyword::query()->where('keyword', 'plumber austin')->sole();
    expect($top->position)->toBe(3)
        ->and($top->previous_position)->toBe(6)
        ->and($top->cpc)->toBe(4.26)
        ->and($top->difficulty)->toBe(31)
        ->and($top->seen_at->toDateString())->toBe('2026-10-20')
        ->and(RankedKeyword::query()->where('keyword', 'unknown volume')->sole()->volume)->toBeNull()
        ->and(DomainSnapshot::query()->sole()->keywords)->toBe(480);

    Http::assertSent(fn (Request $r) => $r->data()[0]['limit'] === 500 && $r->data()[0]['item_types'] === ['organic']);
});

it('counts clients and competitors once a month from the command', function () {
    Http::fake(['*/domain_rank_overview/live' => Http::response(overviewResponseFor('x', labsMetrics()))]);

    $this->artisan('clockwork:seo-snapshot-domains')->expectsOutputToContain('Counted 2 domains')->assertSuccessful();
    expect(DomainSnapshot::query()->pluck('domain')->sort()->values()->all())->toBe(['acme.com', 'rival.com']);

    // Same month again: nothing bought.
    $this->artisan('clockwork:seo-snapshot-domains')->expectsOutputToContain('Counted 0 domains')->assertSuccessful();
    Http::assertSentCount(2);
});

it('stops the command at the spending cap', function () {
    app(BudgetGuard::class)->save(['caps' => ['research' => 0]]);
    Http::fake();

    $this->artisan('clockwork:seo-snapshot-domains')->assertFailed();
    Http::assertNothingSent();
    expect(DomainSnapshot::count())->toBe(0);
});

describe('read tools', function () {
    it('shows counts for the client and competitors and prices what is missing, without calling out', function () {
        Http::fake();
        foreach (['2026-10-01' => 450, '2026-11-01' => 480] as $month => $count) {
            DomainSnapshot::create(['domain' => 'acme.com', 'market_key' => '2840-en', 'month' => $month, 'provider' => 'dataforseo', 'keywords' => $count, 'top3' => 17, 'top10' => 57]);
        }

        $content = researchTool($this->user, SeoDomainOverview::class, ['site' => 'acme.com']);

        expect($content['summary'])->toContain('ranks for 480 keywords')
            ->and($content['domains'][0])->toMatchArray(['domain' => 'acme.com', 'is_client' => true, 'latest_month' => '2026-11', 'keywords' => 480, 'top10' => 57])
            ->and($content['domains'][0]['history'])->toBe([['2026-10', 450, 17, 57, null], ['2026-11', 480, 17, 57, null]])
            ->and($content['domains'][1])->toMatchArray(['domain' => 'rival.com', 'is_client' => false, 'keywords' => null])
            ->and(array_column($content['meta']['caveats'], 'code'))->toContain('provider_database', 'not_counted_yet');
        Http::assertNothingSent();
    });

    it('lists and filters stored keywords, and says when nothing is stored', function () {
        Http::fake();
        foreach ([['plumber austin', 3, 1300, 210.4, '/'], ['drain cleaning austin', 14, 880, 12.0, '/drains'], ['leak repair', 22, 90, 1.5, '/leaks']] as [$kw, $pos, $vol, $etv, $path]) {
            RankedKeyword::create(['domain' => 'acme.com', 'market_key' => '2840-en', 'provider' => 'dataforseo', 'keyword' => $kw, 'position' => $pos, 'volume' => $vol, 'estimated_traffic' => $etv, 'url' => "https://www.acme.com{$path}", 'fetched_at' => now()]);
        }

        $all = researchTool($this->user, SeoRankedKeywords::class, ['site' => 'acme.com']);
        $cols = array_flip($all['columns']);
        expect(array_column($all['rows'], $cols['keyword']))->toBe(['plumber austin', 'drain cleaning austin', 'leak repair']);

        $pageTwo = researchTool($this->user, SeoRankedKeywords::class, ['site' => 'acme.com', 'min_position' => 11, 'max_position' => 20, 'contains' => 'AUSTIN']);
        expect(array_column($pageTwo['rows'], $cols['keyword']))->toBe(['drain cleaning austin'])->and($pageTwo['rows_matching'])->toBe(1);

        $none = researchTool($this->user, SeoRankedKeywords::class, ['site' => 'acme.com', 'domain' => 'rival.com']);
        expect($none['rows'])->toBe([])->and(array_column($none['meta']['caveats'], 'code'))->toContain('nothing_stored');
        Http::assertNothingSent();
    });
});

describe('seo_domain_research_fetch', function () {
    it('prices first and spends nothing until the cost is confirmed', function () {
        Http::fake(['*/ranked_keywords/live' => Http::response(rankedResponse([rankedItem('plumber austin', 3, 1300, 210.4)]))]);

        $quote = researchTool($this->user, SeoDomainResearchFetch::class, ['site' => 'acme.com', 'what' => 'ranked_keywords', 'domain' => 'rival.com']);
        expect($quote)->toMatchArray(['bought' => false, 'domain' => 'rival.com', 'estimated_cost_usd' => 0.132, 'would_be_refused' => null]);
        Http::assertNothingSent();

        ClockworkServer::actingAs($this->user)->tool(SeoDomainResearchFetch::class, ['site' => 'acme.com', 'what' => 'ranked_keywords', 'domain' => 'rival.com', 'confirm_cost_usd' => 0.01])->assertHasErrors();
        Http::assertNothingSent();

        $bought = researchTool($this->user, SeoDomainResearchFetch::class, ['site' => 'acme.com', 'what' => 'ranked_keywords', 'domain' => 'rival.com', 'confirm_cost_usd' => 0.14]);
        expect($bought)->toMatchArray(['bought' => true, 'stored' => 1, 'cost_usd' => 0.01236, 'keywords' => 480])
            ->and(RankedKeyword::query()->where('domain', 'rival.com')->count())->toBe(1);

        $usage = ApiUsage::query()->sole();
        expect($usage->user_id)->toBe($this->user->id)->and($usage->site_id)->toBe($this->site->id);
    });

    it('is refused over the cap and for things that are not domains', function () {
        Http::fake();
        ClockworkServer::actingAs($this->user)->tool(SeoDomainResearchFetch::class, ['site' => 'acme.com', 'what' => 'count', 'domain' => 'not a domain', 'confirm_cost_usd' => 1])->assertHasErrors();

        app(BudgetGuard::class)->save(['caps' => ['research' => 0]]);
        $quote = researchTool($this->user, SeoDomainResearchFetch::class, ['site' => 'acme.com', 'what' => 'count']);
        expect($quote['would_be_refused'])->toContain('cap');
        ClockworkServer::actingAs($this->user)->tool(SeoDomainResearchFetch::class, ['site' => 'acme.com', 'what' => 'count', 'confirm_cost_usd' => 1])->assertHasErrors();

        Http::assertNothingSent();
    });
});
