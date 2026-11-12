<?php

use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\Seo\DataForSeo\BudgetGuard;
use Modules\Seo\Models\ApiUsage;
use Modules\Seo\Models\KeywordIdeaPull;
use Modules\Seo\Models\KeywordMetric;
use Modules\Seo\Models\PageAudit;
use Modules\Seo\Models\SiteProfile;
use Tests\Fixtures\DataForSeoFixtures;

beforeEach(function () {
    mockIssueCounterZero();
    Http::preventStrayRequests();
    config(['clockwork.seo.dataforseo_login' => 'api-login', 'clockwork.seo.dataforseo_password' => 'api-password-canary']);
    $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00:00', 'UTC'));
    $this->user = User::factory()->operator()->create();
    $this->site = Site::factory()->create(['domain' => 'acme.com']);
    SiteProfile::create(['site_id' => $this->site->id, 'is_enabled' => true, 'gsc_site_url' => 'https://www.acme.com/']);
});

it('links every client page from one tab bar', function () {
    Http::fake();
    $routes = ['seo.sites.show', 'seo.sites.rankings', 'seo.sites.keyword-map', 'seo.sites.keywords', 'seo.sites.research', 'seo.sites.page-audit', 'seo.sites.profile'];

    foreach ($routes as $name) {
        $response = $this->actingAs($this->user)->get(route($name, $this->site))->assertOk()->assertSee('data-site-tabs', false);
        foreach ($routes as $other) {
            $response->assertSee(route($other, $this->site), false);
        }
    }
    Http::assertNothingSent();
});

describe('keywords page', function () {
    it('shows stored figures, prices the rest, and spends nothing on a lookup', function () {
        Http::fake();
        KeywordMetric::create(['keyword' => 'drain cleaning', 'market_key' => '2840-en', 'provider' => 'dataforseo', 'volume' => 720, 'cpc' => 8.4, 'difficulty' => 22, 'intent' => 'commercial', 'fetched_at' => now()]);

        $this->actingAs($this->user)->post(route('seo.sites.keywords.lookup', $this->site), ['keywords' => "Drain Cleaning\nleak repair\nsite:acme.com"])
            ->assertOk()
            ->assertSee('720')->assertSee('Easy')
            ->assertSee('Not bought yet')
            ->assertSee('Buy the 1 missing (about $0.012)')
            ->assertSee('contains a symbol or search operator');

        Http::assertNothingSent();
        expect(ApiUsage::count())->toBe(0);
    });

    it('buys only the missing keywords and shows them', function () {
        KeywordMetric::create(['keyword' => 'drain cleaning', 'market_key' => '2840-en', 'provider' => 'dataforseo', 'volume' => 720, 'fetched_at' => now()]);
        Http::fake(['*/keyword_overview/live' => Http::response(DataForSeoFixtures::envelope([DataForSeoFixtures::task('ov', [['items' => [
            ['keyword' => 'leak repair', 'keyword_info' => ['search_volume' => 1900, 'cpc' => 5.5], 'keyword_properties' => ['keyword_difficulty' => 41], 'search_intent_info' => ['main_intent' => 'commercial']],
        ]]], 0.01212)]))]);

        $this->actingAs($this->user)->post(route('seo.sites.keywords.buy', $this->site), ['keywords' => "drain cleaning\nleak repair"])
            ->assertOk()->assertSee('Bought figures for 1 keywords for $0.0121')->assertSee('1,900');

        Http::assertSent(fn ($r) => $r->data()[0]['keywords'] === ['leak repair']);
        expect(ApiUsage::query()->sole()->user_id)->toBe($this->user->id);
    });

    it('refuses to buy past the cap', function () {
        Http::fake();
        app(BudgetGuard::class)->save(['caps' => ['keywords' => 0]]);

        $this->actingAs($this->user)->post(route('seo.sites.keywords.buy', $this->site), ['keywords' => 'leak repair'])
            ->assertRedirect(route('seo.sites.keywords', $this->site))->assertSessionHas('warning');
        Http::assertNothingSent();
    });

    it('buys an idea list and opens it', function () {
        Http::fake(['*/keyword_suggestions/live' => Http::response(DataForSeoFixtures::envelope([DataForSeoFixtures::task('ks', [['total_count' => 2, 'items' => [
            ['keyword' => 'drain cleaning cost', 'keyword_info' => ['search_volume' => 480], 'keyword_properties' => ['keyword_difficulty' => 12]],
            ['keyword' => 'drain cleaning near me', 'keyword_info' => ['search_volume' => 8100], 'keyword_properties' => ['keyword_difficulty' => 55]],
        ]]], 0.0122)]))]);

        $this->actingAs($this->user)->post(route('seo.sites.keywords.ideas', $this->site), ['seeds' => "a\nb", 'mode' => 'suggestions', 'limit' => 100])
            ->assertSessionHasErrors('seeds');
        Http::assertNothingSent();

        $this->actingAs($this->user)->post(route('seo.sites.keywords.ideas', $this->site), ['seeds' => 'Drain Cleaning', 'mode' => 'suggestions', 'limit' => 100])
            ->assertRedirect(route('seo.sites.keywords', [$this->site, 'list' => KeywordIdeaPull::query()->sole()->id]))
            ->assertSessionHas('status', fn (string $m) => str_contains($m, 'Bought 2 ideas'));

        $html = $this->actingAs($this->user)->get(route('seo.sites.keywords', [$this->site, 'list' => KeywordIdeaPull::query()->sole()->id]))
            ->assertOk()->assertSee('drain cleaning near me')->assertSee('8,100')->getContent();
        // Most volume first.
        expect(strpos($html, 'drain cleaning near me'))->toBeLessThan(strpos($html, 'drain cleaning cost'));
    });
});

describe('page audit page', function () {
    it('audits pages typed as paths and shows a blocked page as unknown', function () {
        Http::fake([
            'https://acme.com/services' => Http::response('<html><head><title>Drain Cleaning in Austin | Acme Plumbing Co</title></head><body><h1>Drains</h1><p>one two three</p></body></html>', 200, ['Content-Type' => 'text/html']),
            'https://acme.com/blocked' => Http::response('no', 403),
        ]);

        $this->actingAs($this->user)->post(route('seo.sites.page-audit.run', $this->site), ['urls' => "/services\nacme.com/blocked"])
            ->assertRedirect(route('seo.sites.page-audit', $this->site))
            ->assertSessionHas('warning', fn (string $m) => str_contains($m, 'Read 1 of 2 pages'));

        $this->actingAs($this->user)->get(route('seo.sites.page-audit', $this->site))
            ->assertOk()
            ->assertSee('Drain Cleaning in Austin | Acme Plumbing Co')
            ->assertSee('None found')
            ->assertSee('Blocked')
            ->assertSee('unknown, not empty');
        expect(PageAudit::count())->toBe(2);
    });

    it('refuses other sites and more than five pages without fetching', function () {
        Http::fake();

        $this->actingAs($this->user)->post(route('seo.sites.page-audit.run', $this->site), ['urls' => 'https://evil.com/'])->assertSessionHasErrors('urls');
        $this->actingAs($this->user)->post(route('seo.sites.page-audit.run', $this->site), ['urls' => '/1 /2 /3 /4 /5 /6'])->assertSessionHasErrors('urls');

        Http::assertNothingSent();
        expect(PageAudit::count())->toBe(0);
    });
});
