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
use Modules\Seo\Keywords\Market;
use Modules\Seo\Mcp\Support\ResponseEnvelope;
use Modules\Seo\Mcp\Support\SiteFinder;
use Modules\Seo\Models\RankedKeyword;
use Modules\Seo\Research\DomainResearch;

#[Name('seo_ranked_keywords')]
#[Title('Keywords a domain ranks for')]
#[Description('The stored list of keywords a domain ranks for on Google in the site\'s country: position, ranking page, volume, difficulty, intent and estimated traffic. Works for the site itself or any competitor whose list was bought. From storage; never spends money. If nothing is stored it says what buying the list costs (seo_domain_research_fetch). Positions here can be up to 90 days old; for tracked keywords use seo_rankings.')]
#[IsReadOnly]
#[IsIdempotent]
class SeoRankedKeywords extends SeoTool
{
    private const SORTS = ['traffic' => ['estimated_traffic', 'desc'], 'position' => ['position', 'asc'], 'volume' => ['volume', 'desc']];

    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A Control site id or a domain. Sets the country and language.')->required(),
            'domain' => $schema->string()->description('The domain whose keywords to list. Default: the site itself.'),
            'max_position' => $schema->integer()->min(1)->max(100)->description('Only keywords ranking at this position or better.'),
            'min_position' => $schema->integer()->min(1)->max(100)->description('Only keywords ranking at this position or worse, e.g. 11 for page two.'),
            'contains' => $schema->string()->max(100)->description('Only keywords containing this text.'),
            'url_contains' => $schema->string()->max(200)->description('Only keywords whose ranking page contains this text.'),
            'min_volume' => $schema->integer()->min(0),
            'sort' => $schema->string()->enum(array_keys(self::SORTS))->description('Default traffic.'),
            'limit' => $schema->integer()->min(1)->max(1000)->description('Default 200.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($this->caller($request) === null) {
            return Response::error(self::NO_USER);
        }

        $profile = SiteFinder::profile($request->get('site'));
        if (is_string($profile)) {
            return Response::error($profile);
        }

        $market = Market::forProfile($profile);
        $domain = DomainResearch::domain(filled($request->get('domain')) ? (string) $request->get('domain') : (string) $profile->site->domain);
        if ($domain === '') {
            return Response::error('domain must be a domain such as example.com.');
        }

        $base = RankedKeyword::query()->where('domain', $domain)->where('market_key', $market->key());
        $stored = (clone $base)->count();
        $fetched = (clone $base)->max('fetched_at');

        $like = fn (string $text) => '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($text)).'%';
        $query = (clone $base)
            ->when(is_numeric($request->get('max_position')), fn ($q) => $q->where('position', '<=', (int) $request->get('max_position')))
            ->when(is_numeric($request->get('min_position')), fn ($q) => $q->where('position', '>=', (int) $request->get('min_position')))
            ->when(is_numeric($request->get('min_volume')), fn ($q) => $q->where('volume', '>=', (int) $request->get('min_volume')))
            ->when(filled($request->get('contains')), fn ($q) => $q->where('keyword', 'like', $like((string) $request->get('contains'))))
            ->when(filled($request->get('url_contains')), fn ($q) => $q->whereRaw('LOWER(url) LIKE ?', [$like((string) $request->get('url_contains'))]));

        $matching = (clone $query)->count();
        [$column, $direction] = self::SORTS[$request->get('sort')] ?? self::SORTS['traffic'];
        $limit = max(1, min(1000, (int) $request->get('limit', 200)));

        $rows = $query->orderBy($column, $direction)->orderBy('keyword')->limit($limit)->get()->map(fn (RankedKeyword $k) => [
            $k->keyword, $k->position, $k->previous_position, ResponseEnvelope::clip($k->url, 300), $k->volume, $k->difficulty, $k->intent, $k->cpc, $k->estimated_traffic,
        ])->all();

        $caveats = [
            ['code' => 'stored_list', 'message' => 'This is the top of the domain\'s keywords by estimated traffic as of the last pull, up to 1,000. It is not every keyword the domain ranks for; the full count is in seo_domain_overview.'],
            ['code' => 'lagging', 'message' => 'Positions come from DataForSEO\'s stored results, re-read every 30 to 90 days, for the whole country. They can differ from a live check today.'],
        ];
        if ($stored === 0) {
            $caveats[] = ['code' => 'nothing_stored', 'message' => sprintf('No keyword list is stored for %s. Buying up to 1,000 costs about $%.2f with seo_domain_research_fetch; ask the person first.', $domain, DomainResearch::rankedCost(1000))];
        }

        return Response::structured(ResponseEnvelope::make(
            summary: $stored === 0
                ? "No ranked-keyword list is stored for {$domain}."
                : "{$domain}: {$matching} of {$stored} stored keywords match, showing ".count($rows).'. List pulled '.substr((string) $fetched, 0, 10).'.',
            profile: $profile,
            period: ['fetched' => $fetched !== null ? substr((string) $fetched, 0, 10) : null, 'market' => $market->toArray()],
            dataState: null,
            finalAfter: null,
            caveats: $caveats,
            data: [
                'domain' => $domain,
                'columns' => ['keyword', 'position', 'previous_position', 'url', 'volume', 'difficulty', 'intent', 'cpc', 'estimated_traffic'],
                'rows' => $rows,
                'rows_matching' => $matching,
                'truncated' => $matching > count($rows),
            ],
        ));
    }
}
