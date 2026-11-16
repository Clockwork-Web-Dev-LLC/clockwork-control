<?php

namespace Tests\Fixtures;

/**
 * Canned GA4 Data API reports. The month report deliberately does NOT equal
 * the sum of the daily rows: GA4 counts sessions approximately and a session
 * crossing midnight counts on both days.
 */
final class GoogleAnalyticsFixtures
{
    public const MONTH_ORGANIC_SESSIONS = 1000;

    public const DAILY_ORGANIC_SESSIONS = 40;

    /**
     * @param  array<string, mixed>  $request
     * @param  list<string>  $dims
     * @return array<string, mixed>
     */
    public static function reportFor(array $request, array $dims, int $scale = 1): array
    {
        $quota = ['tokensPerHour' => ['consumed' => 5, 'remaining' => 39000]];

        if ($dims === ['date', 'sessionDefaultChannelGroup'] || $dims === ['date', 'sessionPrimaryChannelGroup']) {
            $range = $request['dateRanges'][0];
            $rows = [];
            for ($d = strtotime($range['startDate']); $d <= strtotime($range['endDate']); $d += 86400) {
                $rows[] = self::row([date('Ymd', $d), 'Organic Search'], [self::DAILY_ORGANIC_SESSIONS * $scale, 30 * $scale]);
            }

            return self::report($dims, ['sessions', 'engagedSessions'], $rows, $quota);
        }

        if ($dims === ['date', 'sessionSource', 'sessionMedium']) {
            $range = $request['dateRanges'][0];

            return self::report($dims, ['sessions', 'engagedSessions'], [
                self::row([str_replace('-', '', $range['endDate']), 'chatgpt.com', 'referral'], [2, 1]),
            ], $quota);
        }

        if ($dims === ['sessionDefaultChannelGroup'] || $dims === ['sessionPrimaryChannelGroup']) {
            return self::report($dims, ['sessions', 'engagedSessions', 'keyEvents'], [
                self::row(['Organic Search'], [self::MONTH_ORGANIC_SESSIONS * $scale, 600 * $scale, 12 * $scale]),
                self::row(['Direct'], [300 * $scale, 150 * $scale, 3 * $scale]),
            ], $quota, thresholded: true);
        }

        if ($dims === ['eventName']) {
            return self::report($dims, ['keyEvents', 'eventCount'], [
                self::row(['generate_lead'], [9 * $scale, 9 * $scale]),
                self::row(['form_submit'], [6 * $scale, 7 * $scale]),
            ], $quota);
        }

        return self::report($dims, [], [], $quota);
    }

    /** @return array<string, mixed> */
    private static function row(array $dims, array $metrics): array
    {
        return [
            'dimensionValues' => array_map(fn ($v) => ['value' => (string) $v], $dims),
            'metricValues' => array_map(fn ($v) => ['value' => (string) $v], $metrics),
        ];
    }

    /** @return array<string, mixed> */
    private static function report(array $dims, array $metrics, array $rows, array $quota, bool $thresholded = false): array
    {
        return array_filter([
            'dimensionHeaders' => array_map(fn ($n) => ['name' => $n], $dims),
            'metricHeaders' => array_map(fn ($n) => ['name' => $n, 'type' => 'TYPE_INTEGER'], $metrics),
            'rows' => $rows,
            'rowCount' => count($rows),
            'metadata' => $thresholded ? ['subjectToThresholding' => true, 'currencyCode' => 'USD'] : ['currencyCode' => 'USD'],
            'propertyQuota' => $quota,
        ], fn ($v) => $v !== []);
    }
}
