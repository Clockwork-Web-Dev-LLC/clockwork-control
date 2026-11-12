<?php

namespace Modules\Seo\Maps;

use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Seo\Keywords\KeywordMetricsService;
use Modules\Seo\Keywords\Market;
use Modules\Seo\Models\KeywordMap;
use Modules\Seo\Models\KeywordMapRow;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Services\SearchRows;
use Modules\Seo\Support\GscDates;

/**
 * The rules of a keyword map, used by the pages and by Claude's tools alike.
 *
 * - One map per site is current. Starting a new dated version copies it and
 *   archives the old one, which is then read-only.
 * - Rows are hidden, never deleted.
 * - An edit names the version of the row it was based on, so two people (or
 *   a person and Claude) can't silently overwrite each other.
 */
class KeywordMapService
{
    public const TITLE_RANGE = [50, 60];

    public const META_RANGE = [140, 160];

    /** Fields an edit may set. Metrics are filled from bought data, not typed. */
    public const EDITABLE = [
        'page_name', 'url', 'is_new_page', 'primary_keyword', 'secondary_keywords', 'intent', 'proposed_title',
        'proposed_meta', 'schema_types', 'ship_status', 'shipped_on', 'ship_note', 'notes', 'excluded', 'sort',
    ];

    public function __construct(
        private readonly KeywordMetricsService $metrics,
        private readonly SearchRows $searchRows,
    ) {}

    public function current(int $siteId): ?KeywordMap
    {
        return KeywordMap::query()->where('site_id', $siteId)->where('status', KeywordMap::STATUS_CURRENT)->latest('id')->first();
    }

    /**
     * Import a CSV as the site's new current map.
     *
     * @return array{map: KeywordMap, rows: int, needs_review: int, unknown_columns: list<string>, skipped: int}
     */
    public function importCsv(Site $site, string $csv, ?string $name = null, ?int $userId = null): array
    {
        $parsed = KeywordMapCsv::parse($csv);
        if ($parsed['rows'] === []) {
            throw new InvalidArgumentException('The file has a header row but no pages under it.');
        }

        $map = DB::transaction(function () use ($site, $parsed, $name, $userId) {
            $map = $this->startMap($site, $name, 'csv_import', null, $userId);
            foreach ($parsed['rows'] as $row) {
                // An imported file is taken as it is; warnings() points
                // out what to fix, such as em dashes.
                $map->rows()->create($row);
            }

            return $map;
        });

        return [
            'map' => $map,
            'rows' => count($parsed['rows']),
            'needs_review' => count(array_filter($parsed['rows'], fn (array $r) => $r['needs_review'])),
            'unknown_columns' => $parsed['unknown_columns'],
            'skipped' => $parsed['skipped'],
        ];
    }

    /** A new dated version: a copy of the current map (or an empty one), which becomes current. */
    public function newVersion(Site $site, ?string $name = null, ?int $userId = null): KeywordMap
    {
        return DB::transaction(function () use ($site, $name, $userId) {
            $previous = $this->current($site->id);
            $map = $this->startMap($site, $name, $previous === null ? 'manual' : 'copy', $previous?->id, $userId);

            foreach ($previous?->rows ?? [] as $row) {
                $map->rows()->create(collect($row->getAttributes())->except(['id', 'map_id', 'created_at', 'updated_at'])->all());
            }

            return $map;
        });
    }

    /**
     * Add a row to the current map.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addRow(KeywordMap $map, array $attributes): KeywordMapRow
    {
        $this->assertEditable($map);
        $clean = $this->clean($attributes);
        $clean['sort'] ??= ((int) $map->rows()->max('sort')) + 1;

        return $map->rows()->create($clean);
    }

    /**
     * Change a row. $expectedUpdatedAt is the row's updated_at as the editor
     * last saw it; a mismatch means someone else changed it since.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateRow(KeywordMapRow $row, array $attributes, ?string $expectedUpdatedAt): KeywordMapRow
    {
        $this->assertEditable($row->map);

        if ($expectedUpdatedAt !== null && $row->updated_at !== null
            && CarbonImmutable::parse($expectedUpdatedAt)->getTimestamp() !== $row->updated_at->getTimestamp()) {
            throw new StaleMapRowException('This row was changed by someone else since you read it. Read the map again and reapply the change.');
        }

        $clean = $this->clean($attributes);

        // A different primary keyword makes the old figures wrong.
        if (array_key_exists('primary_keyword', $clean) && $clean['primary_keyword'] !== $row->primary_keyword) {
            $clean += ['volume' => null, 'difficulty' => null, 'cpc' => null, 'metrics_provider' => null, 'metrics_fetched_at' => null];
        }
        if (($clean['ship_status'] ?? $row->ship_status) === 'live' && ! array_key_exists('shipped_on', $clean) && $row->shipped_on === null) {
            $clean['shipped_on'] = now()->toDateString();
        }

        $row->fill($clean);
        // Touch even when nothing differs, so the caller's next edit has a
        // fresh version to name.
        $row->updated_at = now();
        $row->save();

        return $row;
    }

    /**
     * Fill volume, difficulty, CPC and intent from keyword data Control has
     * already bought. Never spends. Returns the keywords with nothing stored.
     *
     * @return array{filled: int, missing: list<string>}
     */
    public function fillMetrics(KeywordMap $map): array
    {
        $this->assertEditable($map);

        $market = Market::forProfile(SiteProfile::query()->where('site_id', $map->site_id)->first());
        $rows = $map->rows->filter(fn (KeywordMapRow $r) => ! $r->excluded && filled($r->primary_keyword));
        $lookup = $this->metrics->lookup($rows->pluck('primary_keyword')->unique()->values()->all(), $market);
        $filled = 0;

        foreach ($rows as $row) {
            $metric = $lookup['found'][$row->primary_keyword] ?? null;
            if ($metric === null || in_array($row->primary_keyword, $lookup['stale'], true)) {
                continue;
            }

            $row->forceFill([
                'volume' => $metric->volume,
                'difficulty' => $metric->difficulty,
                'cpc' => $metric->cpc,
                'intent' => $row->intent ?? $metric->intent,
                'metrics_provider' => KeywordMetricsService::PROVIDER,
                'metrics_fetched_at' => $metric->fetched_at,
            ])->save();
            $filled++;
        }

        return ['filled' => $filled, 'missing' => array_values(array_unique([...$lookup['missing'], ...$lookup['stale']]))];
    }

    /**
     * What a person should look at before the map goes to a client.
     *
     * @return list<array{code: string, row_id: ?int, page: ?string, message: string}>
     */
    public function warnings(KeywordMap $map): array
    {
        $warnings = [];
        $rows = $map->rows->filter(fn (KeywordMapRow $r) => ! $r->excluded);

        foreach ($rows as $row) {
            $label = $row->page_name ?? $row->url ?? "Row {$row->sort}";
            $add = function (string $code, string $message) use (&$warnings, $row, $label): void {
                $warnings[] = ['code' => $code, 'row_id' => $row->id, 'page' => $label, 'message' => $message];
            };

            if (blank($row->primary_keyword)) {
                $add('no_primary_keyword', "{$label} has no primary keyword.");
            }
            foreach (['proposed_title' => ['Title', self::TITLE_RANGE], 'proposed_meta' => ['Meta description', self::META_RANGE]] as $field => [$name, $range]) {
                if (filled($row->{$field})) {
                    $length = mb_strlen((string) $row->{$field});
                    if ($length < $range[0] || $length > $range[1]) {
                        $add($field.'_length', "{$name} for {$label} is {$length} characters; aim for {$range[0]} to {$range[1]}.");
                    }
                }
            }
            foreach (['proposed_title', 'proposed_meta', 'notes'] as $field) {
                if (str_contains((string) $row->{$field}, '—')) {
                    $add('em_dash', "{$label}: the ".str_replace('_', ' ', $field).' contains an em dash. Rewrite it before it goes to the client.');
                }
            }
            if ($row->needs_review) {
                $add('needs_review', "{$label}: the import could not read its status (\"{$row->ship_note}\"). Check it.");
            }
            if ($row->metrics_provider === 'imported') {
                $add('imported_metrics', "{$label}: volume and difficulty were imported from another tool and are not comparable with DataForSEO figures.");
            }
        }

        // Two pages chasing the same keyword compete with each other.
        foreach ($rows->filter(fn (KeywordMapRow $r) => filled($r->primary_keyword))->groupBy('primary_keyword') as $keyword => $group) {
            if ($group->count() > 1) {
                $pages = $group->map(fn (KeywordMapRow $r) => $r->page_name ?? $r->url ?? "Row {$r->sort}")->implode(', ');
                $warnings[] = ['code' => 'duplicate_primary_keyword', 'row_id' => null, 'page' => null, 'message' => "\"{$keyword}\" is the primary keyword of {$group->count()} pages: {$pages}. Pick one page for it."];
            }
        }

        return $warnings;
    }

    /**
     * The page to work on next: the one not started with the most
     * non-branded Google impressions over the last three complete months.
     *
     * @return array{row_id: int, page: ?string, url: ?string, primary_keyword: ?string, impressions: int, months: list<string>, reason: string}|null
     */
    public function recommendedNext(KeywordMap $map): ?array
    {
        $candidates = $map->rows->filter(fn (KeywordMapRow $r) => ! $r->excluded && $r->ship_status === 'not_started' && ! $r->is_new_page && filled($r->url));
        if ($candidates->isEmpty()) {
            return null;
        }

        $last = GscDates::today()->startOfMonth()->subMonthNoOverflow();
        $months = [$last->subMonthsNoOverflow(2), $last->subMonthNoOverflow(), $last];
        $pages = $this->searchRows->aggregate($map->site_id, 'page', $months, true)['rows'];

        $impressions = [];
        foreach ($pages as $page) {
            $impressions[self::pathKey((string) $page['page'])] = ($impressions[self::pathKey((string) $page['page'])] ?? 0) + (int) $page['impressions'];
        }

        $best = null;
        foreach ($candidates as $row) {
            $count = $impressions[self::pathKey((string) $row->url)] ?? 0;
            if ($count > 0 && ($best === null || $count > $best['impressions'])) {
                $best = [
                    'row_id' => $row->id, 'page' => $row->page_name, 'url' => $row->url, 'primary_keyword' => $row->primary_keyword,
                    'impressions' => $count, 'months' => array_map(fn (CarbonImmutable $m) => $m->format('Y-m'), $months),
                ];
            }
        }

        if ($best === null) {
            return null;
        }

        return $best + ['reason' => sprintf(
            'Not started, and it had the most non-branded Google impressions of the pages not started: %s from %s to %s.',
            number_format($best['impressions']), $best['months'][0], $best['months'][2],
        )];
    }

    /** "https://www.acme.com/services/" and "/services" are the same page. */
    public static function pathKey(string $url): string
    {
        $path = parse_url(trim($url), PHP_URL_PATH);
        $path = is_string($path) ? $path : trim($url);

        return '/'.trim(mb_strtolower($path), '/');
    }

    private function startMap(Site $site, ?string $name, string $source, ?int $copiedFrom, ?int $userId): KeywordMap
    {
        KeywordMap::query()->where('site_id', $site->id)->where('status', KeywordMap::STATUS_CURRENT)->update(['status' => KeywordMap::STATUS_ARCHIVED]);

        return KeywordMap::create([
            'site_id' => $site->id,
            'name' => mb_substr(filled($name) ? trim((string) $name) : 'Keyword map '.now()->toDateString(), 0, 120),
            'status' => KeywordMap::STATUS_CURRENT,
            'source' => $source,
            'copied_from_id' => $copiedFrom,
            'created_by' => $userId,
        ]);
    }

    private function assertEditable(?KeywordMap $map): void
    {
        if ($map === null || ! $map->isCurrent()) {
            throw new InvalidArgumentException('Only the current map can be changed. Older versions are kept as they were.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function clean(array $attributes): array
    {
        $clean = array_intersect_key($attributes, array_flip(self::EDITABLE));
        $this->assertNoEmDash($clean);

        foreach (['page_name' => 191, 'ship_note' => 255, 'intent' => 30] as $field => $max) {
            if (isset($clean[$field])) {
                $clean[$field] = mb_substr(trim((string) $clean[$field]), 0, $max) ?: null;
            }
        }
        foreach (['proposed_title' => 500, 'proposed_meta' => 1000, 'notes' => 5000, 'url' => 2000] as $field => $max) {
            if (array_key_exists($field, $clean)) {
                $clean[$field] = $clean[$field] === null ? null : (mb_substr(trim((string) $clean[$field]), 0, $max) ?: null);
            }
        }
        if (array_key_exists('primary_keyword', $clean)) {
            $clean['primary_keyword'] = filled($clean['primary_keyword']) ? mb_substr(KeywordMetricsService::normalize((string) $clean['primary_keyword']), 0, 191) : null;
        }
        if (isset($clean['ship_status']) && ! array_key_exists($clean['ship_status'], KeywordMapRow::STATUSES)) {
            throw new InvalidArgumentException('Status must be one of: '.implode(', ', array_keys(KeywordMapRow::STATUSES)).'.');
        }
        if (array_key_exists('secondary_keywords', $clean)) {
            $secondary = [];
            foreach ((array) $clean['secondary_keywords'] as $item) {
                $keyword = KeywordMetricsService::normalize((string) (is_array($item) ? ($item['keyword'] ?? '') : $item));
                if ($keyword !== '') {
                    $volume = is_array($item) && isset($item['volume']) && is_numeric($item['volume']) ? max(0, (int) $item['volume']) : null;
                    $secondary[] = ['keyword' => mb_substr($keyword, 0, 191), 'volume' => $volume];
                }
            }
            $clean['secondary_keywords'] = $secondary === [] ? null : array_slice($secondary, 0, 50);
        }
        if (array_key_exists('schema_types', $clean)) {
            $types = array_values(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 80), (array) $clean['schema_types'])));
            $clean['schema_types'] = $types === [] ? null : array_slice($types, 0, 20);
        }
        if (isset($clean['shipped_on'])) {
            $clean['shipped_on'] = CarbonImmutable::parse((string) $clean['shipped_on'])->toDateString();
        }

        return $clean;
    }

    /**
     * Em dashes read as machine-written and clients notice. Refused in
     * anything a client will see.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertNoEmDash(array $attributes): void
    {
        foreach (['proposed_title', 'proposed_meta', 'notes'] as $field) {
            if (isset($attributes[$field]) && is_string($attributes[$field]) && str_contains($attributes[$field], '—')) {
                $name = str_replace('_', ' ', $field);

                throw new InvalidArgumentException("The {$name} contains an em dash (—). Rewrite it with a comma, a colon or two sentences.");
            }
        }
    }
}
