<?php

namespace App\Console\Commands;

use App\Models\ScheduledJobRun;
use Illuminate\Console\Command;

class PruneScheduledJobRuns extends Command
{
    protected $signature = 'clockwork:prune-scheduled-job-runs
        {--days= : Retention window in days (overrides config)}';

    protected $description = 'Delete scheduled_job_runs rows older than the retention window, keeping the /settings/scheduled-jobs history bounded.';

    public function handle(): int
    {
        $override = $this->option('days');
        $days = $override !== null && $override !== ''
            ? (int) $override
            : (int) config('clockwork.scheduled_jobs.retention_days', 30);

        $cutoff = now()->subDays(max(1, $days));

        $deleted = ScheduledJobRun::query()->where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} scheduled job run row(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
