<?php

namespace Modules\Hetzner;

/**
 * Parses Hetzner Cloud's metrics API payload shape, which differs from
 * DigitalOcean's. Where DO returns Prometheus-style multi-mode jiffy
 * counters that need delta math, Hetzner already returns ready-to-use
 * percentages and rates inside `metrics.time_series.<key>.values`, with
 * each value being `[unix_ts, "stringified_number"]`.
 *
 * We only need the latest sample per series for the dashboard, so this
 * stays a thin selector. If we ever want to graph the full window we
 * can return the whole values array instead.
 */
class HetznerMetricsParser
{
    /**
     * Hetzner reports CPU as percentage 0–100 directly. Pull the latest
     * value from the series.
     *
     * @param  array  $payload  the `metrics` payload from /servers/{id}/metrics?type=cpu
     */
    public function latestCpuPercent(array $payload): ?float
    {
        $values = $payload['time_series']['cpu']['values'] ?? [];

        return $this->lastNumeric($values);
    }

    /**
     * Pulls the most recent numeric value from a Hetzner values array of
     * shape `[[unix_ts, "string"], …]`. Hetzner stringifies floats so we
     * cast on the way out.
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
