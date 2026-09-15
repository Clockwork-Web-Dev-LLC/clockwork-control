<?php

namespace App\Services\Fail2ban;

use App\Models\ActionLog;
use App\Models\BlockedIp;
use App\Services\ActionLog\ActionLogger;
use App\Support\Settings;
use Illuminate\Support\Carbon;

class BanRetention
{
    public const SETTING_RETENTION_MONTHS = 'bans.retention_months';

    public const DEFAULT_RETENTION_MONTHS = 12;

    /** Written to blocked_ips.decided_by when a row is archived by retention. */
    public const DECIDED_BY = 'retention';

    /** Age cutoffs the Bulk Clear UI may post. 0 = every active ban. */
    public const PRUNE_MONTH_OPTIONS = [0, 1, 3, 6, 12];

    public function __construct(
        protected Settings $settings,
        protected ActionLogger $logger,
    ) {}

    /**
     * Get the configured ban retention period in months (default: 12 months / 1 year).
     * A value of 0 means retention is disabled (bans kept indefinitely).
     */
    public function retentionMonths(): int
    {
        return (int) $this->settings->get(self::SETTING_RETENTION_MONTHS, self::DEFAULT_RETENTION_MONTHS);
    }

    /**
     * Update the configured ban retention period in months.
     */
    public function setRetentionMonths(int $months): void
    {
        $this->settings->put(self::SETTING_RETENTION_MONTHS, max(0, $months));
    }

    /**
     * Count active bans older than the specified number of months.
     * If $months is null, uses the configured retentionMonths().
     * If $months is 0, counts all active bans.
     */
    public function countBansOlderThan(?int $months = null): int
    {
        $months = $months ?? $this->retentionMonths();

        $query = BlockedIp::query()->whereNull('unbanned_at');

        if ($months > 0) {
            $query->where('banned_at', '<', Carbon::now()->subMonths($months));
        }

        return $query->count();
    }

    /**
     * Returns an active ban count breakdown by age intervals for UI preview.
     *
     * @return array{older_than_1m: int, older_than_3m: int, older_than_6m: int, older_than_12m: int, total: int}
     */
    public function breakdown(): array
    {
        $now = Carbon::now();

        return [
            'older_than_1m' => BlockedIp::query()->whereNull('unbanned_at')->where('banned_at', '<', $now->copy()->subMonth())->count(),
            'older_than_3m' => BlockedIp::query()->whereNull('unbanned_at')->where('banned_at', '<', $now->copy()->subMonths(3))->count(),
            'older_than_6m' => BlockedIp::query()->whereNull('unbanned_at')->where('banned_at', '<', $now->copy()->subMonths(6))->count(),
            'older_than_12m' => BlockedIp::query()->whereNull('unbanned_at')->where('banned_at', '<', $now->copy()->subMonths(12))->count(),
            'total' => BlockedIp::query()->whereNull('unbanned_at')->count(),
        ];
    }

    /**
     * Prune active bans older than the specified threshold by marking them unbanned.
     *
     * Because fail2ban releases bans from iptables on servers after 24 hours (bantime=86400),
     * pruning expired bans updates the Clockwork database state without expensive SSH round-trips.
     *
     * @param  int|null  $months  Threshold in months (defaults to configured retention). 0 = clear all active bans.
     * @param  string  $actor  ActionLog actor: `auto` for the nightly job, `manual` for the UI.
     * @return int Number of bans marked unbanned.
     */
    public function prune(?int $months = null, bool $dryRun = false, string $actor = 'auto'): int
    {
        $months = $months ?? $this->retentionMonths();

        $query = BlockedIp::query()->whereNull('unbanned_at');

        if ($months > 0) {
            $cutoff = Carbon::now()->subMonths($months);
            $query->where('banned_at', '<', $cutoff);
        }

        $count = $query->count();

        if ($count === 0 || $dryRun) {
            return $count;
        }

        $now = Carbon::now();

        // Perform chunked update so very large fleets update safely
        $pruned = 0;
        BlockedIp::query()
            ->whereNull('unbanned_at')
            ->when($months > 0, fn ($q) => $q->where('banned_at', '<', Carbon::now()->subMonths($months)))
            ->chunkById(1000, function ($batch) use ($now, &$pruned) {
                $ids = $batch->pluck('id')->all();
                $affected = BlockedIp::query()
                    ->whereIn('id', $ids)
                    ->update([
                        'unbanned_at' => $now,
                        'decided_by' => self::DECIDED_BY,
                    ]);
                $pruned += $affected;
            });

        $summary = $months > 0
            ? "Bulk pruned {$pruned} active bans older than {$months} month(s)."
            : "Bulk cleared all {$pruned} active bans.";

        $this->logger->record(
            actionType: ActionLog::TYPE_BAN_EXPIRED,
            summary: $summary,
            actor: $actor,
            details: [
                'months_cutoff' => $months,
                'pruned_count' => $pruned,
            ],
        );

        return $pruned;
    }
}
