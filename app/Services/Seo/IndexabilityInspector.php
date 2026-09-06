<?php

namespace App\Services\Seo;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RobotsTxtParser\RobotsTxtParser;
use RobotsTxtParser\RobotsTxtValidator;
use Throwable;

class IndexabilityInspector
{
    public const REASON_META_NOINDEX = 'meta_noindex';

    public const REASON_HEADER_NOINDEX = 'header_noindex';

    public const REASON_ROBOTS_DISALLOW_ALL = 'robots_disallow_all';

    public const REASON_WP_BLOG_PUBLIC_ZERO = 'wp_blog_public_zero';

    /**
     * robots.txt user-agent groups worth treating as evidence of a real
     * indexability problem, beyond the generic wildcard group — a block
     * scoped to one of these (e.g. `User-agent: Googlebot` with no `*`
     * group at all) still fully blocks that engine and must not be missed.
     * Deliberately NOT every crawler name that might appear (SEO tools like
     * AhrefsBot/SemrushBot are commonly blocked on purpose and say nothing
     * about search-engine indexability).
     *
     * @var list<string>
     */
    private const SEARCH_ENGINE_USER_AGENTS = [
        '*', 'googlebot', 'googlebot-image', 'googlebot-news', 'googlebot-video',
        'bingbot', 'msnbot', 'slurp', 'duckduckbot', 'baiduspider', 'yandexbot', 'yandex',
    ];

    /**
     * Inspect HTML for `<meta name="robots"|"googlebot" content="...noindex...">`.
     * Uses PHP DOMDocument + DOMXPath with libxml error suppression so malformed HTML never throws.
     * Returns the matched meta tag snippet, or null if clean.
     */
    public function inspectMeta(string $html): ?string
    {
        if (trim($html) === '') {
            return null;
        }

        // Fast-path substring check before spinning up DOMDocument. "none" is
        // Google's documented equivalent of "noindex, nofollow" but doesn't
        // contain the substring "noindex" itself, so it's checked separately.
        $hasNoindexToken = stripos($html, 'noindex') !== false;
        $hasNoneToken = stripos($html, '"none"') !== false || stripos($html, "'none'") !== false;
        if (! $hasNoindexToken && ! $hasNoneToken) {
            return null;
        }

        // Meta tags reside in the document <head>. Cap large documents to
        // avoid memory exhaustion during parsing — but search well beyond
        // the base cap for the actual </head> boundary first, so a page
        // with a lot of legitimate inline CSS/JSON-LD before </head> doesn't
        // get truncated before its real meta tag is ever seen. Only fall
        // back to a hard byte-offset cut (dropping whatever's beyond it) when
        // no </head> shows up within that wider search window at all.
        if (strlen($html) > 500_000) {
            $headEnd = stripos($html, '</head>');
            if ($headEnd !== false && $headEnd < 2_000_000) {
                $html = substr($html, 0, $headEnd + 7);
            } else {
                $html = substr($html, 0, 500_000);
            }
        }

        $prevErrors = libxml_use_internal_errors(true);
        $doc = new DOMDocument;

        try {
            // Prepend XML encoding declaration to handle UTF-8 cleanly
            $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
            $xpath = new DOMXPath($doc);

            // Match meta elements with name 'robots' or 'googlebot' (case-insensitive)
            $query = '//meta[
                translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "robots"
                or translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "googlebot"
            ]';

            $nodes = $xpath->query($query);
            if ($nodes) {
                foreach ($nodes as $node) {
                    if (! $node instanceof DOMElement) {
                        continue;
                    }

                    $content = $node->getAttribute('content');
                    if ($this->hasNoindexDirective($content)) {
                        return $doc->saveHTML($node) ?: '<meta name="'.$node->getAttribute('name').'" content="'.$content.'">';
                    }
                }
            }
        } catch (Throwable) {
            // Parsing errors safely suppressed
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prevErrors);
        }

        return null;
    }

    /**
     * Inspect X-Robots-Tag HTTP header value for noindex.
     */
    public function inspectHeader(?string $xRobotsTag): ?string
    {
        if ($xRobotsTag === null || trim($xRobotsTag) === '') {
            return null;
        }

        if ($this->hasNoindexDirective($xRobotsTag)) {
            return trim($xRobotsTag);
        }

        return null;
    }

    /**
     * Inspect robots.txt content using RFC-compliant precedence rules.
     * Checks if the testPath (default '/') is disallowed for the generic
     * crawler group ('*') AND for any group specifically naming a real
     * search-engine crawler — a robots.txt with no '*' group at all (e.g.
     * only `User-agent: Googlebot`) still fully blocks that engine and must
     * not be reported as clean just because the wildcard group is absent.
     */
    public function inspectRobotsTxt(string $robotsTxtContent, string $testPath = '/'): ?string
    {
        if (trim($robotsTxtContent) === '') {
            return null;
        }

        try {
            $parser = new RobotsTxtParser($robotsTxtContent);
            $allRules = $parser->getRules();
            $validator = new RobotsTxtValidator($allRules);

            $declaredAgents = array_intersect(self::SEARCH_ENGINE_USER_AGENTS, array_keys($allRules));
            // Always check the wildcard group even when it isn't explicitly
            // declared — RobotsTxtValidator itself falls back to it.
            $agentsToCheck = $declaredAgents === [] ? ['*'] : $declaredAgents;

            foreach ($agentsToCheck as $userAgent) {
                if (! $validator->isUrlAllow($testPath, $userAgent)) {
                    return $this->extractDisallowLine($robotsTxtContent)
                        ?? "Disallow: {$testPath} (user-agent: {$userAgent})";
                }
            }
        } catch (Throwable) {
            // Malformed robots.txt falls through as non-blocking
        }

        return null;
    }

    private function extractDisallowLine(string $robotsTxtContent): ?string
    {
        foreach (explode("\n", $robotsTxtContent) as $line) {
            $clean = trim($line);
            if (stripos($clean, 'Disallow:') === 0) {
                return $clean;
            }
        }

        return null;
    }

    /**
     * Classify indexability status according to vector priority:
     * 1. Meta tag (highest priority, most deliberate)
     * 2. Header (X-Robots-Tag)
     * 3. robots.txt
     *
     * @return array{blocked: bool, reason: ?string, snippet: ?string}
     */
    public function classify(?string $metaResult, ?string $headerResult, ?string $robotsResult): array
    {
        if ($metaResult !== null) {
            return [
                'blocked' => true,
                'reason' => self::REASON_META_NOINDEX,
                'snippet' => $metaResult,
            ];
        }

        if ($headerResult !== null) {
            return [
                'blocked' => true,
                'reason' => self::REASON_HEADER_NOINDEX,
                'snippet' => 'X-Robots-Tag: '.$headerResult,
            ];
        }

        if ($robotsResult !== null) {
            return [
                'blocked' => true,
                'reason' => self::REASON_ROBOTS_DISALLOW_ALL,
                'snippet' => $robotsResult,
            ];
        }

        return [
            'blocked' => false,
            'reason' => null,
            'snippet' => null,
        ];
    }

    private function hasNoindexDirective(string $directives): bool
    {
        // Directives are typically comma-separated (e.g. "noindex, nofollow").
        // "none" is Google's documented shorthand for "noindex, nofollow" —
        // treated as an equally blocking directive, not just a literal
        // "noindex" token.
        $tokens = preg_split('/[\s,]+/', strtolower(trim($directives)));
        if (! is_array($tokens)) {
            return false;
        }

        return in_array('noindex', $tokens, true) || in_array('none', $tokens, true);
    }
}
