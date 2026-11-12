<?php

use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Seo\Audit\SiteAuditRunner;
use Modules\Seo\Audit\SiteAuditSettings;
use Modules\Seo\DataForSeo\BudgetGuard;
use Modules\Seo\DataForSeo\Pricing;
use Modules\Seo\Models\ApiUsage;
use Modules\Seo\Models\SiteAudit;
use Modules\Seo\Models\SiteAuditPage;
use Modules\Seo\Models\SiteProfile;
use Tests\Fixtures\DataForSeoFixtures;

function crawlSummary(string $progress = 'finished', int $crawled = 3): array
{
    return DataForSeoFixtures::envelope([DataForSeoFixtures::task('crawl-1', [[
        'crawl_progress' => $progress,
        'crawl_status' => ['max_crawl_pages' => 500, 'pages_in_queue' => $progress === 'finished' ? 0 : 40, 'pages_crawled' => $crawled],
        'domain_info' => ['name' => 'acme.com', 'cms' => 'wordpress', 'checks' => ['sitemap' => true, 'robots_txt' => true, 'test_https_redirect' => false, 'ssl' => true]],
        'page_metrics' => $progress === 'finished' ? [
            'links_external' => 12, 'links_internal' => 240, 'duplicate_title' => 2, 'duplicate_description' => 1, 'duplicate_content' => 0,
            'broken_links' => 4, 'broken_resources' => 0, 'redirect_loop' => 0, 'onpage_score' => 87.456, 'non_indexable' => 1,
            'checks' => ['no_h1_tag' => 1, 'no_description' => 2, 'title_too_long' => 1, 'is_broken' => 1, 'is_https' => 3],
        ] : null,
    ]])]);
}

function crawlPage(string $path, int $status = 200, array $failed = [], array $meta = []): array
{
    return [
        'resource_type' => 'html', 'status_code' => $status, 'url' => "https://acme.com{$path}", 'size' => 48211, 'click_depth' => substr_count($path, '/'),
        'onpage_score' => 91.2, 'page_timing' => ['duration_time' => 420],
        'meta' => $meta + ['title' => 'Title of '.$path, 'description' => 'About '.$path, 'htags' => ['h1' => ['Heading '.$path], 'h2' => ['x']], 'canonical' => "https://acme.com{$path}", 'internal_links_count' => 20, 'external_links_count' => 2, 'content' => ['plain_text_word_count' => 512]],
        'checks' => array_fill_keys($failed, true) + ['is_https' => false, 'has_html_doctype' => false, 'canonical' => false],
    ];
}

function crawlPagesResponse(array $pages): array
{
    return DataForSeoFixtures::envelope([DataForSeoFixtures::task('crawl-1', [['crawl_progress' => 'finished', 'total_items_count' => count($pages), 'items_count' => count($pages), 'items' => $pages]])]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['clockwork.seo.dataforseo_login' => 'api-login', 'clockwork.seo.dataforseo_password' => 'api-password-canary']);
    $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00:00', 'UTC'));
    $this->site = Site::factory()->create(['domain' => 'www.acme.com']);
    SiteProfile::create(['site_id' => $this->site->id, 'is_enabled' => true]);
    $this->runner = app(SiteAuditRunner::class);
});

it('prices a crawl by its page cap', function () {
    expect(Pricing::onPage(500))->toBe(0.075)
        ->and(Pricing::onPage(500, true))->toBe(0.75)
        ->and(app(SiteAuditSettings::class)->crawlCost())->toBe(0.075);
});

it('starts a crawl capped at the page limit and refuses a second one while it runs', function () {
    Http::fake(['*/on_page/task_post' => Http::response(DataForSeoFixtures::created(['crawl-1'], 0.075))]);

    $result = $this->runner->start($this->site);

    expect($result['ok'])->toBeTrue()
        ->and($result['audit'])->toMatchArray(['task_id' => 'crawl-1', 'status' => 'crawling', 'max_pages' => 500, 'javascript' => false, 'cost_usd' => 0.075])
        ->and(ApiUsage::query()->sole()->feature)->toBe('site_audit');

    Http::assertSent(fn (Request $r) => $r->data()[0]['target'] === 'acme.com' && $r->data()[0]['max_crawl_pages'] === 500 && $r->data()[0]['enable_javascript'] === false);

    $again = $this->runner->start($this->site);
    expect($again['ok'])->toBeFalse()->and($again['error'])->toContain('already running');
    Http::assertSentCount(1);
});

it('keeps a running crawl as it is', function () {
    $audit = SiteAudit::create(['site_id' => $this->site->id, 'provider' => 'dataforseo', 'task_id' => 'crawl-1', 'status' => 'crawling', 'max_pages' => 500, 'started_at' => now()]);
    Http::fake(['*/on_page/summary/crawl-1' => Http::response(crawlSummary('in_progress', 120))]);

    expect($this->runner->collect())->toBe(['finished' => 0, 'crawling' => 1, 'failed' => 0])
        ->and($audit->fresh())->toMatchArray(['status' => 'crawling', 'pages_crawled' => 120])
        ->and(SiteAuditPage::count())->toBe(0);
});

it('stores a finished crawl: totals, counts per check, site-wide findings and every page', function () {
    $audit = SiteAudit::create(['site_id' => $this->site->id, 'provider' => 'dataforseo', 'task_id' => 'crawl-1', 'status' => 'crawling', 'max_pages' => 500, 'started_at' => now()]);
    Http::fake([
        '*/on_page/summary/crawl-1' => Http::response(crawlSummary()),
        '*/on_page/pages' => Http::response(crawlPagesResponse([
            crawlPage('/'),
            crawlPage('/services/', 200, ['no_description', 'title_too_long'], ['description' => null]),
            crawlPage('/old/', 404, ['is_broken', 'no_h1_tag'], ['title' => null, 'htags' => []]),
        ])),
    ]);

    expect($this->runner->collect())->toBe(['finished' => 1, 'crawling' => 0, 'failed' => 0]);

    $audit->refresh();
    expect($audit->status)->toBe('finished')
        ->and($audit->pages_crawled)->toBe(3)
        ->and($audit->score)->toBe(87.46)
        ->and($audit->totals)->toMatchArray(['broken_links' => 4, 'duplicate_title' => 2, 'non_indexable' => 1])
        ->and($audit->totals)->not->toHaveKey('onpage_score')
        ->and($audit->check_counts)->toMatchArray(['no_h1_tag' => 1, 'no_description' => 2])
        ->and($audit->site_checks)->toMatchArray(['sitemap' => true, 'test_https_redirect' => false])
        ->and($audit->finished_at)->not->toBeNull();

    $services = SiteAuditPage::query()->where('url', 'https://acme.com/services/')->sole();
    expect($services->failed_checks)->toBe(['no_description', 'title_too_long'])
        ->and($services->description)->toBeNull()
        ->and($services->word_count)->toBe(512)
        ->and($services->load_ms)->toBe(420)
        ->and($services->h1)->toBe('Heading /services/');

    $old = SiteAuditPage::query()->where('url', 'https://acme.com/old/')->sole();
    expect($old->status_code)->toBe(404)->and($old->title)->toBeNull()->and($old->h1)->toBeNull();

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/on_page/pages') && $r->data()[0]['id'] === 'crawl-1' && $r->data()[0]['filters'] === [['resource_type', '=', 'html']]);
});

it('writes off a crawl that never finishes', function () {
    $stuck = SiteAudit::create(['site_id' => $this->site->id, 'provider' => 'dataforseo', 'task_id' => 'crawl-1', 'status' => 'crawling', 'max_pages' => 500, 'started_at' => now()->subHours(50)]);
    Http::fake(['*/on_page/summary/crawl-1' => Http::response(crawlSummary('in_progress'))]);
    expect($this->runner->collect()['failed'])->toBe(1)
        ->and($stuck->fresh()->status)->toBe('failed')
        ->and($stuck->fresh()->error)->toContain('48 hours');
});

it('fails a crawl DataForSEO no longer has', function () {
    $lost = SiteAudit::create(['site_id' => $this->site->id, 'provider' => 'dataforseo', 'task_id' => 'crawl-1', 'status' => 'crawling', 'max_pages' => 500, 'started_at' => now()]);
    Http::fake(['*/on_page/summary/crawl-1' => Http::response(DataForSeoFixtures::envelope([DataForSeoFixtures::task('crawl-1', null, 0.0, 40401, 'Task Not Found.')]))]);

    expect($this->runner->collect()['failed'])->toBe(1)->and($lost->fresh()->error)->toContain('Not Found');
});

it('starts crawls for due clients only, once per interval', function () {
    $recent = Site::factory()->create(['domain' => 'recent.com']);
    SiteProfile::create(['site_id' => $recent->id, 'is_enabled' => true]);
    SiteAudit::create(['site_id' => $recent->id, 'provider' => 'dataforseo', 'task_id' => 'old', 'status' => 'finished', 'max_pages' => 500, 'started_at' => now()->subDays(10)]);
    $off = Site::factory()->create(['domain' => 'off.com']);
    SiteProfile::create(['site_id' => $off->id, 'is_enabled' => false]);

    Http::fake(['*/on_page/task_post' => Http::response(DataForSeoFixtures::created(['crawl-1'], 0.075))]);

    expect($this->runner->startDue())->toMatchArray(['due' => 1, 'started' => 1, 'failed' => 0]);
    expect($this->runner->startDue()['due'])->toBe(0);
    Http::assertSentCount(1);

    // Weekly: the 10-day-old one is due now.
    app(SiteAuditSettings::class)->save('weekly', 1000, false);
    Http::fake(['*/on_page/task_post' => Http::response(DataForSeoFixtures::created(['crawl-2'], 0.15))]);
    expect($this->runner->startDue()['started'])->toBe(1)
        ->and(SiteAudit::query()->where('site_id', $recent->id)->latest('id')->first()->max_pages)->toBe(1000);

    app(SiteAuditSettings::class)->save('off', 500, false);
    SiteAudit::query()->delete();
    expect($this->runner->startDue()['due'])->toBe(0);
});

it('stops at the spending cap', function () {
    app(BudgetGuard::class)->save(['caps' => ['site_audit' => 0]]);
    Http::fake();
    $this->artisan('clockwork:seo-site-audit')->assertFailed();
    Http::assertNothingSent();
    expect(SiteAudit::count())->toBe(0);
});

it('runs from the command', function () {
    Http::fake(['*/on_page/task_post' => Http::response(DataForSeoFixtures::created(['crawl-1'], 0.075))]);
    $this->artisan('clockwork:seo-site-audit')->expectsOutputToContain('started 1 crawls')->assertSuccessful();
    $this->artisan('clockwork:seo-site-audit', ['--now' => true])->assertFailed();
});
