<?php

namespace App\Console\Commands;

use App\Models\ActionLog;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use Illuminate\Console\Command;

/**
 * One-shot backfill: seed a single 'up' uptime_transition action_log for every
 * site that's currently up but has no transition history.
 *
 * Why this exists: the Companion Uptime page renders 100% / green UP circle
 * from the action_log mirror. A site that has never gone down (most of the
 * fleet) had no events to push, so the page sat in its empty state forever.
 * The forward fix in UptimeStateUpdater::handleSuccess() seeds new sites on
 * unknown → up, but existing sites already past that boundary need this
 * one-time backfill.
 *
 * Idempotent: skips any site that already has a TYPE_UPTIME_TRANSITION row.
 * The seed event uses uptime_last_up_at (or now() as fallback) as ran_at, so
 * Companion's percentage windows count from when the site was first verified.
 */
class BackfillUptimeSeed extends Command
{
    protected $signature = 'clockwork:backfill-uptime-seed
                            {--site= : Limit to a single site domain or ID}
                            {--dry-run : Report what would be seeded without writing}';

    protected $description = 'Seed an initial up event for sites currently up with no transition history.';

    public function handle(ActionLogger $logger): int
    {
        $query = Site::query()
            ->where('uptime_monitoring_enabled', true)
            ->where('uptime_state', 'up');

        if ($single = $this->option('site')) {
            $query->where(function ($q) use ($single) {
                $q->where('id', $single)->orWhere('domain', $single);
            });
        }

        $sites = $query->get();
        $seeded = 0;
        $skipped = 0;

        foreach ($sites as $site) {
            $hasHistory = ActionLog::query()
                ->where('site_id', $site->id)
                ->where('action_type', ActionLog::TYPE_UPTIME_TRANSITION)
                ->exists();

            if ($hasHistory) {
                $skipped++;

                continue;
            }

            $confirmedAt = $site->uptime_last_up_at ?? now();

            $this->line("  seed → {$site->domain} (confirmed up at {$confirmedAt})");

            if ($this->option('dry-run')) {
                $seeded++;

                continue;
            }

            $logger->record(
                actionType: ActionLog::TYPE_UPTIME_TRANSITION,
                summary: "{$site->domain} monitoring started — confirmed up",
                site: $site,
                target: 'up',
                details: ['status_code' => $site->uptime_last_status_code, 'seed' => true, 'backfilled' => true],
                ok: true,
                actor: 'backfill',
                ranAt: $confirmedAt,
            );

            $seeded++;
        }

        $this->info("Seeded: {$seeded} | Skipped (already had history): {$skipped} | Total scanned: {$sites->count()}");

        return self::SUCCESS;
    }
}
