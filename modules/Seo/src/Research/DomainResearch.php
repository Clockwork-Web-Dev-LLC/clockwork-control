<?php

namespace Modules\Seo\Research;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\DataForSeo\Feature;
use Modules\Seo\DataForSeo\Pricing;
use Modules\Seo\Keywords\Market;
use Modules\Seo\Models\DomainSnapshot;
use Modules\Seo\Models\RankedKeyword;
use Modules\Seo\Ranks\SerpParser;
use Throwable;

/**
 * What a domain ranks for, from DataForSEO Labs: a monthly count of its
 * ranking keywords, and the list of those keywords.
 *
 * Labs reads its own stored copy of Google's results, refreshed every 30 to
 * 90 days per keyword, for a whole country. So these figures lag, are never
 * city-level, and are not comparable with another tool's count.
 */
class DomainResearch
{
    public const OVERVIEW_PATH = '/v3/dataforseo_labs/google/domain_rank_overview/live';

    public const RANKED_PATH = '/v3/dataforseo_labs/google/ranked_keywords/live';

    public const MAX_KEYWORDS = 1000;

    public function __construct(private readonly DataForSeoClient $client) {}

    public static function domain(string $value): string
    {
        return SerpParser::host($value);
    }

    public static function overviewCost(): float
    {
        return Pricing::labs(1);
    }

    public static function rankedCost(int $limit): float
    {
        return Pricing::labs(max(1, min(self::MAX_KEYWORDS, $limit)));
    }

    /**
     * Buy this month's ranking-keyword count for a domain.
     *
     * @return array{ok: bool, snapshot: ?DomainSnapshot, cost: float, error: ?string, refused: bool}
     */
    public function snapshot(string $domain, Market $market, ?int $siteId = null, ?User $user = null): array
    {
        $domain = self::domain($domain);

        $result = $this->client->post(self::OVERVIEW_PATH, [[
            'target' => $domain,
            'location_code' => $market->locationCode,
            'language_code' => $market->languageCode,
        ]], Feature::RESEARCH, self::overviewCost(), $siteId, $user);

        if (! $result->ok) {
            return ['ok' => false, 'snapshot' => null, 'cost' => 0.0, 'error' => $result->error, 'refused' => $result->refused];
        }

        $item = $result->firstResult()[0]['items'][0] ?? null;

        return [
            'ok' => true,
            // No item means Labs has nothing on this domain: stored as
            // unknown, so it is not bought again this month.
            'snapshot' => $this->storeSnapshot($domain, $market, is_array($item) ? ($item['metrics'] ?? null) : null),
            'cost' => $result->cost,
            'error' => null,
            'refused' => false,
        ];
    }

    /**
     * Buy the keywords a domain ranks for, best traffic first, and replace
     * the stored list. Also refreshes this month's count from the same call.
     *
     * @return array{ok: bool, stored: int, total: ?int, cost: float, error: ?string, refused: bool}
     */
    public function pullRankedKeywords(string $domain, Market $market, int $limit = self::MAX_KEYWORDS, ?int $siteId = null, ?User $user = null): array
    {
        $domain = self::domain($domain);
        $limit = max(1, min(self::MAX_KEYWORDS, $limit));

        $result = $this->client->post(self::RANKED_PATH, [[
            'target' => $domain,
            'location_code' => $market->locationCode,
            'language_code' => $market->languageCode,
            'item_types' => ['organic'],
            'limit' => $limit,
            'order_by' => ['ranked_serp_element.serp_item.etv,desc'],
        ]], Feature::RESEARCH, self::rankedCost($limit), $siteId, $user);

        if (! $result->ok) {
            return ['ok' => false, 'stored' => 0, 'total' => null, 'cost' => 0.0, 'error' => $result->error, 'refused' => $result->refused];
        }

        $data = $result->firstResult()[0] ?? [];
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $now = Carbon::now();
        $rows = [];

        foreach ($items as $item) {
            $keyword = mb_strtolower(trim((string) ($item['keyword_data']['keyword'] ?? '')));
            $serp = $item['ranked_serp_element']['serp_item'] ?? null;
            $position = is_array($serp) ? (int) ($serp['rank_group'] ?? 0) : 0;
            if ($keyword === '' || mb_strlen($keyword) > 191 || $position < 1 || isset($rows[$keyword])) {
                continue;
            }

            $info = (array) ($item['keyword_data']['keyword_info'] ?? []);
            $previous = $serp['rank_changes']['previous_rank_absolute'] ?? null;

            $rows[$keyword] = [
                'domain' => $domain, 'market_key' => $market->key(), 'provider' => DataForSeoClient::PROVIDER,
                'keyword' => $keyword,
                'position' => min(65535, $position),
                'previous_position' => is_numeric($previous) && $previous > 0 ? min(65535, (int) $previous) : null,
                'url' => isset($serp['url']) ? mb_substr((string) $serp['url'], 0, 2048) : null,
                'volume' => isset($info['search_volume']) ? max(0, (int) $info['search_volume']) : null,
                'cpc' => isset($info['cpc']) ? round((float) $info['cpc'], 2) : null,
                'difficulty' => isset($item['keyword_data']['keyword_properties']['keyword_difficulty']) ? min(100, max(0, (int) $item['keyword_data']['keyword_properties']['keyword_difficulty'])) : null,
                'intent' => isset($item['keyword_data']['search_intent_info']['main_intent']) ? mb_substr((string) $item['keyword_data']['search_intent_info']['main_intent'], 0, 20) : null,
                'estimated_traffic' => isset($serp['etv']) ? round((float) $serp['etv'], 2) : null,
                'seen_at' => self::time($item['ranked_serp_element']['last_updated_time'] ?? null),
                'fetched_at' => $now,
            ];
        }

        DB::transaction(function () use ($domain, $market, $rows): void {
            RankedKeyword::query()->where('domain', $domain)->where('market_key', $market->key())->where('provider', DataForSeoClient::PROVIDER)->delete();
            foreach (array_chunk(array_values($rows), 200) as $chunk) {
                RankedKeyword::query()->insert($chunk);
            }
        });

        if (is_array($data['metrics'] ?? null)) {
            $this->storeSnapshot($domain, $market, $data['metrics']);
        }

        return ['ok' => true, 'stored' => count($rows), 'total' => isset($data['total_count']) ? (int) $data['total_count'] : null, 'cost' => $result->cost, 'error' => null, 'refused' => false];
    }

    public function latestSnapshot(string $domain, Market $market): ?DomainSnapshot
    {
        return DomainSnapshot::query()->where('domain', self::domain($domain))->where('market_key', $market->key())->orderByDesc('month')->first();
    }

    public function hasSnapshotThisMonth(string $domain, Market $market): bool
    {
        return DomainSnapshot::query()
            ->where('domain', self::domain($domain))->where('market_key', $market->key())
            ->where('month', CarbonImmutable::now('UTC')->startOfMonth()->toDateString())
            ->exists();
    }

    /** @param  array<string, mixed>|null  $metrics */
    private function storeSnapshot(string $domain, Market $market, ?array $metrics): DomainSnapshot
    {
        // Labs nests the figures under "organic"; the published spec shows them flat.
        $m = is_array($metrics['organic'] ?? null) ? $metrics['organic'] : ($metrics ?? []);
        $int = fn (string $key): ?int => isset($m[$key]) && is_numeric($m[$key]) ? max(0, (int) $m[$key]) : null;

        $bucketKeys = ['pos_1', 'pos_2_3', 'pos_4_10', 'pos_11_20', 'pos_21_30', 'pos_31_40', 'pos_41_50', 'pos_51_60', 'pos_61_70', 'pos_71_80', 'pos_81_90', 'pos_91_100'];
        $buckets = [];
        foreach ($bucketKeys as $key) {
            $buckets[$key] = $int($key);
        }
        // A sum is only known when every part is.
        $sum = function (array $keys) use ($buckets): ?int {
            $total = 0;
            foreach ($keys as $key) {
                if ($buckets[$key] === null) {
                    return null;
                }
                $total += $buckets[$key];
            }

            return $total;
        };

        return DomainSnapshot::query()->updateOrCreate(
            ['domain' => $domain, 'market_key' => $market->key(), 'month' => CarbonImmutable::now('UTC')->startOfMonth()->toDateString(), 'provider' => DataForSeoClient::PROVIDER],
            [
                'keywords' => $int('count'),
                'top3' => $sum(['pos_1', 'pos_2_3']),
                'top10' => $sum(['pos_1', 'pos_2_3', 'pos_4_10']),
                'top20' => $sum(['pos_1', 'pos_2_3', 'pos_4_10', 'pos_11_20']),
                'top100' => $sum($bucketKeys),
                'buckets' => $m === [] ? null : $buckets,
                'estimated_traffic' => isset($m['etv']) && is_numeric($m['etv']) ? round((float) $m['etv'], 2) : null,
                'traffic_value' => isset($m['estimated_paid_traffic_cost']) && is_numeric($m['estimated_paid_traffic_cost']) ? round((float) $m['estimated_paid_traffic_cost'], 2) : null,
                'new_keywords' => $int('is_new'), 'up_keywords' => $int('is_up'), 'down_keywords' => $int('is_down'), 'lost_keywords' => $int('is_lost'),
                'fetched_at' => now(),
            ],
        );
    }

    private static function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
