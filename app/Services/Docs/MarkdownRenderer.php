<?php

namespace App\Services\Docs;

use Illuminate\Support\Carbon;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Parses a markdown doc file into a DocPage. Two responsibilities:
 *
 *   1. Extract YAML frontmatter (between leading `---` lines) and parse it.
 *   2. Convert the remaining markdown to HTML via CommonMark with GFM tables,
 *      strikethrough, task lists, and autolinks enabled.
 *
 * Kept as a single small class — doc volume is bounded (target: ~30 pages),
 * the rendering is straightforward, no need to pull in a heavier framework.
 */
class MarkdownRenderer
{
    public function render(string $slug, string $rawContent): DocPage
    {
        [$frontmatter, $body] = $this->splitFrontmatter($rawContent);
        $html = $this->convert($body);

        $tags = $frontmatter['tags'] ?? [];
        if (! is_array($tags)) {
            $tags = [];
        }

        $tracks = $frontmatter['tracks'] ?? [];
        if (! is_array($tracks)) {
            $tracks = [];
        }

        $section = (string) ($frontmatter['section'] ?? 'Misc');
        $category = isset($frontmatter['category'])
            ? (string) $frontmatter['category']
            : (isset($frontmatter['group']) ? (string) $frontmatter['group'] : $this->inferCategory($slug, $section));

        return new DocPage(
            slug: $slug,
            title: (string) ($frontmatter['title'] ?? $this->humanizeSlug($slug)),
            section: $section,
            order: (int) ($frontmatter['order'] ?? 999),
            updated: $this->parseDate($frontmatter['updated'] ?? null),
            author: isset($frontmatter['author']) ? (string) $frontmatter['author'] : null,
            tags: array_values(array_map(fn ($t) => (string) $t, $tags)),
            tracks: array_values(array_map(fn ($t) => (string) $t, $tracks)),
            htmlBody: $html,
            excerpt: $this->buildExcerpt($body),
            category: $category,
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function splitFrontmatter(string $raw): array
    {
        // Frontmatter must be at the very start, opened with `---` on its own
        // line and closed with another `---`. Anything else = no frontmatter.
        if (! str_starts_with($raw, "---\n") && ! str_starts_with($raw, "---\r\n")) {
            return [[], $raw];
        }

        $offset = str_starts_with($raw, "---\r\n") ? 5 : 4;
        $endPattern = "\n---\n";
        $endPos = strpos($raw, $endPattern, $offset);
        if ($endPos === false) {
            // Try CRLF variant
            $endPattern = "\r\n---\r\n";
            $endPos = strpos($raw, $endPattern, $offset);
            if ($endPos === false) {
                return [[], $raw];
            }
        }

        $yamlBlock = substr($raw, $offset, $endPos - $offset);
        $body = substr($raw, $endPos + strlen($endPattern));
        $parsed = $this->parseFrontmatter($yamlBlock);

        return [$parsed, $body];
    }

    /**
     * Tiny frontmatter parser. Handles the shapes we actually use:
     *   key: scalar value
     *   key: [item1, item2, item3]   (inline string array)
     * Comments and blank lines ignored. Anything else (multi-line strings,
     * nested mappings, flow maps) is out of scope — keep doc frontmatter
     * simple and this stays simple.
     *
     * @return array<string, mixed>
     */
    private function parseFrontmatter(string $yaml): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $yaml) ?: [] as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#')) {
                continue;
            }
            $colon = strpos($trim, ':');
            if ($colon === false) {
                continue;
            }
            $key = trim(substr($trim, 0, $colon));
            $rawValue = trim(substr($trim, $colon + 1));
            $out[$key] = $this->parseFrontmatterValue($rawValue);
        }

        return $out;
    }

    private function parseFrontmatterValue(string $raw): mixed
    {
        if ($raw === '' || $raw === '~' || strtolower($raw) === 'null') {
            return null;
        }
        // Inline array: [a, b, c] or ['a', "b", c]
        if (str_starts_with($raw, '[') && str_ends_with($raw, ']')) {
            $inner = trim(substr($raw, 1, -1));
            if ($inner === '') {
                return [];
            }
            $items = array_map('trim', $this->splitTopLevel($inner));

            return array_map(fn ($i) => $this->stripQuotes($i), $items);
        }

        return $this->stripQuotes($raw);
    }

    /**
     * Splits on top-level commas only — commas inside `{...}` (a tracks:
     * brace-list like `{A,B,C}.php`) don't count as separators. Plain
     * `explode(',', ...)` was splitting a single entry like
     * `app/Console/Commands/{ScanSiteCheck,CheckBlacklists}.php` into two
     * broken fragments (`app/Console/Commands/{ScanSiteCheck` and
     * `CheckBlacklists}.php`), silently corrupting every tracks: entry
     * using brace syntax — confirmed live on 7 doc pages 2026-08-31, found
     * while building the coverage checker (see CoverageChecker's docblock).
     *
     * @return list<string>
     */
    private function splitTopLevel(string $inner): array
    {
        $items = [];
        $depth = 0;
        $current = '';

        for ($i = 0, $len = strlen($inner); $i < $len; $i++) {
            $char = $inner[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth = max(0, $depth - 1);
            }

            if ($char === ',' && $depth === 0) {
                $items[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }
        $items[] = $current;

        return $items;
    }

    private function stripQuotes(string $v): string
    {
        if (strlen($v) >= 2) {
            $first = $v[0];
            $last = $v[-1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($v, 1, -1);
            }
        }

        return $v;
    }

    private function convert(string $markdown): string
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return $converter->convert($markdown)->getContent();
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function humanizeSlug(string $slug): string
    {
        $tail = basename($slug);

        return ucwords(str_replace(['-', '_', '/'], ' ', $tail));
    }

    /**
     * First plain-text paragraph from the markdown body, capped at ~280 chars.
     * Used by the index/landing page to show a one-line preview per page.
     */
    private function buildExcerpt(string $body): string
    {
        $paragraphs = preg_split("/\n\s*\n/", trim($body), 2);
        $first = is_array($paragraphs) && isset($paragraphs[0]) ? $paragraphs[0] : '';
        // Strip basic markdown decorations.
        $plain = preg_replace('/[#>*_`\[\]]+/', '', $first) ?? '';
        $plain = trim(preg_replace('/\s+/', ' ', $plain) ?? '');
        if (mb_strlen($plain) > 280) {
            $plain = mb_substr($plain, 0, 277).'…';
        }

        return $plain;
    }

    /**
     * Infer a functional category for a page based on its URL slug and section.
     */
    public function inferCategory(string $slug, string $section): string
    {
        if (str_starts_with($slug, 'integrations/')) {
            if ($slug === 'integrations/overview') {
                return 'Overview';
            }
            if (in_array($slug, ['integrations/pressable', 'integrations/spinupwp', 'integrations/wp-engine', 'integrations/kinsta', 'integrations/cloudways', 'integrations/gridpane'], true)) {
                return 'Managed WordPress Hosts';
            }
            if (in_array($slug, ['integrations/digitalocean', 'integrations/hetzner', 'integrations/azure', 'integrations/vultr', 'integrations/linode', 'integrations/digitalocean-spaces'], true)) {
                return 'Cloud Infrastructure & VPS';
            }
            if (in_array($slug, ['integrations/sucuri-sitecheck', 'integrations/safe-browsing', 'integrations/urlhaus-spamhaus', 'integrations/ssh-and-fail2ban', 'integrations/arcjet-bots'], true)) {
                return 'Security & Threat Intelligence';
            }
            if (in_array($slug, ['integrations/slack', 'integrations/mattermost', 'integrations/mailgun', 'integrations/twilio'], true)) {
                return 'Alerts & Communications';
            }

            return 'Performance, DNS & Utilities';
        }

        if (str_starts_with($slug, 'features/')) {
            if (in_array($slug, ['features/dashboard', 'features/sites-fleet-view', 'features/servers', 'features/inactive-sites', 'features/wordpress-plugin-inventory'], true)) {
                return 'Fleet & Asset Inventory';
            }
            if (in_array($slug, ['features/uptime-monitoring', 'features/security-scans', 'features/performance-scans', 'features/ssl-cert-tracking', 'features/contact-form-testing', 'features/traffic-and-capacity'], true)) {
                return 'Monitoring & Site Health';
            }
            if (in_array($slug, ['features/updates', 'features/review-queue', 'features/server-updates-and-reboots', 'features/backup-relay', 'features/system-updates'], true)) {
                return 'Updates, Maintenance & Backups';
            }

            return 'Platform & Administration';
        }

        if (str_starts_with($slug, 'getting-started/')) {
            return 'Onboarding & Setup';
        }
        if (str_starts_with($slug, 'concepts/')) {
            return 'Mental Models';
        }
        if (str_starts_with($slug, 'runbooks/')) {
            return 'Incident Response SOPs';
        }
        if (str_starts_with($slug, 'architecture/')) {
            return 'System Architecture & Pipelines';
        }
        if (str_starts_with($slug, 'reference/') || str_starts_with($slug, 'internal/')) {
            return 'Developer & System Reference';
        }

        return $section !== '' ? $section : 'General';
    }
}
