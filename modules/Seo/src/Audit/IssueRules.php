<?php

namespace Modules\Seo\Audit;

/**
 * Turns DataForSEO's raw true/false crawl checks into issues a person can
 * act on: a severity, a category, a plain title and what to do about it.
 *
 * DataForSEO only says whether each check is true. Whether true is the
 * problem depends on the check ("no_h1_tag" true is bad; "is_https" false
 * is bad), so each rule says which answer is the problem. A check with no
 * rule here is not reported.
 *
 * Severity is our judgement, not the provider's:
 *   error   - likely to cost rankings or break the page for visitors
 *   warning - worth fixing, rarely urgent
 *   notice  - worth knowing; often intentional
 *
 * The check names were taken from DataForSEO's documentation and are not
 * yet confirmed against a real crawl. A name that never appears simply
 * never fires.
 */
final class IssueRules
{
    public const SEVERITIES = ['error' => 'Error', 'warning' => 'Warning', 'notice' => 'Notice'];

    public const CATEGORIES = [
        'crawl' => 'Crawling and status codes',
        'https' => 'HTTPS and security',
        'indexing' => 'Indexing and canonicals',
        'content' => 'Titles, descriptions and content',
        'links' => 'Links and redirects',
        'speed' => 'Speed and page weight',
        'markup' => 'HTML and structured data',
        'images' => 'Images',
    ];

    /**
     * Page checks: key => [the answer that is a problem, severity, category, title, fix].
     *
     * @var array<string, array{0: bool, 1: string, 2: string, 3: string, 4: string}>
     */
    public const PAGE = [
        'is_4xx_code' => [true, 'error', 'crawl', 'Pages that return a 4xx error', 'These URLs are linked from the site but answer "not found" or "forbidden". Restore the page, redirect it to the closest live page, or remove the links to it.'],
        'is_5xx_code' => [true, 'error', 'crawl', 'Pages that return a server error', 'The server failed on these URLs during the crawl. Check the server and application error logs; if it was a one-off, it will clear on the next crawl.'],
        'is_broken' => [true, 'error', 'crawl', 'Broken pages', 'These pages could not be loaded. Fix or redirect them, and remove links pointing at them.'],
        'is_http' => [true, 'error', 'https', 'Pages served over http', 'These pages load without HTTPS. Redirect every http URL to its https version.'],
        'https_to_http_links' => [true, 'error', 'https', 'Secure pages that link to or load http content', 'Change these links and resources to https so browsers do not flag or block them.'],
        'no_title' => [true, 'error', 'content', 'Pages with no title tag', 'Give each page a unique title of about 50 to 60 characters that names what the page is about.'],
        'duplicate_title' => [true, 'error', 'content', 'Pages sharing the same title', 'Search engines cannot tell these pages apart. Write a distinct title for each, or point duplicates at one page with a canonical or redirect.'],
        'duplicate_content' => [true, 'error', 'content', 'Pages with near-identical content', 'Merge these pages, differentiate them, or set a canonical to the one that should rank.'],
        'canonical_to_broken' => [true, 'error', 'indexing', 'Canonical points to a broken page', 'The canonical tag names a URL that does not load. Point it at the live version of the page.'],
        'recursive_canonical' => [true, 'error', 'indexing', 'Canonicals that loop', 'These pages name each other as canonical. Pick one and point the others at it.'],
        'broken_links' => [true, 'error', 'links', 'Pages containing broken links', 'These pages link to URLs that do not load. Update or remove those links.'],
        'broken_resources' => [true, 'error', 'links', 'Pages loading broken images, scripts or styles', 'A file these pages rely on does not load. Fix the path or remove the reference.'],

        'no_description' => [true, 'warning', 'content', 'Pages with no meta description', 'Add a description of about 140 to 160 characters. Google may show it under the title in results.'],
        'duplicate_description' => [true, 'warning', 'content', 'Pages sharing the same meta description', 'Write a description specific to each page.'],
        'no_h1_tag' => [true, 'warning', 'content', 'Pages with no H1 heading', 'Add one H1 that says what the page is about.'],
        'title_too_long' => [true, 'warning', 'content', 'Titles that are too long', 'Titles over about 65 characters are cut off in results. Shorten them, keeping the main keyword near the start.'],
        'title_too_short' => [true, 'warning', 'content', 'Titles that are too short', 'A title under about 30 characters wastes space. Add what the page offers and, for a local business, where.'],
        'duplicate_title_tag' => [true, 'warning', 'markup', 'Pages with more than one title tag', 'Only one title tag should be in the page. This usually comes from a theme and an SEO plugin both printing one.'],
        'duplicate_meta_tags' => [true, 'warning', 'markup', 'Pages with repeated meta tags', 'The same meta tag is printed more than once, usually by two plugins. Keep one.'],
        'low_content_rate' => [true, 'warning', 'content', 'Pages with very little text', 'Most of these pages is code, not words. Add useful content, or noindex pages that are not meant to rank.'],
        'lorem_ipsum' => [true, 'warning', 'content', 'Pages with placeholder text', 'Lorem ipsum filler is live on these pages. Replace it with real copy.'],
        'canonical_to_redirect' => [true, 'warning', 'indexing', 'Canonical points to a redirect', 'Point the canonical at the final URL, not one that redirects.'],
        'canonical_chain' => [true, 'warning', 'indexing', 'Canonical chains', 'The canonical names a page whose own canonical names another. Point each at the final page directly.'],
        'is_orphan_page' => [true, 'warning', 'links', 'Pages no other page links to', 'These pages are only reachable from the sitemap. Link to them from related pages or the menu, or remove them if they are not needed.'],
        'has_links_to_redirects' => [true, 'warning', 'links', 'Pages linking to redirected URLs', 'Update these internal links to the final URL to save a hop.'],
        'redirect_chain' => [true, 'warning', 'links', 'Redirect chains', 'These URLs redirect more than once. Redirect straight to the final URL.'],
        'has_meta_refresh_redirect' => [true, 'warning', 'links', 'Pages that redirect with a meta refresh', 'Use a server-side 301 redirect instead.'],
        'is_link_relation_conflict' => [true, 'warning', 'links', 'Pages linked with both follow and nofollow', 'The same page is linked with conflicting rel attributes. Make the links consistent.'],
        'high_loading_time' => [true, 'warning', 'speed', 'Slow pages', 'These pages took over 3 seconds to load for the crawler. Check images, caching and render-blocking files.'],
        'high_waiting_time' => [true, 'warning', 'speed', 'Slow server response', 'The server took over 1.5 seconds to start answering. Check hosting, caching and slow database queries.'],
        'size_greater_than_3mb' => [true, 'warning', 'speed', 'Pages over 3 MB', 'Compress images and remove unused scripts and styles.'],
        'large_page_size' => [true, 'warning', 'speed', 'Large HTML documents', 'The HTML alone is over 1 MB. Look for inlined images, scripts or very long pages.'],
        'has_render_blocking_resources' => [true, 'warning', 'speed', 'Pages with render-blocking files', 'Defer or inline the scripts and styles that delay the first paint.'],
        'no_content_encoding' => [true, 'warning', 'speed', 'Pages sent without compression', 'Turn on gzip or Brotli on the server or CDN.'],
        'no_image_alt' => [true, 'warning', 'images', 'Pages with images missing alt text', 'Add alt text that describes each meaningful image.'],
        'has_micromarkup_errors' => [true, 'warning', 'markup', 'Pages with structured data errors', 'The schema markup on these pages does not validate. Test the page in Google\'s Rich Results Test and fix what it reports.'],
        'deprecated_html_tags' => [true, 'warning', 'markup', 'Pages using deprecated HTML tags', 'Replace outdated tags such as <center> or <font> with CSS.'],
        'no_doctype' => [true, 'warning', 'markup', 'Pages with no doctype', 'Add <!DOCTYPE html> as the first line so browsers render in standards mode.'],
        'no_encoding_meta_tag' => [true, 'warning', 'markup', 'Pages that do not declare a character set', 'Add <meta charset="utf-8"> near the top of the head.'],
        'frame' => [true, 'warning', 'markup', 'Pages using frames', 'Content inside frames is hard for search engines to credit to the page. Put it in the page itself.'],
        'flash' => [true, 'warning', 'markup', 'Pages using Flash', 'Flash no longer runs in browsers. Replace it.'],

        'is_redirect' => [true, 'notice', 'links', 'URLs that redirect', 'Fine when intended. Check that internal links point at the final URL.'],
        'seo_friendly_url' => [false, 'notice', 'content', 'URLs that are not search-friendly', 'These URLs are long, use odd characters or do not relate to the title. Prefer short, lower-case, hyphenated paths for new pages; changing a live URL needs a redirect.'],
        'low_character_count' => [true, 'notice', 'content', 'Short pages', 'Under about 1,000 characters of text. Fine for contact or utility pages; thin for a page meant to rank.'],
        'low_readability_rate' => [true, 'notice', 'content', 'Hard-to-read pages', 'The text scores low for readability. Shorter sentences and plainer words usually help.'],
        'irrelevant_title' => [true, 'notice', 'content', 'Titles that do not match the page', 'The title shares few words with the page content. Check it describes the page.'],
        'irrelevant_description' => [true, 'notice', 'content', 'Descriptions that do not match the page', 'The description shares few words with the page content. Check it describes the page.'],
        'has_misspelling' => [true, 'notice', 'content', 'Pages with possible misspellings', 'Check the flagged words; brand and trade terms are often false alarms.'],
        'no_image_title' => [true, 'notice', 'images', 'Pages with images missing a title attribute', 'Optional. Alt text matters more.'],
        'no_favicon' => [true, 'notice', 'markup', 'Pages with no favicon', 'Add a favicon so the site has an icon in tabs and in search results.'],
        'small_page_size' => [true, 'notice', 'speed', 'Very small documents', 'Under 1 KB of HTML. Usually an empty or error page.'],
    ];

    /**
     * Site-wide checks: key => [the answer that is a problem, severity, category, title, fix].
     *
     * @var array<string, array{0: bool, 1: string, 2: string, 3: string, 4: string}>
     */
    public const SITE = [
        'ssl' => [false, 'error', 'https', 'No valid SSL certificate', 'The site has no working certificate. Install one and redirect all traffic to https.'],
        'test_https_redirect' => [false, 'error', 'https', 'http does not redirect to https', 'Add a site-wide 301 redirect from http to https.'],
        'start_page_deny_flag' => [true, 'error', 'indexing', 'The home page blocks search engines', 'robots.txt or a meta tag tells search engines not to crawl the home page. Remove the block unless the site is meant to be private.'],
        'sitemap' => [false, 'warning', 'crawl', 'No XML sitemap found', 'Publish a sitemap and reference it in robots.txt and Search Console.'],
        'test_canonicalization' => [false, 'warning', 'indexing', 'www and non-www both answer', 'Pick one form and 301 redirect the other to it, so the site is not split in two.'],
        'test_page_not_found' => [false, 'warning', 'crawl', 'Missing pages do not return 404', 'A made-up URL answers as if it exists. Configure the server to return a real 404 status.'],
        'robots_txt' => [false, 'notice', 'crawl', 'No robots.txt file', 'Optional, but a robots.txt naming the sitemap helps crawlers.'],
        'http2' => [false, 'notice', 'speed', 'The server does not use HTTP/2', 'HTTP/2 loads pages faster. Most hosts and CDNs can turn it on.'],
        'test_directory_browsing' => [false, 'notice', 'https', 'Directory listing may be open', 'The server may list folder contents to visitors. Turn directory browsing off.'],
    ];

    /**
     * Site totals that are issues when above zero: key => [severity, category, title, fix].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public const TOTALS = [
        'redirect_loop' => ['error', 'links', 'Redirect loops', 'These URLs redirect in a circle and never load. Fix the redirect rules.'],
        'non_indexable' => ['notice', 'indexing', 'Pages search engines cannot index', 'Blocked by robots.txt, a noindex tag or a header. Intended for admin or thank-you pages; a problem for pages meant to rank.'],
    ];
}
