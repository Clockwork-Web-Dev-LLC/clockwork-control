<?php

namespace Modules\Seo\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Seo\Mcp\Support\AuditLog;
use Modules\Seo\Mcp\Support\ResponseEnvelope;
use Modules\Seo\Mcp\Support\SiteFinder;
use Modules\Seo\Models\AiImpressionEntry;

#[Name('seo_ai_impressions_record')]
#[Title('Record AI impressions for a month')]
#[Description('Store the month\'s total from Search Console\'s Generative AI report for one site, or mark the report "not_available" for that site. Google has no API for this report, so read the number in Search Console first. An existing different value is only replaced with overwrite set to true.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
class SeoAiImpressionsRecord extends SeoTool
{
    public const NAME = 'seo_ai_impressions_record';

    /** Search Console's Generative AI data starts 2026-05-18. */
    private const FIRST_MONTH = '2026-05';

    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A Control site id or a domain.')->required(),
            'month' => $schema->string()->pattern('^\d{4}-\d{2}$')->description('YYYY-MM. A finished month, May 2026 or later.')->required(),
            'status' => $schema->string()->enum([AiImpressionEntry::STATUS_ENTERED, AiImpressionEntry::STATUS_NOT_AVAILABLE])
                ->description('entered with a number, or not_available when Search Console shows no Generative AI report for this site.')->required(),
            'ai_impressions' => $schema->integer()->min(0)->description('The month total. Required when status is entered.'),
            'top_pages' => $schema->array()->max(10)->items($schema->object([
                'url' => $schema->string()->required(),
                'impressions' => $schema->integer()->min(0)->required(),
            ]))->description('Up to 10 top pages from the report.'),
            'overwrite' => $schema->boolean()->description('Replace a different value already stored. Default false.'),
            'note' => $schema->string()->max(200)->description('Optional note, 200 characters at most.'),
        ];
    }

    public function handle(Request $request, AuditLog $audit): Response|ResponseFactory
    {
        $user = $this->caller($request);
        if ($user === null) {
            return Response::error(self::NO_USER);
        }

        $profile = SiteFinder::profile($request->get('site'));
        if (is_string($profile)) {
            return Response::error($profile);
        }

        $month = $this->parseMonth($request->get('month'));
        if ($month === null) {
            return Response::error('month must look like 2026-10.');
        }
        if ($month->format('Y-m') < self::FIRST_MONTH) {
            return Response::error('Search Console has no Generative AI data before May 2026.');
        }
        if ($month->gt($this->lastCompleteMonth())) {
            return Response::error('Record a month once it is over. The latest month that can be recorded is '.$this->lastCompleteMonth()->format('Y-m').'.');
        }

        $status = (string) $request->get('status');
        if (! in_array($status, [AiImpressionEntry::STATUS_ENTERED, AiImpressionEntry::STATUS_NOT_AVAILABLE], true)) {
            return Response::error('status must be entered or not_available.');
        }

        $entered = $status === AiImpressionEntry::STATUS_ENTERED;
        $impressions = $request->get('ai_impressions');
        if ($entered && (! is_int($impressions) || $impressions < 0 || $impressions > 4_000_000_000)) {
            return Response::error('ai_impressions must be a whole number of 0 or more when status is entered.');
        }
        // "not available" stores no number, so it can never read as 0.
        $impressions = $entered ? (int) $impressions : null;

        $note = $request->get('note');
        if ($note !== null && (! is_string($note) || mb_strlen($note) > 200)) {
            return Response::error('note must be text of 200 characters at most.');
        }

        $topPages = $this->topPages($request->get('top_pages'));
        if (is_string($topPages)) {
            return Response::error($topPages);
        }
        $topPages = $entered ? $topPages : [];

        $existing = AiImpressionEntry::query()
            ->where('site_id', $profile->site_id)
            ->where('month', $month->toDateString())
            ->first();

        $before = $existing === null ? null : [
            'status' => $existing->status,
            'ai_impressions' => $existing->ai_impressions,
            'source' => $existing->source,
        ];

        $differs = $existing !== null && ($existing->status !== $status || $existing->ai_impressions !== $impressions);
        if ($differs && ! $request->get('overwrite', false)) {
            $stored = $existing->status === AiImpressionEntry::STATUS_ENTERED
                ? number_format((int) $existing->ai_impressions).' AI impressions'
                : 'not available';

            return Response::error("{$month->format('Y-m')} already has a different value for {$profile->site->domain}: {$stored} (source {$existing->source}). Check which is right, then call again with overwrite set to true to replace it.");
        }

        AiImpressionEntry::query()->updateOrCreate(
            ['site_id' => $profile->site_id, 'month' => $month->toDateString()],
            [
                'status' => $status,
                'ai_impressions' => $impressions,
                'top_pages' => $topPages === [] ? null : $topPages,
                'source' => 'mcp',
                'note' => $note,
                'entered_by_user_id' => $user->id,
            ],
        );

        $webImpressions = DB::table('seo_gsc_daily')
            ->where('site_id', $profile->site_id)
            ->where('segment', 'all')
            ->whereBetween('date', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->sum('impressions');
        $webImpressions = (int) $webImpressions;

        $warnings = [];
        if ($entered && $webImpressions > 0 && $impressions > $webImpressions) {
            $warnings[] = 'AI impressions ('.number_format($impressions).') are higher than the month\'s web impressions ('.number_format($webImpressions).'). Check the number was read from the right report and month.';
        }
        $domain = SiteFinder::normalizeDomain((string) $profile->site->domain);
        foreach ($topPages as $page) {
            if (SiteFinder::normalizeDomain($page['url']) !== $domain) {
                $warnings[] = "Top page {$page['url']} is not on {$domain}.";
            }
        }

        $audit->record(
            AuditLog::AI_IMPRESSIONS_RECORDED,
            $user,
            self::NAME,
            $profile->site->domain,
            "Recorded AI impressions for {$profile->site->domain}, {$month->format('Y-m')}: ".($entered ? number_format($impressions) : 'not available').'.',
            ['ai_impressions' => [
                'before' => $before,
                'after' => ['status' => $status, 'ai_impressions' => $impressions, 'source' => 'mcp', 'month' => $month->format('Y-m')],
            ]],
        );

        return Response::structured([
            'summary' => "Saved {$month->format('Y-m')} for {$profile->site->domain}: "
                .($entered ? number_format($impressions).' AI impressions' : 'report not available')
                .($before !== null ? ' (replaced an earlier entry)' : '').'.'
                .($warnings !== [] ? ' '.count($warnings).' warning(s).' : ''),
            'site' => ResponseEnvelope::site($profile),
            'month' => $month->format('Y-m'),
            'status' => $status,
            'ai_impressions' => $impressions,
            'ai_share_pct' => $entered && $webImpressions > 0 ? round($impressions / $webImpressions * 100, 2) : null,
            'web_impressions' => $webImpressions > 0 ? $webImpressions : null,
            'top_pages' => $topPages,
            'previous' => $before,
            'warnings' => $warnings,
        ]);
    }

    /**
     * @return list<array{url: string, impressions: int}>|string the pages, or what is wrong with them
     */
    private function topPages(mixed $input): array|string
    {
        if ($input === null) {
            return [];
        }
        if (! is_array($input) || count($input) > 10) {
            return 'top_pages must be a list of 10 pages at most.';
        }

        $pages = [];
        foreach ($input as $page) {
            $url = is_array($page) ? ($page['url'] ?? null) : null;
            $impressions = is_array($page) ? ($page['impressions'] ?? null) : null;

            if (! is_string($url) || $url === '' || mb_strlen($url) > 2048 || ! is_int($impressions) || $impressions < 0) {
                return 'Each top page needs a url and a whole number of impressions.';
            }

            $pages[] = ['url' => (string) ResponseEnvelope::clip($url, 2048), 'impressions' => $impressions];
        }

        return $pages;
    }
}
