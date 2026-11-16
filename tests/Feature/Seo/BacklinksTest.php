<?php

use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Mcp\Servers\ClockworkServer;
use Modules\Seo\Backlinks\BacklinkResearch;
use Modules\Seo\DataForSeo\BudgetGuard;
use Modules\Seo\DataForSeo\Pricing;
use Modules\Seo\Mcp\Tools\SeoBacklinks;
use Modules\Seo\Mcp\Tools\SeoDomainResearchFetch;
use Modules\Seo\Models\ApiUsage;
use Modules\Seo\Models\BacklinkGapDomain;
use Modules\Seo\Models\BacklinkSnapshot;
use Modules\Seo\Models\ReferringDomain;
use Modules\Seo\Models\SiteProfile;
use Tests\Fixtures\DataForSeoFixtures;

function backlinkSummary(?array $data = []): array
{
    return DataForSeoFixtures::envelope([DataForSeoFixtures::task('bs', $data === null ? null : [$data + [
        'target' => 'acme.com', 'first_seen' => '2019-03-02 10:00:00 +00:00', 'rank' => 41, 'backlinks' => 5230, 'backlinks_spam_score' => 12,
        'broken_backlinks' => 7, 'referring_domains' => 310, 'referring_domains_nofollow' => 44, 'referring_main_domains' => 288,
    ]], 0.02404)]);
}

function referringResponse(array $domains, ?int $total = null): array
{
    $items = [];
    foreach ($domains as $domain => [$rank, $spam]) {
        $items[] = ['type' => 'backlinks_referring_domain', 'domain' => $domain, 'rank' => $rank, 'backlinks' => 3, 'backlinks_spam_score' => $spam, 'first_seen' => '2024-05-06 00:00:00 +00:00'];
    }

    return DataForSeoFixtures::envelope([DataForSeoFixtures::task('rd', [['target' => 'acme.com', 'total_count' => $total ?? count($items), 'items_count' => count($items), 'items' => $items]], 0.0241)]);
}

function backlinkTool(User $user, string $tool, array $arguments): array
{
    $response = ClockworkServer::actingAs($user)->tool($tool, $arguments)->assertOk();

    return (fn () => $this->response->toArray()['result']['structuredContent'])->call($response);
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['clockwork.seo.dataforseo_login' => 'api-login', 'clockwork.seo.dataforseo_password' => 'api-password-canary']);
    $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
    $this->user = User::factory()->operator()->create();
    $this->site = Site::factory()->create(['domain' => 'www.acme.com']);
    SiteProfile::create(['site_id' => $this->site->id, 'is_enabled' => true, 'competitors' => ['rival.com']]);
    $this->backlinks = app(BacklinkResearch::class);
});

it('prices by request and by row', function () {
    expect(Pricing::backlinks(1))->toBe(0.02404)
        ->and(Pricing::backlinks(1000))->toBe(0.06)
        ->and(BacklinkResearch::referringCost())->toBe(0.06);
});

it('stores a month of backlink totals, and unknown when the provider has nothing', function () {
    Http::fake(['*/backlinks/summary/live' => Http::sequence()->push(backlinkSummary())->push(backlinkSummary(null))]);

    $snapshot = $this->backlinks->snapshot('https://www.acme.com/', $this->site->id)['snapshot'];

    expect($snapshot)->toMatchArray(['domain' => 'acme.com', 'backlinks' => 5230, 'referring_domains' => 310, 'referring_main_domains' => 288, 'rank' => 41, 'spam_score' => 12, 'broken_backlinks' => 7])
        ->and($snapshot->month->toDateString())->toBe('2026-10-01')
        ->and(ApiUsage::query()->sole()->feature)->toBe('backlinks');

    Http::assertSent(fn (Request $r) => $r->data()[0]['target'] === 'acme.com' && $r->data()[0]['rank_scale'] === 'one_hundred' && $r->data()[0]['exclude_internal_backlinks'] === true);

    $empty = $this->backlinks->snapshot('unknown.com')['snapshot'];
    expect($empty->backlinks)->toBeNull()->and($empty->referring_domains)->toBeNull();
});

it('works out new and lost referring domains between pulls', function () {
    Http::fake(['*/backlinks/referring_domains/live' => Http::sequence()
        ->push(referringResponse(['news.com' => [80, 3], 'blog.net' => [40, 10], 'dir.org' => [20, 70], 'acme.com' => [41, 1]]))
        ->push(referringResponse(['news.com' => [82, 3], 'dir.org' => [20, 72], 'fresh.io' => [55, 5]]))
        ->push(referringResponse(['news.com' => [82, 3], 'dir.org' => [20, 72], 'fresh.io' => [55, 5], 'blog.net' => [40, 10]]))]);

    // First pull: everything is stored, nothing is "new".
    $first = $this->backlinks->pullReferringDomains('acme.com');
    expect($first)->toMatchArray(['ok' => true, 'stored' => 3, 'new' => 0, 'lost' => 0, 'complete' => true])
        ->and(ReferringDomain::query()->whereNotNull('found_at')->count())->toBe(0)
        ->and(ReferringDomain::query()->where('referring_domain', 'acme.com')->exists())->toBeFalse();

    $this->travel(31)->days();
    $second = $this->backlinks->pullReferringDomains('acme.com');
    expect($second)->toMatchArray(['stored' => 3, 'new' => 1, 'lost' => 1]);

    $lost = ReferringDomain::query()->where('referring_domain', 'blog.net')->sole();
    $new = ReferringDomain::query()->where('referring_domain', 'fresh.io')->sole();
    expect($lost->lost_at->toDateString())->toBe('2026-11-07')
        ->and($new->found_at->toDateString())->toBe('2026-11-07')
        ->and($new->first_seen->toDateString())->toBe('2024-05-06')
        ->and(ReferringDomain::query()->where('referring_domain', 'news.com')->sole()->rank)->toBe(82);

    // It comes back: live again, and counted as new.
    $this->travel(30)->days();
    $third = $this->backlinks->pullReferringDomains('acme.com');
    expect($third)->toMatchArray(['new' => 1, 'lost' => 0])
        ->and($lost->fresh()->lost_at)->toBeNull();
});

it('never marks a domain lost when the pull stopped at the limit', function () {
    ReferringDomain::create(['domain' => 'acme.com', 'provider' => 'dataforseo', 'referring_domain' => 'past-the-cut.com', 'rank' => 1, 'seen_at' => now()]);
    Http::fake(['*/backlinks/referring_domains/live' => Http::response(referringResponse(['news.com' => [80, 3], 'blog.net' => [40, 10]], 5000))]);

    $result = $this->backlinks->pullReferringDomains('acme.com', 2);

    expect($result)->toMatchArray(['complete' => false, 'lost' => 0, 'total' => 5000])
        ->and(ReferringDomain::query()->where('referring_domain', 'past-the-cut.com')->sole()->lost_at)->toBeNull();
});

it('counts clients and competitors once a month from the command, with the client\'s linking domains', function () {
    Http::fake([
        '*/backlinks/summary/live' => Http::response(backlinkSummary()),
        '*/backlinks/referring_domains/live' => Http::response(referringResponse(['news.com' => [80, 3]])),
    ]);

    $this->artisan('clockwork:seo-snapshot-backlinks')->expectsOutputToContain('Counted 2 domains')->assertSuccessful();
    expect(BacklinkSnapshot::query()->pluck('domain')->sort()->values()->all())->toBe(['acme.com', 'rival.com'])
        // Only the client's own list is pulled, not each competitor's.
        ->and(ReferringDomain::query()->pluck('domain')->unique()->all())->toBe(['acme.com']);

    $this->artisan('clockwork:seo-snapshot-backlinks')->expectsOutputToContain('Counted 0 domains')->assertSuccessful();
    Http::assertSentCount(3);
});

it('stops the command at the backlinks cap', function () {
    app(BudgetGuard::class)->save(['caps' => ['backlinks' => 0]]);
    Http::fake();

    $this->artisan('clockwork:seo-snapshot-backlinks')->assertFailed();
    Http::assertNothingSent();
});

describe('tools', function () {
    it('reads totals and referring domains from storage, with what is new, lost and spammy', function () {
        Http::fake();
        BacklinkSnapshot::create(['domain' => 'acme.com', 'month' => '2026-09-01', 'provider' => 'dataforseo', 'backlinks' => 5000, 'referring_domains' => 300, 'rank' => 40]);
        BacklinkSnapshot::create(['domain' => 'acme.com', 'month' => '2026-10-01', 'provider' => 'dataforseo', 'backlinks' => 5230, 'referring_domains' => 310, 'rank' => 41, 'spam_score' => 12]);
        $row = fn (string $d, array $extra) => ReferringDomain::create($extra + ['domain' => 'acme.com', 'provider' => 'dataforseo', 'referring_domain' => $d, 'rank' => 50, 'spam_score' => 5, 'seen_at' => now()]);
        $row('news.com', ['rank' => 80]);
        $row('fresh.io', ['found_at' => now()->subDays(3)]);
        $row('gone.net', ['lost_at' => now()->subDays(3)]);
        $row('spam_y%.biz', ['rank' => 5, 'spam_score' => 88]);

        $all = backlinkTool($this->user, SeoBacklinks::class, ['site' => 'acme.com']);
        expect($all['summary'])->toContain('5,230 backlinks from 310 referring domains')
            ->and($all['totals'][0])->toMatchArray(['domain' => 'acme.com', 'is_client' => true, 'rank' => 41, 'spam_score' => 12])
            ->and($all['totals'][0]['history'])->toBe([['2026-09', 5000, 300, 40], ['2026-10', 5230, 310, 41]])
            ->and($all['totals'][1])->toMatchArray(['domain' => 'rival.com', 'backlinks' => null])
            ->and(array_column($all['referring_domains']['rows'], 0))->toBe(['news.com', 'fresh.io', 'spam_y%.biz'])
            ->and($all['referring_domains']['live_stored'])->toBe(3)
            ->and(array_column($all['meta']['caveats'], 'code'))->toContain('provider_index', 'provider_scores', 'not_counted_yet');

        $rows = fn (array $args) => array_column(backlinkTool($this->user, SeoBacklinks::class, ['site' => 'acme.com'] + $args)['referring_domains']['rows'], 0);
        expect($rows(['show' => 'new']))->toBe(['fresh.io'])
            ->and($rows(['show' => 'lost']))->toBe(['gone.net'])
            ->and($rows(['show' => 'high_spam']))->toBe(['spam_y%.biz'])
            ->and($rows(['contains' => 'y%']))->toBe(['spam_y%.biz'])
            ->and($rows(['contains' => '_e']))->toBe([]);
        Http::assertNothingSent();
    });

    it('buys backlink data through the fetch tool, price first, on the backlinks budget', function () {
        Http::fake(['*/backlinks/summary/live' => Http::response(backlinkSummary())]);

        $quote = backlinkTool($this->user, SeoDomainResearchFetch::class, ['site' => 'acme.com', 'what' => 'referring_domains', 'limit' => 500]);
        expect($quote)->toMatchArray(['bought' => false, 'estimated_cost_usd' => 0.042]);
        Http::assertNothingSent();

        $bought = backlinkTool($this->user, SeoDomainResearchFetch::class, ['site' => 'acme.com', 'what' => 'backlink_summary', 'domain' => 'rival.com', 'confirm_cost_usd' => 0.03]);
        expect($bought['bought'])->toBeTrue()
            ->and($bought['summary'])->toContain('5,230 backlinks from 310 referring domains')
            ->and(ApiUsage::query()->sole())->toMatchArray(['feature' => 'backlinks', 'user_id' => $this->user->id]);

        // The backlinks cap, not the research cap, holds it.
        app(BudgetGuard::class)->save(['caps' => ['backlinks' => 0, 'research' => 50]]);
        ClockworkServer::actingAs($this->user)->tool(SeoDomainResearchFetch::class, ['site' => 'acme.com', 'what' => 'backlink_summary', 'confirm_cost_usd' => 1])->assertHasErrors();
        Http::assertSentCount(1);
    });
});

function gapResponse(array $domains, int $total = 0): array
{
    $items = [];
    foreach ($domains as $domain => [$rank, $spam]) {
        $items[] = ['domain_intersection' => ['1' => ['type' => 'backlinks_domain_intersection', 'target' => $domain, 'rank' => $rank, 'backlinks' => 4, 'backlinks_spam_score' => $spam]], 'summary' => ['intersections_count' => 1]];
    }

    return DataForSeoFixtures::envelope([DataForSeoFixtures::task('di', [['targets' => ['1' => 'rival.com'], 'total_count' => $total ?: count($items), 'items_count' => count($items), 'items' => $items]], 0.0241)]);
}

describe('backlink gap', function () {
    it('stores the domains linking to a competitor but not the client, replacing the last pull', function () {
        BacklinkGapDomain::create(['domain' => 'acme.com', 'competitor_domain' => 'rival.com', 'provider' => 'dataforseo', 'referring_domain' => 'stale.com']);
        Http::fake(['*/backlinks/domain_intersection/live' => Http::response(gapResponse(['chamber.org' => [70, 2], 'www.trade.com' => [55, 8], 'rival.com' => [40, 1], 'acme.com' => [41, 1]], 900))]);

        $result = $this->backlinks->pullGap('www.acme.com', 'rival.com', 500, $this->site->id);

        expect($result)->toMatchArray(['ok' => true, 'stored' => 2, 'total' => 900])
            ->and(BacklinkGapDomain::query()->orderByDesc('rank')->pluck('referring_domain')->all())->toBe(['chamber.org', 'trade.com']);

        Http::assertSent(fn (Request $r) => $r->data()[0]['targets'] === ['1' => 'rival.com'] && $r->data()[0]['exclude_targets'] === ['acme.com'] && $r->data()[0]['limit'] === 500);

        expect($this->backlinks->pullGap('acme.com', 'acme.com')['ok'])->toBeFalse();
        Http::assertSentCount(1);
    });

    it('lists each gap domain once, the ones linking to most competitors first', function () {
        $row = fn (string $competitor, string $domain, int $rank, int $spam = 5) => BacklinkGapDomain::create(['domain' => 'acme.com', 'competitor_domain' => $competitor, 'provider' => 'dataforseo', 'referring_domain' => $domain, 'rank' => $rank, 'spam_score' => $spam, 'backlinks' => 2]);
        $row('rival.com', 'chamber.org', 70);
        $row('other.com', 'chamber.org', 70);
        $row('rival.com', 'bigmag.com', 90);
        $row('other.com', 'junk.biz', 10, 95);

        $gap = $this->backlinks->gap('acme.com');
        expect(array_column($gap['rows'], 'domain'))->toBe(['chamber.org', 'bigmag.com', 'junk.biz'])
            ->and($gap['rows'][0])->toMatchArray(['competitors' => ['rival.com', 'other.com'], 'backlinks' => 4, 'rank' => 70])
            ->and($gap['compared'])->toBe(['rival.com', 'other.com'])
            ->and(array_column($this->backlinks->gap('acme.com', null, 200, 60)['rows'], 'domain'))->toBe(['chamber.org', 'bigmag.com'])
            ->and(array_column($this->backlinks->gap('acme.com', ['other.com'])['rows'], 'domain'))->toBe(['chamber.org', 'junk.biz']);

        Http::fake();
        $content = backlinkTool($this->user, SeoBacklinks::class, ['site' => 'acme.com', 'show' => 'gap']);
        expect($content['gap']['rows'][0])->toBe(['chamber.org', 70, 5, ['rival.com', 'other.com'], 2])
            ->and($content['referring_domains'])->toBeNull()
            ->and(array_column($content['meta']['caveats'], 'code'))->toContain('gap_is_prospects');
        Http::assertNothingSent();
    });

    it('buys the gap through the fetch tool against a competitor only', function () {
        Http::fake(['*/backlinks/domain_intersection/live' => Http::response(gapResponse(['chamber.org' => [70, 2]]))]);

        ClockworkServer::actingAs($this->user)->tool(SeoDomainResearchFetch::class, ['site' => 'acme.com', 'what' => 'backlink_gap', 'confirm_cost_usd' => 1])->assertHasErrors();
        $quote = backlinkTool($this->user, SeoDomainResearchFetch::class, ['site' => 'acme.com', 'what' => 'backlink_gap', 'domain' => 'rival.com']);
        expect($quote)->toMatchArray(['bought' => false, 'estimated_cost_usd' => 0.042]);
        Http::assertNothingSent();

        $bought = backlinkTool($this->user, SeoDomainResearchFetch::class, ['site' => 'acme.com', 'what' => 'backlink_gap', 'domain' => 'rival.com', 'confirm_cost_usd' => 0.05]);
        expect($bought['bought'])->toBeTrue()->and($bought['summary'])->toContain('link to rival.com but not to acme.com');
    });
});

describe('backlinks page', function () {
    beforeEach(fn () => mockIssueCounterZero());

    it('shows totals, linking domains with filters, and the gap, from stored data', function () {
        Http::fake();
        BacklinkSnapshot::create(['domain' => 'acme.com', 'month' => '2026-09-01', 'provider' => 'dataforseo', 'backlinks' => 5000, 'referring_domains' => 300, 'rank' => 40]);
        BacklinkSnapshot::create(['domain' => 'acme.com', 'month' => '2026-10-01', 'provider' => 'dataforseo', 'backlinks' => 5230, 'referring_domains' => 310, 'rank' => 41, 'spam_score' => 12]);
        ReferringDomain::create(['domain' => 'acme.com', 'provider' => 'dataforseo', 'referring_domain' => 'news.com', 'rank' => 80, 'seen_at' => now()]);
        ReferringDomain::create(['domain' => 'acme.com', 'provider' => 'dataforseo', 'referring_domain' => 'gone.net', 'rank' => 30, 'seen_at' => now(), 'lost_at' => now()->subDays(2)]);
        BacklinkGapDomain::create(['domain' => 'acme.com', 'competitor_domain' => 'rival.com', 'provider' => 'dataforseo', 'referring_domain' => 'chamber.org', 'rank' => 70]);

        $html = $this->actingAs($this->user)->get(route('seo.sites.backlinks', $this->site))
            ->assertOk()
            ->assertSee('310')->assertSee('+10')->assertSee('5,230')
            ->assertSee('news.com')->assertDontSee('gone.net')
            ->assertSee('chamber.org')
            ->assertSee('Refresh gap ($0.042)', false)
            ->assertSee('data-site-tabs', false)
            ->getContent();

        $row = substr($html, strpos($html, 'data-backlink-row="rival.com"'));
        expect(substr($row, strpos($row, 'data-referring'), 60))->toContain('—');

        $this->actingAs($this->user)->get(route('seo.sites.backlinks', [$this->site, 'show' => 'lost']))->assertOk()->assertSee('gone.net')->assertSee('Lost');
        $this->actingAs($this->user)->get(route('seo.sites.backlinks', [$this->site, 'show' => 'everything']))->assertSessionHasErrors('show');
        Http::assertNothingSent();
    });

    it('buys only for the client and its named competitors, inside the backlinks cap', function () {
        Http::fake(['*/backlinks/summary/live' => Http::response(backlinkSummary())]);

        $this->actingAs($this->user)->post(route('seo.sites.backlinks.buy', $this->site), ['what' => 'backlink_summary', 'domain' => 'evil.com'])->assertSessionHas('warning');
        $this->actingAs($this->user)->post(route('seo.sites.backlinks.buy', $this->site), ['what' => 'backlink_gap', 'domain' => 'acme.com'])->assertSessionHas('warning');
        Http::assertNothingSent();

        $this->actingAs($this->user)->post(route('seo.sites.backlinks.buy', $this->site), ['what' => 'backlink_summary', 'domain' => 'rival.com'])
            ->assertRedirect(route('seo.sites.backlinks', $this->site))->assertSessionHas('status');
        expect(BacklinkSnapshot::query()->where('domain', 'rival.com')->exists())->toBeTrue();

        app(BudgetGuard::class)->save(['caps' => ['backlinks' => 0]]);
        $this->actingAs($this->user)->post(route('seo.sites.backlinks.buy', $this->site), ['what' => 'referring_domains'])->assertSessionHas('warning', fn (string $m) => str_contains($m, 'cap'));
        Http::assertSentCount(1);
    });
});
