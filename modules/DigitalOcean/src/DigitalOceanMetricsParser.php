<?php

namespace Modules\DigitalOcean;

/**
 * Parses DigitalOcean's /monitoring/metrics/droplet/* payload shapes into
 * the scalar values DigitalOceanCloudProvider::metrics() needs. Formerly
 * the metrics-parsing half of App\Services\DigitalOcean\ServerHealthEvaluator
 * — split from the status-classification half (now App\Services\Monitoring\
 * CpuStatusClassifier) during the Phase 4 module extraction, since that
 * half is used by core app code across every provider, not just DO.
 */
class DigitalOceanMetricsParser
{
    /**
     * DO returns CPU as cumulative jiffy counters per mode (idle, user, system, …).
     * To convert to "% in use" over a window, take (last - first) for each mode,
     * then 1 - (idle_delta / total_delta).
     *
     * @param  array  $cpuData  the `data` payload from /monitoring/metrics/droplet/cpu
     * @return float|null percentage (0-100), or null if data is unusable
     */
    public function percentCpuUsed(array $cpuData): ?float
    {
        $results = $cpuData['result'] ?? [];

        if ($results === []) {
            return null;
        }

        $idleDelta = null;
        $totalDelta = 0.0;

        foreach ($results as $series) {
            $values = $series['values'] ?? [];

            if (count($values) < 2) {
                return null;
            }

            $first = (float) ($values[array_key_first($values)][1] ?? 0);
            $last = (float) ($values[array_key_last($values)][1] ?? 0);
            $delta = $last - $first;

            if ($delta < 0) {
                return null;
            }

            $totalDelta += $delta;

            if (($series['metric']['mode'] ?? null) === 'idle') {
                $idleDelta = $delta;
            }
        }

        if ($idleDelta === null || $totalDelta <= 0) {
            return null;
        }

        $percent = (1 - ($idleDelta / $totalDelta)) * 100;

        return max(0, min(100, $percent));
    }

    /**
     * Take the latest sample from the first series in a single-value DO metrics payload
     * (e.g. load_1, memory_free, filesystem_free).
     */
    public function latestSingleValue(array $payload): ?float
    {
        $results = $payload['result'] ?? [];
        $series = $results[0] ?? null;

        if (! $series) {
            return null;
        }

        $values = $series['values'] ?? [];
        if ($values === []) {
            return null;
        }

        $last = $values[array_key_last($values)][1] ?? null;

        return $last !== null ? (float) $last : null;
    }

    /**
     * Compute "% in use" given free + total payloads. Returns null if either lacks data.
     */
    public function percentUsed(array $freePayload, array $totalPayload): ?float
    {
        $free = $this->latestSingleValue($freePayload);
        $total = $this->latestSingleValue($totalPayload);

        if ($free === null || $total === null || $total <= 0) {
            return null;
        }

        $percent = (1 - ($free / $total)) * 100;

        return max(0, min(100, $percent));
    }
}
