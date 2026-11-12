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
use Modules\Seo\Keywords\Market;
use Modules\Seo\Mcp\Support\ResponseEnvelope;
use Modules\Seo\Mcp\Support\SiteFinder;
use Modules\Seo\Research\DomainResearch;

#[Name('seo_domain_research_fetch')]
#[Title('Buy ranking data for a domain')]
#[Description('Spends money. Buys from DataForSEO either this month\'s ranking-keyword count for a domain (what "count", about 1 cent) or the list of keywords it ranks for (what "ranked_keywords", up to 1,000, about 13 cents). Call it first WITHOUT confirm_cost_usd to get the price and spend nothing; tell the person the price and what it buys; call again with confirm_cost_usd only after they agree. Read the result afterwards with seo_domain_overview or seo_ranked_keywords.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld]
class SeoDomainResearchFetch extends SeoTool
{
    public const NAME = 'seo_domain_research_fetch';

    /** @var array<string, mixed> */
    protected ?array $meta = ['anthropic/requiresUserInteraction' => true];

    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('The client this is for: a Control site id or a domain. Sets the country and whose budget line it goes on.')->required(),
            'what' => $schema->string()->enum(['count', 'ranked_keywords'])->required(),
            'domain' => $schema->string()->description('The domain to look up. Default: the site itself.'),
            'limit' => $schema->integer()->min(10)->max(1000)->description('For ranked_keywords: how many keywords, best traffic first. Default 1000. Fewer costs less.'),
            'confirm_cost_usd' => $schema->number()->description('The price the person agreed to. Leave out to get the price only.'),
        ];
    }

    public function handle(Request $request, DomainResearch $research, BudgetGuard $budget, DataForSeoClient $client): Response|ResponseFactory
    {
        $user = $this->caller($request);
        if ($user === null) {
            return Response::error(self::NO_USER);
        }

        $profile = SiteFinder::profile($request->get('site'));
        if (is_string($profile)) {
            return Response::error($profile);
        }

        $what = (string) $request->get('what');
        if (! in_array($what, ['count', 'ranked_keywords'], true)) {
            return Response::error('what must be count or ranked_keywords.');
        }

        $domain = DomainResearch::domain(filled($request->get('domain')) ? (string) $request->get('domain') : (string) $profile->site->domain);
        if (preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) !== 1) {
            return Response::error('domain must be a domain such as example.com.');
        }

        $limit = max(10, min(DomainResearch::MAX_KEYWORDS, (int) $request->get('limit', DomainResearch::MAX_KEYWORDS)));
        $market = Market::forProfile($profile);
        $estimate = $what === 'count' ? DomainResearch::overviewCost() : DomainResearch::rankedCost($limit);
        $buys = $what === 'count'
            ? "this month's ranking-keyword count for {$domain}"
            : "up to {$limit} keywords {$domain} ranks for";

        $refusal = $budget->refusal(Feature::RESEARCH, $estimate, $user);
        $confirm = $request->get('confirm_cost_usd');

        if ($confirm === null) {
            return Response::structured([
                'summary' => sprintf('Not bought yet. %s would cost about $%.3f.', ucfirst($buys), $estimate)
                    .($refusal !== null ? ' It would be refused right now: '.$refusal : ' Ask the person, then call again with confirm_cost_usd.')
                    .($what === 'count' && $research->hasSnapshotThisMonth($domain, $market) ? ' A count for this month is already stored, so buying again adds nothing.' : ''),
                'bought' => false,
                'site' => ResponseEnvelope::site($profile),
                'domain' => $domain,
                'what' => $what,
                'estimated_cost_usd' => $estimate,
                'would_be_refused' => $refusal,
                'price_note' => 'From DataForSEO\'s published prices; not yet confirmed by a real call.',
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

        $result = $what === 'count'
            ? $research->snapshot($domain, $market, $profile->site_id, $user)
            : $research->pullRankedKeywords($domain, $market, $limit, $profile->site_id, $user);

        if (! $result['ok']) {
            return Response::error('DataForSEO did not return the data: '.$result['error'].' Nothing was stored.');
        }

        $snapshot = $research->latestSnapshot($domain, $market);

        return Response::structured([
            'summary' => sprintf('Bought %s for $%.4f. ', $buys, $result['cost'])
                .($what === 'count'
                    ? ($snapshot?->keywords !== null ? "{$domain} ranks for ".number_format($snapshot->keywords).' keywords by DataForSEO\'s count.' : "DataForSEO has no ranking data for {$domain}.")
                    : "Stored {$result['stored']} keywords".($result['total'] !== null ? ' of '.number_format($result['total']).' it ranks for' : '').'. Read them with seo_ranked_keywords.'),
            'bought' => true,
            'site' => ResponseEnvelope::site($profile),
            'domain' => $domain,
            'what' => $what,
            'cost_usd' => $result['cost'],
            'keywords' => $snapshot?->keywords,
            'top10' => $snapshot?->top10,
            'stored_keywords' => $result['stored'] ?? null,
            'spent_this_month_usd' => round($budget->spentThisMonth(), 4),
        ]);
    }
}
