<?php

namespace Tests\Unit\Services\Seo;

use App\Services\Seo\IndexabilityInspector;
use Tests\TestCase;

class IndexabilityInspectorTest extends TestCase
{
    private IndexabilityInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inspector = new IndexabilityInspector;
    }

    public function test_inspect_meta_detects_standard_noindex(): void
    {
        $html = '<html><head><meta name="robots" content="noindex, nofollow"></head><body><h1>Hi</h1></body></html>';
        $snippet = $this->inspector->inspectMeta($html);

        $this->assertNotNull($snippet);
        $this->assertStringContainsString('noindex', $snippet);
        $this->assertStringContainsString('robots', $snippet);
    }

    public function test_inspect_meta_is_case_and_whitespace_insensitive(): void
    {
        $html = '<html><head><META   NAME="ROBOTS"   CONTENT=" NOINDEX , NOFOLLOW "></head></html>';
        $snippet = $this->inspector->inspectMeta($html);

        $this->assertNotNull($snippet);
        $this->assertStringContainsString('NOINDEX', $snippet);
    }

    public function test_inspect_meta_detects_googlebot_meta_tag(): void
    {
        $html = '<html><head><meta name="googlebot" content="noindex"></head></html>';
        $snippet = $this->inspector->inspectMeta($html);

        $this->assertNotNull($snippet);
        $this->assertStringContainsString('googlebot', $snippet);
    }

    public function test_inspect_meta_ignores_html_comments_containing_noindex(): void
    {
        // A naive regex would fail this; DOMDocument correctly ignores comments
        $html = '<html><head><!-- <meta name="robots" content="noindex"> --><meta name="robots" content="index, follow"></head></html>';
        $snippet = $this->inspector->inspectMeta($html);

        $this->assertNull($snippet);
    }

    public function test_inspect_meta_returns_null_for_clean_page(): void
    {
        $html = '<html><head><title>Clean Page</title><meta name="description" content="hello"></head><body>Welcome</body></html>';
        $this->assertNull($this->inspector->inspectMeta($html));
    }

    public function test_inspect_header_detects_noindex(): void
    {
        $this->assertSame('noindex, nofollow', $this->inspector->inspectHeader('noindex, nofollow'));
        $this->assertSame('NOINDEX', $this->inspector->inspectHeader('NOINDEX'));
        $this->assertNull($this->inspector->inspectHeader('all'));
        $this->assertNull($this->inspector->inspectHeader('index, follow'));
        $this->assertNull($this->inspector->inspectHeader(null));
        $this->assertNull($this->inspector->inspectHeader(''));
    }

    public function test_inspect_robots_txt_detects_blanket_disallow(): void
    {
        $robotsTxt = "User-agent: *\nDisallow: /";
        $snippet = $this->inspector->inspectRobotsTxt($robotsTxt, '/');

        $this->assertNotNull($snippet);
        $this->assertStringContainsString('Disallow:', $snippet);
    }

    public function test_inspect_robots_txt_respects_longest_match_allow_precedence(): void
    {
        $robotsTxt = "User-agent: *\nDisallow: /\nAllow: /public-page";

        // Root / is disallowed
        $rootSnippet = $this->inspector->inspectRobotsTxt($robotsTxt, '/');
        $this->assertNotNull($rootSnippet);

        // Subpath /public-page is allowed due to RFC longest-match precedence
        $publicSnippet = $this->inspector->inspectRobotsTxt($robotsTxt, '/public-page');
        $this->assertNull($publicSnippet);
    }

    public function test_inspect_robots_txt_returns_null_for_clean_robots(): void
    {
        $robotsTxt = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php";
        $this->assertNull($this->inspector->inspectRobotsTxt($robotsTxt, '/'));
    }

    public function test_classify_priority_order(): void
    {
        // 1. Meta > Header > Robots
        $c1 = $this->inspector->classify('<meta name="robots" content="noindex">', 'noindex', 'Disallow: /');
        $this->assertTrue($c1['blocked']);
        $this->assertSame(IndexabilityInspector::REASON_META_NOINDEX, $c1['reason']);

        // 2. Header > Robots
        $c2 = $this->inspector->classify(null, 'noindex', 'Disallow: /');
        $this->assertTrue($c2['blocked']);
        $this->assertSame(IndexabilityInspector::REASON_HEADER_NOINDEX, $c2['reason']);

        // 3. Robots alone
        $c3 = $this->inspector->classify(null, null, 'Disallow: /');
        $this->assertTrue($c3['blocked']);
        $this->assertSame(IndexabilityInspector::REASON_ROBOTS_DISALLOW_ALL, $c3['reason']);

        // 4. All clean
        $c4 = $this->inspector->classify(null, null, null);
        $this->assertFalse($c4['blocked']);
        $this->assertNull($c4['reason']);
        $this->assertNull($c4['snippet']);
    }

    public function test_inspect_meta_handles_oversized_payloads(): void
    {
        $largeBody = str_repeat('<div>huge content</div>', 40000); // > 800 KB
        $html = '<html><head><meta name="robots" content="noindex"></head><body>'.$largeBody.'</body></html>';

        $snippet = $this->inspector->inspectMeta($html);
        $this->assertNotNull($snippet);
        $this->assertStringContainsString('noindex', $snippet);
    }

    public function test_inspect_meta_still_detects_noindex_when_head_is_large_but_under_the_search_cap(): void
    {
        // A real noindex meta tag sitting past the base 500 KB cap, but
        // still inside </head> and under the wider search window, must not
        // be silently truncated away (this used to hard-cut at exactly
        // 500 KB whenever </head> fell beyond that point at all).
        $bulkyHead = str_repeat('<style>.x{color:red}</style>', 30000); // ~870 KB, still < 2MB
        $html = '<html><head>'.$bulkyHead.'<meta name="robots" content="noindex"></head><body>ok</body></html>';

        $this->assertGreaterThan(500_000, strpos($html, '<meta name="robots"'));

        $snippet = $this->inspector->inspectMeta($html);
        $this->assertNotNull($snippet);
        $this->assertStringContainsString('noindex', $snippet);
    }

    public function test_inspect_meta_detects_content_none_as_equivalent_to_noindex(): void
    {
        $html = '<html><head><meta name="robots" content="none"></head></html>';
        $snippet = $this->inspector->inspectMeta($html);

        $this->assertNotNull($snippet);
        $this->assertStringContainsString('none', $snippet);
    }

    public function test_inspect_header_detects_none_as_equivalent_to_noindex(): void
    {
        $this->assertSame('none', $this->inspector->inspectHeader('none'));
        $this->assertSame('NONE', $this->inspector->inspectHeader('NONE'));
    }

    public function test_inspect_robots_txt_detects_a_block_scoped_to_a_named_search_engine_with_no_wildcard_group(): void
    {
        // No "User-agent: *" group at all — a naive '*'-only check reports
        // this as clean, but it fully blocks Googlebot.
        $robotsTxt = "User-agent: Googlebot\nDisallow: /";

        $snippet = $this->inspector->inspectRobotsTxt($robotsTxt, '/');
        $this->assertNotNull($snippet);
    }

    public function test_inspect_robots_txt_does_not_treat_a_non_search_engine_bot_block_as_indexability_loss(): void
    {
        // Blocking a non-search-engine crawler (a common, deliberate choice
        // for SEO scraper tools) says nothing about real search visibility.
        $robotsTxt = "User-agent: AhrefsBot\nDisallow: /";

        $this->assertNull($this->inspector->inspectRobotsTxt($robotsTxt, '/'));
    }
}
