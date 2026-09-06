<?php

namespace App\Console\Commands;

use App\Models\ActionLog;
use App\Models\Site;
use App\Services\Chat\ChatNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Daily sweep for two "silently stuck" Companion states that nothing else
 * catches:
 *
 *   1. STUCK INSTALL — an install attempt failed (a companion_install
 *      action_logs row with ok=false) and companion_installed is still
 *      false, with no successful attempt since. 24h grace period so a site
 *      an operator is actively retrying right now doesn't alert mid
 *      troubleshooting. This is exactly the state two real sites were in
 *      on 2026-08-31 (tourscompany.example, auctioneersite.example)
 *      before being found by manually re-checking companion-canary-set
 *      --list — nothing would have surfaced them on its own, and in fact
 *      CompanionCanaryDeploy wasn't even writing the failure to
 *      action_logs at all until ActionLogger::recordCompanionInstall was
 *      added alongside this command.
 *
 *   2. STALE SNAPSHOT — companion_installed=true but neither
 *      companion_snapshot_at nor companion_last_seen_at has moved in 3+
 *      days. The nightly refresh-companion-snapshot run (01:30 ET) should
 *      touch this daily; 3 days tolerates a couple of missed/failed runs
 *      before treating it as a real problem rather than a blip.
 *
 * State lives on sites.companion_stuck_since / companion_stuck_reason —
 * same "state + timestamp, alert only on transition" shape as
 * ContactFormTest's failure_streak / state_changed_at. An alert fires once
 * when a site newly becomes stuck; a recovery alert fires once when it
 * clears. Re-running this daily against an already-alerted site is a
 * silent no-op until the underlying state actually changes.
 */
class DetectStuckCompanionState extends Command
{
    public const INSTALL_FAILURE_GRACE_HOURS = 24;

    public const SNAPSHOT_STALE_DAYS = 3;

    public const REASON_INSTALL_FAILED = 'install_failed';

    public const REASON_SNAPSHOT_STALE = 'snapshot_stale';

    protected $signature = 'clockwork:detect-stuck-companion-state
        {--dry-run : Report what would change without writing to sites or sending alerts}';

    protected $description = 'Daily sweep for Companion sites stuck in a failed-install or gone-silent state that nothing else alerts on.';

    public function handle(ChatNotifier $chatNotifier): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $installFailedIds = $this->stuckInstallSiteIds();
        $snapshotStaleIds = $this->staleSnapshotSiteIds();
        $currentlyStuckIds = Site::query()->whereNotNull('companion_stuck_since')->pluck('id');

        $candidateIds = $currentlyStuckIds
            ->merge($installFailedIds)
            ->merge($snapshotStaleIds)
            ->unique();

        if ($candidateIds->isEmpty()) {
            $this->info('No stuck or previously-stuck Companion sites found.');

            return self::SUCCESS;
        }

        $newlyStuck = 0;
        $stillStuck = 0;
        $recovered = 0;

        foreach (Site::query()->whereIn('id', $candidateIds)->orderBy('domain')->get() as $site) {
            $reason = match (true) {
                $installFailedIds->contains($site->id) => self::REASON_INSTALL_FAILED,
                $snapshotStaleIds->contains($site->id) => self::REASON_SNAPSHOT_STALE,
                default => null,
            };

            $wasStuck = $site->companion_stuck_since !== null;

            if ($reason !== null) {
                if (! $wasStuck) {
                    $newlyStuck++;
                    $this->warn("  NEW STUCK   {$site->domain} ({$reason})");
                    if (! $dryRun) {
                        $site->forceFill([
                            'companion_stuck_since' => Carbon::now(),
                            'companion_stuck_reason' => $reason,
                        ])->save();
                        $chatNotifier->companionUnreachable($site, $this->reasonText($reason, $site));
                    }
                } else {
                    $stillStuck++;
                    if (! $dryRun && $site->companion_stuck_reason !== $reason) {
                        $site->forceFill(['companion_stuck_reason' => $reason])->save();
                    }
                }
            } elseif ($wasStuck) {
                $recovered++;
                $stuckSeconds = $site->companion_stuck_since->diffInSeconds(Carbon::now());
                $this->info("  RECOVERED   {$site->domain} (was stuck ".$site->companion_stuck_since->diffForHumans(null, true).')');
                if (! $dryRun) {
                    $site->forceFill([
                        'companion_stuck_since' => null,
                        'companion_stuck_reason' => null,
                    ])->save();
                    $chatNotifier->companionReachable($site, $stuckSeconds);
                }
            }
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}Done. newly_stuck={$newlyStuck} still_stuck={$stillStuck} recovered={$recovered}");

        return self::SUCCESS;
    }

    /**
     * Sites where the latest companion_install action_logs row is a
     * failure, companion_installed is still false, and that failure is
     * older than the grace period. Bounded to sites that have EVER logged
     * a failed install attempt (cheap distinct pluck) before doing the
     * per-site "what's the latest row" lookup, so this stays fast even on
     * a large fleet — most companion_installed=false sites never had an
     * install attempted at all and are filtered out before the loop.
     *
     * @return Collection<int, int>
     */
    private function stuckInstallSiteIds(): Collection
    {
        $everFailedSiteIds = ActionLog::query()
            ->where('action_type', ActionLog::TYPE_COMPANION_INSTALL)
            ->where('ok', false)
            ->whereNotNull('site_id')
            ->distinct()
            ->pluck('site_id');

        if ($everFailedSiteIds->isEmpty()) {
            return collect();
        }

        $cutoff = Carbon::now()->subHours(self::INSTALL_FAILURE_GRACE_HOURS);

        return Site::query()
            ->whereIn('id', $everFailedSiteIds)
            ->where('is_wordpress', true)
            ->where('companion_installed', false)
            ->hostMonitored()
            ->get(['id'])
            ->filter(function (Site $site) use ($cutoff) {
                $latest = ActionLog::query()
                    ->where('site_id', $site->id)
                    ->where('action_type', ActionLog::TYPE_COMPANION_INSTALL)
                    ->latest('ran_at')
                    ->first();

                return $latest !== null && ! $latest->ok && $latest->ran_at->lt($cutoff);
            })
            ->pluck('id');
    }

    /**
     * companion_installed=true sites where neither companion_snapshot_at
     * nor its companion_last_seen_at fallback (set at install time and on
     * every successful Companion call) has moved in SNAPSHOT_STALE_DAYS.
     * Sites where both are null are skipped rather than flagged — that's
     * a genuinely fresh install still waiting on its first nightly pass,
     * not a stuck one.
     *
     * @return Collection<int, int>
     */
    private function staleSnapshotSiteIds(): Collection
    {
        $cutoff = Carbon::now()->subDays(self::SNAPSHOT_STALE_DAYS);

        return Site::query()
            ->where('is_wordpress', true)
            ->where('companion_installed', true)
            ->hostMonitored()
            ->where(function ($q) use ($cutoff) {
                $q->where(function ($qq) use ($cutoff) {
                    $qq->whereNotNull('companion_snapshot_at')->where('companion_snapshot_at', '<', $cutoff);
                })->orWhere(function ($qq) use ($cutoff) {
                    $qq->whereNull('companion_snapshot_at')
                        ->whereNotNull('companion_last_seen_at')
                        ->where('companion_last_seen_at', '<', $cutoff);
                });
            })
            ->pluck('id');
    }

    private function reasonText(string $reason, Site $site): string
    {
        return match ($reason) {
            self::REASON_INSTALL_FAILED => sprintf(
                'An install attempt failed more than %dh ago and has not been retried successfully since. Check this site\'s history for the failure detail.',
                self::INSTALL_FAILURE_GRACE_HOURS,
            ),
            self::REASON_SNAPSHOT_STALE => sprintf(
                "Companion is marked installed but hasn't reported in for %d+ days (last seen: %s). Likely the plugin was removed, broken, or the site is unreachable.",
                self::SNAPSHOT_STALE_DAYS,
                $site->companion_snapshot_at?->diffForHumans() ?? $site->companion_last_seen_at?->diffForHumans() ?? 'never',
            ),
            default => 'Unknown reason.',
        };
    }
}
