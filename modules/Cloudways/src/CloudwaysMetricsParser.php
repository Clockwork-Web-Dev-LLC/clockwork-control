<?php

namespace Modules\Cloudways;

/**
 * Parses whatever shape Cloudways' monitoring endpoints actually return
 * into the scalar values CloudwaysCloudProvider::metrics() needs.
 *
 * ASSUMPTION FLAG: there is no live Cloudways account behind this build, so
 * the exact payload shape below is a best-effort guess modeled loosely on
 * DigitalOcean/Hetzner's time-series graph responses (a list of
 * {timestamp, value} points, most-recent last) and on how Cloudways'
 * dashboard graphs are described publicly (percentage series for CPU/RAM,
 * a used/total pair for disk). Every accessor here is defensive about
 * missing/malformed keys specifically because this shape is unconfirmed —
 * treat any of it as needing correction once real payloads are available.
 */
class CloudwaysMetricsParser
{
    /**
     * CPU/RAM/load monitor-summary payload. Shape assumption:
     * { data: [ { timestamp: int, value: float }, ... ] } already expressed
     * as a 0-100 percentage for cpu/ram, or a raw load average for 'load' —
     * i.e., unlike DigitalOcean's cumulative-jiffy-counter CPU metric,
     * Cloudways' dashboard graphs are assumed to hand back an
     * already-computed instantaneous value per sample, so this just takes
     * the latest sample rather than needing a delta calculation.
     */
    public function latestPercent(array $payload): ?float
    {
        $points = $payload['data'] ?? [];

        if (! is_array($points) || $points === []) {
            return null;
        }

        $last = end($points);

        if (! is_array($last) || ! isset($last['value']) || ! is_numeric($last['value'])) {
            return null;
        }

        return max(0.0, min(100.0, (float) $last['value']));
    }

    /**
     * Same series shape as latestPercent() but without clamping to 0-100 —
     * used for load_1, which is not a percentage.
     */
    public function latestRawValue(array $payload): ?float
    {
        $points = $payload['data'] ?? [];

        if (! is_array($points) || $points === []) {
            return null;
        }

        $last = end($points);

        if (! is_array($last) || ! isset($last['value']) || ! is_numeric($last['value'])) {
            return null;
        }

        return (float) $last['value'];
    }

    /**
     * Disk usage snapshot. Shape assumption: { used_mb: float, total_mb:
     * float } (see CloudwaysClient::serverDiskUsage()) — a current
     * snapshot rather than a time series, since disk usage graphs are
     * commonly exposed as "used vs total" rather than a percentage
     * history on hosting-panel APIs.
     */
    public function diskPercent(array $payload): ?float
    {
        $used = $payload['used_mb'] ?? null;
        $total = $payload['total_mb'] ?? null;

        if (! is_numeric($used) || ! is_numeric($total) || (float) $total <= 0) {
            return null;
        }

        $percent = ((float) $used / (float) $total) * 100;

        return max(0.0, min(100.0, $percent));
    }
}
