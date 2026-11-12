<?php

namespace Modules\Seo\Maps;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Seo\Keywords\KeywordMetricsService;
use Modules\Seo\Models\KeywordMap;
use Modules\Seo\Models\KeywordMapRow;
use Throwable;

/**
 * Keyword maps as CSV: one row per page, in a fixed column order. Reading
 * is forgiving about header names, so a sheet kept by hand usually imports
 * as it is.
 */
final class KeywordMapCsv
{
    /** The column order written, and the names read. */
    public const COLUMNS = [
        '#', 'Page', 'URL', 'Primary Keyword', 'Volume', 'Difficulty (0-100)', 'CPC', 'Secondary Keywords',
        'Intent', 'Proposed Title', 'Title Length', 'Proposed Meta Description', 'Meta Length', 'Schema', 'Status', 'Notes',
    ];

    /** Other names people give these columns. Lower case, punctuation stripped. */
    private const ALIASES = [
        'sort' => ['', 'no', 'number', 'order'],
        'page_name' => ['page', 'page name', 'name'],
        'url' => ['url', 'slug', 'page url', 'link'],
        'primary_keyword' => ['primary keyword', 'primary', 'keyword', 'target keyword', 'main keyword'],
        'volume' => ['volume', 'search volume', 'vol', 'monthly volume'],
        'difficulty' => ['difficulty 0100', 'difficulty', 'kd', 'kd ', 'keyword difficulty'],
        'cpc' => ['cpc', 'cost per click'],
        'secondary_keywords' => ['secondary keywords', 'secondary', 'supporting keywords'],
        'intent' => ['intent', 'search intent'],
        'proposed_title' => ['proposed title', 'title', 'new title', 'title tag'],
        'proposed_meta' => ['proposed meta description', 'proposed meta', 'meta description', 'meta', 'new meta description'],
        'schema_types' => ['schema', 'schema types', 'structured data'],
        'ship_status' => ['status', 'ship status', 'progress'],
        'notes' => ['notes', 'note', 'comments'],
    ];

    public const MAX_ROWS = 2000;

    /**
     * @return array{rows: list<array<string, mixed>>, unknown_columns: list<string>, skipped: int}
     */
    public static function parse(string $csv, ?int $year = null): array
    {
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = self::records($csv);

        // The header is the first line that names a keyword or URL column;
        // sheets often have a title line or two above it.
        $map = [];
        $unknown = [];
        $headerIndex = null;
        foreach (array_slice($lines, 0, 10, true) as $index => $cells) {
            [$candidate, $unknownCells] = self::header($cells);
            if (isset($candidate['primary_keyword']) || (isset($candidate['url']) && count($candidate) >= 3)) {
                $map = $candidate;
                $unknown = $unknownCells;
                $headerIndex = $index;
                break;
            }
        }
        if ($headerIndex === null) {
            throw new InvalidArgumentException('No header row found. The file needs a row with column names such as: '.implode(', ', array_slice(self::COLUMNS, 1, 3)).'.');
        }

        $year ??= (int) CarbonImmutable::now()->format('Y');
        $rows = [];
        $skipped = 0;

        foreach (array_slice($lines, $headerIndex + 1) as $cells) {
            $get = fn (string $field): ?string => isset($map[$field]) ? self::blank($cells[$map[$field]] ?? null) : null;

            $url = $get('url');
            $keyword = $get('primary_keyword');
            $page = $get('page_name');
            if ($url === null && $keyword === null && $page === null) {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                $skipped++;

                continue;
            }

            $isNew = false;
            if ($url !== null && preg_match('/\(\s*new\s*\)/i', $url) === 1) {
                $isNew = true;
                $url = self::blank((string) preg_replace('/\(\s*new\s*\)/i', '', $url));
            }

            [$status, $shippedOn, $shipNote, $statusUnclear] = self::status($get('ship_status'), $year);
            $hasMetrics = $get('volume') !== null || $get('difficulty') !== null || $get('cpc') !== null;

            $rows[] = [
                'sort' => count($rows) + 1,
                'page_name' => $page === null ? null : mb_substr($page, 0, 191),
                'url' => $url,
                'is_new_page' => $isNew,
                'primary_keyword' => $keyword === null ? null : mb_substr(KeywordMetricsService::normalize($keyword), 0, 191),
                'volume' => self::number($get('volume')),
                'difficulty' => ($d = self::number($get('difficulty'))) !== null ? min(100, $d) : null,
                'cpc' => self::money($get('cpc')),
                // Figures in a file came from somewhere else. They are kept,
                // marked, and never mixed up with DataForSEO's.
                'metrics_provider' => $hasMetrics ? 'imported' : null,
                'secondary_keywords' => self::secondary($get('secondary_keywords')),
                'intent' => ($i = $get('intent')) === null ? null : mb_substr(mb_strtolower($i), 0, 30),
                'proposed_title' => $get('proposed_title'),
                'proposed_meta' => $get('proposed_meta'),
                'schema_types' => self::list($get('schema_types')),
                'ship_status' => $status,
                'shipped_on' => $shippedOn,
                'ship_note' => $shipNote,
                'notes' => $get('notes'),
                'needs_review' => $statusUnclear,
            ];
        }

        return ['rows' => $rows, 'unknown_columns' => $unknown, 'skipped' => $skipped];
    }

    public static function export(KeywordMap $map, bool $includeExcluded = false): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::COLUMNS, ',', '"', '');

        foreach ($map->rows as $row) {
            if ($row->excluded && ! $includeExcluded) {
                continue;
            }

            $secondary = implode('; ', array_map(
                fn (array $k) => $k['keyword'].(isset($k['volume']) ? " ({$k['volume']}/mo)" : ''),
                $row->secondary_keywords ?? [],
            ));
            $status = KeywordMapRow::STATUSES[$row->ship_status] ?? $row->ship_status;
            if ($row->shipped_on !== null) {
                $status .= ' '.$row->shipped_on->toDateString();
            }
            if (filled($row->ship_note)) {
                $status .= " ({$row->ship_note})";
            }
            // Only DataForSEO's difficulty goes out with a band. An imported
            // figure is another tool's score and is left as a bare number.
            $difficulty = $row->difficulty === null ? '' : ($row->metrics_provider === 'dataforseo'
                ? $row->difficulty.' ('.KeywordMetricsService::difficultyBand($row->difficulty).')'
                : (string) $row->difficulty);

            fputcsv($out, array_map([self::class, 'safe'], [
                (string) $row->sort,
                (string) $row->page_name,
                $row->url.($row->is_new_page ? ' (NEW)' : ''),
                (string) $row->primary_keyword,
                $row->volume === null ? '' : (string) $row->volume,
                $difficulty,
                $row->cpc === null ? '' : number_format($row->cpc, 2, '.', ''),
                $secondary,
                (string) $row->intent,
                (string) $row->proposed_title,
                $row->proposed_title === null ? '' : (string) mb_strlen($row->proposed_title),
                (string) $row->proposed_meta,
                $row->proposed_meta === null ? '' : (string) mb_strlen($row->proposed_meta),
                implode(', ', $row->schema_types ?? []),
                $status,
                (string) $row->notes,
            ]), ',', '"', '');
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** A cell starting with = + - @ would run as a formula in a spreadsheet. */
    public static function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /** @return list<list<string>> */
    private static function records(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $lines = [];
        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $lines[] = array_map(fn ($c) => (string) $c, $cells);
        }
        fclose($handle);

        return $lines;
    }

    /**
     * @param  list<string>  $cells
     * @return array{0: array<string, int>, 1: list<string>}
     */
    private static function header(array $cells): array
    {
        $map = [];
        $unknown = [];

        foreach ($cells as $index => $cell) {
            $name = trim((string) preg_replace('/[^a-z0-9 ]+/', '', mb_strtolower(trim($cell))));
            if ($name === 'title length' || $name === 'meta length' || $name === 'len') {
                continue; // recomputed, never imported
            }

            $matched = false;
            foreach (self::ALIASES as $field => $aliases) {
                if (! isset($map[$field]) && in_array($name, $aliases, true) && ! ($field === 'sort' && $name === '' && trim($cell) !== '#')) {
                    $map[$field] = $index;
                    $matched = true;
                    break;
                }
            }
            if (! $matched && trim($cell) !== '') {
                $unknown[] = trim($cell);
            }
        }

        return [$map, $unknown];
    }

    /** @return array{0: string, 1: ?string, 2: ?string, 3: bool} status, shipped on, note, unclear */
    private static function status(?string $text, int $year): array
    {
        if ($text === null) {
            return ['not_started', null, null, false];
        }

        $lower = mb_strtolower($text);
        $status = match (true) {
            str_starts_with($lower, 'live'), str_starts_with($lower, 'shipped'), str_starts_with($lower, 'done'), str_starts_with($lower, 'published') => 'live',
            str_starts_with($lower, 'draft') => 'drafted',
            str_starts_with($lower, 'planned'), str_starts_with($lower, 'new page') => 'planned_new',
            str_starts_with($lower, 'not started'), str_starts_with($lower, 'todo'), str_starts_with($lower, 'to do') => 'not_started',
            default => null,
        };

        $shippedOn = null;
        try {
            if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $text, $m) === 1) {
                $shippedOn = CarbonImmutable::createFromFormat('!Y-m-d', $m[0])->toDateString();
            } elseif (preg_match('#\b(\d{1,2})/(\d{1,2})(?:/(\d{2,4}))?\b#', $text, $m) === 1 && checkdate((int) $m[1], (int) $m[2], 2024)) {
                $y = isset($m[3]) ? (int) (strlen($m[3]) === 2 ? '20'.$m[3] : $m[3]) : $year;
                $shippedOn = sprintf('%04d-%02d-%02d', $y, (int) $m[1], (int) $m[2]);
            }
        } catch (Throwable) {
            $shippedOn = null;
        }

        if ($status === null) {
            // Not a status we know: keep every word, and flag it.
            return ['not_started', null, mb_substr($text, 0, 255), true];
        }

        $note = preg_match('/\(([^)]*)\)/', $text, $m) === 1 ? self::blank($m[1]) : null;

        return [$status, $status === 'live' ? $shippedOn : null, $note === null ? null : mb_substr($note, 0, 255), false];
    }

    /** @return list<array{keyword: string, volume: ?int}>|null */
    private static function secondary(?string $text): ?array
    {
        if ($text === null) {
            return null;
        }

        $out = [];
        foreach (preg_split('/[;\n]+/', $text) ?: [] as $part) {
            $volume = null;
            if (preg_match('/\(\s*([\d,.]+\s*k?)\s*(?:\/\s*mo)?\s*\)\s*$/i', $part, $m) === 1) {
                $volume = self::number($m[1]);
                $part = substr($part, 0, -strlen($m[0]));
            }
            $keyword = KeywordMetricsService::normalize($part);
            if ($keyword !== '') {
                $out[] = ['keyword' => mb_substr($keyword, 0, 191), 'volume' => $volume];
            }
        }

        return $out === [] ? null : array_slice($out, 0, 50);
    }

    /** @return list<string>|null */
    private static function list(?string $text): ?array
    {
        if ($text === null) {
            return null;
        }
        $items = array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $text) ?: [])));

        return $items === [] ? null : array_slice($items, 0, 20);
    }

    /** "1,300", "1.3k" and "1300/mo" are 1300; "n/a" and "-" are unknown. */
    private static function number(?string $text): ?int
    {
        if ($text === null || preg_match('/^\s*([\d,]*\.?\d+)\s*(k)?/i', $text, $m) !== 1) {
            return null;
        }
        $value = (float) str_replace(',', '', $m[1]) * (isset($m[2]) ? 1000 : 1);

        return $value < 0 || $value > 4_000_000_000 ? null : (int) round($value);
    }

    private static function money(?string $text): ?float
    {
        if ($text === null || preg_match('/([\d,]*\.?\d+)/', $text, $m) !== 1) {
            return null;
        }

        return round(min(99_999_999, (float) str_replace(',', '', $m[1])), 2);
    }

    private static function blank(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }
}
