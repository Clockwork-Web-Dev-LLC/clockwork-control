<?php

namespace Modules\Seo\Google;

use Illuminate\Support\Sleep;
use Modules\Seo\Models\GoogleConnection;

/**
 * Search Console API v3: sites.list and searchAnalytics.query.
 *
 * siteUrl is the exact URL-prefix property (https://www.example.com/) or
 * sc-domain:example.com, URL-encoded into the path. Access is per property:
 * a URL-prefix user can't read the sc-domain property, and the reverse.
 */
class SearchConsoleClient
{
    public const BASE_URL = 'https://searchconsole.googleapis.com/webmasters/v3';

    public const PAGE_SIZE = 25000;

    /** GSC returns at most 50,000 rows per day per search type; past this a month may be cut short. */
    public const TRUNCATION_ROWS = 50000;

    /** Hard stop on paging, well past anything a small site produces. */
    private const MAX_PAGES = 8;

    public function __construct(private readonly GoogleApi $api) {}

    public function listSites(GoogleConnection $connection): GoogleApiResult
    {
        return $this->api->get($connection, self::BASE_URL.'/sites');
    }

    /** @param array<string, mixed> $body */
    public function query(GoogleConnection $connection, string $siteUrl, array $body): GoogleApiResult
    {
        return $this->api->post($connection, self::BASE_URL.'/sites/'.rawurlencode($siteUrl).'/searchAnalytics/query', $body);
    }

    /**
     * Page through a grouped query (page, query or both) 25,000 rows at a
     * time, at most one call a second. Stops on the first short page.
     *
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, rows: list<array<string, mixed>>, truncated: bool, result: ?GoogleApiResult}
     */
    public function queryAll(GoogleConnection $connection, string $siteUrl, array $body): array
    {
        $rows = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            if ($page > 0) {
                Sleep::sleep(1);
            }

            $result = $this->query($connection, $siteUrl, $body + [
                'rowLimit' => self::PAGE_SIZE,
                'startRow' => $page * self::PAGE_SIZE,
            ]);

            if (! $result->ok) {
                return ['ok' => false, 'rows' => $rows, 'truncated' => false, 'result' => $result];
            }

            $batch = array_values((array) ($result->json['rows'] ?? []));
            array_push($rows, ...$batch);

            if (count($batch) < self::PAGE_SIZE) {
                break;
            }
        }

        return ['ok' => true, 'rows' => $rows, 'truncated' => count($rows) >= self::TRUNCATION_ROWS, 'result' => null];
    }

    /**
     * An excludingRegex filter on query, for the non-branded totals.
     *
     * @return array<string, mixed>
     */
    public static function excludingQueries(string $regex): array
    {
        return [
            'dimensionFilterGroups' => [[
                'filters' => [[
                    'dimension' => 'query',
                    'operator' => 'excludingRegex',
                    'expression' => $regex,
                ]],
            ]],
        ];
    }
}
