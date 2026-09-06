<?php

namespace App\Console\Commands;

use App\Models\ReviewQueueEntry;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Promote any pending review-queue entry whose IP has accumulated 2+ lockout
 * occurrences fleet-wide. Mirrors the manual bulk-approve flow — entries are
 * marked queued_for_ban and the existing clockwork:process-pending-bans worker
 * does the actual fail2ban dispatch on the next tick.
 *
 * Trigger threshold satisfies BOTH of the user-defined cases:
 *   - same site banned twice  → one entry with evidence.occurrences = 2
 *   - banned on two servers   → two entries with evidence.occurrences = 1 each
 *
 * Gated by Settings('auto_approve_repeats_enabled') so the user can flip it off
 * from the UI without touching the scheduler.
 */
class AutoApproveRepeats extends Command
{
    public const DECIDED_BY = 'auto-repeat';

    protected $signature = 'clockwork:auto-approve-repeats
        {--threshold=2 : Total occurrences across pending entries that triggers promotion}
        {--force : Run even if the setting is disabled (for ad-hoc sweeps)}';

    protected $description = 'Promote review-queue entries for IPs with 2+ lockouts to queued_for_ban (when enabled).';

    public function handle(Settings $settings): int
    {
        $enabled = (bool) $settings->get('auto_approve_repeats_enabled', false);

        if (! $enabled && ! $this->option('force')) {
            return self::SUCCESS;
        }

        $threshold = max(1, (int) $this->option('threshold'));
        $promoted = $this->sweep($threshold);

        $this->info("Promoted {$promoted} pending entr".($promoted === 1 ? 'y' : 'ies')." (threshold ≥ {$threshold}).");

        return self::SUCCESS;
    }

    /**
     * Single-pass sweep. Returns the number of pending rows promoted.
     * Public so the controller can call it synchronously when the toggle flips ON.
     */
    public function sweep(int $threshold = 2): int
    {
        $pending = ReviewQueueEntry::query()
            ->where('status', ReviewQueueEntry::STATUS_PENDING)
            ->get(['id', 'ip', 'evidence']);

        $promoted = 0;
        $now = Carbon::now();

        foreach ($pending->groupBy('ip') as $ip => $group) {
            $totalOccurrences = $group->sum(function ($entry) {
                $evidence = $entry->evidence ?? [];

                return (int) ($evidence['occurrences'] ?? 1);
            });

            if ($totalOccurrences < $threshold) {
                continue;
            }

            $ids = $group->pluck('id')->all();

            $rows = ReviewQueueEntry::query()
                ->whereIn('id', $ids)
                ->where('status', ReviewQueueEntry::STATUS_PENDING)
                ->update([
                    'status' => ReviewQueueEntry::STATUS_QUEUED_FOR_BAN,
                    'decided_at' => $now,
                    'decided_by' => self::DECIDED_BY,
                ]);

            $promoted += $rows;

            Log::info('auto_approve_repeats.promoted', [
                'ip' => $ip,
                'entry_ids' => $ids,
                'total_occurrences' => $totalOccurrences,
                'rows_updated' => $rows,
            ]);
        }

        return $promoted;
    }
}
