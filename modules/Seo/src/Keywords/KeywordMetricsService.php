<?php

namespace Modules\Seo\Keywords;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Seo\DataForSeo\BudgetGuard;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\DataForSeo\Feature;
use Modules\Seo\DataForSeo\Pricing;
use Modules\Seo\Models\KeywordMetric;

/**
 * Volume, difficulty, CPC and intent for keywords, bought from DataForSEO
 * and kept for 30 days so nothing is bought twice.
 *
 * Buying is two steps. quote() says what the missing keywords would cost
 * and spends nothing. fetch() needs that quote back, with the cost a person
 * agreed to. A quote works once, for the person it was made for, for 15
 * minutes.
 */
class KeywordMetricsService
{
    public const PROVIDER = DataForSeoClient::PROVIDER;

    public const DEFAULT_MAX_AGE_DAYS = 30;

    public const QUOTE_MINUTES = 15;

    /** DataForSEO's own limits for one keyword. */
    public const MAX_KEYWORD_LENGTH = 80;

    public const MAX_KEYWORD_WORDS = 10;

    public function __construct(
        private readonly DataForSeoClient $client,
        private readonly BudgetGuard $budget,
    ) {}

    /** How a keyword is stored and compared: lower case, single spaces. */
    public static function normalize(string $keyword): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($keyword)));
    }

    /**
     * Split a list into keywords we can look up and ones DataForSEO would
     * reject, so a bad one never costs a whole call.
     *
     * @param  list<string>  $keywords
     * @return array{valid: list<string>, rejected: list<array{keyword: string, reason: string}>}
     */
    public function clean(array $keywords): array
    {
        $valid = [];
        $rejected = [];

        foreach ($keywords as $raw) {
            $keyword = self::normalize((string) $raw);
            if ($keyword === '' || isset($valid[$keyword])) {
                continue;
            }

            $reason = match (true) {
                mb_strlen($keyword) > self::MAX_KEYWORD_LENGTH => 'longer than '.self::MAX_KEYWORD_LENGTH.' characters',
                count(explode(' ', $keyword)) > self::MAX_KEYWORD_WORDS => 'more than '.self::MAX_KEYWORD_WORDS.' words',
                // Search operators and symbols Google Ads data doesn't accept.
                preg_match('/[,!@%^*()={};~`<>?\\\\|"]|\b(site|inurl|intitle):/u', $keyword) === 1 => 'contains a symbol or search operator',
                default => null,
            };

            if ($reason !== null) {
                $rejected[] = ['keyword' => mb_substr($keyword, 0, 100), 'reason' => $reason];
            } else {
                $valid[$keyword] = $keyword;
            }
        }

        return ['valid' => array_values($valid), 'rejected' => $rejected];
    }

    /**
     * What is already stored. Never calls DataForSEO.
     *
     * @param  list<string>  $keywords  already cleaned
     * @return array{found: array<string, KeywordMetric>, missing: list<string>, stale: list<string>}
     */
    public function lookup(array $keywords, Market $market, int $maxAgeDays = self::DEFAULT_MAX_AGE_DAYS): array
    {
        $rows = KeywordMetric::query()
            ->where('market_key', $market->key())
            ->where('provider', self::PROVIDER)
            ->whereIn('keyword', $keywords)
            ->get()
            ->keyBy('keyword');

        $cutoff = Carbon::now()->subDays(max(1, $maxAgeDays));
        $found = [];
        $missing = [];
        $stale = [];

        foreach ($keywords as $keyword) {
            $row = $rows->get($keyword);
            if ($row === null) {
                $missing[] = $keyword;

                continue;
            }

            $found[$keyword] = $row;
            if ($row->fetched_at === null || $row->fetched_at->lt($cutoff)) {
                $stale[] = $keyword;
            } elseif ($market->city !== null && ! $row->no_data && ! array_key_exists($market->city, (array) $row->local_volumes)) {
                // Country figures are fresh, but this city was never bought.
                $stale[] = $keyword;
            }
        }

        return ['found' => $found, 'missing' => $missing, 'stale' => $stale];
    }

    /**
     * Price a purchase without making it.
     *
     * @param  list<string>  $keywords  the ones to buy (missing and stale)
     * @return array<string, mixed>
     */
    public function quote(array $keywords, Market $market, User $user): array
    {
        $overview = Pricing::keywordOverview(count($keywords));
        $city = $market->city !== null ? Pricing::cityVolume(count($keywords)) : ['calls' => 0, 'usd' => 0.0];
        $estimate = round($overview['usd'] + $city['usd'], 4);

        $id = (string) Str::uuid();
        $expires = Carbon::now()->addMinutes(self::QUOTE_MINUTES);

        Cache::put($this->quoteKey($id), [
            'user_id' => $user->id,
            'keywords' => $keywords,
            'market' => $market->toArray(),
            'estimate_usd' => $estimate,
        ], $expires);

        return [
            'quote_id' => $id,
            'keywords_to_buy' => count($keywords),
            'calls' => $overview['calls'] + $city['calls'],
            'estimate_usd' => $estimate,
            'includes_city_volume' => $market->city,
            'expires_at' => $expires->toIso8601String(),
            'spent_this_month_usd' => round($this->budget->spentThisMonth(Feature::KEYWORDS), 4),
            'monthly_cap_usd' => $this->budget->featureCap(Feature::KEYWORDS),
            // Said now, so nobody approves a purchase that will be refused.
            'would_be_refused' => $this->budget->refusal(Feature::KEYWORDS, $estimate, $user),
            'estimate_note' => 'An estimate from published prices. The real cost is recorded after the purchase.',
        ];
    }

    /**
     * Take a quote back out of the cache. It works once.
     *
     * @return array{user_id: int, keywords: list<string>, market: array{location_code: int, language_code: string, city: ?string}, estimate_usd: float}|null
     */
    public function redeemQuote(string $quoteId): ?array
    {
        $quote = Cache::pull($this->quoteKey($quoteId));

        return is_array($quote) ? $quote : null;
    }

    /** Look at a quote without using it up. */
    public function peekQuote(string $quoteId): ?array
    {
        $quote = Cache::get($this->quoteKey($quoteId));

        return is_array($quote) ? $quote : null;
    }

    /**
     * Buy and store. Called by the queued job.
     *
     * @param  list<string>  $keywords  cleaned
     * @return array{stored: int, no_data: int, cost: float, error: ?string}
     */
    public function fetch(array $keywords, Market $market, ?User $user = null, ?int $siteId = null): array
    {
        $summary = ['stored' => 0, 'no_data' => 0, 'cost' => 0.0, 'error' => null];

        foreach (array_chunk($keywords, Pricing::KEYWORD_OVERVIEW_MAX) as $chunk) {
            $result = $this->client->post(
                '/v3/dataforseo_labs/google/keyword_overview/live',
                [['keywords' => $chunk, 'location_code' => $market->locationCode, 'language_code' => $market->languageCode]],
                Feature::KEYWORDS,
                Pricing::keywordOverview(count($chunk))['usd'],
                $siteId,
                $user,
            );

            if (! $result->ok || $result->okTasks() === []) {
                $summary['error'] = $result->error ?? (string) ($result->tasks[0]['status_message'] ?? 'DataForSEO returned no result.');

                return $summary;
            }

            $summary['cost'] += $result->cost;
            $items = [];
            foreach ((array) ($result->firstResult()[0]['items'] ?? []) as $item) {
                if (is_array($item) && isset($item['keyword'])) {
                    $items[self::normalize((string) $item['keyword'])] = $item;
                }
            }

            foreach ($chunk as $keyword) {
                $item = $items[$keyword] ?? null;
                $this->store($keyword, $market, $item);
                $item === null ? $summary['no_data']++ : $summary['stored']++;
            }
        }

        if ($market->city !== null) {
            $summary['error'] = $this->fetchCityVolume($keywords, $market, $user, $siteId, $summary['cost']);
        }

        $summary['cost'] = round($summary['cost'], 6);

        return $summary;
    }

    /**
     * The difficulty band for a 0 to 100 score. Fixed defaults, not
     * calibrated against any other tool's scale.
     */
    public static function difficultyBand(?int $difficulty): ?string
    {
        if ($difficulty === null) {
            return null;
        }

        foreach ((array) config('clockwork.seo.difficulty_bands', []) as $label => $upTo) {
            if ($difficulty <= (int) $upTo) {
                return (string) $label;
            }
        }

        return 'Very hard';
    }

    /**
     * @param  ?array<string, mixed>  $item  one keyword_overview item, or null when DataForSEO had nothing
     */
    private function store(string $keyword, Market $market, ?array $item): void
    {
        $info = (array) ($item['keyword_info'] ?? []);
        $intent = (array) ($item['search_intent_info'] ?? []);
        $difficulty = $item['keyword_properties']['keyword_difficulty'] ?? null;

        $existing = KeywordMetric::query()
            ->where('keyword', $keyword)->where('market_key', $market->key())->where('provider', self::PROVIDER)
            ->first();

        KeywordMetric::query()->updateOrCreate(
            ['keyword' => $keyword, 'market_key' => $market->key(), 'provider' => self::PROVIDER],
            [
                // A missing figure stays null. It is never stored as 0.
                'volume' => is_numeric($info['search_volume'] ?? null) ? (int) $info['search_volume'] : null,
                'monthly' => is_array($info['monthly_searches'] ?? null) ? array_slice($info['monthly_searches'], 0, 12) : null,
                'cpc' => is_numeric($info['cpc'] ?? null) ? round((float) $info['cpc'], 2) : null,
                'paid_competition' => is_numeric($info['competition'] ?? null) ? round((float) $info['competition'], 4) : null,
                'difficulty' => is_numeric($difficulty) ? max(0, min(100, (int) $difficulty)) : null,
                'intent' => is_string($intent['main_intent'] ?? null) ? mb_substr($intent['main_intent'], 0, 20) : null,
                'secondary_intents' => is_array($intent['foreign_intent'] ?? null) ? array_values($intent['foreign_intent']) : null,
                'local_volumes' => $existing?->local_volumes,
                'no_data' => $item === null,
                'fetched_at' => now(),
            ],
        );
    }

    /**
     * City-level volume comes from a different, dearer endpoint. It only
     * has volume: difficulty and intent stay country-level.
     *
     * @param  list<string>  $keywords
     * @return ?string an error, or null
     */
    private function fetchCityVolume(array $keywords, Market $market, ?User $user, ?int $siteId, float &$cost): ?string
    {
        foreach (array_chunk($keywords, Pricing::ADS_VOLUME_MAX) as $chunk) {
            $result = $this->client->post(
                '/v3/keywords_data/google_ads/search_volume/live',
                [['keywords' => $chunk, 'location_name' => $market->city, 'language_code' => $market->languageCode]],
                Feature::KEYWORDS,
                Pricing::cityVolume(count($chunk))['usd'],
                $siteId,
                $user,
            );

            if (! $result->ok || $result->okTasks() === []) {
                return 'City volume: '.($result->error ?? (string) ($result->tasks[0]['status_message'] ?? 'DataForSEO returned no result.'));
            }

            $cost += $result->cost;
            $volumes = [];
            foreach ($result->firstResult() as $row) {
                if (is_array($row) && isset($row['keyword'])) {
                    $volumes[self::normalize((string) $row['keyword'])] = is_numeric($row['search_volume'] ?? null) ? (int) $row['search_volume'] : null;
                }
            }

            foreach ($chunk as $keyword) {
                $row = KeywordMetric::query()
                    ->where('keyword', $keyword)->where('market_key', $market->key())->where('provider', self::PROVIDER)
                    ->first();
                if ($row === null) {
                    continue;
                }

                $local = (array) $row->local_volumes;
                // Asked and nothing came back: recorded as null for this
                // city, so it is not bought again and not read as 0.
                $local[$market->city] = $volumes[$keyword] ?? null;
                $row->forceFill(['local_volumes' => $local])->save();
            }
        }

        return null;
    }

    private function quoteKey(string $id): string
    {
        return 'seo.keyword_quote.'.$id;
    }
}
