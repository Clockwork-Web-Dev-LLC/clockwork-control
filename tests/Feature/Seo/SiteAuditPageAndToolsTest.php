<?php

use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\Mcp\Servers\ClockworkServer;
use Modules\Seo\Audit\SiteAuditIssues;
use Modules\Seo\Audit\SiteAuditSettings;
use Modules\Seo\DataForSeo\BudgetGuard;
use Modules\Seo\Mcp\Tools\SeoSiteAudit;
use Modules\Seo\Mcp\Tools\SeoSiteAuditRun;
use Modules\Seo\Models\SiteAudit;
use Modules\Seo\Models\SiteAuditPage;
use Modules\Seo\Models\SiteProfile;
use Tests\Fixtures\DataForSeoFixtures;

function storedAudit(int $siteId, array $pages, array $siteChecks = [], array $attributes = []): SiteAudit
{
    $computed = app(SiteAuditIssues::class)->fromRows(array_values($pages), $siteChecks, []);
    $count = fn (string $severity) => count(array_filter($computed, fn (array $i) => $i['severity'] === $severity));

    $audit = SiteAudit::create($attributes + [
        'site_id' => $siteId, 'provider' => 'dataforseo', 'task_id' => uniqid('crawl-'), 'status' => 'finished', 'max_pages' => 500,
        'pages_crawled' => count($pages), 'score' => 82.5, 'site_checks' => $siteChecks, 'issue_counts' => array_column($computed, 'pages', 'key'),
        'errors' => $count('error'), 'warnings' => $count('warning'), 'notices' => $count('notice'),
        'cost_usd' => 0.0006, 'started_at' => now(), 'finished_at' => now(),
    ]);
    foreach ($pages as $url => $checks) {
        SiteAuditPage::create(['audit_id' => $audit->id, 'url' => $url, 'url_hash' => sha1($url), 'status_code' => ($checks['is_4xx_code'] ?? false) ? 404 : 200, 'title' => 'T', 'checks' => $checks]);
    }

    return $audit;
}

function auditTool(User $user, string $tool, array $arguments): array
{
    $response = ClockworkServer::actingAs($user)->tool($tool, $arguments)->assertOk();

    return (fn () => $this->response->toArray()['result']['structuredContent'])->call($response);
}

beforeEach(function () {
    mockIssueCounterZero();
    Http::preventStrayRequests();
    config(['clockwork.seo.dataforseo_login' => 'api-login', 'clockwork.seo.dataforseo_password' => 'api-password-canary']);
    $this->travelTo(CarbonImmutable::parse('2026-10-11 18:00:00', 'UTC'));
    $this->user = User::factory()->operator()->create();
    $this->site = Site::factory()->create(['domain' => 'acme.com']);
    SiteProfile::create(['site_id' => $this->site->id, 'is_enabled' => true]);

    $this->twoCrawls = function () {
        storedAudit($this->site->id, [
            'https://acme.com/' => ['no_h1_tag' => true, 'no_description' => true],
            'https://acme.com/a' => ['no_h1_tag' => true],
        ], ['sitemap' => false]);
        $this->travel(31)->days();

        return storedAudit($this->site->id, [
            'https://acme.com/' => ['no_h1_tag' => false, 'no_description' => true],
            'https://acme.com/a' => ['no_h1_tag' => true],
            'https://acme.com/gone' => ['is_4xx_code' => true, 'no_h1_tag' => true],
        ], ['sitemap' => true]);
    };
});

describe('site audit page', function () {
    it('says when a site has never been crawled', function () {
        Http::fake();
        $this->actingAs($this->user)->get(route('seo.sites.site-audit', $this->site))
            ->assertOk()->assertSee('has not been crawled yet')->assertSee('Crawl now (up to $0.08)')->assertSee('data-site-tabs', false);
        Http::assertNothingSent();
    });

    it('shows the latest crawl, the change since the one before, and the pages behind an issue', function () {
        Http::fake();
        $latest = ($this->twoCrawls)();

        $this->actingAs($this->user)->get(route('seo.sites.site-audit', $this->site))
            ->assertOk()
            ->assertSee('Pages that return a 4xx error')
            ->assertSee('Pages with no H1 heading')
            ->assertSee('Since the crawl on 2026-10-11')
            ->assertSee('2 fixed')->assertSee('2 new')
            ->assertSee('Cleared completely: No XML sitemap found')
            ->assertSee('not comparable with another tool');

        $this->actingAs($this->user)->get(route('seo.sites.site-audit', [$this->site, 'issue' => 'no_h1_tag']))
            ->assertOk()->assertSee('Add one H1 that says what the page is about.')->assertSee('https://acme.com/gone')->assertSee('2 pages');

        // An older crawl can be opened; another site's cannot.
        $first = SiteAudit::query()->orderBy('id')->first();
        $this->actingAs($this->user)->get(route('seo.sites.site-audit', [$this->site, 'crawl' => $first->id]))->assertOk()->assertSee('No XML sitemap found');
        $other = Site::factory()->create(['domain' => 'other.com']);
        $this->actingAs($this->user)->get(route('seo.sites.site-audit', [$other, 'crawl' => $latest->id]))->assertOk()->assertSee('has not been crawled yet');
        Http::assertNothingSent();
    });

    it('starts a crawl from the button, once, inside the cap', function () {
        Http::fake(['*/on_page/task_post' => Http::response(DataForSeoFixtures::created(['crawl-9'], 0.075))]);

        $this->actingAs($this->user)->post(route('seo.sites.site-audit.run', $this->site))
            ->assertRedirect(route('seo.sites.site-audit', $this->site))->assertSessionHas('status');
        expect(SiteAudit::query()->sole())->toMatchArray(['status' => 'crawling', 'started_by' => $this->user->id]);

        $this->actingAs($this->user)->post(route('seo.sites.site-audit.run', $this->site))->assertSessionHas('warning', fn (string $m) => str_contains($m, 'already running'));
        $this->actingAs($this->user)->get(route('seo.sites.site-audit', $this->site))->assertSee('Crawl in progress');
        Http::assertSentCount(1);
    });

    it('refuses the button past the cap', function () {
        Http::fake();
        app(BudgetGuard::class)->save(['caps' => ['site_audit' => 0]]);

        $this->actingAs($this->user)->post(route('seo.sites.site-audit.run', $this->site))->assertSessionHas('warning', fn (string $m) => str_contains($m, 'cap'));
        Http::assertNothingSent();
        expect(SiteAudit::count())->toBe(0);
    });

    it('lets only an admin change the crawl settings', function () {
        Http::fake();
        $this->actingAs($this->user)->put(route('seo.site-audit.update'), ['frequency' => 'weekly', 'max_pages' => 1000])->assertForbidden();

        $admin = User::factory()->create();
        $this->actingAs($admin)->put(route('seo.site-audit.update'), ['frequency' => 'daily', 'max_pages' => 7])->assertSessionHasErrors(['frequency', 'max_pages']);
        $this->actingAs($admin)->put(route('seo.site-audit.update'), ['frequency' => 'weekly', 'max_pages' => 1000, 'javascript' => '1'])
            ->assertRedirect(route('seo.settings'))->assertSessionHas('status', fn (string $m) => str_contains($m, 'At most $1.50 a crawl'));

        $settings = app(SiteAuditSettings::class);
        expect([$settings->frequency(), $settings->maxPages(), $settings->javascript()])->toBe(['weekly', 1000, true]);
        $this->actingAs($admin)->get(route('seo.settings'))->assertOk()->assertSee('Save site audit');
    });
});

describe('seo_site_audit', function () {
    it('says when there is no crawl, then returns issues with fixes and the change', function () {
        Http::fake();
        $empty = auditTool($this->user, SeoSiteAudit::class, ['site' => 'acme.com']);
        expect($empty['crawl'])->toBeNull()->and(array_column($empty['meta']['caveats'], 'code'))->toContain('no_crawl');

        ($this->twoCrawls)();
        $content = auditTool($this->user, SeoSiteAudit::class, ['site' => 'acme.com']);
        $issues = collect($content['issues'])->keyBy('key');

        expect($content['crawl'])->toMatchArray(['pages_crawled' => 3, 'errors' => 1, 'warnings' => 2, 'javascript' => false])
            ->and($content['summary'])->toContain('2 fixed, 2 new')
            ->and($content['issues'][0]['key'])->toBe('is_4xx_code')
            ->and($issues['no_h1_tag'])->toMatchArray(['severity' => 'warning', 'pages' => 2, 'new' => 1, 'fixed' => 1, 'still_open' => 1])
            ->and($issues['no_h1_tag']['fix'])->toContain('Add one H1')
            ->and($content['change']['cleared'])->toBe(['No XML sitemap found'])
            ->and($content['crawls'])->toHaveCount(2)
            ->and(array_column($content['meta']['caveats'], 'code'))->toContain('provider_score', 'no_javascript');

        $errors = auditTool($this->user, SeoSiteAudit::class, ['site' => 'acme.com', 'severity' => 'error']);
        expect(array_column($errors['issues'], 'key'))->toBe(['is_4xx_code']);
        Http::assertNothingSent();
    });

    it('lists the pages behind one issue and rejects an unknown one', function () {
        Http::fake();
        ($this->twoCrawls)();

        $content = auditTool($this->user, SeoSiteAudit::class, ['site' => 'acme.com', 'issue' => 'no_h1_tag']);
        expect(array_column($content['pages'], 'url'))->toBe(['https://acme.com/a', 'https://acme.com/gone'])
            ->and($content['pages'][1]['status_code'])->toBe(404)
            ->and($content['truncated'])->toBeFalse();

        ClockworkServer::actingAs($this->user)->tool(SeoSiteAudit::class, ['site' => 'acme.com', 'issue' => 'not_an_issue'])->assertHasErrors();
        Http::assertNothingSent();
    });
});

describe('seo_site_audit_run', function () {
    it('prices first and starts a crawl only when the cost is confirmed', function () {
        Http::fake(['*/on_page/task_post' => Http::response(DataForSeoFixtures::created(['crawl-9'], 0.075))]);

        $quote = auditTool($this->user, SeoSiteAuditRun::class, ['site' => 'acme.com']);
        expect($quote)->toMatchArray(['started' => false, 'page_cap' => 500, 'max_cost_usd' => 0.075, 'already_running' => false, 'last_crawl' => null]);
        Http::assertNothingSent();

        ClockworkServer::actingAs($this->user)->tool(SeoSiteAuditRun::class, ['site' => 'acme.com', 'confirm_cost_usd' => 0.01])->assertHasErrors();
        Http::assertNothingSent();

        $started = auditTool($this->user, SeoSiteAuditRun::class, ['site' => 'acme.com', 'confirm_cost_usd' => 0.08]);
        expect($started)->toMatchArray(['started' => true, 'page_cap' => 500])
            ->and(SiteAudit::query()->sole()->started_by)->toBe($this->user->id);

        // A second one while it runs is refused.
        ClockworkServer::actingAs($this->user)->tool(SeoSiteAuditRun::class, ['site' => 'acme.com', 'confirm_cost_usd' => 0.08])->assertHasErrors();
        expect(auditTool($this->user, SeoSiteAuditRun::class, ['site' => 'acme.com'])['already_running'])->toBeTrue();
        Http::assertSentCount(1);
    });
});
