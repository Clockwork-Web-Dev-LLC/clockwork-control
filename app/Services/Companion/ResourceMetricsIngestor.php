<?php

namespace App\Services\Companion;

use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pulls hourly CPU/memory rollups from Companion (1.17.0+) and UPSERTs them
 * into `site_metrics`. Idempotent — re-pulling the same window is safe.
 *
 * Cursor: `sites.resource_metrics_cursor_at` stores the most-recent bucket_at
 * we've successfully written. Next pull asks for rows >= (cursor - 1 hour) so
 * the still-accumulating current-hour bucket gets updated on every pull
 * instead of being frozen after first ingestion.
 */
class ResourceMetricsIngestor
{
    /** When no cursor exists yet, seed with the last 24h of data. */
    private const SEED_HOURS = 24;

    /** Re-pull the most recent N hours on every run so the open bucket stays fresh. */
    private const REPULL_OVERLAP_HOURS = 2;

    /**
     * The constructor accepts a client factory closure (not a single client)
     * because `ClockworkCompanionClient` is per-site — it carries the Site
     * model + secret on construction. Passing a closure lets tests inject a
     * mock factory while the production wiring just maps `fn ($s) => new
     * ClockworkCompanionClient($s)` in a service provider (or uses the
     * default below).
     *
     * @param  ?callable(Site): ClockworkCompanionClient  $clientFactory
     */
    public function __construct(?callable $clientFactory = null)
    {
        $this->clientFactory = $clientFactory ?? (fn (Site $s) => new ClockworkCompanionClient($s));
    }

    /** @var callable(Site): ClockworkCompanionClient */
    private $clientFactory;

    public function ingest(Site $site): IngestResult
    {
        $caps = $site->companion_capabilities ?? [];
        if (! is_array($caps) || ! in_array('resource-sampler', $caps, true)) {
            return new IngestResult(0, null, 'skipped: companion does not advertise resource-sampler');
        }

        $cursor = $site->resource_metrics_cursor_at instanceof Carbon
            ? $site->resource_metrics_cursor_at->copy()->subHours(self::REPULL_OVERLAP_HOURS)
            : Carbon::now()->subHours(self::SEED_HOURS);

        $clientForSite = ($this->clientFactory)($site);
        $response = $clientForSite->resourceReport($cursor->toIso8601String());

        if (! ($response['ok'] ?? false)) {
            throw new RuntimeException("resource-report returned ok=false for site #{$site->id}");
        }

        $rows = $response['rows'] ?? [];
        if (! is_array($rows) || $rows === []) {
            return new IngestResult(0, $site->resource_metrics_cursor_at, null);
        }

        $now = Carbon::now();
        $maxBucket = null;

        $payload = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $bucketIso = (string) ($row['bucket_at'] ?? '');
            $bucket = $bucketIso !== '' ? Carbon::parse($bucketIso) : null;
            if ($bucket === null) {
                continue;
            }
            if ($maxBucket === null || $bucket->greaterThan($maxBucket)) {
                $maxBucket = $bucket->copy();
            }
            $payload[] = [
                'site_id' => $site->id,
                'bucket_at' => $bucket->toDateTimeString(),
                'cpu_us_total' => (int) ($row['cpu_us_total'] ?? 0),
                'wall_us_total' => (int) ($row['wall_us_total'] ?? 0),
                'mem_peak_bytes' => (int) ($row['mem_peak_max'] ?? 0),
                'requests' => (int) ($row['requests'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($payload === []) {
            return new IngestResult(0, $site->resource_metrics_cursor_at, null);
        }

        // UPSERT — overwrites the current-hour row on every pull (which is
        // what we want; Companion keeps adding to that bucket as the hour
        // unfolds, and we mirror its current state).
        DB::table('site_metrics')->upsert(
            $payload,
            ['site_id', 'bucket_at'],
            ['cpu_us_total', 'wall_us_total', 'mem_peak_bytes', 'requests', 'updated_at']
        );

        if ($maxBucket !== null) {
            $site->forceFill(['resource_metrics_cursor_at' => $maxBucket])->save();
        }

        return new IngestResult(count($payload), $maxBucket, null);
    }
}
