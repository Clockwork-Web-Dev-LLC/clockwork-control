<?php

namespace App\Services\Logs;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly RANGE (UNIX_TIMESTAMP(event_at)) partitions on threat_logs so
 * DROP PARTITION reclaims disk. InnoDB DELETE does not shrink the .ibd.
 * TIMESTAMP cannot use RANGE COLUMNS; UNIX_TIMESTAMP() is the MySQL-supported form.
 *
 * Foreign keys are omitted: MySQL does not allow FKs on partitioned InnoDB
 * tables. site_id stays indexed; site deletes no longer cascade here.
 */
class ThreatLogPartitionedTable
{
    public const TABLE = 'threat_logs';

    public function supportsPartitioning(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    public function isPartitioned(?string $table = self::TABLE): bool
    {
        if (! $this->supportsPartitioning()) {
            return false;
        }

        $row = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL',
            [$table],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    /**
     * Return partition and sizing stats for UI inspection.
     *
     * @return array{
     *   supported: bool,
     *   partitioned: bool,
     *   partitions: list<string>,
     *   row_count: int,
     *   data_bytes: int,
     *   index_bytes: int,
     *   binlog_expire_seconds: ?int
     * }
     */
    public function status(?string $table = self::TABLE): array
    {
        $table ??= self::TABLE;
        if (! $this->supportsPartitioning()) {
            $rowCount = 0;
            try {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    $rowCount = DB::table($table)->count();
                }
            } catch (\Throwable) {
                // Ignore query errors in unusual test contexts
            }

            return [
                'supported' => false,
                'partitioned' => false,
                'partitions' => [],
                'row_count' => $rowCount,
                'data_bytes' => 0,
                'index_bytes' => 0,
                'binlog_expire_seconds' => null,
            ];
        }

        $rows = DB::select(
            'SELECT PARTITION_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
             FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );

        $partitionNames = [];
        $totalRows = 0;
        $totalDataBytes = 0;
        $totalIndexBytes = 0;
        $hasPartitions = false;

        foreach ($rows as $row) {
            if ($row->PARTITION_NAME !== null) {
                $hasPartitions = true;
                $partitionNames[] = (string) $row->PARTITION_NAME;
            }
            $totalRows += (int) ($row->TABLE_ROWS ?? 0);
            $totalDataBytes += (int) ($row->DATA_LENGTH ?? 0);
            $totalIndexBytes += (int) ($row->INDEX_LENGTH ?? 0);
        }

        $binlogExpire = null;
        try {
            $val = DB::selectOne('SELECT @@binlog_expire_logs_seconds AS s');
            if ($val && isset($val->s)) {
                $binlogExpire = (int) $val->s;
            }
        } catch (\Throwable) {
            // Non-admin user or variable unavailable
        }

        return [
            'supported' => true,
            'partitioned' => $hasPartitions,
            'partitions' => $partitionNames,
            'row_count' => $totalRows,
            'data_bytes' => $totalDataBytes,
            'index_bytes' => $totalIndexBytes,
            'binlog_expire_seconds' => $binlogExpire,
        ];
    }

    /**
     * @return list<string>
     */
    public function createTableSql(string $table, Carbon $fromMonth, Carbon $throughMonth): array
    {
        $partitions = [];
        $cursor = $fromMonth->copy()->startOfMonth();
        $end = $throughMonth->copy()->startOfMonth();
        while ($cursor->lte($end)) {
            $next = $cursor->copy()->addMonth();
            $name = 'p'.$cursor->format('Ym');
            $partitions[] = "PARTITION {$name} VALUES LESS THAN ({$this->unixBoundSql($next)})";
            $cursor = $next;
        }
        $partitions[] = 'PARTITION pmax VALUES LESS THAN MAXVALUE';

        $sql = 'CREATE TABLE `'.$table.'` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `site_id` bigint unsigned NOT NULL,
  `source` varchar(255) NOT NULL,
  `event_at` timestamp NOT NULL,
  `ip` varchar(45) NOT NULL,
  `user_agent` varchar(1024) DEFAULT NULL,
  `request_path` varchar(2048) DEFAULT NULL,
  `request_method` varchar(16) DEFAULT NULL,
  `status_code` smallint unsigned DEFAULT NULL,
  `raw` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`, `event_at`),
  KEY `threat_logs_site_id_event_at_index` (`site_id`, `event_at`),
  KEY `threat_logs_ip_event_at_index` (`ip`, `event_at`),
  KEY `threat_logs_event_at_index` (`event_at`),
  KEY `threat_logs_ip_index` (`ip`),
  KEY `threat_logs_event_at_request_path_index` (`event_at`, `request_path`(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (UNIX_TIMESTAMP(`event_at`)) (
  '.implode(",\n  ", $partitions).'
)';

        return [$sql];
    }

    public function createReplacement(string $table, Carbon $fromMonth, Carbon $throughMonth): void
    {
        DB::statement("SET time_zone = '+00:00'");
        DB::statement('DROP TABLE IF EXISTS `'.$table.'`');
        foreach ($this->createTableSql($table, $fromMonth, $throughMonth) as $sql) {
            DB::statement($sql);
        }
    }

    public function copyWindow(string $from, string $to, Carbon $since, Carbon $until, ?callable $afterDay = null): int
    {
        $copied = 0;
        $day = $since->copy()->startOfDay();
        $end = $until->copy()->startOfDay()->addDay();

        while ($day->lt($end)) {
            $next = $day->copy()->addDay();
            $inserted = DB::affectingStatement(
                "INSERT INTO `{$to}` (`id`, `site_id`, `source`, `event_at`, `ip`, `user_agent`, `request_path`, `request_method`, `status_code`, `raw`, `created_at`, `updated_at`)
                 SELECT `id`, `site_id`, `source`, `event_at`, `ip`, `user_agent`, `request_path`, `request_method`, `status_code`, `raw`, `created_at`, `updated_at`
                 FROM `{$from}`
                 WHERE `event_at` >= ? AND `event_at` < ?",
                [$day->toDateTimeString(), $next->toDateTimeString()],
            );
            $copied += $inserted;
            if ($afterDay) {
                $afterDay($day->toDateString(), $inserted, $copied);
            }
            $day = $next;
        }

        return $copied;
    }

    /**
     * Recopy recent rows that landed on $from after a day was already copied.
     */
    public function copyCatchup(string $from, string $to, Carbon $since): int
    {
        return DB::affectingStatement(
            "INSERT IGNORE INTO `{$to}` (`id`, `site_id`, `source`, `event_at`, `ip`, `user_agent`, `request_path`, `request_method`, `status_code`, `raw`, `created_at`, `updated_at`)
             SELECT `id`, `site_id`, `source`, `event_at`, `ip`, `user_agent`, `request_path`, `request_method`, `status_code`, `raw`, `created_at`, `updated_at`
             FROM `{$from}`
             WHERE `event_at` >= ?",
            [$since->toDateTimeString()],
        );
    }

    public function syncAutoIncrement(string $table, int $atLeast = 0): int
    {
        $max = (int) (DB::selectOne('SELECT COALESCE(MAX(`id`), 0) AS m FROM `'.$table.'`')->m ?? 0);
        $next = max($max + 1, $atLeast);
        DB::statement('ALTER TABLE `'.$table.'` AUTO_INCREMENT = '.$next);

        return $next;
    }

    public function swap(string $built, string $live, string $retired): void
    {
        DB::statement('RENAME TABLE `'.$live.'` TO `'.$retired.'`, `'.$built.'` TO `'.$live.'`');
    }

    /**
     * Drop monthly partitions whose upper bound is still at or before $cutoff.
     *
     * @return list<string>
     */
    public function dropPartitionsBefore(Carbon $cutoff): array
    {
        DB::statement("SET time_zone = '+00:00'");
        $rows = DB::select(
            'SELECT PARTITION_NAME, PARTITION_DESCRIPTION
             FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL
             ORDER BY PARTITION_ORDINAL_POSITION',
            [self::TABLE],
        );

        $dropped = [];
        foreach ($rows as $row) {
            $name = (string) $row->PARTITION_NAME;
            if ($name === 'pmax') {
                continue;
            }
            $boundAt = $this->boundToCarbon((string) $row->PARTITION_DESCRIPTION);
            if ($boundAt === null) {
                continue;
            }
            if ($boundAt->lte($cutoff)) {
                DB::statement('ALTER TABLE `'.self::TABLE.'` DROP PARTITION `'.$name.'`');
                $dropped[] = $name;
            }
        }

        $this->ensureCurrentAndNextMonths();

        return $dropped;
    }

    public function ensureCurrentAndNextMonths(): void
    {
        if (! $this->isPartitioned()) {
            return;
        }

        DB::statement("SET time_zone = '+00:00'");

        $existing = collect(DB::select(
            'SELECT PARTITION_NAME FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL',
            [self::TABLE],
        ))->pluck('PARTITION_NAME')->all();

        foreach ([0, 1, 2] as $offset) {
            $month = now()->startOfMonth()->addMonths($offset);
            $name = 'p'.$month->format('Ym');
            if (in_array($name, $existing, true)) {
                continue;
            }
            $boundSql = $this->unixBoundSql($month->copy()->addMonth());
            DB::statement(
                'ALTER TABLE `'.self::TABLE.'` REORGANIZE PARTITION pmax INTO (
                    PARTITION '.$name.' VALUES LESS THAN ('.$boundSql.'),
                    PARTITION pmax VALUES LESS THAN MAXVALUE
                )',
            );
            $existing[] = $name;
        }
    }

    private function unixBoundSql(Carbon $exclusiveUpper): string
    {
        return "UNIX_TIMESTAMP('".$exclusiveUpper->copy()->utc()->format('Y-m-d 00:00:00')."')";
    }

    private function boundToCarbon(string $bound): ?Carbon
    {
        $bound = trim($bound, "\"'");
        if ($bound === '' || strtoupper($bound) === 'MAXVALUE') {
            return null;
        }
        if (ctype_digit($bound)) {
            return Carbon::createFromTimestamp((int) $bound, 'UTC');
        }
        if (preg_match("/UNIX_TIMESTAMP\\('([^']+)'\\)/i", $bound, $m) === 1) {
            return Carbon::parse($m[1], 'UTC');
        }

        return Carbon::parse($bound, 'UTC');
    }
}
