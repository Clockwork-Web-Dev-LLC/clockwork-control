<?php

namespace Modules\Linode;

/**
 * Parses Linode API v4 metrics/stats payloads into scalar values.
 */
class LinodeMetricsParser
{
    /**
     * Linode reports CPU in `cpu` time series as `[[timestamp, percentage], ...]`.
     */
    public function latestCpuPercent(array $payload): ?float
    {
        $values = $payload['cpu'] ?? $payload['data']['cpu'] ?? [];

        return $this->lastNumeric($values);
    }

    /**
     * Pulls the most recent numeric value from a Linode series array of shape `[[unix_ts, number], ...]`.
     */
    public function lastNumeric(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        $last = $values[array_key_last($values)][1] ?? null;
        if ($last === null) {
            return null;
        }

        return (float) $last;
    }
}
