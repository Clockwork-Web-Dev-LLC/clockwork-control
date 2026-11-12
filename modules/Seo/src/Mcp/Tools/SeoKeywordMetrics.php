<?php

namespace Modules\Seo\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Seo\Keywords\KeywordMetricsService;
use Modules\Seo\Keywords\Market;
use Modules\Seo\Mcp\Support\SiteFinder;

#[Name('seo_keyword_metrics')]
#[Title('Keyword metrics (and a quote for missing ones)')]
#[Description('Search volume, difficulty (0-100), CPC and intent for up to 700 keywords, from what Control has already bought. It never spends money. For keywords Control does not have, or has but older than max_age_days, it returns a quote: show the person the number of keywords and the cost, and only if they agree call seo_keyword_metrics_fetch with the quote_id. A null volume means "no figure", not zero. Difficulty is DataForSEO\'s scale and is not comparable to Semrush KD %.')]
#[IsReadOnly]
#[IsIdempotent]
class SeoKeywordMetrics extends SeoTool
{
    public const NAME = 'seo_keyword_metrics';

    public const MAX_KEYWORDS = 700;

    public function schema(JsonSchema $schema): array
    {
        return [
            'keywords' => $schema->array()->min(1)->max(self::MAX_KEYWORDS)->items($schema->string())->description('Up to 700 keywords.')->required(),
            'site' => $schema->string()->description('A Control site id or domain. Uses that site\'s market (country, language, city) unless market is given.'),
            'market' => $schema->object([
                'location_code' => $schema->integer()->description('DataForSEO location code. 2840 is the United States.'),
                'language_code' => $schema->string()->description('e.g. en'),
                'city' => $schema->string()->description('For local volume, e.g. "Austin,Texas,United States". Costs extra.'),
            ])->description('Overrides the site\'s market. Default: United States, English, no city.'),
            'max_age_days' => $schema->integer()->min(1)->max(90)->description('Treat stored figures older than this as stale. Default 30.'),
        ];
    }

    public function handle(Request $request, KeywordMetricsService $metrics): Response|ResponseFactory
    {
        $user = $this->caller($request);
        if ($user === null) {
            return Response::error(self::NO_USER);
        }

        $keywords = $request->get('keywords');
        if (! is_array($keywords) || $keywords === [] || count($keywords) > self::MAX_KEYWORDS || array_filter($keywords, fn ($k) => ! is_string($k)) !== []) {
            return Response::error('keywords must be a list of 1 to '.self::MAX_KEYWORDS.' text values.');
        }

        $market = $this->market($request);
        if (is_string($market)) {
            return Response::error($market);
        }

        $maxAge = max(1, min(90, (int) ($request->get('max_age_days') ?? KeywordMetricsService::DEFAULT_MAX_AGE_DAYS)));
        $cleaned = $metrics->clean($keywords);
        $lookup = $metrics->lookup($cleaned['valid'], $market, $maxAge);

        $rows = [];
        foreach ($lookup['found'] as $keyword => $row) {
            $rows[] = [
                $keyword,
                $row->volume,
                $market->city !== null ? ((array) $row->local_volumes)[$market->city] ?? null : null,
                $row->cpc,
                $row->difficulty,
                KeywordMetricsService::difficultyBand($row->difficulty),
                $row->intent,
                $row->no_data ? 'no_data' : (in_array($keyword, $lookup['stale'], true) ? 'stale' : 'fresh'),
                $row->fetched_at?->toDateString(),
            ];
        }

        $toBuy = array_values(array_unique([...$lookup['missing'], ...$lookup['stale']]));
        $quote = $toBuy === [] ? null : $metrics->quote($toBuy, $market, $user);

        return Response::structured([
            'summary' => count($lookup['found']).' of '.count($cleaned['valid']).' keywords are stored'
                .($toBuy === [] ? '. Nothing to buy.' : sprintf('. %d to buy for about $%.2f: ask the person before calling seo_keyword_metrics_fetch.', count($toBuy), $quote['estimate_usd']))
                .($cleaned['rejected'] !== [] ? ' '.count($cleaned['rejected']).' rejected.' : ''),
            'market' => $market->toArray(),
            'columns' => ['keyword', 'volume', 'city_volume', 'cpc_usd', 'difficulty', 'difficulty_band', 'intent', 'state', 'fetched_on'],
            'rows' => $rows,
            'missing' => $lookup['missing'],
            'stale' => $lookup['stale'],
            'rejected' => $cleaned['rejected'],
            'quote' => $quote,
            'notes' => [
                'Difficulty is DataForSEO\'s 0 to 100 score with default bands that are not calibrated. Do not call it KD % or compare it with a difficulty score from another tool.',
                'Volume is country-level. city_volume is only filled when a city was asked for and bought.',
                'state no_data means DataForSEO had no figures for the keyword. It is not zero searches.',
            ],
        ]);
    }

    private function market(Request $request): Market|string
    {
        $base = new Market;

        if (filled($request->get('site'))) {
            $profile = SiteFinder::profile($request->get('site'));
            if (is_string($profile)) {
                return $profile;
            }
            $base = Market::forProfile($profile);
        }

        $override = $request->get('market');
        if ($override === null) {
            return $base;
        }
        if (! is_array($override)) {
            return 'market must be an object with location_code, language_code and optionally city.';
        }

        $location = $override['location_code'] ?? $base->locationCode;
        $language = $override['language_code'] ?? $base->languageCode;
        $city = $override['city'] ?? $base->city;

        if (! is_int($location) || $location < 1 || ! is_string($language) || preg_match('/^[a-z]{2}(-[A-Za-z]{2,4})?$/', $language) !== 1) {
            return 'market needs a numeric location_code (2840 is the United States) and a language_code such as en.';
        }
        if ($city !== null && (! is_string($city) || mb_strlen($city) > 191)) {
            return 'market.city must be a place name such as "Austin,Texas,United States".';
        }

        return new Market($location, $language, $city === '' ? null : $city);
    }
}
