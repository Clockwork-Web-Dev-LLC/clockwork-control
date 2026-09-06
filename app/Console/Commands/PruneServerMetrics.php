<?php

namespace App\Console\Commands;

use App\Models\ServerMetric;
use Illuminate\Console\Command;

class PruneServerMetrics extends Command
{
    protected $signature = 'clockwork:prune-server-metrics {--days=90 : Retention window in days}';

    protected $description = 'Delete server_metrics rows older than the retention window (default 90 days).';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $deleted = ServerMetric::where('recorded_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} row(s) older than {$days} days (before {$cutoff->toDateTimeString()}).");

        return self::SUCCESS;
    }
}
