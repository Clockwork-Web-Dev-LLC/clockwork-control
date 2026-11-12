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
use Modules\Seo\Models\DomainCompetitor;
use Modules\Seo\Models\RankedKeyword;
use Modules\Seo\Research\DomainResearch;

#[Name('seo_competitors')]
#[Title('A site\'s search competitors')]
#[Description('Who competes with a site in Google search: the competitors named on its profile, and the domains discovered to rank for the same keywords (shared keyword count, their average position, their total keywords and estimated traffic). From storage; never spends money. If no discovery is stored it says what one costs (seo_domain_research_fetch, what "competitors"). Discovered domains include directories and publishers, not only businesses, so pick the real rivals with the person.')]
#[IsReadOnly]
#[IsIdempotent]
class SeoCompetitors extends SeoTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A Control site id or a domain.')->required(),
            'limit' => $schema->integer()->min(1)->max(100)->description('Default 30, most shared keywords first.'),
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
        $domain = DomainResearch::domain((string) $profile->site->domain);
        $named = array_values(array_filter((array) ($profile->competitors ?? []), 'is_string'));
        $limit = max(1, min(100, (int) $request->get('limit', 30)));

        $discovered = DomainCompetitor::query()->where('domain', $domain)->where('market_key', $market->key())
            ->orderByDesc('shared_keywords')->orderBy('competitor_domain')->limit($limit)->get();
        $withLists = RankedKeyword::query()->where('market_key', $market->key())
            ->whereIn('domain', [...$named, ...$discovered->pluck('competitor_domain')->all()])
            ->distinct()->pluck('domain')->all();

        $caveats = [['code' => 'not_all_rivals', 'message' => 'Discovered domains are whoever shares the most keywords: directories, publishers and national brands appear beside real local rivals.']];
        if ($discovered->isEmpty()) {
            $caveats[] = ['code' => 'not_discovered', 'message' => sprintf('No competitor discovery is stored for %s. One costs about $%.3f with seo_domain_research_fetch (what "competitors"); ask the person first.', $domain, DomainResearch::competitorsCost(50))];
        }

        return Response::structured(ResponseEnvelope::make(
            summary: "{$domain}: ".count($named).' named '.(count($named) === 1 ? 'competitor' : 'competitors').', '.$discovered->count().' discovered'.($discovered->isNotEmpty() ? ' (found '.substr((string) $discovered->max('fetched_at'), 0, 10).')' : '').'.',
            profile: $profile,
            period: ['market' => $market->toArray()],
            dataState: null,
            finalAfter: null,
            caveats: $caveats,
            data: [
                'named' => array_map(fn (string $d) => ['domain' => $d, 'keyword_list_stored' => in_array($d, $withLists, true)], $named),
                'discovered_columns' => ['domain', 'shared_keywords', 'their_avg_position', 'their_keywords', 'their_estimated_traffic', 'named', 'keyword_list_stored'],
                'discovered' => $discovered->map(fn (DomainCompetitor $c) => [
                    $c->competitor_domain, $c->shared_keywords, $c->avg_position, $c->competitor_keywords, $c->competitor_traffic,
                    in_array($c->competitor_domain, $named, true), in_array($c->competitor_domain, $withLists, true),
                ])->all(),
                'how_to_name' => 'A person names competitors on the site\'s Rankings page in Control; named ones are tracked in every rank check and counted monthly.',
            ],
        ));
    }
}
