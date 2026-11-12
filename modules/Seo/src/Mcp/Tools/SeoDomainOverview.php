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
use Modules\Seo\Models\DomainSnapshot;
use Modules\Seo\Models\RankedKeyword;
use Modules\Seo\Research\DomainResearch;

#[Name('seo_domain_overview')]
#[Title('Ranking-keyword counts for a site and its competitors')]
#[Description('How many keywords a site and its named competitors rank for on Google in the site\'s country, month by month: total, top 3, top 10, top 20, estimated traffic. From stored monthly counts; never spends money. A domain with no stored count comes back with nulls and what it would cost to buy with seo_domain_research_fetch. These counts come from DataForSEO\'s own database and are not comparable with another tool\'s figure.')]
#[IsReadOnly]
#[IsIdempotent]
class SeoDomainOverview extends SeoTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A Control site id or a domain.')->required(),
            'domains' => $schema->array()->max(10)->items($schema->string())->description('Other domains to include. Default: the site and its named competitors.'),
            'months' => $schema->integer()->min(1)->max(36)->description('How many months of history. Default 12.'),
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
        $own = DomainResearch::domain((string) $profile->site->domain);
        $extra = is_array($request->get('domains')) ? array_filter($request->get('domains'), 'is_string') : array_filter((array) ($profile->competitors ?? []), 'is_string');
        $domains = array_slice(array_values(array_unique(array_filter([$own, ...array_map([DomainResearch::class, 'domain'], $extra)]))), 0, 11);
        $months = max(1, min(36, (int) $request->get('months', 12)));

        $snapshots = DomainSnapshot::query()
            ->whereIn('domain', $domains)->where('market_key', $market->key())
            ->where('month', '>=', now('UTC')->startOfMonth()->subMonths($months - 1)->toDateString())
            ->orderBy('month')
            ->get()
            ->groupBy('domain');

        $listed = RankedKeyword::query()->whereIn('domain', $domains)->where('market_key', $market->key())
            ->selectRaw('domain, COUNT(*) AS stored, MAX(fetched_at) AS fetched_at')->groupBy('domain')->get()->keyBy('domain');

        $out = [];
        $missing = [];
        foreach ($domains as $domain) {
            $history = $snapshots->get($domain, collect());
            $latest = $history->last();
            if ($latest === null) {
                $missing[] = $domain;
            }

            $out[] = [
                'domain' => $domain,
                'is_client' => $domain === $own,
                'latest_month' => $latest?->month->format('Y-m'),
                'keywords' => $latest?->keywords,
                'top3' => $latest?->top3,
                'top10' => $latest?->top10,
                'top20' => $latest?->top20,
                'estimated_traffic' => $latest?->estimated_traffic,
                'history' => $history->map(fn (DomainSnapshot $s) => [$s->month->format('Y-m'), $s->keywords, $s->top3, $s->top10, $s->estimated_traffic])->values()->all(),
                'ranked_keywords_stored' => (int) ($listed->get($domain)->stored ?? 0),
                'ranked_keywords_fetched' => isset($listed[$domain]) ? substr((string) $listed[$domain]->fetched_at, 0, 10) : null,
            ];
        }

        $caveats = [
            ['code' => 'provider_database', 'message' => 'Counts come from DataForSEO\'s own keyword database for one country. They are lower than, and not comparable with, counts from another tool. Never put the two on one trend line.'],
            ['code' => 'lagging', 'message' => 'Each keyword\'s results are re-read every 30 to 90 days, so a month-to-month change partly reflects timing. For the client\'s own recent movement use Search Console or seo_rankings.'],
            ['code' => 'traffic_is_estimate', 'message' => 'Estimated traffic is a model, not visits. For a client\'s real clicks use seo_month_numbers.'],
        ];
        if ($missing !== []) {
            $caveats[] = ['code' => 'not_counted_yet', 'message' => 'No stored count for: '.implode(', ', $missing).sprintf('. Each costs about $%.3f to buy with seo_domain_research_fetch; clients and named competitors are counted automatically on the 6th of each month.', DomainResearch::overviewCost())];
        }

        $client = $out[0];

        return Response::structured(ResponseEnvelope::make(
            summary: $client['keywords'] === null
                ? "{$own}: no ranking-keyword count stored yet."
                : "{$own} ranks for ".number_format($client['keywords'])." keywords ({$client['latest_month']}, DataForSEO count)".($client['top10'] !== null ? ', '.number_format($client['top10']).' in the top 10' : '').'. '.(count($out) - 1).' other '.(count($out) === 2 ? 'domain' : 'domains').' included.',
            profile: $profile,
            period: ['months' => $months, 'market' => $market->toArray()],
            dataState: null,
            finalAfter: null,
            caveats: $caveats,
            data: ['history_columns' => ['month', 'keywords', 'top3', 'top10', 'estimated_traffic'], 'domains' => $out],
        ));
    }
}
