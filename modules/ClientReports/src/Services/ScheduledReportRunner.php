<?php

namespace Modules\ClientReports\Services;

use Illuminate\Support\Carbon;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportSchedule;

/**
 * One scheduled report cycle, shared by `clockwork:send-client-reports` and the
 * schedule's "Send now" button so the two can't drift apart.
 *
 * Callers are responsible for the guards (enabled, due, active site, auto mode
 * having recipients) — this only does the work.
 */
class ScheduledReportRunner
{
    public function __construct(
        protected ClientReportCompiler $compiler,
        protected ClientReportSender $sender,
    ) {}

    public function run(ClientReportSchedule $schedule): ScheduledReportRun
    {
        $start = now()->subMonth()->startOfMonth();
        $end = now()->subMonth()->endOfMonth();
        $recipients = array_values(array_filter((array) $schedule->recipients));

        if ($schedule->delivery_mode === ClientReportSchedule::MODE_DRAFT) {
            $report = $this->compile($schedule, $start, $end, $recipients);
            $schedule->advanceNextRun();

            return new ScheduledReportRun($report, isDraft: true, resumed: false);
        }

        // A previous run for this period that didn't reach everyone is resumed
        // rather than recompiled, so recipients who already got it aren't
        // emailed a second copy.
        $report = $this->unfinishedReport($schedule, $start, $end);
        $resumed = $report !== null;
        $report ??= $this->compile($schedule, $start, $end, $recipients);

        $pending = array_values(array_diff($recipients, $report->sent_to ?? []));
        $failed = $this->sender->send($report, $pending);

        if ($failed === []) {
            $schedule->update(['last_sent_at' => now()]);
            $schedule->advanceNextRun();
        }

        return new ScheduledReportRun(
            $report,
            isDraft: false,
            resumed: $resumed,
            sentTo: array_values(array_diff($pending, $failed)),
            failed: $failed,
        );
    }

    protected function unfinishedReport(ClientReportSchedule $schedule, Carbon $start, Carbon $end): ?ClientReport
    {
        return ClientReport::query()
            ->where('schedule_id', $schedule->id)
            ->whereDate('period_start', $start->toDateString())
            ->whereDate('period_end', $end->toDateString())
            ->where('status', '!=', 'sent')
            ->latest('id')
            ->first();
    }

    /**
     * @param  list<string>  $recipients
     */
    protected function compile(ClientReportSchedule $schedule, Carbon $start, Carbon $end, array $recipients): ClientReport
    {
        $site = $schedule->site;

        return ClientReport::create([
            'site_id' => $site->id,
            'template_id' => $schedule->template_id,
            'schedule_id' => $schedule->id,
            'title' => "Maintenance Report: {$site->domain} ({$start->format('M Y')})",
            'period_start' => $start,
            'period_end' => $end,
            'sections_data' => $this->compiler->compile(
                site: $site,
                periodStart: $start,
                periodEnd: $end,
                sections: $schedule->template?->sections,
            ),
            'client_name' => $site->domain,
            'client_email' => $recipients !== [] ? implode(', ', $recipients) : null,
            'status' => 'generated',
        ]);
    }
}
