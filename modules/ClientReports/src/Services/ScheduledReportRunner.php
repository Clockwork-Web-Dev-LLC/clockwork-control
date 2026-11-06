<?php

namespace Modules\ClientReports\Services;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportSchedule;

/**
 * One scheduled report cycle, shared by `clockwork:send-client-reports` and the
 * schedule's "Send now" button so the two can't drift apart. recompile() is the
 * reports' Recompile action: the same compile and review check for an unsent
 * report's own period, without ever sending.
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

    /**
     * Whether a site gets the report for a period ending $periodEnd: on a
     * care plan now, or on one until after the period ended (switched off on
     * the 1st by the Bill.com sync, say), in which case it is held for a
     * person to confirm (meta.care_plan_ended_at).
     */
    public static function siteIsEligibleFor(?Site $site, Carbon $periodEnd): bool
    {
        return self::siteIsEligible($site)
            || ($site !== null && ClientReportCompiler::carePlanSwitchedOffAfter($site, $periodEnd) !== null);
    }

    public function run(ClientReportSchedule $schedule): ScheduledReportRun
    {
        // NoOverflow: on the 29th-31st a plain subMonth() rolls into the
        // current month (Oct 31 - 1 month = "Sep 31" = Oct 1).
        $month = now()->subMonthNoOverflow();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $recipients = array_values(array_filter((array) $schedule->recipients));

        if ($schedule->delivery_mode === ClientReportSchedule::MODE_DRAFT) {
            // A copy for this period that already reached someone is the
            // period's report: a second live copy beside it would show as
            // ready to send and email the client again.
            $delivered = $this->samePeriod($schedule, $start, $end)
                ->whereIn('status', [ClientReport::STATUS_GENERATED, ClientReport::STATUS_SENT])
                ->latest('id')
                ->get()
                ->first(fn (ClientReport $r) => ! empty($r->sent_to));
            if ($delivered !== null) {
                $schedule->advanceNextRun();

                return new ScheduledReportRun($delivered, isDraft: true, resumed: true);
            }

            $report = $this->compileFresh($schedule, $start, $end, $recipients);
            $reasons = $this->flagForReview($report);
            $schedule->advanceNextRun();

            return new ScheduledReportRun($report, isDraft: true, resumed: false, heldReasons: $reasons);
        }

        // An earlier report for this period is reused rather than recompiled, so
        // recipients who already got it aren't emailed a second copy and a mail
        // outage doesn't pile up a new report every day. That includes one
        // already sent (say, finished by hand after a failed run). A held
        // report is the exception: it's recompiled, since someone may have
        // fixed the problem. So is an unsent one compiled under older report
        // rules (ClientReport::isStale()).
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

        $pending = ClientReport::addressesWithout(ClientReport::uniqueAddresses($recipients), $report->sent_to ?? []);
        $alreadySent = $pending === [] && $report->status === ClientReport::STATUS_SENT;
        $failed = $alreadySent ? [] : $this->sender->send($report, $pending);

        if ($failed === []) {
            if (! $alreadySent || $schedule->last_sent_at === null) {
                $schedule->update(['last_sent_at' => now()]);
            }
            $schedule->advanceNextRun();

            if ($schedule->site) {
                app(ReportRecipientsSyncService::class)->pushToSite($schedule->site, $schedule);
            }
        }

        return new ScheduledReportRun(
            $report,
            isDraft: false,
            resumed: $resumed,
            sentTo: array_values(array_diff($pending, $failed)),
            failed: $failed,
        );
    }

    /**
     * Recompile a scheduled report that hasn't reached anyone, from current
     * data, for the report's own period (not "last month", which is what
     * run() compiles), so a held draft picks up whatever was fixed or added
     * since: hours logged, a scan rerun, a backup recorded. The older copy is
     * superseded and the new one is checked for review the way run() checks
     * one: an auto schedule that has never sent holds it as its first
     * report, a draft schedule doesn't add that reason.
     *
     * It never sends anything, even when the new copy is clean and the
     * schedule has sent before, and it doesn't move the schedule on: it is
     * not a scheduled cycle. A clean copy is sent by hand from the history
     * table like any other.
     *
     * @throws InvalidArgumentException when the report isn't a scheduled one
     *                                  that is still unsent
     */
    public function recompile(ClientReport $report): ScheduledReportRun
    {
        return DB::transaction(function () use ($report) {
            // Re-read under a lock, so a second click can't recompile a copy
            // the first one has just superseded.
            $current = ClientReport::query()->lockForUpdate()->find($report->id);
            $schedule = $current?->schedule;

            if ($current === null || ! $current->canRecompile() || $schedule === null
                || $schedule->site === null || $schedule->site_id !== $current->site_id) {
                throw new InvalidArgumentException('Only a scheduled report that has not been sent to anyone can be recompiled.');
            }

            $start = $current->period_start->copy()->startOfDay();
            $end = $current->period_end->copy()->startOfDay();
            $recipients = array_values(array_filter((array) $schedule->recipients));
            $notes = $current->sections_data['meta']['custom_notes'] ?? null;

            $fresh = $this->compileFresh($schedule, $start, $end, $recipients, is_string($notes) ? $notes : null);
            $reasons = $this->flagForReview(
                $fresh,
                firstReport: $schedule->delivery_mode !== ClientReportSchedule::MODE_DRAFT && $schedule->last_sent_at === null,
            );

            return new ScheduledReportRun($fresh, isDraft: true, resumed: false, heldReasons: $reasons);
        });
    }

    protected function resumableReport(ClientReportSchedule $schedule, Carbon $start, Carbon $end): ?ClientReport
    {
        $report = $this->samePeriod($schedule, $start, $end)
            ->whereIn('status', [ClientReport::STATUS_GENERATED, ClientReport::STATUS_SENT])
            ->latest('id')
            ->first();

        // Held, or compiled under older report rules: either way nobody has
        // it yet, so recompile rather than send it as it is.
        $unsent = $report !== null && empty($report->sent_to);
        $held = $unsent && ! empty($report->sections_data['meta']['review_reasons']);
        $outdated = $unsent && $report->isStale();

        return $held || $outdated ? null : $report;
    }

    /**
     * Compile a new report for the period, retiring any earlier copy that never
     * reached anyone, so there's one live report per schedule per period and
     * an older copy can't be sent by mistake.
     *
     * @param  list<string>  $recipients
     */
    protected function compileFresh(ClientReportSchedule $schedule, Carbon $start, Carbon $end, array $recipients, ?string $customNotes = null): ClientReport
    {
        $earlier = $this->samePeriod($schedule, $start, $end)
            ->where('status', ClientReport::STATUS_GENERATED)
            ->get()
            ->filter(fn (ClientReport $r) => empty($r->sent_to));

        // A person's review of the malware findings, from the newest copy
        // that has one: it still holds if the new copy shows the same scan
        // and findings.
        $malwareReview = $earlier->sortByDesc('id')
            ->map(fn (ClientReport $r) => $r->sections_data['security']['malware_reviewed'] ?? null)
            ->first(fn ($mark) => is_array($mark));

        $earlier->each(fn (ClientReport $r) => $r->update(['status' => ClientReport::STATUS_SUPERSEDED]));

        $report = $this->compile($schedule, $start, $end, $recipients, $customNotes);

        if (is_array($malwareReview)) {
            $this->reapplyMalwareReview($report, $malwareReview);
        }

        return $report;
    }

    /**
     * Carry an operator's "findings reviewed" mark onto a freshly compiled
     * copy when it shows the same scan with the same findings
     * (ReportReviewFlags::malwareReviewed). A newer scan or different
     * findings need a person to look again, so the mark is dropped.
     *
     * @param  array<string, mixed>  $mark  an earlier copy's security.malware_reviewed
     */
    protected function reapplyMalwareReview(ClientReport $report, array $mark): void
    {
        $data = is_array($report->sections_data) ? $report->sections_data : [];
        if (! isset($data['security'])) {
            return;
        }

        $data['security']['malware_reviewed'] = $mark;
        if (ReportReviewFlags::malwareReviewed((array) $data['security'])) {
            $report->update(['sections_data' => $data]);
        }
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
    protected function compile(ClientReportSchedule $schedule, Carbon $start, Carbon $end, array $recipients, ?string $customNotes = null): ClientReport
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
                customNotes: $customNotes,
                sections: $schedule->template?->sections,
            ),
            'client_name' => $site->domain,
            'client_email' => $recipients !== [] ? implode(', ', $recipients) : null,
            'status' => ClientReport::STATUS_GENERATED,
        ]);
    }
}
