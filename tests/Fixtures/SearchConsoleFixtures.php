<?php

namespace Tests\Fixtures;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Seo\Google\AnalyticsDataClient;
use Modules\Seo\Google\GoogleOAuth;
use Modules\Seo\Google\SearchConsoleClient;

/**
 * Canned Search Console responses, routed by what the request asks for, so a
 * whole sync can run against Http::fake().
 */
final class SearchConsoleFixtures
{
    /** @return array<string, mixed> */
    public static function sites(string ...$siteUrls): array
    {
        return ['siteEntry' => array_map(fn ($u) => ['siteUrl' => $u, 'permissionLevel' => 'siteFullUser'], $siteUrls)];
    }

    /** @return array<string, mixed> */
    public static function row(array $keys, int $clicks, int $impressions, float $position = 5.0): array
    {
        return [
            'keys' => $keys,
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? $clicks / $impressions : 0,
            'position' => $position,
        ];
    }

    /**
     * A GSC searchAnalytics answer for $body: one row per day for date
     * queries, a few rows for page/query groupings. $scale lets a test change
     * the numbers between two runs.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function answer(array $body, int $scale = 1): array
    {
        $dims = (array) ($body['dimensions'] ?? []);
        $nonbranded = isset($body['dimensionFilterGroups']);

        if ($dims === ['date']) {
            $rows = [];
            for ($d = strtotime($body['startDate']); $d <= strtotime($body['endDate']); $d += 86400) {
                $rows[] = self::row([date('Y-m-d', $d)], ($nonbranded ? 2 : 3) * $scale, ($nonbranded ? 20 : 30) * $scale);
            }

            return ['rows' => $rows];
        }

        if ((int) ($body['startRow'] ?? 0) > 0) {
            return ['rows' => []];
        }

        return ['rows' => match ($dims) {
            ['page'] => [
                self::row(['https://www.acme.com/'], 40 * $scale, 400 * $scale, 3.2),
                self::row(['https://www.acme.com/roofing/'], 10 * $scale, 300 * $scale, 8.1),
            ],
            ['query'] => [
                self::row(['acme roofing'], 30 * $scale, 200 * $scale, 1.4),
                self::row(['roof repair phoenix'], 8 * $scale, 250 * $scale, 9.0),
                self::row(['site:acme.com'], 1, 2, 1.0),
            ],
            ['page', 'query'] => [
                self::row(['https://www.acme.com/', 'acme roofing'], 30 * $scale, 200 * $scale, 1.4),
                self::row(['https://www.acme.com/roofing/', 'roof repair phoenix'], 8 * $scale, 250 * $scale, 9.0),
            ],
            default => [],
        }];
    }

    /** @return array<string, mixed> */
    public static function ga4Answer(array $body, int $scale = 1): array
    {
        $reports = [];
        foreach ((array) ($body['requests'] ?? []) as $request) {
            $dims = array_column((array) ($request['dimensions'] ?? []), 'name');
            $reports[] = GoogleAnalyticsFixtures::reportFor($request, $dims, $scale);
        }

        return ['reports' => $reports];
    }

    /**
     * Fake every Google endpoint a sync touches. $gscOverride and $ga4Override
     * may return a response to use instead (e.g. a 429 for one site). Pass a
     * closure as $scale to change the numbers between two runs: Http::fake()
     * keeps the first matching stub, so faking twice doesn't replace it.
     */
    public static function fakeGoogle(int|\Closure $scale = 1, ?callable $gscOverride = null, ?callable $ga4Override = null): void
    {
        Http::fake(function (Request $request) use ($scale, $gscOverride, $ga4Override) {
            $url = $request->url();
            $scale = $scale instanceof \Closure ? (int) $scale() : $scale;

            if ($url === GoogleOAuth::TOKEN_URL) {
                return Http::response(GoogleOAuthFixtures::refresh());
            }
            if ($url === SearchConsoleClient::BASE_URL.'/sites') {
                return Http::response(self::sites('https://www.acme.com/', 'https://www.beta.com/'));
            }
            if (str_starts_with($url, SearchConsoleClient::BASE_URL.'/sites/')) {
                if ($gscOverride && ($override = $gscOverride($request)) !== null) {
                    return $override;
                }

                return Http::response(self::answer($request->data(), $scale));
            }
            if (str_starts_with($url, AnalyticsDataClient::BASE_URL.'/properties/')) {
                if ($ga4Override && ($override = $ga4Override($request)) !== null) {
                    return $override;
                }

                return Http::response(self::ga4Answer($request->data(), $scale));
            }

            return Http::response(['error' => 'unexpected '.$url], 500);
        });
    }
}
