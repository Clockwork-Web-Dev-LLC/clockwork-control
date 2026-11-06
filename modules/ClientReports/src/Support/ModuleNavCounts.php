<?php

namespace Modules\ClientReports\Support;

use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\ClientReports\Models\ClientReportSchedule;
use Modules\ClientReports\Services\ReportBrowser;
use Throwable;

/**
 * The numbers on the module's tabs: how many of the latest period's
 * reports are waiting for someone, and how many care-plan sites have no
 * report schedule. Worked out once per request.
 */
final class ModuleNavCounts
{
    /** @return array{period: ?string, period_label: ?string, waiting: int, review: int, missing: int} */
    public static function get(): array
    {
        return once(function () {
            try {
                $browser = app(ReportBrowser::class);
                $period = $browser->latestPeriod();
                $waiting = $review = 0;
                if ($period !== null) {
                    foreach ($browser->list(new ReportListFilters(period: $period)) as $report) {
                        $state = ReportState::of($report);
                        $waiting += in_array($state, ReportState::WAITING, true) ? 1 : 0;
                        $review += in_array($state, [ReportState::NEEDS_REVIEW, ReportState::OUTDATED], true) ? 1 : 0;
                    }
                }

                return [
                    'period' => $period,
                    'period_label' => $period !== null ? Carbon::createFromFormat('!Y-m', $period)->format('M Y') : null,
                    'waiting' => $waiting,
                    'review' => $review,
                    'missing' => self::sitesMissingSchedule(),
                ];
            } catch (Throwable $e) {
                Log::warning('client_reports.nav_counts_failed', ['error' => $e->getMessage()]);

                return ['period' => null, 'period_label' => null, 'waiting' => 0, 'review' => 0, 'missing' => 0];
            }
        });
    }

    /** Active care-plan sites (all active sites when care plans are off fleet-wide) with no report schedule. */
    public static function sitesMissingSchedule(): int
    {
        return Site::query()
            ->where('is_inactive', false)
            ->carePlanEligible()
            ->whereNotIn('id', ClientReportSchedule::query()->select('site_id'))
            ->count();
    }
}
