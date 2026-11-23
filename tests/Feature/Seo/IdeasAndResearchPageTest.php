<?php

use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Mcp\Servers\ClockworkServer;
use Modules\Seo\DataForSeo\BudgetGuard;
use Modules\Seo\Mcp\Tools\SeoKeywordIdeas;
use Modules\Seo\Models\ApiUsage;
use Modules\Seo\Models\DomainCompetitor;
use Modules\Seo\Models\DomainSnapshot;
use Modules\Seo\Models\KeywordIdeaPull;
use Modules\Seo\Models\KeywordMetric;
use Modules\Seo\Models\RankedKeyword;
use Modules\Seo\Models\SiteProfile;
use Tests\Fixtures\DataForSeoFixtures;

function ideaItem(string $keyword, ?int $volume, ?int $difficulty): array
{
    return ['se_type' => 'google', 'keyword' => $keyword, 'keyword_info' => ['search_volume' => $volume, 'cpc' => 3.2], 'keyword_properties' => ['keyword_difficulty' => $difficulty], 'search_intent_info' => ['main_intent' => 'informational']];
}

function ideasResponse(array $items, int $total = 5400): array
{
    return DataForSeoFixtures::envelope([DataForSeoFixtures::task('ki', [['se_type' => 'google', 'total_count' => $total, 'items_count' => count($items), 'items' => $items]], 0.0126)]);
}

function ideasTool(User $user, array $arguments): array
{
    $response = ClockworkServer::actingAs($user)->tool(SeoKeywordIdeas::class, $arguments)->assertOk();

    return (fn () => $this->response->toArray()['result']['structuredContent'])->call($response);
}

beforeEach(function () {
    mockIssueCounterZero();
    Http::preventStrayRequests();
    config(['clockwork.seo.dataforseo_login' => 'api-login', 'clockwork.seo.dataforseo_password' => 'api-password-canary']);
    $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00:00', 'UTC'));
    $this->user = User::factory()->operator()->create();
    $this->site = Site::factory()->create(['domain' => 'acme.com']);
    SiteProfile::create(['site_id' => $this->site->id, 'is_enabled' => true, 'competitors' => ['rival.com']]);
});

describe('seo_keyword_ideas', function () {
    it('prices first, buys on confirmation, stores the figures, and reads the list again for free', function () {
        Http::fake(['*/keyword_ideas/live' => Http::response(ideasResponse([
            ideaItem('How to Unclog a Drain', 9900, 18), ideaItem('drain snake', 5400, 35), ideaItem('rare thing', null, null), ideaItem('drain snake', 1, 1),
        ]))]);

        $quote = ideasTool($this->user, ['site' => 'acme.com', 'seeds' => ['Drain Cleaning', 'clogged drain'], 'limit' => 500]);
        expect($quote)->toMatchArray(['bought' => false, 'mode' => 'ideas', 'seeds' => ['drain cleaning', 'clogged drain'], 'estimated_cost_usd' => 0.072]);
        Http::assertNothingSent();

        ClockworkServer::actingAs($this->user)->tool(SeoKeywordIdeas::class, ['site' => 'acme.com', 'seeds' => ['drain cleaning'], 'limit' => 500, 'confirm_cost_usd' => 0.01])->assertHasErrors();
        Http::assertNothingSent();

        $bought = ideasTool($this->user, ['site' => 'acme.com', 'seeds' => ['drain cleaning', 'clogged drain'], 'limit' => 500, 'confirm_cost_usd' => 0.08]);
        expect($bought['bought'])->toBeTrue()
            ->and($bought['summary'])->toContain('Bought 3 ideas for $0.0126')->toContain('5,400 available')
            ->and($bought['rows'])->toBe([
                ['how to unclog a drain', 9900, 18, 'Easy', 3.2, 'informational'],
                ['drain snake', 5400, 35, 'Medium', 3.2, 'informational'],
                ['rare thing', null, null, null, 3.2, 'informational'],
            ]);

        Http::assertSent(fn (Request $r) => $r->data()[0]['keywords'] === ['drain cleaning', 'clogged drain'] && $r->data()[0]['limit'] === 500);

        // The figures landed in the keyword store, so they are never bought twice.
        expect(KeywordMetric::query()->where('keyword', 'drain snake')->sole()->volume)->toBe(5400)
            ->and(ApiUsage::query()->sole()->feature)->toBe('research');

        $pull = KeywordIdeaPull::query()->sole();
        $again = ideasTool($this->user, ['site' => 'acme.com', 'pull_id' => $pull->id, 'questions_only' => true]);
        expect($again['bought'])->toBeFalse()->and(array_column($again['rows'], 0))->toBe(['how to unclog a drain']);

        $easy = ideasTool($this->user, ['site' => 'acme.com', 'pull_id' => $pull->id, 'max_difficulty' => 20]);
        expect(array_column($easy['rows'], 0))->toBe(['how to unclog a drain']);

        $list = ideasTool($this->user, ['site' => 'acme.com']);
        expect($list['lists'][0])->toMatchArray(['pull_id' => $pull->id, 'mode' => 'ideas', 'ideas' => 3, 'available' => 5400]);

        Http::assertSentCount(1);
    });

    it('reads the nested shape of related keywords, and limits single-seed modes', function () {
        Http::fake(['*/related_keywords/live' => Http::response(ideasResponse([
            ['se_type' => 'google', 'keyword_data' => ideaItem('pipe relining', 700, 40), 'depth' => 1, 'related_keywords' => ['x']],
        ], 1))]);

        ClockworkServer::actingAs($this->user)->tool(SeoKeywordIdeas::class, ['site' => 'acme.com', 'mode' => 'related', 'seeds' => ['a b', 'c d']])->assertHasErrors();

        $bought = ideasTool($this->user, ['site' => 'acme.com', 'mode' => 'related', 'seeds' => ['pipe repair'], 'limit' => 100, 'confirm_cost_usd' => 1]);
        expect(array_column($bought['rows'], 0))->toBe(['pipe relining']);
        Http::assertSent(fn (Request $r) => $r->data()[0]['keyword'] === 'pipe repair' && $r->data()[0]['depth'] === 2);
    });

    it('is refused at the cap and never shows another site\'s list', function () {
        Http::fake();
        $other = Site::factory()->create(['domain' => 'other.com']);
        $foreign = KeywordIdeaPull::create(['site_id' => $other->id, 'mode' => 'ideas', 'seeds' => ['secret'], 'market_key' => '2840-en', 'keywords' => ['secret idea'], 'keyword_count' => 1]);
        ClockworkServer::actingAs($this->user)->tool(SeoKeywordIdeas::class, ['site' => 'acme.com', 'pull_id' => $foreign->id])->assertHasErrors();

        app(BudgetGuard::class)->save(['caps' => ['research' => 0]]);
        ClockworkServer::actingAs($this->user)->tool(SeoKeywordIdeas::class, ['site' => 'acme.com', 'seeds' => ['drain'], 'confirm_cost_usd' => 1])->assertHasErrors();
        Http::assertNothingSent();
    });
});

describe('research page', function () {
    it('shows counts, lists, competitors and the gap from stored data, with dashes for unknowns', function () {
        Http::fake();
        DomainSnapshot::create(['domain' => 'acme.com', 'market_key' => '2840-en', 'month' => '2026-10-01', 'provider' => 'dataforseo', 'keywords' => 450, 'top10' => 50]);
        DomainSnapshot::create(['domain' => 'acme.com', 'market_key' => '2840-en', 'month' => '2026-11-01', 'provider' => 'dataforseo', 'keywords' => 480, 'top3' => 17, 'top10' => 57]);
        DomainCompetitor::create(['domain' => 'acme.com', 'market_key' => '2840-en', 'provider' => 'dataforseo', 'competitor_domain' => 'directory.com', 'shared_keywords' => 150, 'competitor_keywords' => 50000]);
        RankedKeyword::create(['domain' => 'acme.com', 'market_key' => '2840-en', 'provider' => 'dataforseo', 'keyword' => 'plumber austin', 'position' => 3, 'volume' => 1300, 'estimated_traffic' => 210, 'fetched_at' => now()]);
        RankedKeyword::create(['domain' => 'rival.com', 'market_key' => '2840-en', 'provider' => 'dataforseo', 'keyword' => 'emergency plumber', 'position' => 4, 'volume' => 2000, 'fetched_at' => now()]);

        $html = $this->actingAs($this->user)->get(route('seo.sites.research', $this->site))
            ->assertOk()
            ->assertSee('480')->assertSee('+30')
            ->assertSee('directory.com')
            ->assertSee('plumber austin')
            ->assertSee('emergency plumber')
            ->assertSee('not comparable with counts from another tool')
            ->assertSee('Refresh list ($0.132)')
            ->getContent();

        // rival.com has no count: a dash, not a zero.
        $row = substr($html, strpos($html, 'data-domain-row="rival.com"'));
        expect(substr($row, strpos($row, 'data-keywords'), 60))->toContain('—');
        Http::assertNothingSent();
    });

    it('buys only for the client and its named competitors, inside the cap', function () {
        Http::fake(['*/domain_rank_overview/live' => Http::response(DataForSeoFixtures::envelope([DataForSeoFixtures::task('ov', [['items' => [['metrics' => ['organic' => ['count' => 77, 'pos_1' => 1]]]]]], 0.01212)]))]);

        $this->actingAs($this->user)->post(route('seo.sites.research.buy', $this->site), ['what' => 'count', 'domain' => 'evil.com'])
            ->assertSessionHas('warning');
        Http::assertNothingSent();

        $this->actingAs($this->user)->post(route('seo.sites.research.buy', $this->site), ['what' => 'count', 'domain' => 'rival.com'])
            ->assertRedirect(route('seo.sites.research', $this->site))->assertSessionHas('status');
        expect(DomainSnapshot::query()->where('domain', 'rival.com')->sole()->keywords)->toBe(77)
            ->and(ApiUsage::query()->sole()->user_id)->toBe($this->user->id);

        app(BudgetGuard::class)->save(['caps' => ['research' => 0]]);
        $this->actingAs($this->user)->post(route('seo.sites.research.buy', $this->site), ['what' => 'ranked_keywords'])
            ->assertSessionHas('warning', fn (string $m) => str_contains($m, 'cap'));
        Http::assertSentCount(1);
    });
});

describe('ranked keywords page', function () {
    beforeEach(function () {
        Http::fake();
        foreach ([['plumber austin', 3, 1300, 210.4, '/'], ['drain cleaning austin', 14, 880, 12.0, '/drains'], ['leak repair', 22, 90, 1.5, '/blog/leaks'], ['100% off_sale', 40, null, null, '/x']] as [$kw, $pos, $vol, $etv, $path]) {
            RankedKeyword::create(['domain' => 'acme.com', 'market_key' => '2840-en', 'provider' => 'dataforseo', 'keyword' => $kw, 'position' => $pos, 'volume' => $vol, 'estimated_traffic' => $etv, 'url' => "https://www.acme.com{$path}", 'fetched_at' => now()]);
        }
        RankedKeyword::create(['domain' => 'rival.com', 'market_key' => '2840-en', 'provider' => 'dataforseo', 'keyword' => 'emergency plumber', 'position' => 4, 'volume' => 2000, 'fetched_at' => now()]);
        $this->rows = fn (array $query) => (function (string $html) {
            preg_match_all('/data-ranked-keyword="([^"]+)"/', $html, $m);

            return array_map('html_entity_decode', $m[1]);
        })($this->actingAs($this->user)->get(route('seo.sites.research.keywords', [$this->site] + $query))->assertOk()->getContent());
    });

    afterEach(fn () => Http::assertNothingSent());

    it('lists the client by default, most traffic first, with unknowns as dashes', function () {
        expect(($this->rows)([]))->toBe(['plumber austin', 'drain cleaning austin', 'leak repair', '100% off_sale']);

        $this->actingAs($this->user)->get(route('seo.sites.research.keywords', $this->site))
            ->assertSee('4 of 4 stored keywords match')->assertSee('not all of them')->assertSee('/blog/leaks');
    });

    it('filters by position, text, page and volume, and sorts', function () {
        expect(($this->rows)(['min_position' => 11, 'max_position' => 20]))->toBe(['drain cleaning austin'])
            ->and(($this->rows)(['contains' => 'AUSTIN', 'sort' => 'keyword']))->toBe(['drain cleaning austin', 'plumber austin'])
            ->and(($this->rows)(['url_contains' => '/blog/']))->toBe(['leak repair'])
            ->and(($this->rows)(['min_volume' => 500, 'sort' => 'position']))->toBe(['plumber austin', 'drain cleaning austin'])
            // % and _ in a search are plain characters, not wildcards.
            ->and(($this->rows)(['contains' => '100%']))->toBe(['100% off_sale'])
            ->and(($this->rows)(['contains' => '_r']))->toBe([]);
    });

    it('shows a competitor\'s list and says when a domain has none', function () {
        expect(($this->rows)(['domain' => 'https://www.rival.com/']))->toBe(['emergency plumber']);

        $this->actingAs($this->user)->get(route('seo.sites.research.keywords', [$this->site, 'domain' => 'nolist.com']))
            ->assertOk()->assertSee('No keyword list is stored for this domain');

        $this->actingAs($this->user)->get(route('seo.sites.research', $this->site))
            ->assertSee(route('seo.sites.research.keywords', $this->site), false);
    });

    it('rejects bad filter values', function () {
        $this->actingAs($this->user)->get(route('seo.sites.research.keywords', [$this->site, 'sort' => 'drop table', 'min_position' => 500]))
            ->assertSessionHasErrors(['sort', 'min_position']);
    });
});
