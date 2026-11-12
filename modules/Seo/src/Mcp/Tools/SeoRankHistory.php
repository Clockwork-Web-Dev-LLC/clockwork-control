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
use Modules\Seo\Keywords\KeywordMetricsService;
use Modules\Seo\Mcp\Support\ResponseEnvelope;
use Modules\Seo\Mcp\Support\SiteFinder;
use Modules\Seo\Models\RankCheck;
use Modules\Seo\Models\TrackedKeyword;
use Throwable;

#[Name('seo_rank_history')]
#[Title('Rank history for tracked keywords')]
#[Description('Every stored Google rank check for up to 20 of a site\'s tracked keywords over a date range: date, position, ranking page, competitor positions and AI Overview. From stored checks; never spends money. Days without a row were not checked. A row with a null position was checked and the site was not within the depth looked at.')]
#[IsReadOnly]
#[IsIdempotent]
class SeoRankHistory extends SeoTool
{
    private const MAX_KEYWORDS = 20;

    private const MAX_DAYS = 800;

    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A Control site id or a domain.')->required(),
            'keywords' => $schema->array()->min(1)->max(self::MAX_KEYWORDS)->items($schema->string())
                ->description('Tracked keywords, as text or as keyword_id from seo_rankings. Up to 20.')->required(),
            'device' => $schema->string()->enum(['desktop', 'mobile'])->description('Default: both.'),
            'start' => $schema->string()->format('date')->description('YYYY-MM-DD. Default: 90 days before end.'),
            'end' => $schema->string()->format('date')->description('YYYY-MM-DD. Default: today.'),
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

        $asked = $request->get('keywords');
        if (! is_array($asked) || $asked === [] || count($asked) > self::MAX_KEYWORDS) {
            return Response::error('keywords must list 1 to '.self::MAX_KEYWORDS.' tracked keywords.');
        }

        try {
            $end = filled($request->get('end')) ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $request->get('end')) : CarbonImmutable::now('UTC')->startOfDay();
            $start = filled($request->get('start')) ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $request->get('start')) : $end->subDays(90);
        } catch (Throwable) {
            return Response::error('start and end must look like 2026-10-31.');
        }
        if ($start->gt($end)) {
            return Response::error('start must be on or before end.');
        }
        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            return Response::error('Ask for '.self::MAX_DAYS.' days at most.');
        }

        $ids = [];
        $texts = [];
        foreach ($asked as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $ids[] = (int) $value;
            } elseif (is_string($value) && trim($value) !== '') {
                $texts[] = KeywordMetricsService::normalize($value);
            }
        }

        $device = in_array($request->get('device'), ['desktop', 'mobile'], true) ? (string) $request->get('device') : null;

        $tracked = TrackedKeyword::query()
            ->where('site_id', $profile->site_id)
            ->where(fn ($q) => $q->whereIn('id', $ids)->orWhereIn('keyword', $texts))
            ->when($device !== null, fn ($q) => $q->where('device', $device))
            ->orderBy('keyword')->orderBy('device')
            ->limit(self::MAX_KEYWORDS * 4)
            ->get();

        $found = $tracked->pluck('keyword')->all();
        $notTracked = array_values(array_filter($texts, fn (string $t) => ! in_array($t, $found, true)));

        $checks = RankCheck::query()
            ->whereIn('tracked_keyword_id', $tracked->pluck('id'))
            ->whereBetween('checked_on', [$start->toDateString(), $end->toDateString()])
            ->orderBy('checked_on')
            ->get()
            ->groupBy('tracked_keyword_id');

        $competitors = array_values(array_filter((array) ($profile->competitors ?? []), 'is_string'));
        $columns = ['date', 'position', 'url', 'has_ai_overview', 'cited_in_ai_overview', ...array_map(fn (string $c) => "competitor:{$c}", $competitors)];

        $series = [];
        foreach ($tracked as $keyword) {
            $history = $checks->get($keyword->id, collect());
            $positions = $history->pluck('position')->filter();

            $series[] = [
                'keyword_id' => $keyword->id,
                'keyword' => $keyword->keyword,
                'device' => $keyword->device,
                'place' => $keyword->location_name,
                'is_active' => $keyword->is_active,
                'checks' => $history->count(),
                'best_position' => $positions->min(),
                'worst_position' => $positions->max(),
                'rows' => $history->map(fn (RankCheck $c) => [
                    $c->checked_on->toDateString(),
                    $c->position,
                    ResponseEnvelope::clip($c->url, 300),
                    $c->has_ai_overview,
                    $c->cited_in_ai_overview,
                    ...array_map(fn (string $domain) => $c->competitors[$domain] ?? null, $competitors),
                ])->values()->all(),
            ];
        }

        $caveats = [
            ['code' => 'gaps_are_unchecked', 'message' => 'A missing date was not checked; it is not a drop. A null position was checked and not found within the depth looked at.'],
            ['code' => 'one_check_one_place', 'message' => 'Each position is one check from one place on one device, so small day-to-day moves are noise.'],
        ];
        if ($notTracked !== []) {
            $caveats[] = ['code' => 'not_tracked', 'message' => 'Not tracked for this site, so there is no history: '.implode(', ', array_slice($notTracked, 0, 20)).'.'];
        }

        return Response::structured(ResponseEnvelope::make(
            summary: ($profile->site->domain ?? 'Site').': rank history for '.count($series).' tracked '.(count($series) === 1 ? 'keyword' : 'keywords')." from {$start->toDateString()} to {$end->toDateString()}".($notTracked !== [] ? '; '.count($notTracked).' asked for are not tracked.' : '.'),
            profile: $profile,
            period: ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            dataState: null,
            finalAfter: null,
            caveats: $caveats,
            data: ['columns' => $columns, 'keywords' => $series, 'not_tracked' => $notTracked],
        ));
    }
}
