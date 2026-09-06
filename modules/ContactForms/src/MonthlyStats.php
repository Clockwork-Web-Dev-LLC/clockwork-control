<?php

namespace Modules\ContactForms;

use App\Models\ContactFormTestRun;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Per-site stats for a single calendar month, used by the monthly summary
 * email. Computed from contact_form_test_runs rows in PHP (small N — at
 * most ~31 rows per site per month at the daily cadence) for clarity.
 */
class MonthlyStats
{
    public function __construct(
        public readonly string $monthLabel,        // 'November 2026'
        public readonly int $totalRuns,
        public readonly int $passedRuns,
        public readonly int $failedRuns,
        public readonly int $longestOutageMinutes, // 0 if no outage
        public readonly ?Carbon $longestOutageStart,
        public readonly ?Carbon $longestOutageEnd,
        public readonly ?Carbon $lastRunAt,
        public readonly ?string $lastRunStatus,    // success | failed | null
    ) {}

    public function passRatePct(): int
    {
        if ($this->totalRuns === 0) {
            return 0;
        }

        return (int) round(($this->passedRuns / $this->totalRuns) * 100);
    }

    public static function forSiteMonth(Site $site, CarbonImmutable $monthStart): self
    {
        $monthEnd = $monthStart->endOfMonth();

        $runs = $site->contactFormTestRuns()
            ->whereBetween('ran_at', [$monthStart, $monthEnd])
            ->orderBy('ran_at')
            ->get();

        $total = $runs->count();
        $passed = $runs->where('status', ContactFormTestRun::STATUS_SUCCESS)->count();
        $failed = $total - $passed;

        // Longest outage = longest contiguous run of failed test results.
        // Computed by walking the time-ordered runs and tracking the current
        // failure window. Approximates the actual outage as start-of-first-fail
        // → end-of-last-fail; gaps between daily runs of up to 24h are normal.
        $longestMinutes = 0;
        $longestStart = null;
        $longestEnd = null;
        $currentStart = null;
        $currentEnd = null;

        foreach ($runs as $run) {
            if ($run->status === ContactFormTestRun::STATUS_FAILED) {
                $currentStart ??= $run->ran_at;
                $currentEnd = $run->ran_at;
            } else {
                if ($currentStart) {
                    $minutes = (int) $currentStart->diffInMinutes($currentEnd);
                    if ($minutes > $longestMinutes) {
                        $longestMinutes = $minutes;
                        $longestStart = $currentStart;
                        $longestEnd = $currentEnd;
                    }
                }
                $currentStart = null;
                $currentEnd = null;
            }
        }
        // Outage that's still ongoing at month end
        if ($currentStart) {
            $minutes = (int) $currentStart->diffInMinutes($currentEnd);
            if ($minutes > $longestMinutes) {
                $longestMinutes = $minutes;
                $longestStart = $currentStart;
                $longestEnd = $currentEnd;
            }
        }

        $last = $runs->last();

        return new self(
            monthLabel: $monthStart->format('F Y'),
            totalRuns: $total,
            passedRuns: $passed,
            failedRuns: $failed,
            longestOutageMinutes: $longestMinutes,
            longestOutageStart: $longestStart,
            longestOutageEnd: $longestEnd,
            lastRunAt: $last?->ran_at,
            lastRunStatus: $last?->status,
        );
    }
}
