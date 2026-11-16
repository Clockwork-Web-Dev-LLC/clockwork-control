<?php

namespace Modules\Seo\Mcp\Tools;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Seo\Mcp\Support\ResponseEnvelope;
use Modules\Seo\Mcp\Support\SiteFinder;
use Modules\Seo\Services\SearchNumbers;
use Modules\Seo\Services\SearchRows;

#[Name('seo_month_numbers')]
#[Title('SEO numbers for one month')]
#[Description('Every monthly report number for one site and one month: Search Console clicks, impressions, CTR, position and non-branded figures, organic keywords, GA4 organic sessions, engagement, AI Assistant sessions and key events, and AI impressions. Each comes with the previous month and the same month last year. Add "include" sections for top pages, top queries, a monthly trend or GA4 channels. A null comparison was suppressed on purpose; read the caveats.')]
#[IsReadOnly]
#[IsIdempotent]
class SeoMonthNumbers extends SeoTool
{
    private const SECTIONS = ['top_pages', 'top_queries', 'trend', 'channels'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A Control site id or a domain.')->required(),
            'month' => $schema->string()->pattern('^\d{4}-\d{2}$')->description('YYYY-MM. Default: the last complete calendar month, which can still be provisional.'),
            'include' => $schema->array()->items($schema->string()->enum(self::SECTIONS))->description('Extra sections to return.'),
            'top_n' => $schema->integer()->min(1)->max(25)->description('Rows in top_pages and top_queries. Default 10.'),
            'trend_months' => $schema->integer()->min(2)->max(16)->description('Months in the trend, ending with this month. Default 13.'),
        ];
    }

    public function handle(Request $request, SearchNumbers $numbers, SearchRows $rows): Response|ResponseFactory
    {
        if ($this->caller($request) === null) {
            return Response::error(self::NO_USER);
        }

        $profile = SiteFinder::profile($request->get('site'));
        if (is_string($profile)) {
            return Response::error($profile);
        }

        $month = $this->lastCompleteMonth();
        if (filled($request->get('month'))) {
            $month = $this->parseMonth($request->get('month'));
            if ($month === null) {
                return Response::error('month must look like 2026-10.');
            }
        }

        $result = $numbers->month($profile, $month);
        $include = $this->pick($request->get('include'), self::SECTIONS, []);
        $topN = max(1, min(25, (int) ($request->get('top_n') ?? 10)));
        $trendMonths = max(2, min(16, (int) ($request->get('trend_months') ?? 13)));

        $sections = [];
        if (in_array('top_pages', $include, true)) {
            $sections['top_pages'] = $rows->top($profile->site_id, 'page', $month, $topN);
        }
        if (in_array('top_queries', $include, true)) {
            $sections['top_queries'] = $rows->top($profile->site_id, 'query', $month, $topN);
        }
        if (in_array('trend', $include, true)) {
            $sections['trend'] = $numbers->trend($profile, $month, $trendMonths);
        }
        if (in_array('channels', $include, true)) {
            $sections['channels'] = $numbers->channels($profile, $month);
        }

        return Response::structured(ResponseEnvelope::make(
            summary: $this->summary($result),
            profile: $profile,
            period: [
                'month' => $result['month'],
                'previous_month' => $result['previous_month'],
                'yoy_month' => $result['yoy_month'],
            ],
            dataState: $result['data_state'],
            finalAfter: $result['final_after'],
            caveats: $result['caveats'],
            data: [
                'compare_yoy' => $result['compare_yoy'],
                'report_notes' => ResponseEnvelope::clip($profile->report_notes, 2000),
                // The same arrays the /seo/sites/{site} page shows.
                'gsc' => $result['gsc'],
                'ga4' => $result['ga4'],
                'ai' => $result['ai'],
                'annotations' => $result['annotations'],
            ] + $sections,
        ));
    }

    /** @param  array<string, mixed>  $result */
    private function summary(array $result): string
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $result['month'])->format('F Y');
        $domain = $result['site']['domain'] ?? 'this site';

        $state = match ($result['data_state']) {
            'final' => 'final',
            'provisional' => "PROVISIONAL until {$result['final_after']}",
            default => 'NOT SYNCED: no stored data for this month',
        };

        $clicks = $result['gsc']['clicks']['value'];

        return "{$domain}, {$month}: {$state}."
            .($clicks !== null ? ' '.number_format($clicks).' clicks.' : '')
            .($result['ai']['status'] === 'missing' ? ' AI impressions not recorded.' : '');
    }
}
