<?php

namespace Modules\Seo\Mcp\Tools;

use App\Models\SiteUptimeEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
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
use Modules\Seo\Models\Annotation;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Services\SearchNumbers;
use Modules\Seo\Support\GscDates;
use Throwable;

#[Name('seo_daily_series')]
#[Title('Daily SEO series')]
#[Description('Day-by-day Search Console and GA4 figures for one site, for spotting dips and their dates. Overlays list site outages and known Google changes in the same range. The last 5 days can still change. Never add GA4 days up into a month figure: use seo_month_numbers for that.')]
#[IsReadOnly]
#[IsIdempotent]
class SeoDailySeries extends SeoTool
{
    private const SERIES = ['clicks', 'impressions', 'ctr', 'position', 'organic_sessions', 'ai_assistant_sessions'];

    private const OVERLAYS = ['outages', 'annotations'];

    private const MAX_DAYS = 490;

    private const MAY_CHANGE_DAYS = 5;

    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A Control site id or a domain.')->required(),
            'start' => $schema->string()->format('date')->description('YYYY-MM-DD. Default: 90 days before end.'),
            'end' => $schema->string()->format('date')->description('YYYY-MM-DD. Default: the latest stored day.'),
            'series' => $schema->array()->items($schema->string()->enum(self::SERIES))->description('Default: clicks and impressions.'),
            'segment' => $schema->string()->enum(['all', 'nonbranded'])->description('Search Console segment. Default all.'),
            'overlays' => $schema->array()->items($schema->string()->enum(self::OVERLAYS))->description('Default: both.'),
            'yoy' => $schema->boolean()->description('Add each series for the same date a year earlier. Default false.'),
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

        $series = $this->pick($request->get('series'), self::SERIES, ['clicks', 'impressions']);
        if ($series === []) {
            return Response::error('series must name at least one of: '.implode(', ', self::SERIES).'.');
        }
        $segment = $request->get('segment') === 'nonbranded' ? 'nonbranded' : 'all';
        $overlays = $this->pick($request->get('overlays'), self::OVERLAYS, self::OVERLAYS);
        $yoy = (bool) $request->get('yoy', false);

        $latest = DB::table('seo_gsc_daily')->where('site_id', $profile->site_id)->where('segment', 'all')->max('date')
            ?? DB::table('seo_ga4_daily')->where('site_id', $profile->site_id)->max('date');

        try {
            $end = filled($request->get('end'))
                ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $request->get('end'))
                : ($latest !== null ? CarbonImmutable::parse(substr((string) $latest, 0, 10)) : GscDates::yesterday());
            $start = filled($request->get('start'))
                ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $request->get('start'))
                : $end->subDays(90);
        } catch (Throwable) {
            return Response::error('start and end must look like 2026-10-31.');
        }

        $end = CarbonImmutable::parse($end->toDateString());
        $start = CarbonImmutable::parse($start->toDateString());

        if ($start->gt($end)) {
            return Response::error('start must be on or before end.');
        }
        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            return Response::error('Ask for '.self::MAX_DAYS.' days at most.');
        }

        $values = $this->load($profile, $start, $end, $segment);
        $lastYear = $yoy ? $this->load($profile, $start->subYear(), $end->subYear(), $segment) : [];
        $mayChangeFrom = GscDates::today()->subDays(self::MAY_CHANGE_DAYS)->toDateString();

        $columns = ['date', ...$series];
        if ($yoy) {
            array_push($columns, ...array_map(fn (string $s) => $s.'_yoy', $series));
        }
        $columns[] = 'may_change';

        $rows = [];
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $row = [$date];
            foreach ($series as $name) {
                $row[] = $values[$date][$name] ?? null;
            }
            if ($yoy) {
                $before = $day->subYear()->toDateString();
                foreach ($series as $name) {
                    $row[] = $lastYear[$before][$name] ?? null;
                }
            }
            $row[] = $date >= $mayChangeFrom;
            $rows[] = $row;
        }

        $caveats = [];
        if ($segment === 'nonbranded') {
            $caveats[] = $profile->brand_regex === null
                ? ['code' => 'no_brand_terms', 'message' => 'No brand terms are set, so there are no non-branded figures.']
                : ['code' => 'nonbranded_known_queries_only', 'message' => 'Non-branded figures cover known queries only: Google never lists anonymized queries.'];
        }
        if (array_intersect($series, ['organic_sessions', 'ai_assistant_sessions']) !== []) {
            $caveats[] = ['code' => 'ga4_days_do_not_sum', 'message' => 'GA4 estimates sessions, so these days do not add up to GA4\'s month figure. Use seo_month_numbers for months.'];
        }
        if (in_array('ai_assistant_sessions', $series, true) && $start->toDateString() < SearchNumbers::AI_CHANNEL_SINCE) {
            $caveats[] = ['code' => 'ai_sessions_estimate', 'message' => 'AI assistant sessions before '.SearchNumbers::AI_CHANNEL_SINCE.' are an estimate from referral sources.'];
        }

        $data = ['columns' => $columns, 'rows' => $rows];
        if (in_array('annotations', $overlays, true)) {
            $data['annotations'] = Annotation::query()->forSite($profile->site_id)
                ->where('starts_on', '<=', $end->toDateString())
                ->where('ends_on', '>=', $start->toDateString())
                ->orderBy('starts_on')
                ->get()
                ->map(fn (Annotation $a) => [
                    'starts_on' => $a->starts_on->toDateString(),
                    'ends_on' => $a->ends_on->toDateString(),
                    'kind' => $a->kind,
                    'label' => $a->label,
                ])->all();
        }
        if (in_array('outages', $overlays, true)) {
            $data['outages'] = $this->outages($profile->site_id, $start, $end);
        }

        return Response::structured(ResponseEnvelope::make(
            summary: ($profile->site->domain ?? 'Site').": {$start->toDateString()} to {$end->toDateString()}, ".count($rows).' days. Days from '.$mayChangeFrom.' on can still change.',
            profile: $profile,
            period: ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'segment' => $segment],
            dataState: null,
            finalAfter: null,
            caveats: $caveats,
            data: $data,
        ));
    }

    /**
     * @return array<string, array<string, int|float|null>> keyed by date
     */
    private function load(SiteProfile $profile, CarbonImmutable $start, CarbonImmutable $end, string $segment): array
    {
        $range = [$start->toDateString(), $end->toDateString()];
        $out = [];

        $gsc = DB::table('seo_gsc_daily')
            ->where('site_id', $profile->site_id)
            ->where('segment', $segment)
            ->whereBetween('date', $range)
            ->get();

        foreach ($gsc as $row) {
            $date = substr((string) $row->date, 0, 10);
            $impressions = (int) $row->impressions;
            $out[$date]['clicks'] = (int) $row->clicks;
            $out[$date]['impressions'] = $impressions;
            $out[$date]['ctr'] = $impressions > 0 ? round((int) $row->clicks / $impressions * 100, 2) : null;
            $out[$date]['position'] = $impressions > 0 ? round((float) $row->position, 1) : null;
        }

        $ga4 = DB::table('seo_ga4_daily')
            ->where('site_id', $profile->site_id)
            ->whereBetween('date', $range)
            ->get();

        // A day with any GA4 row was synced, so a channel with no row that
        // day is a real zero.
        foreach ($ga4 as $row) {
            $date = substr((string) $row->date, 0, 10);
            $out[$date]['organic_sessions'] ??= 0;
            $out[$date]['ai_assistant_sessions'] ??= 0;
        }

        foreach ($ga4 as $row) {
            $date = substr((string) $row->date, 0, 10);
            $beforeChannel = $date < SearchNumbers::AI_CHANNEL_SINCE;

            if ($row->dimension === 'channel' && $row->value === SearchNumbers::ORGANIC_CHANNEL) {
                $out[$date]['organic_sessions'] += (int) $row->sessions;
            }
            if ($row->dimension === 'channel' && ! $beforeChannel && in_array($row->value, SearchNumbers::AI_CHANNELS, true)) {
                $out[$date]['ai_assistant_sessions'] += (int) $row->sessions;
            }
            if ($row->dimension === 'ai_source' && $beforeChannel) {
                $out[$date]['ai_assistant_sessions'] += (int) $row->sessions;
            }
        }

        return $out;
    }

    /**
     * Down-to-up spans from the uptime monitor. Times only: the stored
     * diagnosis can hold server detail and never leaves Control.
     *
     * @return list<array{down_at: string, up_at: ?string}>
     */
    private function outages(int $siteId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $events = SiteUptimeEvent::query()
            ->where('site_id', $siteId)
            ->whereIn('event_type', ['down', 'up'])
            ->whereBetween('event_at', [$start->startOfDay(), $end->endOfDay()])
            ->orderBy('event_at')
            ->limit(400)
            ->get(['event_type', 'event_at']);

        $outages = [];
        $downAt = null;

        foreach ($events as $event) {
            if ($event->event_type === 'down') {
                $downAt ??= $event->event_at;
            } elseif ($downAt !== null) {
                $outages[] = ['down_at' => $downAt->toIso8601String(), 'up_at' => $event->event_at->toIso8601String()];
                $downAt = null;
            }
        }
        if ($downAt !== null) {
            $outages[] = ['down_at' => $downAt->toIso8601String(), 'up_at' => null];
        }

        return array_slice($outages, -50);
    }
}
