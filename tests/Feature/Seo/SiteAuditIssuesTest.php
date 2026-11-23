<?php

use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\Seo\Audit\IssueRules;
use Modules\Seo\Audit\SiteAuditIssues;
use Modules\Seo\Audit\SiteAuditRunner;
use Modules\Seo\Models\SiteAudit;
use Modules\Seo\Models\SiteAuditPage;
use Modules\Seo\Models\SiteProfile;
use Tests\Fixtures\DataForSeoFixtures;

function auditWith(int $siteId, array $pages, array $siteChecks = [], array $totals = [], array $attributes = []): SiteAudit
{
    $issues = app(SiteAuditIssues::class);
    $computed = $issues->fromRows(array_values($pages), $siteChecks, $totals);

    $audit = SiteAudit::create($attributes + [
        'site_id' => $siteId, 'provider' => 'dataforseo', 'task_id' => uniqid('crawl-'), 'status' => 'finished', 'max_pages' => 500,
        'site_checks' => $siteChecks, 'totals' => $totals, 'issue_counts' => array_column($computed, 'pages', 'key'),
        'started_at' => now(), 'finished_at' => now(),
    ]);
    foreach ($pages as $url => $checks) {
        SiteAuditPage::create(['audit_id' => $audit->id, 'url' => $url, 'url_hash' => sha1($url), 'status_code' => 200, 'checks' => $checks]);
    }

    return $audit;
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 18:00:00', 'UTC'));
    $this->site = Site::factory()->create(['domain' => 'acme.com']);
    SiteProfile::create(['site_id' => $this->site->id, 'is_enabled' => true]);
    $this->issues = app(SiteAuditIssues::class);
});

it('has a complete rule for every check it knows', function () {
    foreach ([...IssueRules::PAGE, ...IssueRules::SITE] as $key => $rule) {
        expect($rule[0])->toBeBool("{$key} problem-when")
            ->and(IssueRules::SEVERITIES)->toHaveKey($rule[1])
            ->and(IssueRules::CATEGORIES)->toHaveKey($rule[2])
            ->and(strlen($rule[3]))->toBeGreaterThan(8)
            ->and(strlen($rule[4]))->toBeGreaterThan(20);
    }
    foreach (IssueRules::TOTALS as $rule) {
        expect(IssueRules::SEVERITIES)->toHaveKey($rule[0])->and(IssueRules::CATEGORIES)->toHaveKey($rule[1]);
    }
    // No em dashes in text a client may read.
    expect(json_encode([IssueRules::PAGE, IssueRules::SITE, IssueRules::TOTALS], JSON_UNESCAPED_UNICODE))->not->toContain('—');
});

it('reads each check the right way round and ignores what it has no rule for', function () {
    $issues = collect($this->issues->fromRows(
        [
            // "true is bad" and "false is bad" checks side by side.
            ['no_h1_tag' => true, 'is_https' => true, 'seo_friendly_url' => false, 'has_html_doctype' => true, 'made_up_check' => true],
            ['no_h1_tag' => false, 'seo_friendly_url' => true, 'is_4xx_code' => true],
            ['no_h1_tag' => true],
        ],
        ['ssl' => true, 'test_https_redirect' => false, 'sitemap' => false, 'unknown_site_check' => false],
        ['redirect_loop' => 0, 'non_indexable' => 4, 'broken_links' => 9],
    ))->keyBy('key');

    expect($issues->keys()->all())->toBe(['is_4xx_code', 'site:test_https_redirect', 'no_h1_tag', 'site:sitemap', 'total:non_indexable', 'seo_friendly_url'])
        ->and($issues['no_h1_tag'])->toMatchArray(['scope' => 'page', 'severity' => 'warning', 'pages' => 2])
        ->and($issues['seo_friendly_url'])->toMatchArray(['severity' => 'notice', 'pages' => 1])
        ->and($issues['site:test_https_redirect'])->toMatchArray(['scope' => 'site', 'severity' => 'error'])
        ->and($issues['total:non_indexable']['pages'])->toBe(4)
        // A site check the crawl did not answer is not an issue.
        ->and($issues)->not->toHaveKey('site:robots_txt');
});

it('lists a finished crawl\'s issues from stored counts, worst first, and the pages behind one', function () {
    $audit = auditWith($this->site->id, [
        'https://acme.com/' => ['no_h1_tag' => false, 'no_description' => true],
        'https://acme.com/a' => ['no_h1_tag' => true, 'no_description' => true, 'is_broken' => true],
        'https://acme.com/b' => ['no_h1_tag' => true],
    ], ['sitemap' => false]);

    $list = $this->issues->forAudit($audit);

    // Errors first, then by pages affected, then by title.
    expect(array_column($list, 'key'))->toBe(['is_broken', 'no_h1_tag', 'no_description', 'site:sitemap'])
        ->and($list[0])->toMatchArray(['severity' => 'error', 'pages' => 1])
        ->and($list[2]['fix'])->toContain('140 to 160')
        ->and(array_column($this->issues->pages($audit, 'no_h1_tag'), 'url'))->toBe(['https://acme.com/a', 'https://acme.com/b'])
        ->and($this->issues->pages($audit, 'site:sitemap'))->toBe([])
        ->and($this->issues->previous($audit))->toBeNull();
});

it('compares two crawls: new, fixed, still open, and never counts an uncrawled page as fixed', function () {
    $before = auditWith($this->site->id, [
        'https://acme.com/fixed' => ['no_h1_tag' => true],
        'https://acme.com/still' => ['no_h1_tag' => true, 'no_description' => true],
        'https://acme.com/gone' => ['no_h1_tag' => true],
        'https://acme.com/fine' => ['no_h1_tag' => false],
    ], ['sitemap' => false, 'test_https_redirect' => false]);

    $this->travel(30)->days();
    $now = auditWith($this->site->id, [
        'https://acme.com/fixed' => ['no_h1_tag' => false],
        'https://acme.com/still' => ['no_h1_tag' => true, 'no_description' => false],
        // /gone was not reached this time.
        'https://acme.com/fine' => ['no_h1_tag' => false],
        'https://acme.com/new' => ['no_h1_tag' => true, 'is_broken' => true],
    ], ['sitemap' => false, 'test_https_redirect' => true]);

    expect($this->issues->previous($now)->id)->toBe($before->id);

    $result = $this->issues->compare($now, $before);
    $byKey = collect($result['issues'])->keyBy('key');

    expect($result['comparable'])->toBeTrue()
        ->and($byKey['no_h1_tag'])->toMatchArray(['pages' => 2, 'pages_before' => 3, 'new' => 1, 'fixed' => 1, 'still_open' => 1, 'not_crawled_this_time' => 1])
        ->and($byKey['no_h1_tag']['new_pages'])->toBe(['https://acme.com/new'])
        ->and($byKey['no_h1_tag']['fixed_pages'])->toBe(['https://acme.com/fixed'])
        ->and($byKey['no_description'])->toMatchArray(['pages' => 0, 'fixed' => 1, 'new' => 0])
        ->and($byKey['is_broken'])->toMatchArray(['pages' => 1, 'new' => 1, 'pages_before' => 0])
        // Site-wide issues compare too.
        ->and($byKey['site:sitemap'])->toMatchArray(['still_open' => 1, 'new' => 0, 'fixed' => 0])
        ->and($byKey['site:test_https_redirect'])->toMatchArray(['fixed' => 1, 'still_open' => 0])
        ->and($result['totals'])->toBe(['new' => 2, 'fixed' => 3, 'still_open' => 2])
        // Errors with new pages come first.
        ->and($result['issues'][0]['key'])->toBe('is_broken');
});

it('says when two crawls used different settings', function () {
    $before = auditWith($this->site->id, ['https://acme.com/' => ['no_h1_tag' => true]], [], [], ['max_pages' => 100]);
    $now = auditWith($this->site->id, ['https://acme.com/' => ['no_h1_tag' => true]], [], [], ['max_pages' => 500]);

    $result = $this->issues->compare($now, $before);
    expect($result['comparable'])->toBeFalse()->and($result['note'])->toContain('different settings');
});

it('works out and stores issue counts when a crawl finishes', function () {
    config(['clockwork.seo.dataforseo_login' => 'api-login', 'clockwork.seo.dataforseo_password' => 'api-password-canary']);
    $audit = SiteAudit::create(['site_id' => $this->site->id, 'provider' => 'dataforseo', 'task_id' => 'crawl-1', 'status' => 'crawling', 'max_pages' => 500, 'started_at' => now()]);

    $page = fn (string $path, array $checks) => ['resource_type' => 'html', 'status_code' => 200, 'url' => "https://acme.com{$path}", 'meta' => ['title' => 'T'], 'checks' => $checks];
    Http::fake([
        '*/on_page/summary/crawl-1' => Http::response(DataForSeoFixtures::envelope([DataForSeoFixtures::task('crawl-1', [[
            'crawl_progress' => 'finished', 'crawl_status' => ['pages_crawled' => 2],
            'domain_info' => ['checks' => ['sitemap' => false, 'ssl' => true]],
            'page_metrics' => ['onpage_score' => 80, 'non_indexable' => 1, 'redirect_loop' => 0, 'checks' => ['no_h1_tag' => 1]],
        ]])])),
        '*/on_page/pages' => Http::response(DataForSeoFixtures::envelope([DataForSeoFixtures::task('crawl-1', [['items' => [
            $page('/', ['no_h1_tag' => true, 'is_4xx_code' => false, 'seo_friendly_url' => true]),
            $page('/x', ['no_h1_tag' => false, 'is_4xx_code' => true, 'seo_friendly_url' => false]),
        ]]])])),
    ]);

    app(SiteAuditRunner::class)->collect();
    $audit->refresh();

    $counts = $audit->issue_counts;
    ksort($counts);

    expect($counts)->toBe(['is_4xx_code' => 1, 'no_h1_tag' => 1, 'seo_friendly_url' => 1, 'site:sitemap' => 0, 'total:non_indexable' => 1])
        ->and([$audit->errors, $audit->warnings, $audit->notices])->toBe([1, 2, 2]);
});
