<?php

namespace Modules\Seo\Research;

use Modules\Seo\Keywords\Market;
use Modules\Seo\Models\RankedKeyword;

/**
 * Keyword gap: what competitors rank for that the client doesn't, worked
 * out from the ranked-keyword lists already stored. It buys nothing.
 *
 * "Doesn't rank" here means "is not in the stored list", and a stored list
 * is the top of a domain's keywords by traffic, not all of them. So a gap
 * is a lead to check, not proof.
 */
class KeywordGap
{
    public const SEGMENTS = [
        'missing' => 'Every competitor ranks, the client does not',
        'untapped' => 'At least one competitor ranks, the client does not',
        'weak' => 'The client ranks, but below every competitor that ranks',
        'strong' => 'The client ranks above every competitor that ranks',
        'shared' => 'The client and every competitor rank',
        'unique' => 'Only the client ranks',
    ];

    public const MAX_COMPETITORS = 5;

    /**
     * @param  list<string>  $competitors
     * @return array{client: string, competitors: list<string>, without_data: list<string>, client_has_data: bool, counts: array<string, int>, rows: list<array<string, mixed>>, rows_matching: int}
     */
    public function compare(string $client, array $competitors, Market $market, string $segment = 'missing', int $limit = 200, int $minVolume = 0, ?string $contains = null): array
    {
        $client = DomainResearch::domain($client);
        $competitors = array_slice(array_values(array_unique(array_filter(array_map([DomainResearch::class, 'domain'], $competitors), fn (string $d) => $d !== '' && $d !== $client))), 0, self::MAX_COMPETITORS);

        $rows = RankedKeyword::query()
            ->whereIn('domain', [$client, ...$competitors])
            ->where('market_key', $market->key())
            ->get(['domain', 'keyword', 'position', 'url', 'volume', 'difficulty', 'intent', 'cpc']);

        $have = $rows->pluck('domain')->unique()->all();
        // A competitor with no stored list can't be compared: counting it
        // as "doesn't rank" would invent gaps.
        $compared = array_values(array_intersect($competitors, $have));
        $withoutData = array_values(array_diff($competitors, $have));
        $clientHasData = in_array($client, $have, true);

        $byKeyword = [];
        foreach ($rows as $row) {
            if ($row->domain !== $client && ! in_array($row->domain, $compared, true)) {
                continue;
            }
            $byKeyword[$row->keyword]['positions'][$row->domain] = $row->position;
            // Lists pulled in different months can disagree; take the larger.
            $byKeyword[$row->keyword]['volume'] = $row->volume === null ? ($byKeyword[$row->keyword]['volume'] ?? null) : max($row->volume, $byKeyword[$row->keyword]['volume'] ?? 0);
            $byKeyword[$row->keyword]['difficulty'] ??= $row->difficulty;
            $byKeyword[$row->keyword]['intent'] ??= $row->intent;
            $byKeyword[$row->keyword]['cpc'] ??= $row->cpc;
            if ($row->domain === $client) {
                $byKeyword[$row->keyword]['url'] = $row->url;
            }
        }

        $counts = array_fill_keys(array_keys(self::SEGMENTS), 0);
        $matching = [];

        foreach ($byKeyword as $keyword => $data) {
            $own = $data['positions'][$client] ?? null;
            $others = array_diff_key($data['positions'], [$client => true]);
            $segments = self::segmentsFor($own, $others, count($compared));

            foreach ($segments as $name) {
                $counts[$name]++;
            }
            if (! in_array($segment, $segments, true)
                || ($minVolume > 0 && (int) ($data['volume'] ?? 0) < $minVolume)
                || ($contains !== null && $contains !== '' && ! str_contains($keyword, mb_strtolower($contains)))) {
                continue;
            }

            $matching[] = [
                'keyword' => $keyword,
                'volume' => $data['volume'],
                'difficulty' => $data['difficulty'],
                'intent' => $data['intent'],
                'cpc' => $data['cpc'],
                'client_position' => $own,
                'client_url' => $data['url'] ?? null,
                'competitor_positions' => array_map(fn (string $d) => $others[$d] ?? null, $compared),
                'best_competitor_position' => $others === [] ? null : min($others),
            ];
        }

        // Biggest opportunity first: volume, then how well a competitor does.
        usort($matching, fn (array $a, array $b) => [$b['volume'] ?? -1, $a['best_competitor_position'] ?? 999, $a['keyword']] <=> [$a['volume'] ?? -1, $b['best_competitor_position'] ?? 999, $b['keyword']]);

        return [
            'client' => $client,
            'competitors' => $compared,
            'without_data' => $withoutData,
            'client_has_data' => $clientHasData,
            'counts' => $counts,
            'rows' => array_slice($matching, 0, max(1, $limit)),
            'rows_matching' => count($matching),
        ];
    }

    /**
     * @param  array<string, int>  $others  competitor => position, only those that rank
     * @return list<string>
     */
    private static function segmentsFor(?int $own, array $others, int $competitorCount): array
    {
        if ($competitorCount === 0) {
            return $own !== null ? ['unique'] : [];
        }

        $allRank = count($others) === $competitorCount;

        if ($own === null) {
            return $others === [] ? [] : ($allRank ? ['missing', 'untapped'] : ['untapped']);
        }
        if ($others === []) {
            return ['unique'];
        }

        $segments = $allRank ? ['shared'] : [];
        if ($own > max($others)) {
            $segments[] = 'weak';
        } elseif ($own < min($others)) {
            $segments[] = 'strong';
        }

        return $segments;
    }
}
