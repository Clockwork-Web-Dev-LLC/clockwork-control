<?php

namespace App\Console\Commands;

use App\Services\Fail2ban\BanRetention;
use Illuminate\Console\Command;

class PruneExpiredBans extends Command
{
    protected $signature = 'clockwork:prune-expired-bans
        {--months= : Override the configured retention window in months}
        {--dry-run : Report what would be pruned without mutating the database}';

    protected $description = 'Prune active bans that have exceeded the retention window by marking them unbanned.';

    public function handle(BanRetention $retention): int
    {
        $monthsOption = $this->option('months');
        // `--months=0` is a legitimate "prune all" override. PHP treats the
        // string `'0'` as falsy, so we must key off null/empty — not truthiness.
        $monthsSpecified = $monthsOption !== null && $monthsOption !== '';
        $months = $monthsSpecified ? max(0, (int) $monthsOption) : $retention->retentionMonths();
        $dryRun = (bool) $this->option('dry-run');

        if ($months === 0 && ! $monthsSpecified) {
            $this->info('Ban retention is set to indefinite (0 months). No bans were pruned.');

            return self::SUCCESS;
        }

        $label = $months > 0 ? "older than {$months} month(s)" : 'all active bans';
        $this->info("Checking for active bans {$label}...");

        $count = $retention->prune($months, dryRun: $dryRun);

        if ($dryRun) {
            $this->info("[Dry run] Would prune {$count} active ban(s) {$label}.");

            return self::SUCCESS;
        }

        $this->info("Pruned {$count} active ban(s) {$label}.");

        return self::SUCCESS;
    }
}
