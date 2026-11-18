<?php

namespace Modules\Seo\Ai;

use Modules\Seo\Ranks\SerpParser;

/**
 * Reads one AI answer as DataForSEO returns it and says whether a brand
 * was mentioned in the words, and whether its site is among the sources.
 *
 * The engines return different shapes, so the text and the sources are
 * gathered wherever they appear rather than from fixed fields.
 */
final class AiAnswerParser
{
    private const TEXT_KEYS = ['markdown', 'text', 'snippet'];

    private const SOURCE_KEYS = ['sources', 'references', 'search_results', 'links'];

    /**
     * @param  array<string, mixed>  $result  one entry of the task's "result" array
     * @param  list<string>  $brandTerms  names the client is known by
     * @param  list<string>  $competitors  competitor domains
     * @return array{answered: bool, mentioned: bool, cited: bool, excerpt: ?string, sources: list<string>, competitors: array<string, array{mentioned: bool, cited: bool}>}
     */
    public static function parse(array $result, string $domain, array $brandTerms = [], array $competitors = []): array
    {
        $domain = SerpParser::host($domain);

        // The top-level markdown is the whole answer where an engine gives
        // one; otherwise the pieces are joined.
        $text = is_string($result['markdown'] ?? null) && trim($result['markdown']) !== ''
            ? $result['markdown']
            : self::collectText((array) ($result['items'] ?? []));
        $text = trim($text);

        $sources = [];
        self::collectSources($result, $sources);
        $sources = array_keys($sources);

        $lower = mb_strtolower($text);
        $out = [
            'answered' => $text !== '',
            'mentioned' => self::mentions($lower, $domain, $brandTerms),
            'cited' => self::cites($sources, $domain),
            'excerpt' => $text === '' ? null : mb_substr($text, 0, 1500),
            'sources' => array_slice($sources, 0, 40),
            'competitors' => [],
        ];

        foreach ($competitors as $competitor) {
            $host = SerpParser::host($competitor);
            if ($host === '' || $host === $domain) {
                continue;
            }
            $out['competitors'][$host] = ['mentioned' => self::mentions($lower, $host, []), 'cited' => self::cites($sources, $host)];
        }

        return $out;
    }

    /**
     * Is the brand named in the answer? By a brand term, by its domain, or
     * by the name part of its domain when that is long enough not to be an
     * ordinary word ("acme" yes, "pro" no).
     *
     * @param  list<string>  $brandTerms
     */
    private static function mentions(string $lowerText, string $domain, array $brandTerms): bool
    {
        if ($lowerText === '') {
            return false;
        }

        $needles = array_map(fn (string $t) => mb_strtolower(trim($t)), $brandTerms);
        $needles[] = $domain;
        $label = explode('.', $domain)[0] ?? '';
        if (mb_strlen($label) >= 5) {
            $needles[] = $label;
            $needles[] = str_replace('-', ' ', $label);
        }

        foreach (array_unique(array_filter($needles, fn (string $n) => mb_strlen($n) >= 3)) as $needle) {
            // Whole words only, so "acme" does not match "pacmen".
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u', $lowerText) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $sources */
    private static function cites(array $sources, string $domain): bool
    {
        foreach ($sources as $source) {
            if (SerpParser::matches($source, $domain)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<mixed>  $items */
    private static function collectText(array $items, int $level = 0): string
    {
        if ($level > 5) {
            return '';
        }

        $parts = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $own = '';
            foreach (self::TEXT_KEYS as $key) {
                if (is_string($item[$key] ?? null) && trim($item[$key]) !== '') {
                    $own = $item[$key];
                    break;
                }
            }
            // A block with its own text already includes its children's.
            $parts[] = $own !== '' ? $own : self::collectText((array) ($item['items'] ?? []), $level + 1);
        }

        return trim(implode("\n\n", array_filter($parts)));
    }

    /**
     * @param  array<mixed>  $node
     * @param  array<string, true>  $sources
     */
    private static function collectSources(array $node, array &$sources, int $level = 0): void
    {
        if ($level > 7) {
            return;
        }

        foreach ($node as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            if (in_array($key, self::SOURCE_KEYS, true)) {
                foreach ($value as $source) {
                    if (! is_array($source)) {
                        continue;
                    }
                    $host = SerpParser::host((string) ($source['domain'] ?? $source['url'] ?? ''));
                    if ($host !== '' && str_contains($host, '.') && mb_strlen($host) <= 191) {
                        $sources[$host] = true;
                    }
                }

                continue;
            }
            self::collectSources($value, $sources, $level + 1);
        }
    }
}
