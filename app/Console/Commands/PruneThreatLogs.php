<?php

namespace App\Console\Commands;

use App\Models\ThreatLog;
use App\Services\Logs\ThreatLogPartitionedTable;
use App\Services\Logs\ThreatLogRetention;
use Illuminate\Console\Command;

class PruneThreatLogs extends Command
{
    protected $signature = 'clockwork:prune-threat-logs
        {--days= : Retention window in days (overrides the saved setting)}
        {--chunk=2000 : Rows to delete per batch (unpartitioned tables only)}
        {--max-rows=0 : Stop after this many deletes (0 = no cap)}
        {--dry-run : Report what would be removed without changing data}';

    protected $description = 'Drop threat_logs older than the retention window. Uses DROP PARTITION on MySQL when the table is partitioned; otherwise chunked DELETE.';

    public function handle(ThreatLogRetention $retention, ThreatLogPartitionedTable $partitions): int
    {
        $override = $this->option('days');
        $days = $retention->days($override !== null && $override !== '' ? (int) $override : null);
        $cutoff = $retention->cutoff($override !== null && $override !== '' ? (int) $override : null);

        if ($this->option('dry-run')) {
            if ($partitions->isPartitioned()) {
                $this->info("Dry run: would DROP monthly partitions wholly before {$cutoff->toDateTimeString()} ({$days} days).");

                return self::SUCCESS;
            }

            $count = ThreatLog::query()->where('event_at', '<', $cutoff)->count();
            $this->info("Dry run: {$count} row(s) older than {$days} days (before {$cutoff->toDateTimeString()}).");

            return self::SUCCESS;
        }

        if ($partitions->isPartitioned()) {
            $dropped = $partitions->dropPartitionsBefore($cutoff);
            $retention->recordRun(count($dropped));
            $this->info('Dropped '.count($dropped).' partition(s) wholly before '.$cutoff->toDateString().': '.(implode(', ', $dropped) ?: '(none)'));

            return self::SUCCESS;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $maxRows = max(0, (int) $this->option('max-rows'));
        $query = ThreatLog::query()->where('event_at', '<', $cutoff);

        $deleted = 0;
        do {
            $ids = (clone $query)->orderBy('id')->limit($chunk)->pluck('id');
            $batch = $ids->count();
            if ($batch === 0) {
                break;
            }

            ThreatLog::query()->whereIn('id', $ids)->delete();
            $deleted += $batch;

            if ($deleted === $batch || $deleted % 50_000 < $batch) {
                $this->info("… {$deleted} row(s) deleted");
            }

            if ($maxRows > 0 && $deleted >= $maxRows) {
                break;
            }

            usleep(20_000);
        } while ($batch > 0);

        $retention->recordRun($deleted);
        $this->info("Pruned {$deleted} row(s) older than {$days} days (before {$cutoff->toDateTimeString()}).");

        return self::SUCCESS;
    }
}
