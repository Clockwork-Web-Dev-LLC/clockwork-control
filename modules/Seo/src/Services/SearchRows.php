<?php

namespace Modules\Seo\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stored Search Console page, query and page x query rows, added up over
 * one or more calendar months. Clicks and impressions sum exactly; position
 * is the impression-weighted average, which is how Search Console itself
 * averages it.
 */
class SearchRows
{
    /** More groups than this are cut off, by impressions, and reported as such. */
    public const MAX_GROUPS = 20000;

    /**
     * @param  'page'|'query'|'page_query'  $dimension
     * @param  list<CarbonImmutable>  $months
     * @param  array{page?: array{value: string, match?: string}, query?: array{value: string, match?: string}, min_impressions?: int}  $filters
     * @return array{rows: array<string, array{page: ?string, query: ?string, clicks: int, impressions: int, ctr: ?float, position: ?float}>, cut_off: bool}
     */
    public function aggregate(int $siteId, string $dimension, array $months, bool $nonbrandedOnly = false, array $filters = []): array
    {
        $monthDates = array_map(fn (CarbonImmutable $m) => $m->startOfMonth()->toDateString(), $months);

        // Non-branded pages are not stored as such: they are the page's
        // page x query rows whose query isn't a brand query, added up.
        $pagesFromQueries = $dimension === 'page' && $nonbrandedOnly;
        $sourceDimension = $pagesFromQueries ? 'page_query' : $dimension;

        $query = DB::table('seo_gsc_month_rows')
            ->where('site_id', $siteId)
            ->where('dimension', $sourceDimension)
            ->whereIn('month', $monthDates);

        if ($nonbrandedOnly && $dimension !== 'page') {
            $query->where('is_branded', false);
        }
        if ($pagesFromQueries) {
            $query->where('is_branded', false);
        }

        $this->applyMatch($query, 'page', $filters['page'] ?? null, 'exact');
        $this->applyMatch($query, 'query', $filters['query'] ?? null, 'contains');

        $groupBy = match ($dimension) {
            'page' => ['page'],
            'query' => ['query'],
            default => ['page', 'query'],
        };

        $select = implode(', ', $groupBy);
        $query->selectRaw("{$select}, SUM(clicks) AS clicks, SUM(impressions) AS impressions, SUM(position * impressions) AS weighted")
            ->groupBy($groupBy)
            ->orderByRaw('SUM(impressions) DESC')
            ->limit(self::MAX_GROUPS + 1);

        $minImpressions = (int) ($filters['min_impressions'] ?? 0);
        if ($minImpressions > 0) {
            $query->havingRaw('SUM(impressions) >= ?', [$minImpressions]);
        }

        $found = $query->get();
        $cutOff = $found->count() > self::MAX_GROUPS;

        $rows = [];
        foreach ($found->take(self::MAX_GROUPS) as $row) {
            $page = in_array('page', $groupBy, true) ? (string) $row->page : null;
            $term = in_array('query', $groupBy, true) ? (string) $row->query : null;
            $clicks = (int) $row->clicks;
            $impressions = (int) $row->impressions;

            $rows[$page."\n".$term] = [
                'page' => $page,
                'query' => $term,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : null,
                'position' => $impressions > 0 ? round((float) $row->weighted / $impressions, 1) : null,
            ];
        }

        return ['rows' => $rows, 'cut_off' => $cutOff];
    }

    /**
     * The month's top rows by clicks, each with last month's clicks.
     *
     * @param  'page'|'query'  $dimension
     * @return list<array{page?: string, query?: string, clicks: int, impressions: int, position: ?float, prev_clicks: ?int, change: ?int}>
     */
    public function top(int $siteId, string $dimension, CarbonImmutable $month, int $limit): array
    {
        $current = $this->aggregate($siteId, $dimension, [$month])['rows'];
        if ($dimension === 'query') {
            $current = array_filter($current, fn (array $r) => ! QueryFilters::isJunk((string) $r['query']));
        }

        uasort($current, fn (array $a, array $b) => [$b['clicks'], $b['impressions']] <=> [$a['clicks'], $a['impressions']]);
        $current = array_slice($current, 0, $limit, true);

        $previousMonth = $month->subMonthNoOverflow();
        $previousSynced = $this->monthSynced($siteId, $previousMonth);
        $previous = $previousSynced ? $this->aggregate($siteId, $dimension, [$previousMonth])['rows'] : [];

        $out = [];
        foreach ($current as $key => $row) {
            // Synced but absent last month is a real zero; not synced is unknown.
            $prev = $previousSynced ? (int) ($previous[$key]['clicks'] ?? 0) : null;

            $out[] = [
                $dimension => $row[$dimension],
                'clicks' => $row['clicks'],
                'impressions' => $row['impressions'],
                'position' => $row['position'],
                'prev_clicks' => $prev,
                'change' => $prev === null ? null : $row['clicks'] - $prev,
            ];
        }

        return $out;
    }

    public function monthSynced(int $siteId, CarbonImmutable $month): bool
    {
        return DB::table('seo_gsc_months')
            ->where('site_id', $siteId)
            ->where('month', $month->startOfMonth()->toDateString())
            ->exists();
    }

    /**
     * @param  ?array{value?: mixed, match?: mixed}  $filter
     */
    private function applyMatch(Builder $query, string $column, ?array $filter, string $defaultMatch): void
    {
        $value = trim((string) ($filter['value'] ?? ''));
        if ($value === '') {
            return;
        }

        $match = (string) ($filter['match'] ?? $defaultMatch);
        if ($match === 'exact') {
            $query->where($column, $value);

            return;
        }

        // '!' as the escape character: MySQL and SQLite read a backslash in
        // a string literal differently.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($value));
        $pattern = $match === 'prefix' ? $escaped.'%' : '%'.$escaped.'%';

        $query->whereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
    }
}
