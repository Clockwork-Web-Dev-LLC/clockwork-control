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
use Modules\Seo\Audit\SiteAuditRunner;
use Modules\Seo\Audit\SiteAuditSettings;
use Modules\Seo\DataForSeo\BudgetGuard;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\DataForSeo\Feature;
use Modules\Seo\Mcp\Support\ResponseEnvelope;
use Modules\Seo\Mcp\Support\SiteFinder;
use Modules\Seo\Models\SiteAudit;

#[Name('seo_site_audit_run')]
#[Title('Start a site crawl')]
#[Description('Spends money. Starts a crawl of one site on DataForSEO, paid by the page up to the page cap set in Control (500 pages and about 8 cents by default). Call it first WITHOUT confirm_cost_usd to get the most it can cost and spend nothing; tell the person; call again with confirm_cost_usd only after they agree. A crawl takes from minutes to a few hours; Control collects it by itself and seo_site_audit shows it when it is done. Sites are already crawled on a schedule, so only start one after fixes went live or when the person asks.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld]
class SeoSiteAuditRun extends SeoTool
{
    public const NAME = 'seo_site_audit_run';

    /** @var array<string, mixed> */
    protected ?array $meta = ['anthropic/requiresUserInteraction' => true];

    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A Control site id or a domain.')->required(),
            'confirm_cost_usd' => $schema->number()->description('The most the person agreed to spend. Leave out to get the price only.'),
        ];
    }

    public function handle(Request $request, SiteAuditRunner $runner, SiteAuditSettings $settings, BudgetGuard $budget, DataForSeoClient $client): Response|ResponseFactory
    {
        $user = $this->caller($request);
        if ($user === null) {
            return Response::error(self::NO_USER);
        }

        $profile = SiteFinder::profile($request->get('site'));
        if (is_string($profile)) {
            return Response::error($profile);
        }

        $domain = (string) $profile->site->domain;
        $estimate = $settings->crawlCost();
        $refusal = $budget->refusal(Feature::SITE_AUDIT, $estimate, $user);
        $running = SiteAudit::query()->where('site_id', $profile->site_id)->where('status', SiteAudit::STATUS_CRAWLING)->first();
        $last = SiteAudit::query()->where('site_id', $profile->site_id)->where('status', SiteAudit::STATUS_FINISHED)->orderByDesc('id')->first();
        $confirm = $request->get('confirm_cost_usd');

        if ($confirm === null) {
            return Response::structured([
                'summary' => sprintf('Not started. A crawl of %s would check up to %s pages%s and cost at most $%.3f.', $domain, number_format($settings->maxPages()), $settings->javascript() ? ' with JavaScript' : '', $estimate)
                    .($running !== null ? ' A crawl of this site is already running, so a new one would be refused.' : '')
                    .($refusal !== null ? ' It would be refused right now: '.$refusal : '')
                    .($last !== null ? ' The last finished crawl was '.$last->finished_at?->toDateString().'.' : ' This site has never been crawled.'),
                'started' => false,
                'site' => ResponseEnvelope::site($profile),
                'page_cap' => $settings->maxPages(),
                'javascript' => $settings->javascript(),
                'max_cost_usd' => $estimate,
                'already_running' => $running !== null,
                'would_be_refused' => $refusal,
                'last_crawl' => $last?->finished_at?->toDateString(),
                'scheduled' => $settings->frequency(),
                'price_note' => 'You pay for the pages actually crawled, so a small site costs less. From DataForSEO\'s published prices; not yet confirmed by a real crawl.',
            ]);
        }

        if (! is_numeric($confirm) || (float) $confirm + 0.00001 < $estimate) {
            return Response::error(sprintf('confirm_cost_usd must be at least the most the crawl can cost, $%.3f. Nothing was started.', $estimate));
        }
        if (! $client->isConfigured()) {
            return Response::error('DataForSEO is not set up in Control yet. An admin adds the API login and password under SEO on Settings → Integrations.');
        }
        if ($refusal !== null) {
            return Response::error($refusal.' Nothing was started.');
        }

        $result = $runner->start($profile->site, $user);
        if (! $result['ok']) {
            return Response::error($result['error'].' Nothing was started.');
        }

        return Response::structured([
            'summary' => sprintf('Started a crawl of %s, up to %s pages. It will take from minutes to a few hours; read it with seo_site_audit once it has finished.', $domain, number_format($result['audit']->max_pages)),
            'started' => true,
            'site' => ResponseEnvelope::site($profile),
            'crawl_id' => $result['audit']->id,
            'page_cap' => $result['audit']->max_pages,
            'charged_so_far_usd' => $result['audit']->cost_usd,
            'spent_this_month_usd' => round($budget->spentThisMonth(), 4),
        ]);
    }
}
