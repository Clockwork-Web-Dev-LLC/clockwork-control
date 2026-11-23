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
use Modules\Seo\Jobs\FetchKeywordMetricsJob;
use Modules\Seo\Keywords\KeywordMetricsService;

/**
 * The only SEO tool that spends money. Marked destructive so Claude asks
 * every time, and it re-checks everything itself: hints are advice to the
 * client, not a guarantee.
 */
#[Name('seo_keyword_metrics_fetch')]
#[Title('Buy keyword metrics (spends money)')]
#[Description('Buys the keyword metrics in a quote from seo_keyword_metrics. THIS SPENDS MONEY from the agency\'s DataForSEO balance. Call it only after the person has seen the keyword count and cost and said yes. Pass the quote_id and, as confirm_cost_usd, the estimate they agreed to. A quote works once, for 15 minutes, for the person it was made for. The purchase runs in the background: read the results with seo_keyword_metrics a few seconds later.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent(false)]
#[IsOpenWorld]
class SeoKeywordMetricsFetch extends SeoTool
{
    public const NAME = 'seo_keyword_metrics_fetch';

    /** @var array<string, mixed> */
    protected ?array $meta = ['anthropic/requiresUserInteraction' => true];

    public function schema(JsonSchema $schema): array
    {
        return [
            'quote_id' => $schema->string()->description('From seo_keyword_metrics.')->required(),
            'confirm_cost_usd' => $schema->number()->description('The estimate the person agreed to. Must be at least the quote\'s estimate.')->required(),
        ];
    }

    public function handle(Request $request, KeywordMetricsService $metrics, BudgetGuard $budget, DataForSeoClient $client): Response|ResponseFactory
    {
        $user = $this->caller($request);
        if ($user === null) {
            return Response::error(self::NO_USER);
        }

        if (! $client->isConfigured()) {
            return Response::error('DataForSEO is not set up in Control yet. An admin adds the API login and password under SEO on Settings → Integrations.');
        }

        $quoteId = $request->get('quote_id');
        $confirm = $request->get('confirm_cost_usd');
        if (! is_string($quoteId) || $quoteId === '' || ! is_numeric($confirm)) {
            return Response::error('quote_id and confirm_cost_usd are both required.');
        }

        // Look before taking: a wrong caller or a low confirmation must not
        // use the quote up.
        $quote = $metrics->peekQuote($quoteId);
        if ($quote === null) {
            return Response::error('That quote has expired or was already used. Call seo_keyword_metrics again for a new one.');
        }
        if ((int) $quote['user_id'] !== $user->id) {
            return Response::error('That quote was made for someone else. Call seo_keyword_metrics yourself for a new one.');
        }
        if ((float) $confirm + 0.00005 < (float) $quote['estimate_usd']) {
            return Response::error(sprintf('The quote is for about $%.4f, but only $%.4f was confirmed. Show the person the real figure and confirm that.', $quote['estimate_usd'], (float) $confirm));
        }

        // Checked again now: other spending may have happened since the quote.
        $refusal = $budget->refusal(Feature::KEYWORDS, (float) $quote['estimate_usd'], $user);
        if ($refusal !== null) {
            return Response::error($refusal);
        }

        if ($metrics->redeemQuote($quoteId) === null) {
            return Response::error('That quote was just used. Call seo_keyword_metrics again for a new one.');
        }

        FetchKeywordMetricsJob::dispatch($quote['keywords'], $quote['market'], $user->id);

        return Response::structured([
            'summary' => sprintf('Buying metrics for %d keywords (about $%.2f). Read the results with seo_keyword_metrics in a few seconds.', count($quote['keywords']), $quote['estimate_usd']),
            'queued' => true,
            'keywords' => count($quote['keywords']),
            'estimate_usd' => $quote['estimate_usd'],
            'spent_this_month_usd' => round($budget->spentThisMonth(Feature::KEYWORDS), 4),
            'monthly_cap_usd' => $budget->featureCap(Feature::KEYWORDS),
        ]);
    }
}
