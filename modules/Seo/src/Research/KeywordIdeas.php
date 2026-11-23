<?php

namespace Modules\Seo\Research;

use App\Models\User;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\DataForSeo\Feature;
use Modules\Seo\DataForSeo\Pricing;
use Modules\Seo\Keywords\KeywordMetricsService;
use Modules\Seo\Keywords\Market;
use Modules\Seo\Models\KeywordIdeaPull;

/**
 * New keyword ideas from seed keywords, from DataForSEO Labs. Every idea
 * comes back with its volume, difficulty, CPC and intent, which go into the
 * keyword metrics store, so an idea never has to be bought a second time.
 */
class KeywordIdeas
{
    /** mode => [path, what it finds, most seeds] */
    public const MODES = [
        'ideas' => ['/v3/dataforseo_labs/google/keyword_ideas/live', 'keywords in the same topics as the seeds', 20],
        'suggestions' => ['/v3/dataforseo_labs/google/keyword_suggestions/live', 'longer searches that contain the seed phrase', 1],
        'related' => ['/v3/dataforseo_labs/google/related_keywords/live', 'searches Google itself lists as related to the seed', 1],
    ];

    public const MAX_IDEAS = 1000;

    public function __construct(
        private readonly DataForSeoClient $client,
        private readonly KeywordMetricsService $metrics,
    ) {}

    public static function cost(int $limit): float
    {
        return Pricing::labs(max(1, min(self::MAX_IDEAS, $limit)));
    }

    /**
     * @param  list<string>  $seeds  cleaned keywords
     * @return array{ok: bool, pull: ?KeywordIdeaPull, cost: float, error: ?string, refused: bool}
     */
    public function fetch(string $mode, array $seeds, Market $market, int $limit, int $siteId, ?User $user = null): array
    {
        [$path, , $maxSeeds] = self::MODES[$mode];
        $seeds = array_slice(array_values($seeds), 0, $maxSeeds);
        $limit = max(1, min(self::MAX_IDEAS, $limit));

        $task = ['location_code' => $market->locationCode, 'language_code' => $market->languageCode, 'limit' => $limit];
        if ($mode === 'ideas') {
            $task += ['keywords' => $seeds, 'order_by' => ['keyword_info.search_volume,desc']];
        } elseif ($mode === 'suggestions') {
            $task += ['keyword' => $seeds[0], 'order_by' => ['keyword_info.search_volume,desc']];
        } else {
            // Depth 2: the seed's related searches, and theirs.
            $task += ['keyword' => $seeds[0], 'depth' => 2];
        }

        $result = $this->client->post($path, [$task], Feature::RESEARCH, self::cost($limit), $siteId, $user);
        if (! $result->ok) {
            return ['ok' => false, 'pull' => null, 'cost' => 0.0, 'error' => $result->error, 'refused' => $result->refused];
        }

        $data = $result->firstResult()[0] ?? [];
        $keywords = [];

        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $item) {
            // "related" wraps each keyword's figures in keyword_data.
            $entry = is_array($item['keyword_data'] ?? null) ? $item['keyword_data'] : $item;
            $keyword = KeywordMetricsService::normalize((string) ($entry['keyword'] ?? ''));
            if ($keyword === '' || mb_strlen($keyword) > 191 || isset($keywords[$keyword])) {
                continue;
            }

            $this->metrics->storeItem($keyword, $market, $entry);
            $keywords[$keyword] = true;
        }

        $pull = KeywordIdeaPull::create([
            'site_id' => $siteId,
            'user_id' => $user?->id,
            'mode' => $mode,
            'seeds' => $seeds,
            'market_key' => $market->key(),
            'keywords' => array_keys($keywords),
            'keyword_count' => count($keywords),
            'available' => isset($data['total_count']) ? max(0, (int) $data['total_count']) : null,
            'cost_usd' => $result->cost,
        ]);

        return ['ok' => true, 'pull' => $pull, 'cost' => $result->cost, 'error' => null, 'refused' => false];
    }
}
