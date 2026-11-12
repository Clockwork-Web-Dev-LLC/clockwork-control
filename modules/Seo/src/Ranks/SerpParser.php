<?php

namespace Modules\Seo\Ranks;

/**
 * Reads one Google results page as DataForSEO returns it
 * (/v3/serp/google/organic/task_get/advanced) and answers: where does this
 * site rank, where do its competitors rank, and what else is on the page.
 */
final class SerpParser
{
    /**
     * @param  array<string, mixed>  $result  one entry of the task's "result" array
     * @param  list<string>  $competitors  competitor domains
     * @return array{position: ?int, absolute_position: ?int, url: ?string, depth: int, pages_ranking: int, has_ai_overview: bool, cited_in_ai_overview: bool, features: list<string>, top_domains: list<string>, competitors: array<string, ?int>}
     */
    public static function parse(array $result, string $domain, array $competitors = []): array
    {
        $domain = self::host($domain);
        $items = is_array($result['items'] ?? null) ? $result['items'] : [];

        $position = $absolute = $url = null;
        $pagesRanking = 0;
        $deepest = 0;
        $topDomains = [];
        $competitorPositions = [];
        foreach ($competitors as $competitor) {
            $competitorPositions[self::host($competitor)] = null;
        }
        $hasAiOverview = false;
        $cited = false;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? '');

            if ($type === 'ai_overview') {
                $hasAiOverview = true;
                $cited = $cited || self::cites($item, $domain);

                continue;
            }
            if ($type !== 'organic') {
                continue;
            }

            $rank = isset($item['rank_group']) ? (int) $item['rank_group'] : null;
            $itemDomain = self::host((string) ($item['domain'] ?? ''));
            if ($rank === null || $rank < 1 || $itemDomain === '') {
                continue;
            }
            $deepest = max($deepest, $rank);

            if (count($topDomains) < 10) {
                $topDomains[] = $itemDomain;
            }

            if (self::matches($itemDomain, $domain)) {
                $pagesRanking++;
                if ($position === null || $rank < $position) {
                    $position = $rank;
                    $absolute = isset($item['rank_absolute']) ? (int) $item['rank_absolute'] : null;
                    $url = isset($item['url']) ? mb_substr((string) $item['url'], 0, 2048) : null;
                }
            }

            foreach ($competitorPositions as $competitor => $seen) {
                if (self::matches($itemDomain, $competitor) && ($seen === null || $rank < $seen)) {
                    $competitorPositions[$competitor] = $rank;
                }
            }
        }

        $features = array_values(array_unique(array_filter(
            array_map('strval', is_array($result['item_types'] ?? null) ? $result['item_types'] : []),
            fn (string $t) => $t !== '' && $t !== 'organic',
        )));

        return [
            'position' => $position,
            'absolute_position' => $absolute,
            'url' => $url,
            // What was really looked at. Google can return fewer results
            // than asked for, so "not in the top 30" is only claimed when 30
            // were seen.
            'depth' => $deepest,
            'pages_ranking' => min($pagesRanking, 255),
            'has_ai_overview' => $hasAiOverview,
            'cited_in_ai_overview' => $cited,
            'features' => $features,
            'top_domains' => $topDomains,
            'competitors' => $competitorPositions,
        ];
    }

    /** "https://www.Example.com/x" and "WWW.example.com" both become "example.com". */
    public static function host(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value) ?? $value;
        $value = explode('/', $value)[0];
        $value = explode(':', $value)[0];

        return preg_replace('/^www\./', '', $value) ?? $value;
    }

    /** A subdomain counts as the site: blog.example.com ranks for example.com. */
    public static function matches(string $candidate, string $domain): bool
    {
        return $domain !== '' && ($candidate === $domain || str_ends_with($candidate, '.'.$domain));
    }

    /** Is the domain among the sources an AI Overview links to? */
    private static function cites(array $node, string $domain, int $level = 0): bool
    {
        if ($level > 6) {
            return false;
        }
        foreach ($node as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            if ($key === 'references') {
                foreach ($value as $reference) {
                    $host = self::host((string) (is_array($reference) ? ($reference['domain'] ?? $reference['url'] ?? '') : ''));
                    if ($host !== '' && self::matches($host, $domain)) {
                        return true;
                    }
                }

                continue;
            }
            if (self::cites($value, $domain, $level + 1)) {
                return true;
            }
        }

        return false;
    }
}
