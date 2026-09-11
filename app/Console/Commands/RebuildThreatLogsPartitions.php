<?php

namespace App\Console\Commands;

use App\Services\Logs\ThreatLogPartitionedTable;
use App\Services\Logs\ThreatLogRetention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildThreatLogsPartitions extends Command
{
    protected $signature = 'clockwork:rebuild-threat-logs-partitions
        {--days= : Keep this many days of rows (default: saved retention, 30)}
        {--keep-old : Leave threat_logs_unpartitioned in place instead of dropping it}';

    protected $description = 'Rebuild threat_logs as monthly partitions and copy only the retention window. Reclaims InnoDB disk. MySQL only.';

    public function handle(ThreatLogPartitionedTable $partitions, ThreatLogRetention $retention): int
    {
        if (! $partitions->supportsPartitioning()) {
            $this->error('Partitioned threat_logs requires MySQL.');

            return self::FAILURE;
        }

        if ($partitions->isPartitioned()) {
            $this->info('threat_logs is already partitioned. Ensuring future months exist.');
            $partitions->ensureCurrentAndNextMonths();

            return self::SUCCESS;
        }

        $days = $retention->days($this->option('days') !== null && $this->option('days') !== '' ? (int) $this->option('days') : null);
        $cutoff = $retention->cutoff($this->option('days') !== null && $this->option('days') !== '' ? (int) $this->option('days') : null);
        $built = 'threat_logs_rebuilt';
        $retired = 'threat_logs_unpartitioned';

        $this->info("Building partitioned {$built}; copying rows from {$cutoff->toDateTimeString()} ({$days} days).");

        $fromMonth = $cutoff->copy()->startOfMonth();
        $throughMonth = now()->startOfMonth()->addMonths(2);

        DB::statement("SET time_zone = '+00:00'");
        DB::statement('SET SESSION foreign_key_checks = 0');
        DB::statement('SET SESSION unique_checks = 0');

        $partitions->createReplacement($built, $fromMonth, $throughMonth);
        $copied = $partitions->copyWindow(
            ThreatLogPartitionedTable::TABLE,
            $built,
            $cutoff,
            now()->addDay(),
            function (string $day, int $inserted, int $total) {
                $this->info("… {$day}: {$inserted} row(s) (running total {$total})");
            },
        );
        $this->info("Copied {$copied} row(s). Catching up last 48 hours…");
        $caught = $partitions->copyCatchup(ThreatLogPartitionedTable::TABLE, $built, now()->subDays(2));
        $this->info("Catch-up inserted {$caught} additional row(s).");

        $auto = $partitions->syncAutoIncrement($built, 1);
        $this->info("AUTO_INCREMENT on {$built} set to {$auto}.");

        if (DB::getSchemaBuilder()->hasTable($retired)) {
            DB::statement('DROP TABLE `'.$retired.'`');
        }

        $partitions->swap($built, ThreatLogPartitionedTable::TABLE, $retired);
        $this->info('Live table is now partitioned. Copying any rows that arrived during the swap…');
        $late = $partitions->copyCatchup($retired, ThreatLogPartitionedTable::TABLE, now()->subHours(2));
        $this->info("Post-swap catch-up inserted {$late} row(s).");
        $partitions->syncAutoIncrement(ThreatLogPartitionedTable::TABLE, $auto);

        if ($this->option('keep-old')) {
            $this->warn("Left `{$retired}` in place. Drop it after you confirm the app.");
        } else {
            $this->info("Dropping `{$retired}` to reclaim disk…");
            DB::statement('DROP TABLE `'.$retired.'`');
            $this->info('Old unpartitioned table dropped.');
        }

        return self::SUCCESS;
    }
}
