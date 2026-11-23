<?php

use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Mcp\Servers\ClockworkServer;
use Modules\Seo\Keywords\Market;
use Modules\Seo\Mcp\Tools\SeoCompetitors;
use Modules\Seo\Mcp\Tools\SeoDomainResearchFetch;
use Modules\Seo\Mcp\Tools\SeoKeywordGap;
use Modules\Seo\Models\DomainCompetitor;
use Modules\Seo\Models\RankedKeyword;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Research\DomainResearch;
use Modules\Seo\Research\KeywordGap;
use Tests\Fixtures\DataForSeoFixtures;

function gapTool(User $user, string $tool, array $arguments): array
{
    $response = ClockworkServer::actingAs($user)->tool($tool, $arguments)->assertOk();

    return (fn () => $this->response->toArray()['result']['structuredContent'])->call($response);
}

function ranked(string $domain, string $keyword, int $position, ?int $volume = 100): void
{
    RankedKeyword::create(['domain' => $domain, 'market_key' => '2840-en', 'provider' => 'dataforseo', 'keyword' => $keyword, 'position' => $position, 'volume' => $volume, 'url' => "https://{$domain}/{$position}", 'fetched_at' => now()]);
}

function competitorsResponse(): array
{
    $item = fn (string $domain, int $shared, float $avg, int $count) => [
        'se_type' => 'google', 'domain' => $domain, 'avg_position' => $avg, 'sum_position' => 1, 'intersections' => $shared,
        'full_domain_metrics' => ['organic' => ['count' => $count, 'etv' => 5000.129]], 'metrics' => ['organic' => ['count' => $shared]],
    ];

    return DataForSeoFixtures::envelope([DataForSeoFixtures::task('cd', [[
        'se_type' => 'google', 'target' => 'acme.com', 'total_count' => 3, 'items_count' => 3,
        'items' => [$item('acme.com', 480, 21.5, 480), $item('www.rival.com', 210, 14.25, 900), $item('directory.com', 150, 8.0, 50000)],
    ]], 0.01224)]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['clockwork.seo.dataforseo_login' => 'api-login', 'clockwork.seo.dataforseo_password' => 'api-password-canary']);
    $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00:00', 'UTC'));
    $this->user = User::factory()->operator()->create();
    $this->site = Site::factory()->create(['domain' => 'acme.com']);
    SiteProfile::create(['site_id' => $this->site->id, 'is_enabled' => true, 'competitors' => ['rival.com']]);
});

it('stores discovered competitors without the domain itself, replacing the last discovery', function () {
    DomainCompetitor::create(['domain' => 'acme.com', 'market_key' => '2840-en', 'provider' => 'dataforseo', 'competitor_domain' => 'gone.com', 'shared_keywords' => 1]);
    Http::fake(['*/competitors_domain/live' => Http::response(competitorsResponse())]);

    $result = app(DomainResearch::class)->discoverCompetitors('acme.com', new Market, 50, $this->site->id);

    expect($result)->toMatchArray(['ok' => true, 'stored' => 2])
        ->and(DomainCompetitor::query()->orderByDesc('shared_keywords')->pluck('competitor_domain')->all())->toBe(['rival.com', 'directory.com']);

    $rival = DomainCompetitor::query()->where('competitor_domain', 'rival.com')->sole();
    expect($rival->shared_keywords)->toBe(210)->and($rival->avg_position)->toBe(14.25)
        ->and($rival->competitor_keywords)->toBe(900)->and($rival->competitor_traffic)->toBe(5000.13);

    Http::assertSent(fn (Request $r) => $r->data()[0]['exclude_top_domains'] === true && $r->data()[0]['limit'] === 50);
});

it('sorts keywords into gap segments from stored lists', function () {
    // client, rival, other
    ranked('acme.com', 'both beat us', 20, 900);
    ranked('rival.com', 'both beat us', 3);
    ranked('other.com', 'both beat us', 5);
    ranked('acme.com', 'we lead', 2, 500);
    ranked('rival.com', 'we lead', 9);
    ranked('other.com', 'we lead', 12);
    ranked('rival.com', 'everyone but us', 4, 2000);
    ranked('other.com', 'everyone but us', 6);
    ranked('rival.com', 'one of them', 7, 300);
    ranked('acme.com', 'only us', 5, 50);
    ranked('acme.com', 'middle', 8, 70);
    ranked('rival.com', 'middle', 4);
    ranked('other.com', 'middle', 15);

    $gap = app(KeywordGap::class);
    $missing = $gap->compare('www.acme.com', ['rival.com', 'other.com', 'nodata.com', 'acme.com'], new Market, 'missing');

    expect($missing['competitors'])->toBe(['rival.com', 'other.com'])
        ->and($missing['without_data'])->toBe(['nodata.com'])
        ->and($missing['client_has_data'])->toBeTrue()
        ->and($missing['counts'])->toBe(['missing' => 1, 'untapped' => 2, 'weak' => 1, 'strong' => 1, 'shared' => 3, 'unique' => 1])
        ->and(array_column($missing['rows'], 'keyword'))->toBe(['everyone but us'])
        ->and($missing['rows'][0])->toMatchArray(['client_position' => null, 'competitor_positions' => [4, 6], 'best_competitor_position' => 4, 'volume' => 2000]);

    expect(array_column($gap->compare('acme.com', ['rival.com', 'other.com'], new Market, 'untapped')['rows'], 'keyword'))->toBe(['everyone but us', 'one of them'])
        ->and(array_column($gap->compare('acme.com', ['rival.com', 'other.com'], new Market, 'weak')['rows'], 'keyword'))->toBe(['both beat us'])
        ->and(array_column($gap->compare('acme.com', ['rival.com', 'other.com'], new Market, 'strong')['rows'], 'keyword'))->toBe(['we lead'])
        ->and(array_column($gap->compare('acme.com', ['rival.com', 'other.com'], new Market, 'unique')['rows'], 'keyword'))->toBe(['only us'])
        ->and(array_column($gap->compare('acme.com', ['rival.com', 'other.com'], new Market, 'untapped', 200, 1000)['rows'], 'keyword'))->toBe(['everyone but us']);
});

it('never invents gaps from a competitor with no stored list', function () {
    ranked('acme.com', 'plumber', 5);

    $result = app(KeywordGap::class)->compare('acme.com', ['nodata.com'], new Market, 'missing');

    expect($result['competitors'])->toBe([])
        ->and($result['counts']['missing'])->toBe(0)
        ->and($result['counts']['unique'])->toBe(1);
});

describe('tools', function () {
    it('lists named and discovered competitors and prices a discovery when there is none', function () {
        Http::fake();
        $empty = gapTool($this->user, SeoCompetitors::class, ['site' => 'acme.com']);
        expect($empty['named'])->toBe([['domain' => 'rival.com', 'keyword_list_stored' => false]])
            ->and($empty['discovered'])->toBe([])
            ->and(array_column($empty['meta']['caveats'], 'code'))->toContain('not_discovered');

        DomainCompetitor::create(['domain' => 'acme.com', 'market_key' => '2840-en', 'provider' => 'dataforseo', 'competitor_domain' => 'rival.com', 'shared_keywords' => 210, 'avg_position' => 14.25, 'competitor_keywords' => 900, 'fetched_at' => now()]);
        ranked('rival.com', 'plumber', 3);

        $content = gapTool($this->user, SeoCompetitors::class, ['site' => 'acme.com']);
        expect($content['discovered'])->toBe([['rival.com', 210, 14.25, 900, null, true, true]])
            ->and($content['named'][0]['keyword_list_stored'])->toBeTrue();
        Http::assertNothingSent();
    });

    it('buys a competitor discovery only after the price is confirmed', function () {
        Http::fake(['*/competitors_domain/live' => Http::response(competitorsResponse())]);

        $quote = gapTool($this->user, SeoDomainResearchFetch::class, ['site' => 'acme.com', 'what' => 'competitors']);
        expect($quote)->toMatchArray(['bought' => false, 'estimated_cost_usd' => 0.018]);
        Http::assertNothingSent();

        $bought = gapTool($this->user, SeoDomainResearchFetch::class, ['site' => 'acme.com', 'what' => 'competitors', 'confirm_cost_usd' => 0.02]);
        expect($bought)->toMatchArray(['bought' => true, 'stored' => 2])->and($bought['summary'])->toContain('2 competing domains');
    });

    it('reports the gap with the caveats that keep it honest', function () {
        Http::fake();
        ranked('rival.com', 'emergency plumber', 4, 2000);
        ranked('rival.com', 'tiny keyword', 9, 10);

        $content = gapTool($this->user, SeoKeywordGap::class, ['site' => 'acme.com', 'competitors' => ['rival.com', 'nodata.com']]);
        $cols = array_flip($content['columns']);

        expect($content['compared'])->toBe(['rival.com'])
            ->and($content['counts']['missing'])->toBe(2)
            ->and(array_column($content['rows'], $cols['keyword']))->toBe(['emergency plumber', 'tiny keyword'])
            ->and($content['rows'][0][$cols['position:rival.com']])->toBe(4)
            ->and($content['rows'][0][$cols['client_position']])->toBeNull()
            ->and(array_column($content['meta']['caveats'], 'code'))->toContain('stored_lists_only', 'client_list_missing', 'competitor_lists_missing');

        // With no competitors named and none passed, it says what to do.
        SiteProfile::query()->update(['competitors' => null]);
        ClockworkServer::actingAs($this->user)->tool(SeoKeywordGap::class, ['site' => 'acme.com'])->assertHasErrors();
        Http::assertNothingSent();
    });
});
