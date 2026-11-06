<?php

namespace Modules\Seo\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Seo\Models\AiImpressionEntry;
use Modules\Seo\Models\Annotation;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Support\SeoSettings;

/**
 * One site's report numbers for one month, compared with the previous month
 * and the same month last year, from stored rows only. The site page and
 * Claude's tools present the same arrays.
 *
 * Rules the monthly report always followed by hand:
 * - A comparison whose baseline starts on or before a suppressing annotation
 *   and whose current period ends after it is not comparable: position is
 *   hidden (null), impressions, CTR and non-branded figures get a caveat.
 *   That encodes "never compare position against Sept 2025 or earlier".
 * - GSC month totals are summed from daily site totals (clicks and
 *   impressions sum exactly; position is impression-weighted, as GSC does).
 * - GA4 month figures come only from the month tables, never summed days.
 * - A month is provisional until month end + final_after_days.
 * - Something unavailable is null with a status, never 0.
 */
class SearchNumbers
{
    public const AI_CHANNEL_SINCE = '2026-05-13';

    public const ORGANIC_CHANNEL = 'Organic Search';

    /** Sources disagree on the label. */
    public const AI_CHANNELS = ['AI Assistant', 'AI Assistants'];

    public function __construct(private readonly SeoSettings $settings) {}

    /** @return array<string, mixed> */
    public function month(SiteProfile $profile, CarbonImmutable $month): array
    {
        $month = $month->startOfMonth();
        $periods = [
            'current' => $month,
            'previous' => $month->subMonthNoOverflow(),
            'yoy' => $month->subYear(),
        ];

        $annotations = Annotation::query()->forSite($profile->site_id)
            ->where('starts_on', '<=', $month->endOfMonth()->toDateString())
            ->orderBy('starts_on')->get();

        $gsc = array_map(fn (CarbonImmutable $m) => $this->gscMonth($profile, $m), $periods);
        $ga4 = array_map(fn (CarbonImmutable $m) => $this->ga4Month($profile, $m), $periods);
        $ai = $this->aiImpressions($profile, $month, $gsc['current']['impressions']);

        $caveats = [];
        $suppressed = [];
        foreach (['previous', 'yoy'] as $baseline) {
            foreach ($annotations as $annotation) {
                if (! $this->straddles($annotation, $periods[$baseline], $month)) {
                    continue;
                }
                foreach ((array) $annotation->suppresses as $what) {
                    $suppressed[$baseline][$what] = true;
                    $caveats[] = $this->caveat($what.'_'.$baseline, $annotation);
                }
            }
        }

        $metric = function (string $source, string $key, ?string $suppressKey = null) use ($gsc, $ga4, $suppressed): array {
            $rows = $source === 'gsc' ? $gsc : $ga4;
            $value = $rows['current'][$key];
            $out = ['value' => $value];
            foreach (['previous', 'yoy'] as $baseline) {
                $base = $rows[$baseline][$key];
                $hidden = $suppressKey !== null && ! empty($suppressed[$baseline][$suppressKey]);
                $out[$baseline] = $base;
                $out[$baseline.'_comparable'] = ! $hidden;
                $out[$baseline.'_change'] = $hidden || $value === null || $base === null ? null : round($value - $base, 4);
                $out[$baseline.'_change_pct'] = $hidden || $value === null || $base === null || (float) $base === 0.0
                    ? null : round(($value - $base) / $base * 100, 1);
            }

            return $out;
        };

        $keyEvents = [];
        foreach ($profile->keyEvents() as $event) {
            $keyEvents[$event] = $metric('ga4', 'event:'.$event);
        }

        $state = $this->dataState($profile, $month);
        if ($state === 'provisional') {
            $caveats[] = ['code' => 'provisional', 'message' => 'This month is provisional: Google keeps filling in late data until '.$this->finalAfter($month).'.'];
        }
        if ($profile->brand_regex === null) {
            $caveats[] = ['code' => 'no_brand_terms', 'message' => 'No brand terms are set, so there is no non-branded figure.'];
        } else {
            $caveats[] = ['code' => 'nonbranded_known_queries_only', 'message' => 'Non-branded figures cover known queries only: Google never lists anonymized queries.'];
        }
        if ($ga4['current']['thresholded']) {
            $caveats[] = ['code' => 'ga4_thresholded', 'message' => 'GA4 may have withheld small numbers this month (thresholding), often key events.'];
        }
        if ($ga4['current']['ai_is_estimate']) {
            $caveats[] = ['code' => 'ai_sessions_estimate', 'message' => 'AI assistant sessions before '.self::AI_CHANNEL_SINCE.' are an estimate from referral sources.'];
        }
        if ($gsc['current']['possibly_truncated']) {
            $caveats[] = ['code' => 'gsc_rows_truncated', 'message' => 'Search Console returned its row limit for this month, so page and query lists may be cut short.'];
        }
        $caveats[] = ['code' => 'organic_keywords_gsc_queries', 'message' => 'Organic keywords = distinct Search Console queries with impressions, not a ranking tool\'s count.'];

        return [
            'site' => ['id' => $profile->site_id, 'domain' => $profile->site->domain ?? null],
            'month' => $month->format('Y-m'),
            'previous_month' => $periods['previous']->format('Y-m'),
            'yoy_month' => $periods['yoy']->format('Y-m'),
            'compare_yoy' => $profile->compare_yoy,
            'data_state' => $state,
            'final_after' => $this->finalAfter($month),
            'gsc' => [
                'clicks' => $metric('gsc', 'clicks'),
                'impressions' => $metric('gsc', 'impressions'),
                'ctr' => $metric('gsc', 'ctr'),
                'position' => $metric('gsc', 'position', 'position'),
                'nonbranded_clicks' => $metric('gsc', 'nonbranded_clicks'),
                'nonbranded_impressions' => $metric('gsc', 'nonbranded_impressions'),
                'organic_keywords' => $metric('gsc', 'organic_keywords'),
            ],
            'ga4' => [
                'organic_sessions' => $metric('ga4', 'organic_sessions'),
                'organic_engaged_sessions' => $metric('ga4', 'organic_engaged_sessions'),
                'organic_engagement_rate' => $metric('ga4', 'organic_engagement_rate'),
                'ai_assistant_sessions' => $metric('ga4', 'ai_sessions'),
                'key_events' => $keyEvents,
            ],
            'ai' => $ai,
            'caveats' => array_values(array_unique($caveats, SORT_REGULAR)),
            'annotations' => $annotations->map(fn (Annotation $a) => [
                'starts_on' => $a->starts_on->toDateString(),
                'ends_on' => $a->ends_on->toDateString(),
                'label' => $a->label,
            ])->values()->all(),
        ];
    }

    /** Months with any stored GSC or GA4 data, newest first. @return list<string> Y-m */
    public function availableMonths(SiteProfile $profile): array
    {
        $months = DB::table('seo_gsc_months')->where('site_id', $profile->site_id)->pluck('month')
            ->merge(DB::table('seo_ga4_key_event_months')->where('site_id', $profile->site_id)->distinct()->pluck('month'))
            ->map(fn ($m) => substr((string) $m, 0, 7))
            ->unique()->sortDesc()->values();

        return $months->all();
    }

    /**
     * @return array{clicks: ?int, impressions: ?int, ctr: ?float, position: ?float, nonbranded_clicks: ?int, nonbranded_impressions: ?int, organic_keywords: ?int, possibly_truncated: bool}
     */
    private function gscMonth(SiteProfile $profile, CarbonImmutable $month): array
    {
        $range = [$month->toDateString(), $month->endOfMonth()->toDateString()];

        $totals = fn (string $segment) => DB::table('seo_gsc_daily')
            ->where('site_id', $profile->site_id)->where('segment', $segment)->whereBetween('date', $range)
            ->selectRaw('COUNT(*) as days, SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(position * impressions) as weighted_position')
            ->first();

        $all = $totals('all');
        $hasAll = $all !== null && (int) $all->days > 0;
        $nb = $profile->brand_regex !== null ? $totals('nonbranded') : null;
        $hasNb = $nb !== null && (int) $nb->days > 0;

        $impressions = $hasAll ? (int) $all->impressions : null;
        $clicks = $hasAll ? (int) $all->clicks : null;

        $monthRow = DB::table('seo_gsc_months')->where('site_id', $profile->site_id)->where('month', $month->toDateString())->first();

        return [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $hasAll && $impressions > 0 ? round($clicks / $impressions * 100, 2) : null,
            'position' => $hasAll && $impressions > 0 ? round((float) $all->weighted_position / $impressions, 1) : null,
            'nonbranded_clicks' => $hasNb ? (int) $nb->clicks : null,
            'nonbranded_impressions' => $hasNb ? (int) $nb->impressions : null,
            'organic_keywords' => $monthRow === null ? null : DB::table('seo_gsc_month_rows')
                ->where('site_id', $profile->site_id)->where('month', $month->toDateString())
                ->where('dimension', 'query')->where('impressions', '>', 0)->count(),
            'possibly_truncated' => (bool) ($monthRow->possibly_truncated ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private function ga4Month(SiteProfile $profile, CarbonImmutable $month): array
    {
        /** @var Collection<int, object> $channels */
        $channels = DB::table('seo_ga4_months')->where('site_id', $profile->site_id)->where('month', $month->toDateString())->get();
        $events = DB::table('seo_ga4_key_event_months')->where('site_id', $profile->site_id)->where('month', $month->toDateString())->get();
        $synced = $events->isNotEmpty() || $channels->isNotEmpty();

        $organic = $channels->firstWhere('channel_group', self::ORGANIC_CHANNEL);
        $sessions = $synced ? (int) ($organic->sessions ?? 0) : null;
        $engaged = $synced ? (int) ($organic->engaged_sessions ?? 0) : null;

        // The channel only exists from mid-May 2026, so May and earlier use the referral estimate.
        $aiIsEstimate = $month->lte(CarbonImmutable::parse(self::AI_CHANNEL_SINCE)->startOfMonth());
        $aiSessions = null;
        if ($synced) {
            $aiSessions = $aiIsEstimate
                ? (int) DB::table('seo_ga4_daily')->where('site_id', $profile->site_id)->where('dimension', 'ai_source')
                    ->whereBetween('date', [$month->toDateString(), $month->endOfMonth()->toDateString()])->sum('sessions')
                : (int) $channels->whereIn('channel_group', self::AI_CHANNELS)->sum('sessions');
        }

        $out = [
            'organic_sessions' => $sessions,
            'organic_engaged_sessions' => $engaged,
            'organic_engagement_rate' => $sessions ? round($engaged / $sessions * 100, 1) : null,
            'ai_sessions' => $aiSessions,
            'ai_is_estimate' => $synced && $aiIsEstimate,
            'thresholded' => (bool) $channels->max('subject_to_thresholding') || (bool) $events->max('subject_to_thresholding'),
        ];

        foreach ($profile->keyEvents() as $event) {
            $row = $events->firstWhere('event_name', $event);
            $out['event:'.$event] = $synced ? (float) ($row->key_events ?? 0) : null;
        }

        return $out;
    }

    /** @return array{status: string, ai_impressions: ?int, ai_share_pct: ?float, source: ?string, top_pages: ?array<int, mixed>} */
    private function aiImpressions(SiteProfile $profile, CarbonImmutable $month, ?int $webImpressions): array
    {
        $entry = AiImpressionEntry::query()->where('site_id', $profile->site_id)->where('month', $month->toDateString())->first();

        if ($entry === null) {
            return ['status' => 'missing', 'ai_impressions' => null, 'ai_share_pct' => null, 'source' => null, 'top_pages' => null];
        }

        $value = $entry->status === AiImpressionEntry::STATUS_ENTERED ? $entry->ai_impressions : null;

        return [
            'status' => $entry->status,
            'ai_impressions' => $value,
            'ai_share_pct' => $value !== null && $webImpressions ? round($value / $webImpressions * 100, 2) : null,
            'source' => $entry->source,
            'top_pages' => $entry->top_pages,
        ];
    }

    private function dataState(SiteProfile $profile, CarbonImmutable $month): string
    {
        $gsc = DB::table('seo_gsc_months')->where('site_id', $profile->site_id)->where('month', $month->toDateString())->value('is_final');
        $ga4 = DB::table('seo_ga4_key_event_months')->where('site_id', $profile->site_id)->where('month', $month->toDateString())->max('is_final');

        $states = array_filter([$gsc, $ga4], fn ($v) => $v !== null);
        if ($states === []) {
            return 'not_synced';
        }

        return in_array(false, array_map(fn ($v) => (bool) $v, $states), true) ? 'provisional' : 'final';
    }

    private function finalAfter(CarbonImmutable $month): string
    {
        return $month->endOfMonth()->startOfDay()->addDays($this->settings->finalAfterDays())->toDateString();
    }

    /** The baseline starts on or before the annotation's end, and the current month ends after it. */
    private function straddles(Annotation $annotation, CarbonImmutable $baseline, CarbonImmutable $current): bool
    {
        $end = CarbonImmutable::parse($annotation->ends_on->toDateString());

        return $baseline->startOfMonth()->lte($end) && $current->endOfMonth()->startOfDay()->gt($end);
    }

    /** @return array{code: string, message: string} */
    private function caveat(string $code, Annotation $annotation): array
    {
        $against = str_ends_with($code, '_yoy') ? 'last year' : 'the previous month';

        return match (true) {
            str_starts_with($code, 'position_') => ['code' => $code, 'message' => "Position isn't comparable with {$against}: {$annotation->label} ({$annotation->ends_on->toDateString()})."],
            str_starts_with($code, 'impressions') => ['code' => $code, 'message' => "Impressions and CTR against {$against} cross a Google change: {$annotation->label}."],
            str_starts_with($code, 'nonbranded_') => ['code' => $code, 'message' => "Non-branded figures against {$against} cross a brand-terms change: {$annotation->label}."],
            default => ['code' => $code, 'message' => $annotation->label],
        };
    }
}
