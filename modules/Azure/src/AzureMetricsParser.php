<?php

namespace Modules\Azure;

/**
 * Parses Azure Monitor Metrics API responses into the scalar values
 * PollServers expects. Azure returns an `average` aggregation per 1-minute
 * interval; we take the most recent non-null sample.
 *
 * Response shape:
 * {
 *   "value": [
 *     {
 *       "name": { "value": "Percentage CPU" },
 *       "timeseries": [{ "data": [{ "timeStamp": "...", "average": 12.5 }] }]
 *     },
 *     { "name": { "value": "Available Memory Bytes" }, ... }
 *   ]
 * }
 */
class AzureMetricsParser
{
    /**
     * Extract the latest CPU percentage (0–100) from the Percentage CPU metric.
     *
     * @param  array  $payload  Full JSON response from vmMetrics()
     */
    public function latestCpuPercent(array $payload): ?float
    {
        return $this->latestAverageForMetric($payload, 'Percentage CPU');
    }

    /**
     * Extract the latest available-memory sample in bytes.
     * Callers convert to a percentage using the server's stored memory_mb.
     *
     * @param  array  $payload  Full JSON response from vmMetrics()
     */
    public function latestAvailableMemoryBytes(array $payload): ?float
    {
        return $this->latestAverageForMetric($payload, 'Available Memory Bytes');
    }

    /**
     * Derive memory-used percentage given the parsed metrics payload and the
     * server's total RAM. Returns null if either value is unavailable.
     *
     * @param  array  $payload  Full JSON response from vmMetrics()
     * @param  int|null  $totalMemoryMb  server.memory_mb (populated by ReconcileProvider)
     */
    public function memoryUsedPercent(array $payload, ?int $totalMemoryMb): ?float
    {
        if (! $totalMemoryMb || $totalMemoryMb <= 0) {
            return null;
        }

        $availableBytes = $this->latestAvailableMemoryBytes($payload);
        if ($availableBytes === null) {
            return null;
        }

        $totalBytes = $totalMemoryMb * 1024 * 1024;
        $pct = (1 - ($availableBytes / $totalBytes)) * 100;

        return max(0.0, min(100.0, $pct));
    }

    /**
     * Find the latest non-null average value for the named metric in the
     * Azure Monitor response payload.
     */
    public function latestAverageForMetric(array $payload, string $metricName): ?float
    {
        foreach ($payload['value'] ?? [] as $metric) {
            if (($metric['name']['value'] ?? null) !== $metricName) {
                continue;
            }

            $data = $metric['timeseries'][0]['data'] ?? [];
            if ($data === []) {
                return null;
            }

            // Iterate in reverse — most recent point is last.
            foreach (array_reverse($data) as $point) {
                if (isset($point['average'])) {
                    return (float) $point['average'];
                }
            }
        }

        return null;
    }
}
