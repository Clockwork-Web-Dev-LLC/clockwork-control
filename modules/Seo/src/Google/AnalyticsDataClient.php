<?php

namespace Modules\Seo\Google;

use Modules\Seo\Models\GoogleConnection;

/**
 * GA4 Data API v1beta: batchRunReports (up to 5 reports for one property).
 *
 * Each report asks for returnPropertyQuota, so the sync can stop a property
 * before it runs out of hourly tokens.
 */
class AnalyticsDataClient
{
    public const BASE_URL = 'https://analyticsdata.googleapis.com/v1beta';

    public function __construct(private readonly GoogleApi $api) {}

    /**
     * @param  list<array<string, mixed>>  $requests
     */
    public function batchRunReports(GoogleConnection $connection, string $propertyId, array $requests): GoogleApiResult
    {
        $requests = array_map(fn (array $r) => $r + ['returnPropertyQuota' => true], $requests);

        return $this->api->post($connection, self::BASE_URL.'/properties/'.rawurlencode($propertyId).':batchRunReports', [
            'requests' => $requests,
        ]);
    }

    /**
     * Rows as [dimension values..., metric values...] keyed by header name.
     *
     * @param  array<string, mixed>  $report
     * @return list<array<string, string>>
     */
    public static function rows(array $report): array
    {
        $dims = array_map(fn ($h) => (string) ($h['name'] ?? ''), (array) ($report['dimensionHeaders'] ?? []));
        $mets = array_map(fn ($h) => (string) ($h['name'] ?? ''), (array) ($report['metricHeaders'] ?? []));
        $out = [];

        foreach ((array) ($report['rows'] ?? []) as $row) {
            $item = [];
            foreach ($dims as $i => $name) {
                $item[$name] = (string) ($row['dimensionValues'][$i]['value'] ?? '');
            }
            foreach ($mets as $i => $name) {
                $item[$name] = (string) ($row['metricValues'][$i]['value'] ?? '0');
            }
            $out[] = $item;
        }

        return $out;
    }

    /** @param array<string, mixed> $report */
    public static function isThresholded(array $report): bool
    {
        return (bool) ($report['metadata']['subjectToThresholding'] ?? false);
    }

    /**
     * Lowest remaining hourly token count across the batch's reports, or null if Google didn't say.
     *
     * @param  array<string, mixed>  $json
     */
    public static function remainingHourlyTokens(array $json): ?int
    {
        $remaining = null;

        foreach ((array) ($json['reports'] ?? []) as $report) {
            $value = $report['propertyQuota']['tokensPerHour']['remaining'] ?? null;
            if (is_numeric($value)) {
                $remaining = $remaining === null ? (int) $value : min($remaining, (int) $value);
            }
        }

        return $remaining;
    }
}
