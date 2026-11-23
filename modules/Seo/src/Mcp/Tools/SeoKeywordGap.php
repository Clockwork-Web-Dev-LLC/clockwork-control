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
use Modules\Seo\Research\DomainResearch;
use Modules\Seo\Research\KeywordGap;

#[Name('seo_keyword_gap')]
#[Title('Keyword gap against competitors')]
#[Description('Compare a site with up to 5 competitors: keywords they rank for that it does not (missing, untapped), where it ranks below them (weak) or above them (strong), and what they share. Worked out from ranked-keyword lists already stored; never spends money. Each domain needs its list bought first (seo_domain_research_fetch, what "ranked_keywords"); a competitor with no list is left out and named. A gap is a lead to check, because stored lists hold a domain\'s top keywords, not all of them.')]
#[IsReadOnly]
#[IsIdempotent]
class SeoKeywordGap extends SeoTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A Control site id or a domain.')->required(),
            'competitors' => $schema->array()->max(KeywordGap::MAX_COMPETITORS)->items($schema->string())->description('Up to 5 competitor domains. Default: the competitors named on the site profile.'),
            'segment' => $schema->string()->enum(array_keys(KeywordGap::SEGMENTS))->description('Which keywords to list. Default missing. Counts for every segment are always returned.'),
            'min_volume' => $schema->integer()->min(0)->description('Only keywords with at least this monthly volume.'),
            'contains' => $schema->string()->max(100)->description('Only keywords containing this text.'),
            'limit' => $schema->integer()->min(1)->max(1000)->description('Default 200, highest volume first.'),
        ];
    }

    public function handle(Request $request, KeywordGap $gap): Response|ResponseFactory
    {
        if ($this->caller($request) === null) {
            return Response::error(self::NO_USER);
        }

        $profile = SiteFinder::profile($request->get('site'));
        if (is_string($profile)) {
            return Response::error($profile);
        }

        $competitors = is_array($request->get('competitors'))
            ? array_values(array_filter($request->get('competitors'), 'is_string'))
            : array_values(array_filter((array) ($profile->competitors ?? []), 'is_string'));
        if ($competitors === []) {
            return Response::error('No competitors to compare with. Pass competitors, or have a person name them on the site\'s Rankings page. seo_competitors lists candidates.');
        }
        if (count($competitors) > KeywordGap::MAX_COMPETITORS) {
            $competitors = array_slice($competitors, 0, KeywordGap::MAX_COMPETITORS);
        }

        $segment = array_key_exists((string) $request->get('segment'), KeywordGap::SEGMENTS) ? (string) $request->get('segment') : 'missing';
        $market = Market::forProfile($profile);
        $result = $gap->compare(
            (string) $profile->site->domain, $competitors, $market, $segment,
            max(1, min(1000, (int) $request->get('limit', 200))),
            max(0, (int) $request->get('min_volume', 0)),
            is_string($request->get('contains')) ? $request->get('contains') : null,
        );

        $listCost = DomainResearch::rankedCost(DomainResearch::MAX_KEYWORDS);
        $caveats = [
            ['code' => 'stored_lists_only', 'message' => 'Each domain\'s list holds its top keywords by estimated traffic (up to 1,000), from DataForSEO\'s stored results. "Does not rank" means "not in that list". Check a gap before presenting it as fact.'],
            ['code' => 'country_level', 'message' => 'Positions are for the whole country and can be up to 90 days old. For a local business, a national competitor\'s keywords may not be winnable locally.'],
        ];
        if (! $result['client_has_data']) {
            $caveats[] = ['code' => 'client_list_missing', 'message' => sprintf('No keyword list is stored for %s itself, so every competitor keyword looks like a gap. Buy its list first (about $%.2f).', $result['client'], $listCost)];
        }
        if ($result['without_data'] !== []) {
            $caveats[] = ['code' => 'competitor_lists_missing', 'message' => 'Left out because no keyword list is stored: '.implode(', ', $result['without_data']).sprintf('. Each costs about $%.2f to buy; ask the person first.', $listCost)];
        }

        $columns = ['keyword', 'volume', 'difficulty', 'intent', 'cpc', 'client_position', 'client_url', ...array_map(fn (string $d) => "position:{$d}", $result['competitors'])];
        $rows = array_map(fn (array $r) => [
            $r['keyword'], $r['volume'], $r['difficulty'], $r['intent'], $r['cpc'], $r['client_position'], ResponseEnvelope::clip($r['client_url'], 300), ...$r['competitor_positions'],
        ], $result['rows']);

        return Response::structured(ResponseEnvelope::make(
            summary: $result['competitors'] === []
                ? "{$result['client']}: nothing to compare yet. None of the competitors has a stored keyword list."
                : "{$result['client']} against ".implode(', ', $result['competitors']).": {$result['counts']['missing']} missing, {$result['counts']['untapped']} untapped, {$result['counts']['weak']} weak, {$result['counts']['strong']} strong. Showing ".count($rows)." of {$result['rows_matching']} {$segment}.",
            profile: $profile,
            period: ['market' => $market->toArray()],
            dataState: null,
            finalAfter: null,
            caveats: $caveats,
            data: [
                'compared' => $result['competitors'],
                'segment' => $segment,
                'segments' => KeywordGap::SEGMENTS,
                'counts' => $result['counts'],
                'columns' => $columns,
                'rows' => $rows,
                'rows_matching' => $result['rows_matching'],
                'truncated' => $result['rows_matching'] > count($rows),
            ],
        ));
    }
}
