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
use Modules\Seo\Audit\IssueRules;
use Modules\Seo\Audit\SiteAuditIssues;
use Modules\Seo\Mcp\Support\ResponseEnvelope;
use Modules\Seo\Mcp\Support\SiteFinder;
use Modules\Seo\Models\SiteAudit;

#[Name('seo_site_audit')]
#[Title('Site audit: technical issues from a crawl')]
#[Description('The technical audit of one site from its latest stored crawl: score, issues sorted worst first (each with severity, area, what to do and pages affected), and what changed since the crawl before (new, fixed, still open). Pass issue to get the pages behind one issue. From stored crawls; never spends money. Errors, warnings and notices count kinds of problem, not pages. Severity is Control\'s judgement. The score is DataForSEO\'s own formula: never compare it with another tool\'s site health. To start a new crawl use seo_site_audit_run.')]
#[IsReadOnly]
#[IsIdempotent]
class SeoSiteAudit extends SeoTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A Control site id or a domain.')->required(),
            'crawl_id' => $schema->integer()->description('An older crawl from the crawls list. Default: the latest finished crawl.'),
            'issue' => $schema->string()->description('An issue key from the issues list, to get the pages it affects.'),
            'severity' => $schema->string()->enum(array_keys(IssueRules::SEVERITIES))->description('Only issues of this severity.'),
            'category' => $schema->string()->enum(array_keys(IssueRules::CATEGORIES))->description('Only issues in this area.'),
            'page_limit' => $schema->integer()->min(1)->max(500)->description('With issue: how many pages to list. Default 100.'),
        ];
    }

    public function handle(Request $request, SiteAuditIssues $issues): Response|ResponseFactory
    {
        if ($this->caller($request) === null) {
            return Response::error(self::NO_USER);
        }

        $profile = SiteFinder::profile($request->get('site'));
        if (is_string($profile)) {
            return Response::error($profile);
        }

        $domain = $profile->site->domain ?? 'Site';
        $all = SiteAudit::query()->where('site_id', $profile->site_id)->orderByDesc('id')->limit(24)->get();
        $crawls = $all->map(fn (SiteAudit $a) => [
            'crawl_id' => $a->id, 'status' => $a->status, 'started' => $a->started_at?->toDateString(), 'pages_crawled' => $a->pages_crawled,
            'score' => $a->score, 'errors' => $a->errors, 'warnings' => $a->warnings, 'notices' => $a->notices, 'problem' => $a->status === SiteAudit::STATUS_FAILED ? $a->error : null,
        ])->all();

        $finished = $all->where('status', SiteAudit::STATUS_FINISHED);
        $audit = $request->get('crawl_id') !== null ? $finished->firstWhere('id', (int) $request->get('crawl_id')) : $finished->first();
        $running = $all->firstWhere('status', SiteAudit::STATUS_CRAWLING);

        if ($request->get('crawl_id') !== null && $audit === null) {
            return Response::error('No finished crawl with that id for this site. Call again without crawl_id to see the crawls.');
        }

        if ($audit === null) {
            return Response::structured(ResponseEnvelope::make(
                summary: "{$domain} has no finished crawl yet.".($running !== null ? ' One started '.$running->started_at?->toDateString().' is still running.' : ' Start one with seo_site_audit_run, or wait for the scheduled crawl.'),
                profile: $profile, period: [], dataState: null, finalAfter: null,
                caveats: [['code' => 'no_crawl', 'message' => 'There is no finished crawl of this site, so nothing can be said about its technical health.']],
                data: ['crawl' => null, 'crawls' => $crawls, 'issues' => []],
            ));
        }

        $caveats = [
            ['code' => 'provider_score', 'message' => 'The score is DataForSEO\'s own formula. It is not comparable with a site health score from another tool and must not share a trend line with one.'],
            ['code' => 'severity_is_ours', 'message' => 'Severity is Control\'s judgement of each check, not the provider\'s. A notice is often intentional; check before calling it a problem.'],
        ];
        if (! $audit->javascript) {
            $caveats[] = ['code' => 'no_javascript', 'message' => 'Pages were crawled without running JavaScript, so content or links a page adds in the browser were not seen.'];
        }
        if ($audit->pages_crawled !== null && $audit->pages_crawled >= $audit->max_pages) {
            $caveats[] = ['code' => 'hit_page_cap', 'message' => "The crawl stopped at its cap of {$audit->max_pages} pages, so part of the site was not checked. Counts are for the pages crawled."];
        }
        if ($running !== null) {
            $caveats[] = ['code' => 'crawl_running', 'message' => 'A newer crawl is running; these are the results of the last finished one.'];
        }

        $crawl = [
            'crawl_id' => $audit->id, 'crawled' => $audit->finished_at?->toDateString(), 'pages_crawled' => $audit->pages_crawled, 'page_cap' => $audit->max_pages,
            'javascript' => $audit->javascript, 'score' => $audit->score, 'errors' => $audit->errors, 'warnings' => $audit->warnings, 'notices' => $audit->notices,
            'site_totals' => $audit->totals ?? [],
        ];

        // One issue, with its pages.
        $issueKey = $request->get('issue');
        if (is_string($issueKey) && $issueKey !== '') {
            $issue = collect($issues->forAudit($audit))->firstWhere('key', $issueKey);
            if ($issue === null) {
                return Response::error("This crawl has no issue \"{$issueKey}\". Call without issue to list the ones it has.");
            }
            $limit = max(1, min(500, (int) $request->get('page_limit', 100)));
            $pages = $issue['scope'] === 'page' ? $issues->pages($audit, $issueKey, $limit) : [];

            return Response::structured(ResponseEnvelope::make(
                summary: "{$domain}: \"{$issue['title']}\" ({$issue['severity']}) ".($issue['scope'] === 'site' ? 'applies to the whole site.' : "affects {$issue['pages']} pages; listing ".count($pages).'.'),
                profile: $profile, period: ['crawled' => $crawl['crawled']], dataState: null, finalAfter: null, caveats: $caveats,
                data: [
                    'crawl' => $crawl,
                    'issue' => $issue,
                    'pages' => array_map(fn (array $p) => ['url' => ResponseEnvelope::clip($p['url'], 500), 'status_code' => $p['status_code'], 'title' => ResponseEnvelope::clip($p['title'], 200)], $pages),
                    'truncated' => $issue['scope'] === 'page' && $issue['pages'] > count($pages),
                ],
            ));
        }

        $previous = $issues->previous($audit);
        $comparison = $previous !== null ? $issues->compare($audit, $previous) : null;
        $changes = collect($comparison['issues'] ?? [])->keyBy('key');

        $list = array_values(array_filter($issues->forAudit($audit), fn (array $i) => (! is_string($request->get('severity')) || $i['severity'] === $request->get('severity'))
            && (! is_string($request->get('category')) || $i['category'] === $request->get('category'))));

        $list = array_map(function (array $issue) use ($changes): array {
            $change = $changes->get($issue['key']);

            return $issue + [
                'new' => $change['new'] ?? null,
                'fixed' => $change['fixed'] ?? null,
                'still_open' => $change['still_open'] ?? null,
            ];
        }, $list);

        if ($comparison !== null && $comparison['note'] !== null) {
            $caveats[] = ['code' => 'crawls_differ', 'message' => $comparison['note']];
        }

        return Response::structured(ResponseEnvelope::make(
            summary: "{$domain}, crawled {$crawl['crawled']}: ".number_format((int) $audit->pages_crawled)." pages, score {$audit->score}, {$audit->errors} kinds of error, {$audit->warnings} of warning, {$audit->notices} notices."
                .($comparison !== null ? " Since {$previous->finished_at?->toDateString()}: {$comparison['totals']['fixed']} fixed, {$comparison['totals']['new']} new, {$comparison['totals']['still_open']} still open." : ' No earlier crawl to compare with.'),
            profile: $profile,
            period: ['crawled' => $crawl['crawled'], 'compared_with' => $previous?->finished_at?->toDateString()],
            dataState: null,
            finalAfter: null,
            caveats: $caveats,
            data: [
                'crawl' => $crawl,
                'change' => $comparison === null ? null : $comparison['totals'] + [
                    'unit' => 'page-and-problem pairs',
                    'cleared' => $changes->filter(fn (array $c) => $c['pages'] === 0 && $c['still_open'] === 0 && $c['fixed'] > 0)->pluck('title')->values()->all(),
                ],
                'issues' => $list,
                'areas' => IssueRules::CATEGORIES,
                'crawls' => $crawls,
            ],
        ));
    }
}
