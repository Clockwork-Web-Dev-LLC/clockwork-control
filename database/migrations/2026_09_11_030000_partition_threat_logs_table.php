<?php

use App\Services\Logs\ThreatLogPartitionedTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Threshold under which we can safely auto-rebuild into partitioned table
     * during a synchronous `migrate` run without risk of HTTP/process timeout.
     */
    public const AUTO_REBUILD_MAX_ROWS = 50_000;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $partitions = app(ThreatLogPartitionedTable::class);

        // SQLite in test environments or non-MySQL engines do not support RANGE partitioning.
        if (! $partitions->supportsPartitioning()) {
            return;
        }

        if ($partitions->isPartitioned()) {
            $partitions->ensureCurrentAndNextMonths();

            return;
        }

        if (! Schema::hasTable(ThreatLogPartitionedTable::TABLE)) {
            return;
        }

        $rowCount = DB::table(ThreatLogPartitionedTable::TABLE)->count();

        // Fresh installation or empty table: rebuild instantly (< 100ms)
        if ($rowCount === 0) {
            $fromMonth = now()->startOfMonth()->subMonths(1);
            $throughMonth = now()->startOfMonth()->addMonths(2);
            $partitions->createReplacement(ThreatLogPartitionedTable::TABLE, $fromMonth, $throughMonth);

            return;
        }

        // Small/moderate volume: can be rebuilt safely during migration (~1-3 seconds)
        if ($rowCount <= self::AUTO_REBUILD_MAX_ROWS) {
            Artisan::call('clockwork:rebuild-threat-logs-partitions');

            return;
        }

        // High volume tables (e.g. millions of rows / dozens of GBs):
        // Running a 50M row rebuild synchronously would block deployment or hit PHP max_execution_time.
        // Instead, leave unpartitioned and notify the operator to run the background command or use Settings.
        $message = "Notice: `threat_logs` has {$rowCount} rows and is unpartitioned. "
            .'To convert it to monthly partitions and reclaim InnoDB disk space, run: '
            ."'php artisan clockwork:rebuild-threat-logs-partitions' or click 'Rebuild partitions' in Settings -> Scheduling.";

        Log::warning('threat_logs.unpartitioned_high_volume', [
            'row_count' => $rowCount,
            'advice' => $message,
        ]);

        if (app()->runningInConsole()) {
            fwrite(STDERR, "\n\e[33m[WARNING]\e[0m {$message}\n\n");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverting partitioning is not necessary/destructive.
    }
};
