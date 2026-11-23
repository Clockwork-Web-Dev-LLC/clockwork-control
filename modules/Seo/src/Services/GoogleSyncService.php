<?php

namespace Modules\Seo\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Modules\Seo\Google\AnalyticsDataClient;
use Modules\Seo\Google\GoogleApiResult;
use Modules\Seo\Google\SearchConsoleClient;
use Modules\Seo\Models\GoogleConnection;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Support\GscDates;
use Modules\Seo\Support\SeoSettings;

/**
 * Pulls one site's Search Console and GA4 data into the seo_* tables. Pages,
 * reports and Claude's tools read those tables, never the APIs.
 *
 * Per site per run:
 * 1. GSC daily totals, from the latest stored day minus 5 days (late data
 *    keeps filling in), or from $from on a backfill, to yesterday Pacific.
 *    A second call with the brand regex as excludingRegex gives the
 *    non-branded totals.
 * 2. GSC page, query and page×query rows for every calendar month that isn't
 *    final yet (all months from $from when given). A month turns final when
 *    synced on or after month end + final_after_days.
 * 3. GA4 daily channel and AI-source rows, for the chart only.
 * 4. GA4 month figures from a calendar-month request with no date dimension.
 *    GA4 counts sessions approximately and a session crossing midnight lands
 *    on both days, so summed days never equal the UI's month figure.
 *
 * Month rows start at the first full month in the window, so no stored month
 * is silently partial.
 *
 * A quota answer stops that API for this site tonight; the trailing window
 * catches up tomorrow with no retry logic.
 */
class GoogleSyncService
{
    public const TRAILING_DAYS = 5;

    /** Our own safety margin, not a Google figure. */
    public const GA4_MIN_HOURLY_TOKENS = 1000;

    private const INSERT_CHUNK = 500;

    public function __construct(
        private readonly SearchConsoleClient $gsc,
        private readonly AnalyticsDataClient $ga4,
        private readonly SeoSettings $settings,
    ) {}

    public function syncSite(SiteProfile $profile, ?CarbonImmutable $from = null): SyncOutcome
    {
        $connection = GoogleConnection::current();
        if ($connection === null) {
            return $this->finish($profile, new SyncOutcome(false, ['Google is not connected.']));
        }
        if ($connection->isRevoked()) {
            return $this->finish($profile, new SyncOutcome(false, ['The Google connection was revoked. Reconnect on /seo/settings.'], revoked: true));
        }

        $errors = [];
        $revoked = false;
        $gscMonths = 0;
        $ga4Months = 0;

        if ($profile->hasGsc()) {
            [$failure, $gscMonths] = $this->syncGsc($connection, $profile, $from);
            if ($failure !== null) {
                $errors[] = 'Search Console: '.$failure->error;
                $revoked = $failure->isRevoked();
            } else {
                $profile->forceFill(['last_gsc_synced_at' => now()]);
            }
        }

        if ($profile->hasGa4() && ! $revoked) {
            [$failure, $ga4Months] = $this->syncGa4($connection, $profile, $from);
            if ($failure !== null) {
                $errors[] = 'GA4: '.$failure->error;
                $revoked = $failure->isRevoked();
            } else {
                $profile->forceFill(['last_ga4_synced_at' => now()]);
            }
        }

        return $this->finish($profile, new SyncOutcome($errors === [], $errors, $revoked, $gscMonths, $ga4Months));
    }

    /**
     * Re-pull the non-branded daily totals after the brand terms changed.
     * Only days Search Console still holds can be re-pulled.
     */
    public function repullNonbrandedDaily(SiteProfile $profile, CarbonImmutable $from): SyncOutcome
    {
        $connection = GoogleConnection::current();
        if ($connection === null || $connection->isRevoked() || ! $profile->hasGsc()) {
            return new SyncOutcome(false, ['Google is not connected, or the site has no Search Console property.'], $connection?->isRevoked() ?? false);
        }

        $from = $from->max(GscDates::windowStart());
        $to = GscDates::yesterday();

        if ($profile->brand_regex === null || $profile->brand_regex === '') {
            DB::table('seo_gsc_daily')->where('site_id', $profile->site_id)->where('segment', 'nonbranded')
                ->where('date', '>=', $from->toDateString())->delete();

            return new SyncOutcome(true);
        }

        $failure = $this->syncGscDaily($connection, $profile, 'nonbranded', $from, $to, $profile->brand_regex);

        return $failure === null
            ? new SyncOutcome(true)
            : new SyncOutcome(false, ['Search Console: '.$failure->error], $failure->isRevoked());
    }

    /** @return array{0: ?GoogleApiResult, 1: int} */
    private function syncGsc(GoogleConnection $connection, SiteProfile $profile, ?CarbonImmutable $from): array
    {
        $windowStart = GscDates::windowStart();
        $yesterday = GscDates::yesterday();

        $start = ($from ?? $this->trailingStart('seo_gsc_daily', $profile->site_id, ['segment' => 'all']) ?? $this->historyStart($profile))
            ->max($windowStart);

        if ($start->lte($yesterday)) {
            $failure = $this->syncGscDaily($connection, $profile, 'all', $start, $yesterday, null);
            if ($failure !== null) {
                return [$failure, 0];
            }

            if ($profile->brand_regex !== null && $profile->brand_regex !== '') {
                $nbStart = $from === null
                    ? ($this->trailingStart('seo_gsc_daily', $profile->site_id, ['segment' => 'nonbranded']) ?? $start)->max($windowStart)
                    : $start;
                $failure = $this->syncGscDaily($connection, $profile, 'nonbranded', $nbStart, $yesterday, $profile->brand_regex);
                if ($failure !== null) {
                    return [$failure, 0];
                }
            }
        }

        $synced = 0;
        foreach ($this->monthsToSync('seo_gsc_months', $profile, $from) as $month) {
            $failure = $this->syncGscMonth($connection, $profile, $month);
            if ($failure !== null) {
                return [$failure, $synced];
            }
            $synced++;
        }

        return [null, $synced];
    }

    private function syncGscDaily(GoogleConnection $connection, SiteProfile $profile, string $segment, CarbonImmutable $from, CarbonImmutable $to, ?string $excludeRegex): ?GoogleApiResult
    {
        $body = [
            'startDate' => $from->toDateString(),
            'endDate' => $to->toDateString(),
            'dimensions' => ['date'],
            'type' => 'web',
            'dataState' => 'final',
            'rowLimit' => SearchConsoleClient::PAGE_SIZE,
        ];
        if ($excludeRegex !== null) {
            $body += SearchConsoleClient::excludingQueries($excludeRegex);
        }

        $result = $this->gsc->query($connection, (string) $profile->gsc_site_url, $body);
        if (! $result->ok) {
            return $result;
        }

        $now = now();
        $rows = [];
        foreach ((array) ($result->json['rows'] ?? []) as $row) {
            $date = (string) ($row['keys'][0] ?? '');
            if ($date === '') {
                continue;
            }
            $rows[] = [
                'site_id' => $profile->site_id,
                'date' => $date,
                'segment' => $segment,
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round((float) ($row['ctr'] ?? 0), 6),
                'position' => round((float) ($row['position'] ?? 0), 2),
                'fetched_at' => $now,
            ];
        }

        // Replace the range: a day Google now reports as empty must not keep stale numbers.
        DB::transaction(function () use ($profile, $segment, $from, $to, $rows) {
            DB::table('seo_gsc_daily')
                ->where('site_id', $profile->site_id)->where('segment', $segment)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->delete();
            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                DB::table('seo_gsc_daily')->insert($chunk);
            }
        });

        return null;
    }

    private function syncGscMonth(GoogleConnection $connection, SiteProfile $profile, CarbonImmutable $month): ?GoogleApiResult
    {
        $end = $month->endOfMonth()->startOfDay()->min(GscDates::yesterday());
        if ($end->lt($month)) {
            return null;
        }

        $base = [
            'startDate' => $month->toDateString(),
            'endDate' => $end->toDateString(),
            'type' => 'web',
            'dataState' => 'final',
        ];

        $collected = [];
        $truncated = false;
        $counts = ['page' => 0, 'query' => 0, 'page_query' => 0];
        $first = true;

        foreach (['page' => ['page'], 'query' => ['query'], 'page_query' => ['page', 'query']] as $dimension => $dims) {
            if (! $first) {
                Sleep::sleep(1);
            }
            $first = false;

            $page = $this->gsc->queryAll($connection, (string) $profile->gsc_site_url, $base + ['dimensions' => $dims]);
            if (! $page['ok']) {
                return $page['result'];
            }

            $truncated = $truncated || $page['truncated'];
            $counts[$dimension] = count($page['rows']);

            foreach ($page['rows'] as $row) {
                $keys = (array) ($row['keys'] ?? []);
                $pageUrl = $dimension === 'query' ? null : (string) ($keys[0] ?? '');
                $query = match ($dimension) {
                    'query' => (string) ($keys[0] ?? ''),
                    'page_query' => (string) ($keys[1] ?? ''),
                    default => null,
                };

                $collected[] = [
                    'site_id' => $profile->site_id,
                    'month' => $month->toDateString(),
                    'dimension' => $dimension,
                    'page' => $pageUrl === null ? null : mb_substr($pageUrl, 0, 2048),
                    'query' => $query === null ? null : mb_substr($query, 0, 512),
                    'row_hash' => sha1($dimension."\n".$pageUrl."\n".$query),
                    'is_branded' => $query === null ? null : BrandRegex::matches($query, $profile->brand_terms),
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'ctr' => round((float) ($row['ctr'] ?? 0), 6),
                    'position' => round((float) ($row['position'] ?? 0), 2),
                    'fetched_at' => now(),
                ];
            }
        }

        $isFinal = $this->isFinal($month);

        DB::transaction(function () use ($profile, $month, $collected, $counts, $truncated, $isFinal) {
            DB::table('seo_gsc_month_rows')->where('site_id', $profile->site_id)->where('month', $month->toDateString())->delete();
            foreach (array_chunk($collected, self::INSERT_CHUNK) as $chunk) {
                DB::table('seo_gsc_month_rows')->insert($chunk);
            }

            DB::table('seo_gsc_months')->updateOrInsert(
                ['site_id' => $profile->site_id, 'month' => $month->toDateString()],
                [
                    'is_final' => $isFinal,
                    'rows_page' => $counts['page'],
                    'rows_query' => $counts['query'],
                    'rows_page_query' => $counts['page_query'],
                    'possibly_truncated' => $truncated,
                    'synced_at' => now(),
                ],
            );
        });

        return null;
    }

    /** @return array{0: ?GoogleApiResult, 1: int} */
    private function syncGa4(GoogleConnection $connection, SiteProfile $profile, ?CarbonImmutable $from): array
    {
        $property = (string) $profile->ga4_property_id;
        $yesterday = GscDates::yesterday();

        $start = $from ?? $this->trailingStart('seo_ga4_daily', $profile->site_id, ['dimension' => 'channel']) ?? $this->historyStart($profile);

        for ($chunkStart = $start; $chunkStart->lte($yesterday); $chunkStart = $chunkStart->addMonthNoOverflow()->startOfMonth()) {
            $chunkEnd = $chunkStart->endOfMonth()->startOfDay()->min($yesterday);
            $failure = $this->syncGa4Daily($connection, $profile, $property, $chunkStart, $chunkEnd);
            if ($failure !== null) {
                return [$failure, 0];
            }
        }

        $synced = 0;
        foreach ($this->monthsToSync('seo_ga4_key_event_months', $profile, $from) as $month) {
            $failure = $this->syncGa4Month($connection, $profile, $property, $month);
            if ($failure !== null) {
                return [$failure, $synced];
            }
            $synced++;
        }

        return [null, $synced];
    }

    private function syncGa4Daily(GoogleConnection $connection, SiteProfile $profile, string $property, CarbonImmutable $from, CarbonImmutable $to): ?GoogleApiResult
    {
        $range = [['startDate' => $from->toDateString(), 'endDate' => $to->toDateString()]];
        $metrics = [['name' => 'sessions'], ['name' => 'engagedSessions']];

        $result = $this->ga4->batchRunReports($connection, $property, [
            [
                'dateRanges' => $range,
                'dimensions' => [['name' => 'date'], ['name' => $profile->ga4ChannelDimension()]],
                'metrics' => $metrics,
                'limit' => 100000,
            ],
            [
                'dateRanges' => $range,
                'dimensions' => [['name' => 'date'], ['name' => 'sessionSource'], ['name' => 'sessionMedium']],
                'metrics' => $metrics,
                'dimensionFilter' => ['orGroup' => ['expressions' => [
                    ['filter' => ['fieldName' => 'sessionMedium', 'stringFilter' => ['matchType' => 'EXACT', 'value' => 'ai-assistant']]],
                    ['filter' => ['fieldName' => 'sessionSource', 'stringFilter' => ['matchType' => 'PARTIAL_REGEXP', 'value' => $this->settings->aiReferrerRegex(), 'caseSensitive' => false]]],
                ]]],
                'limit' => 100000,
            ],
        ]);

        if (! $result->ok) {
            return $result;
        }

        $reports = (array) ($result->json['reports'] ?? []);
        $now = now();
        $rows = [];

        foreach (AnalyticsDataClient::rows((array) ($reports[0] ?? [])) as $r) {
            $rows[] = $this->ga4DailyRow($profile, $r['date'] ?? '', 'channel', $r[$profile->ga4ChannelDimension()] ?? '', $r, $now);
        }
        foreach (AnalyticsDataClient::rows((array) ($reports[1] ?? [])) as $r) {
            $rows[] = $this->ga4DailyRow($profile, $r['date'] ?? '', 'ai_source', ($r['sessionSource'] ?? '').' / '.($r['sessionMedium'] ?? ''), $r, $now);
        }

        DB::transaction(function () use ($profile, $from, $to, $rows) {
            DB::table('seo_ga4_daily')->where('site_id', $profile->site_id)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->delete();
            foreach (array_chunk(array_values(array_filter($rows)), self::INSERT_CHUNK) as $chunk) {
                DB::table('seo_ga4_daily')->insert($chunk);
            }
        });

        return $this->quotaFailure($result);
    }

    /**
     * @param  array<string, string>  $r
     * @return ?array<string, mixed>
     */
    private function ga4DailyRow(SiteProfile $profile, string $ymd, string $dimension, string $value, array $r, mixed $now): ?array
    {
        if (strlen($ymd) !== 8) {
            return null;
        }

        return [
            'site_id' => $profile->site_id,
            'date' => substr($ymd, 0, 4).'-'.substr($ymd, 4, 2).'-'.substr($ymd, 6, 2),
            'dimension' => $dimension,
            'value' => mb_substr($value === '' ? '(not set)' : $value, 0, 191),
            'sessions' => (int) ($r['sessions'] ?? 0),
            'engaged_sessions' => (int) ($r['engagedSessions'] ?? 0),
            'fetched_at' => $now,
        ];
    }

    private function syncGa4Month(GoogleConnection $connection, SiteProfile $profile, string $property, CarbonImmutable $month): ?GoogleApiResult
    {
        $end = $month->endOfMonth()->startOfDay()->min(GscDates::yesterday());
        if ($end->lt($month)) {
            return null;
        }

        $range = [['startDate' => $month->toDateString(), 'endDate' => $end->toDateString()]];
        $channelDimension = $profile->ga4ChannelDimension();
        $keyEvents = $profile->keyEvents();

        $result = $this->ga4->batchRunReports($connection, $property, [
            [
                'dateRanges' => $range,
                'dimensions' => [['name' => $channelDimension]],
                'metrics' => [['name' => 'sessions'], ['name' => 'engagedSessions'], ['name' => 'keyEvents']],
            ],
            [
                'dateRanges' => $range,
                'dimensions' => [['name' => 'eventName']],
                'metrics' => [['name' => 'keyEvents'], ['name' => 'eventCount']],
                'dimensionFilter' => ['filter' => ['fieldName' => 'eventName', 'inListFilter' => ['values' => $keyEvents]]],
            ],
        ]);

        if (! $result->ok) {
            return $result;
        }

        $reports = (array) ($result->json['reports'] ?? []);
        $channelReport = (array) ($reports[0] ?? []);
        $eventReport = (array) ($reports[1] ?? []);
        $isFinal = $this->isFinal($month);
        $now = now();

        $channels = array_map(fn (array $r) => [
            'site_id' => $profile->site_id,
            'month' => $month->toDateString(),
            'channel_group' => mb_substr(($r[$channelDimension] ?? '') ?: '(not set)', 0, 191),
            'sessions' => (int) ($r['sessions'] ?? 0),
            'engaged_sessions' => (int) ($r['engagedSessions'] ?? 0),
            'key_events' => (float) ($r['keyEvents'] ?? 0),
            'is_final' => $isFinal,
            'subject_to_thresholding' => AnalyticsDataClient::isThresholded($channelReport),
            'fetched_at' => $now,
        ], AnalyticsDataClient::rows($channelReport));

        $byEvent = [];
        foreach (AnalyticsDataClient::rows($eventReport) as $r) {
            $byEvent[(string) ($r['eventName'] ?? '')] = $r;
        }

        // Every configured event gets a row, so "0 this month" is stored
        // rather than missing (and the row marks the month as synced).
        $events = array_map(fn (string $name) => [
            'site_id' => $profile->site_id,
            'month' => $month->toDateString(),
            'event_name' => mb_substr($name, 0, 191),
            'key_events' => (float) ($byEvent[$name]['keyEvents'] ?? 0),
            'event_count' => (int) ($byEvent[$name]['eventCount'] ?? 0),
            'is_final' => $isFinal,
            'subject_to_thresholding' => AnalyticsDataClient::isThresholded($eventReport),
            'fetched_at' => $now,
        ], $keyEvents);

        DB::transaction(function () use ($profile, $month, $channels, $events) {
            foreach (['seo_ga4_months' => $channels, 'seo_ga4_key_event_months' => $events] as $table => $rows) {
                DB::table($table)->where('site_id', $profile->site_id)->where('month', $month->toDateString())->delete();
                foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
        });

        return $this->quotaFailure($result);
    }

    /** Stop this property early when GA4 says few hourly tokens remain. */
    private function quotaFailure(GoogleApiResult $result): ?GoogleApiResult
    {
        $remaining = AnalyticsDataClient::remainingHourlyTokens($result->json);

        if ($remaining !== null && $remaining < self::GA4_MIN_HOURLY_TOKENS) {
            return GoogleApiResult::failure(429, "Stopped early: only {$remaining} GA4 hourly tokens left for this property.", 'RESOURCE_EXHAUSTED');
        }

        return null;
    }

    /**
     * Months to (re)pull: every month from $from when given, otherwise every
     * month in the window not yet final. Starts at the first full month.
     *
     * @return list<CarbonImmutable>
     */
    private function monthsToSync(string $finalityTable, SiteProfile $profile, ?CarbonImmutable $from): array
    {
        $earliest = ($from ?? $this->historyStart($profile))->max(GscDates::windowStart());
        $first = $earliest->day === 1 ? $earliest->startOfDay() : $earliest->addMonthNoOverflow()->startOfMonth();
        $last = GscDates::yesterday()->startOfMonth();

        $final = $from !== null ? [] : DB::table($finalityTable)
            ->where('site_id', $profile->site_id)->where('is_final', true)
            ->distinct()->pluck('month')->map(fn ($m) => substr((string) $m, 0, 10))->all();

        $months = [];
        for ($m = $first; $m->lte($last); $m = $m->addMonthNoOverflow()) {
            if (! in_array($m->toDateString(), $final, true)) {
                $months[] = $m;
            }
        }

        return $months;
    }

    /** @param array<string, string> $where */
    private function trailingStart(string $table, int $siteId, array $where): ?CarbonImmutable
    {
        $latest = DB::table($table)->where('site_id', $siteId)->where($where)->max('date');

        return $latest === null ? null : CarbonImmutable::parse(substr((string) $latest, 0, 10), GscDates::TIMEZONE)->subDays(self::TRAILING_DAYS);
    }

    private function historyStart(SiteProfile $profile): CarbonImmutable
    {
        return $profile->history_starts_on !== null
            ? CarbonImmutable::parse($profile->history_starts_on->toDateString(), GscDates::TIMEZONE)
            : GscDates::windowStart();
    }

    private function isFinal(CarbonImmutable $month): bool
    {
        return GscDates::today()->gte($month->endOfMonth()->startOfDay()->addDays($this->settings->finalAfterDays()));
    }

    private function finish(SiteProfile $profile, SyncOutcome $outcome): SyncOutcome
    {
        $profile->forceFill(['last_sync_error' => $outcome->ok ? null : mb_substr(implode(' | ', $outcome->errors), 0, 480)])->save();

        return $outcome;
    }
}
