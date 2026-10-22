<?php

namespace Modules\ClientReports\Services;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
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
    public const FIRST_REPORT_REASON = 'This is the first report from this schedule. Once it has been sent, clean reports send themselves.';

    public function __construct(
        protected ClientReportCompiler $compiler,
        protected ClientReportSender $sender,
    ) {}

    /** Reports are a care-plan service; with care plans switched off fleet-wide, every site counts. */
    public static function siteIsEligible(?Site $site): bool
    {
        return $site !== null && (! Site::areCarePlansEnabled() || $site->care_plan_enabled);
    }

    public function run(ClientReportSchedule $schedule): ScheduledReportRun
    {
        $start = now()->subMonth()->startOfMonth();
        $end = now()->subMonth()->endOfMonth();
        $recipients = array_values(array_filter((array) $schedule->recipients));

        if ($schedule->delivery_mode === ClientReportSchedule::MODE_DRAFT) {
            $report = $this->compileFresh($schedule, $start, $end, $recipients);
            $reasons = $this->flagForReview($report);
            $schedule->advanceNextRun();

            return new ScheduledReportRun($report, isDraft: true, resumed: false, heldReasons: $reasons);
        }

        // An earlier report for this period is reused rather than recompiled, so
        // recipients who already got it aren't emailed a second copy and a mail
        // outage doesn't pile up a new report every day. A held report is the
        // exception: it's recompiled, since someone may have fixed the problem.
        $report = $this->resumableReport($schedule, $start, $end);
        $resumed = $report !== null;
        $report ??= $this->compileFresh($schedule, $start, $end, $recipients);

        // Anything that hasn't reached anyone yet is checked first: a fresh
        // report, a draft that predates switching the schedule to auto, or a
        // run that died before its check was saved. A schedule's first report
        // always waits for a person, so a new client's first email is seen
        // before it goes out; after that, clean reports send themselves.
        if (empty($report->sent_to)) {
            $reasons = $this->flagForReview($report, firstReport: $schedule->last_sent_at === null);
            if ($reasons !== []) {
                $schedule->advanceNextRun();

                return new ScheduledReportRun($report, isDraft: true, resumed: $resumed, heldReasons: $reasons);
            }
        }

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

    protected function resumableReport(ClientReportSchedule $schedule, Carbon $start, Carbon $end): ?ClientReport
    {
        $report = $this->samePeriod($schedule, $start, $end)
            ->where('status', ClientReport::STATUS_GENERATED)
            ->latest('id')
            ->first();

        $held = $report !== null && empty($report->sent_to) && ! empty($report->sections_data['meta']['review_reasons']);

        return $held ? null : $report;
    }

    /**
     * Compile a new report for the period, retiring any earlier copy that never
     * reached anyone, so there's one live report per schedule per period and
     * an older copy can't be sent by mistake.
     *
     * @param  list<string>  $recipients
     */
    protected function compileFresh(ClientReportSchedule $schedule, Carbon $start, Carbon $end, array $recipients): ClientReport
    {
        $this->samePeriod($schedule, $start, $end)
            ->where('status', ClientReport::STATUS_GENERATED)
            ->get()
            ->filter(fn (ClientReport $r) => empty($r->sent_to))
            ->each(fn (ClientReport $r) => $r->update(['status' => ClientReport::STATUS_SUPERSEDED]));

        return $this->compile($schedule, $start, $end, $recipients);
    }

    /**
     * Save what a person should check before this report goes out.
     *
     * @return list<string>
     */
    protected function flagForReview(ClientReport $report, bool $firstReport = false): array
    {
        $reasons = ReportReviewFlags::reasons($report->sections_data ?? []);
        if ($firstReport) {
            array_unshift($reasons, self::FIRST_REPORT_REASON);
        }
        if ($reasons !== []) {
            $data = $report->sections_data;
            $data['meta']['review_reasons'] = $reasons;
            $report->update(['sections_data' => $data]);
        }

        return $reasons;
    }

    /** @return Builder<ClientReport> */
    protected function samePeriod(ClientReportSchedule $schedule, Carbon $start, Carbon $end)
    {
        return ClientReport::query()
            ->where('schedule_id', $schedule->id)
            ->whereDate('period_start', $start->toDateString())
            ->whereDate('period_end', $end->toDateString());
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
            'status' => ClientReport::STATUS_GENERATED,
        ]);
    }
}
