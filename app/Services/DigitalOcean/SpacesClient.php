<?php

namespace App\Services\DigitalOcean;

use App\Models\Site;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use RuntimeException;

/**
 * Thin wrapper over the `do_spaces` filesystem disk for enumerating SpinupWP
 * backup files in the Clockwork-managed Spaces bucket.
 *
 * Why a wrapper instead of using Storage::disk('do_spaces') directly:
 *   - Centralises the SpinupWP filename-parsing convention so callers don't
 *     have to know how to extract date/type/size from the keys.
 *   - Gives one place for the "is this configured?" check + the per-bucket
 *     prefix templating logic.
 *   - Keeps ClockworkCompanion-the-pusher decoupled from S3 specifics — the
 *     pusher just asks "give me the history for site X".
 *
 * STUB STATUS (2026-05-03): credentials not yet configured. Methods will throw
 * `RuntimeException` until CLOCKWORK_DO_SPACES_KEY + CLOCKWORK_DO_SPACES_SECRET
 * are populated in .env. Once they are:
 *   1. The TODO blocks below get filled in
 *   2. `clockwork:push-companion-backups` starts including a `history` array
 *   3. The Backups admin page on the Companion side renders the table
 *
 * Filename convention (verified against live bucket 2026-05-03):
 *   <domain>/<YYYY-MM-DD-HH-MM-SS>-<suffix>.<ext>
 *
 * Where <suffix>.<ext> is one of:
 *   - <site_user>.sql.gz  (database backup, e.g. "siteuser.sql.gz")
 *   - files.tar.gz        (files backup)
 *
 * The timestamp prefix is shared between the .sql.gz and .tar.gz members of
 * the same backup run, so it's the natural grouping key. SpinupWP writes
 * both members within ~30 seconds of each other (different mtimes), but the
 * filename timestamp is the authoritative "backup time".
 */
class SpacesClient
{
    public function __construct(
        protected ?string $key = null,
        protected ?string $secret = null,
        protected ?string $region = null,
        protected ?string $bucket = null,
        protected ?string $prefixTemplate = null,
    ) {
        $this->key ??= (string) config('clockwork.do_spaces.key');
        $this->secret ??= (string) config('clockwork.do_spaces.secret');
        $this->region ??= (string) config('clockwork.do_spaces.region');
        $this->bucket ??= (string) config('clockwork.do_spaces.bucket');
        $this->prefixTemplate ??= (string) config('clockwork.do_spaces.prefix_template');
    }

    public function isConfigured(): bool
    {
        return $this->key !== '' && $this->secret !== '';
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function region(): string
    {
        return $this->region;
    }

    /**
     * Lazy disk accessor. Done as a method (not constructor injection) so that
     * the stub can be instantiated without immediately tripping disk
     * resolution — useful for test commands that print "not configured" and
     * exit cleanly.
     */
    protected function disk(): Filesystem
    {
        $this->guardConfigured();

        return Storage::disk('do_spaces');
    }

    /**
     * Cheap "do my credentials work?" probe — lists at most one object in the
     * bucket root. Used by `clockwork:do-spaces-test`.
     *
     * @return array{ok: bool, sample_keys: array<int, string>, message: ?string}
     */
    public function smokeTest(): array
    {
        if (! $this->isConfigured()) {
            return [
                'ok' => false,
                'sample_keys' => [],
                'message' => 'CLOCKWORK_DO_SPACES_KEY / CLOCKWORK_DO_SPACES_SECRET not set in .env.',
            ];
        }

        try {
            // listContents(<path>, recursive=false) on a Flysystem S3 disk
            // hits ListObjectsV2 with the given prefix. Empty path = bucket root.
            $contents = $this->disk()->listContents('', false);
            $sample = [];
            foreach ($contents as $i => $item) {
                if ($i >= 5) {
                    break;
                }
                $sample[] = $item->path();
            }

            return ['ok' => true, 'sample_keys' => $sample, 'message' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'sample_keys' => [], 'message' => $e->getMessage()];
        }
    }

    /**
     * List raw S3 objects under one site's backup prefix.
     *
     * @return array<int, array{key: string, size: int, last_modified: ?int}>
     */
    public function listSiteBackupObjects(Site $site): array
    {
        if (! $this->isConfigured() || ! $site->domain) {
            return [];
        }

        $prefix = str_replace(
            ['{domain}', '{site_id}'],
            [(string) $site->domain, (string) ($site->spinupwp_id ?? '')],
            $this->prefixTemplate
        );

        $rows = [];
        try {
            $listing = $this->disk()->listContents($prefix, true);
            foreach ($listing as $item) {
                if (! $item instanceof FileAttributes) {
                    continue;
                }
                $rows[] = [
                    'key' => $item->path(),
                    'size' => (int) ($item->fileSize() ?? 0),
                    'last_modified' => $item->lastModified(),
                ];
            }
        } catch (\Throwable $e) {
            // Surface upstream as an empty list — the puller treats "no spaces
            // history" as graceful degradation. Logging happens at the caller
            // layer where we know the site context.
            return [];
        }

        return $rows;
    }

    /**
     * Group raw S3 objects into per-run history rows the Backups admin page
     * renders. Grouping key is the timestamp prefix (shared between the .sql.gz
     * and .tar.gz members of the same backup run). Newest first.
     *
     * Output shape (matches BackupsPage::renderHistoryCard):
     *   [
     *     ['date' => '2026-05-03T07:00:43+00:00', 'type' => 'daily',
     *      'database_bytes' => 42561225, 'files_bytes' => 1077273968,
     *      'notes' => null],
     *     ...
     *   ]
     *
     * @param  array<int, array{key: string, size: int, last_modified: ?int}>  $objects
     * @return array<int, array{date: string, type: string, database_bytes: ?int, files_bytes: ?int, notes: ?string}>
     */
    public function toHistoryRows(array $objects): array
    {
        $byRun = [];

        foreach ($objects as $obj) {
            $parsed = $this->parseKey($obj['key']);
            if ($parsed === null) {
                continue;
            }
            [$timestampToken, $kind] = $parsed;
            $byRun[$timestampToken] ??= ['database_bytes' => null, 'files_bytes' => null];
            if ($kind === 'database') {
                $byRun[$timestampToken]['database_bytes'] = $obj['size'];
            } elseif ($kind === 'files') {
                $byRun[$timestampToken]['files_bytes'] = $obj['size'];
            }
        }

        // Newest first by timestamp token (lex-sort works because format is fixed-width
        // YYYY-MM-DD-HH-MM-SS).
        krsort($byRun);

        $out = [];
        foreach ($byRun as $token => $sizes) {
            $iso = $this->tokenToIso($token);
            if ($iso === null) {
                continue;
            }
            $out[] = [
                'date' => $iso,
                'type' => 'daily',
                'database_bytes' => $sizes['database_bytes'],
                'files_bytes' => $sizes['files_bytes'],
                'notes' => null,
            ];
        }

        return $out;
    }

    /**
     * Parse a SpinupWP backup key into [timestampToken, kind].
     *
     * Format: <domain>/<YYYY-MM-DD-HH-MM-SS>-<suffix>.{sql.gz|tar.gz}
     *
     * @return array{0: string, 1: string}|null
     */
    private function parseKey(string $key): ?array
    {
        $basename = basename($key);
        // Anchor on the full timestamp prefix so we don't accidentally match
        // unrelated objects in the bucket.
        if (! preg_match('/^(\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2})-(.+)$/', $basename, $m)) {
            return null;
        }
        $token = $m[1];
        $tail = $m[2];

        if (str_ends_with($tail, '.sql.gz')) {
            return [$token, 'database'];
        }
        if ($tail === 'files.tar.gz' || str_ends_with($tail, '-files.tar.gz')) {
            return [$token, 'files'];
        }

        return null;
    }

    private function tokenToIso(string $token): ?string
    {
        // YYYY-MM-DD-HH-MM-SS → DateTime (UTC, since SpinupWP writes UTC).
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})-(\d{2})-(\d{2})-(\d{2})$/', $token, $m)) {
            return null;
        }
        $iso = sprintf('%s-%s-%sT%s:%s:%sZ', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
        $ts = strtotime($iso);

        return $ts === false ? null : gmdate('c', $ts);
    }

    /**
     * Infer SpinupWP backup schedules (daily / weekly / monthly) from the
     * observed run history. SpinupWP's API only exposes the daily schedule;
     * the others have to be inferred from what actually shows up in the
     * bucket. Self-correcting — schedule changes in SpinupWP appear here
     * within a couple of refresh cycles as new patterns emerge.
     *
     * Heuristic per time-of-day group (rounded to nearest hour):
     *   - 5+ distinct days observed in window  → "daily" (confidence: high)
     *   - 2+ runs all on same weekday          → "weekly" (confidence: high)
     *   - 2+ runs all on same day-of-month     → "monthly" (confidence: high)
     *   - 1 run only                           → cadence inferred from time-
     *                                             slot heuristic, marked
     *                                             provisional: false → true
     *                                             once confirmed
     *
     * @param  array<int, array{date: string, type: string, database_bytes: ?int, files_bytes: ?int, notes: ?string}>  $historyRows
     * @return array<int, array{cadence: string, hour_utc: int, minute_utc: int, sample_at: string, day_of_week: ?string, day_of_month: ?int, occurrences: int, observed_retention_days: ?int, confirmed: bool}>
     */
    public function inferSchedules(array $historyRows): array
    {
        if ($historyRows === []) {
            return [];
        }

        // Group by hour-of-day in UTC. Rounded to hour because SpinupWP runs
        // at HH:00:NN where NN drifts a few seconds between days; the minute
        // is also stable but we don't need that precision to classify.
        $byHour = [];
        $allTs = [];
        foreach ($historyRows as $row) {
            if (empty($row['date'])) {
                continue;
            }
            $ts = strtotime((string) $row['date']);
            if ($ts === false) {
                continue;
            }
            $allTs[] = $ts;
            $hour = (int) gmdate('H', $ts);
            $byHour[$hour][] = $ts;
        }

        if ($byHour === []) {
            return [];
        }

        $now = time();

        // First pass: classify each hour-bucket using only its own data.
        $candidates = [];
        foreach ($byHour as $hour => $timestamps) {
            $sample = max($timestamps);
            $occurrences = count($timestamps);
            $oldest = min($timestamps);
            $retention = (int) floor(($now - $oldest) / 86400);

            $weekdays = array_unique(array_map(fn ($t) => (int) gmdate('w', $t), $timestamps));
            $monthDays = array_unique(array_map(fn ($t) => (int) gmdate('j', $t), $timestamps));
            $distinctDates = array_unique(array_map(fn ($t) => gmdate('Y-m-d', $t), $timestamps));

            if (count($distinctDates) >= 5) {
                $cadence = 'daily';
                $confirmed = true;
                $dow = null;
                $dom = null;
            } elseif (count($weekdays) === 1 && $occurrences >= 2) {
                $cadence = 'weekly';
                $confirmed = true;
                $dow = $this->dayName((int) reset($weekdays));
                $dom = null;
            } elseif (count($monthDays) === 1 && $occurrences >= 2) {
                $cadence = 'monthly';
                $confirmed = true;
                $dow = null;
                $dom = (int) reset($monthDays);
            } else {
                // Provisional — refine across buckets in pass two.
                $cadence = 'daily';
                $confirmed = false;
                $dow = null;
                $dom = null;
            }

            $candidates[$hour] = [
                'cadence' => $cadence,
                'hour_utc' => (int) $hour,
                'minute_utc' => (int) gmdate('i', $sample),
                'sample_at' => gmdate('c', $sample),
                'day_of_week' => $dow,
                'day_of_month' => $dom,
                'occurrences' => $occurrences,
                'observed_retention_days' => $retention,
                'confirmed' => $confirmed,
            ];
        }

        // Second pass: cross-bucket disambiguation for provisional buckets.
        // When multiple unconfirmed buckets coexist, the earliest hour on
        // Sunday/1st-of-month is most likely the weekly/monthly tier;
        // any other bucket is the daily.
        $unconfirmed = array_filter($candidates, fn ($c) => ! $c['confirmed']);
        if (count($unconfirmed) >= 2) {
            // Sort unconfirmed by hour ascending — earliest first
            uasort($unconfirmed, fn ($a, $b) => $a['hour_utc'] <=> $b['hour_utc']);
            $earliest = array_key_first($unconfirmed);
            $earliestSampleTs = strtotime($candidates[$earliest]['sample_at']);
            $earliestDow = (int) gmdate('w', $earliestSampleTs);
            $earliestDom = (int) gmdate('j', $earliestSampleTs);

            if ($earliestDow === 0) {
                $candidates[$earliest]['cadence'] = 'weekly';
                $candidates[$earliest]['day_of_week'] = 'Sunday';
            } elseif ($earliestDom === 1) {
                $candidates[$earliest]['cadence'] = 'monthly';
                $candidates[$earliest]['day_of_month'] = 1;
            }
            // Other unconfirmed buckets stay 'daily' (provisional).
        }

        $schedules = array_values($candidates);

        // Stable order: daily, weekly, monthly, then by hour.
        $rank = ['daily' => 0, 'weekly' => 1, 'monthly' => 2];
        usort($schedules, function ($a, $b) use ($rank) {
            $ra = $rank[$a['cadence']] ?? 9;
            $rb = $rank[$b['cadence']] ?? 9;

            return $ra <=> $rb ?: $a['hour_utc'] <=> $b['hour_utc'];
        });

        return $schedules;
    }

    private function dayName(int $w): string
    {
        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$w] ?? '';
    }

    /**
     * @throws RuntimeException
     */
    private function guardConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'DO Spaces is not configured. Set CLOCKWORK_DO_SPACES_KEY and CLOCKWORK_DO_SPACES_SECRET in .env, then run `php artisan clockwork:do-spaces-test` to verify.'
            );
        }
    }
}
