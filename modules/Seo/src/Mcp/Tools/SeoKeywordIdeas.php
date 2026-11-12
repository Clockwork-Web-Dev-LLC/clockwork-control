<?php

namespace Modules\Seo\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Seo\DataForSeo\BudgetGuard;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\DataForSeo\Feature;
use Modules\Seo\Keywords\KeywordMetricsService;
use Modules\Seo\Keywords\Market;
use Modules\Seo\Mcp\Support\ResponseEnvelope;
use Modules\Seo\Mcp\Support\SiteFinder;
use Modules\Seo\Models\KeywordIdeaPull;
use Modules\Seo\Models\KeywordMetric;
use Modules\Seo\Research\KeywordIdeas;

#[Name('seo_keyword_ideas')]
#[Title('Keyword ideas from seed keywords')]
#[Description('New keyword ideas for a site, each with volume, difficulty, CPC and intent. Three uses. (1) With only site: lists idea lists already bought; free. (2) With pull_id: reads one of those lists again, with filters; free. (3) With seeds: buys a new list from DataForSEO, which spends money (about 13 cents per 1,000 ideas). For (3), call first WITHOUT confirm_cost_usd to get the price, tell the person, and call again with confirm_cost_usd only after they agree. Modes: "ideas" (same topics as up to 20 seeds), "suggestions" (longer searches containing one seed phrase), "related" (searches Google lists as related to one seed). Ideas are country-level. Grouping them into topics is your job.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld]
class SeoKeywordIdeas extends SeoTool
{
    public const NAME = 'seo_keyword_ideas';

    /** @var array<string, mixed> */
    protected ?array $meta = ['anthropic/requiresUserInteraction' => true];

    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A Control site id or a domain. Sets the country and whose budget line a purchase goes on.')->required(),
            'pull_id' => $schema->integer()->description('Read a stored list again. Free.'),
            'seeds' => $schema->array()->max(20)->items($schema->string())->description('Seed keywords for a new list. Up to 20 for ideas; one for suggestions or related.'),
            'mode' => $schema->string()->enum(array_keys(KeywordIdeas::MODES))->description('For a new list. Default ideas.'),
            'limit' => $schema->integer()->min(10)->max(1000)->description('How many ideas to buy (default 300) or to show from a stored list (default 300). Buying fewer costs less.'),
            'confirm_cost_usd' => $schema->number()->description('For a new list: the price the person agreed to. Leave out to get the price only.'),
            'min_volume' => $schema->integer()->min(0)->description('When reading: only ideas with at least this volume.'),
            'max_difficulty' => $schema->integer()->min(0)->max(100)->description('When reading: only ideas at or below this difficulty.'),
            'contains' => $schema->string()->max(100)->description('When reading: only ideas containing this text.'),
            'questions_only' => $schema->boolean()->description('When reading: only ideas phrased as questions.'),
        ];
    }

    public function handle(Request $request, KeywordIdeas $ideas, KeywordMetricsService $metrics, BudgetGuard $budget, DataForSeoClient $client): Response|ResponseFactory
    {
        $user = $this->caller($request);
        if ($user === null) {
            return Response::error(self::NO_USER);
        }

        $profile = SiteFinder::profile($request->get('site'));
        if (is_string($profile)) {
            return Response::error($profile);
        }

        $market = Market::forProfile($profile);
        $limit = max(10, min(KeywordIdeas::MAX_IDEAS, (int) $request->get('limit', 300)));

        if ($request->get('pull_id') !== null) {
            $pull = KeywordIdeaPull::query()->where('site_id', $profile->site_id)->find((int) $request->get('pull_id'));

            return $pull === null
                ? Response::error('No stored idea list with that id for this site. Call with only site to see the lists.')
                : Response::structured($this->show($pull, $profile, $request, $limit, null));
        }

        $seeds = $request->get('seeds');
        if ($seeds === null) {
            $pulls = KeywordIdeaPull::query()->where('site_id', $profile->site_id)->orderByDesc('id')->limit(50)->get();

            return Response::structured(ResponseEnvelope::make(
                summary: ($profile->site->domain ?? 'Site').': '.$pulls->count().' stored idea '.($pulls->count() === 1 ? 'list' : 'lists').'. Pass pull_id to read one, or seeds to price a new one.',
                profile: $profile, period: ['market' => $market->toArray()], dataState: null, finalAfter: null, caveats: [],
                data: ['lists' => $pulls->map(fn (KeywordIdeaPull $p) => [
                    'pull_id' => $p->id, 'mode' => $p->mode, 'seeds' => $p->seeds, 'ideas' => $p->keyword_count, 'available' => $p->available,
                    'bought' => $p->created_at?->toDateString(), 'cost_usd' => $p->cost_usd,
                ])->all()],
            ));
        }

        $mode = array_key_exists((string) $request->get('mode'), KeywordIdeas::MODES) ? (string) $request->get('mode') : 'ideas';
        [, $finds, $maxSeeds] = KeywordIdeas::MODES[$mode];

        if (! is_array($seeds) || array_filter($seeds, fn ($s) => ! is_string($s)) !== []) {
            return Response::error('seeds must be a list of keywords.');
        }
        $cleaned = $metrics->clean(array_values($seeds));
        if ($cleaned['valid'] === []) {
            return Response::error('None of the seeds can be used: '.implode('; ', array_map(fn (array $r) => "{$r['keyword']} ({$r['reason']})", array_slice($cleaned['rejected'], 0, 5))).'.');
        }
        if (count($cleaned['valid']) > $maxSeeds) {
            return Response::error("Mode {$mode} takes {$maxSeeds} seed".($maxSeeds === 1 ? '' : 's').' at most. Use mode ideas for several seeds, or call once per seed.');
        }

        $estimate = KeywordIdeas::cost($limit);
        $refusal = $budget->refusal(Feature::RESEARCH, $estimate, $user);
        $confirm = $request->get('confirm_cost_usd');

        if ($confirm === null) {
            return Response::structured([
                'summary' => sprintf('Not bought yet. Up to %d %s would cost about $%.3f.', $limit, $finds, $estimate)
                    .($refusal !== null ? ' It would be refused right now: '.$refusal : ' Ask the person, then call again with confirm_cost_usd.'),
                'bought' => false,
                'site' => ResponseEnvelope::site($profile),
                'mode' => $mode,
                'seeds' => $cleaned['valid'],
                'rejected_seeds' => $cleaned['rejected'],
                'limit' => $limit,
                'estimated_cost_usd' => $estimate,
                'would_be_refused' => $refusal,
                'price_note' => 'From DataForSEO\'s published prices; not yet confirmed by a real call. You pay for the ideas returned, so a narrow seed can cost less.',
            ]);
        }

        if (! is_numeric($confirm) || (float) $confirm + 0.00001 < $estimate) {
            return Response::error(sprintf('confirm_cost_usd must be at least the estimate, $%.3f. Nothing was bought.', $estimate));
        }
        if (! $client->isConfigured()) {
            return Response::error('DataForSEO is not set up in Control yet. An admin adds the API login and password under SEO on Settings → Integrations.');
        }
        if ($refusal !== null) {
            return Response::error($refusal.' Nothing was bought.');
        }

        $result = $ideas->fetch($mode, $cleaned['valid'], $market, $limit, $profile->site_id, $user);
        if (! $result['ok']) {
            return Response::error('DataForSEO did not return ideas: '.$result['error'].' Nothing was stored.');
        }

        return Response::structured($this->show($result['pull'], $profile, $request, $limit, $result['cost']));
    }

    /** @return array<string, mixed> */
    private function show(KeywordIdeaPull $pull, $profile, Request $request, int $limit, ?float $justPaid): array
    {
        $stored = KeywordMetric::query()
            ->where('market_key', $pull->market_key)->where('provider', KeywordMetricsService::PROVIDER)
            ->whereIn('keyword', $pull->keywords)->get()->keyBy('keyword');

        $contains = is_string($request->get('contains')) ? mb_strtolower($request->get('contains')) : null;
        $questions = '/^(who|what|when|where|why|how|which|can|does|do|is|are|should|will)\b/';
        $rows = [];

        foreach ($pull->keywords as $keyword) {
            $m = $stored->get($keyword);
            if (($contains !== null && $contains !== '' && ! str_contains($keyword, $contains))
                || ($request->get('questions_only') && preg_match($questions, $keyword) !== 1)
                || (is_numeric($request->get('min_volume')) && (int) ($m?->volume ?? 0) < (int) $request->get('min_volume'))
                || (is_numeric($request->get('max_difficulty')) && ($m?->difficulty === null || $m->difficulty > (int) $request->get('max_difficulty')))) {
                continue;
            }
            $rows[] = [$keyword, $m?->volume, $m?->difficulty, KeywordMetricsService::difficultyBand($m?->difficulty), $m?->cpc, $m?->intent];
        }

        usort($rows, fn (array $a, array $b) => [$b[1] ?? -1, $a[0]] <=> [$a[1] ?? -1, $b[0]]);
        $matching = count($rows);
        $rows = array_slice($rows, 0, $limit);

        return ResponseEnvelope::make(
            summary: ($justPaid !== null ? sprintf('Bought %d ideas for $%.4f. ', $pull->keyword_count, $justPaid) : '')
                ."Idea list {$pull->id} ({$pull->mode}, seeds: ".implode(', ', array_slice($pull->seeds, 0, 5)).'): showing '.count($rows)." of {$matching} matching, {$pull->keyword_count} stored"
                .($pull->available !== null && $pull->available > $pull->keyword_count ? ', '.number_format($pull->available).' available from DataForSEO' : '').'.',
            profile: $profile,
            period: ['bought' => $pull->created_at?->toDateString()],
            dataState: null,
            finalAfter: null,
            caveats: [
                ['code' => 'country_level', 'message' => 'Volumes are for the whole country. For a local business, judge local terms on Search Console impressions, not on these volumes.'],
                ['code' => 'difficulty_not_calibrated', 'message' => 'Difficulty is DataForSEO\'s 0 to 100 score with default bands. Do not compare it with a score from another tool.'],
            ],
            data: [
                'bought' => $justPaid !== null,
                'pull_id' => $pull->id,
                'mode' => $pull->mode,
                'seeds' => $pull->seeds,
                'cost_usd' => $pull->cost_usd,
                'columns' => ['keyword', 'volume', 'difficulty', 'difficulty_band', 'cpc', 'intent'],
                'rows' => $rows,
                'rows_matching' => $matching,
                'truncated' => $matching > count($rows),
            ],
        );
    }
}
