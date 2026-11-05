<?php

namespace Modules\ClientReports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Mail\ClientReportTestMail;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportTemplate;
use Modules\ClientReports\Services\ClientReportCompiler;
use Modules\ClientReports\Services\ClientReportSender;
use Modules\ClientReports\Services\ReportBrowser;
use Modules\ClientReports\Services\ScheduledReportRunner;
use Modules\ClientReports\Support\ReportListFilters;
use Modules\ClientReports\Support\ReportState;
use Throwable;

class ClientReportsController extends Controller
{
    /** Most reports one "Recompile" press works through, so the request finishes. */
    public const RECOMPILE_BATCH = 100;

    /**
     * The reports list: one period at a time (the latest by default),
     * filtered by state, hold reason, client or search, with a summary of
     * the period that doubles as filter links. Filters live in the query
     * string so they survive paging and a trip into a report and back.
     */
    public function index(Request $request, ReportBrowser $browser): View
    {
        $periods = $browser->periods();
        $filters = ReportListFilters::fromRequest($request, array_key_first($periods) ?? ReportListFilters::ALL);
        $reports = $browser->list($filters);

        $page = max(1, (int) $request->query('page', 1));
        $paginated = new LengthAwarePaginator(
            $reports->forPage($page, ReportBrowser::PER_PAGE)->values(),
            $reports->count(),
            ReportBrowser::PER_PAGE,
            $page,
            ['path' => route('client-reports.reports'), 'query' => $filters->toQuery()],
        );

        // The summary strip counts the whole period, whatever else is filtered.
        $periodReports = $browser->list(new ReportListFilters(period: $filters->period));
        $outdatedCount = $periodReports
            ->filter(fn (ClientReport $r) => $r->canRecompile() && ReportState::of($r) === ReportState::OUTDATED)
            ->count();

        $sites = Site::query()
            ->where('is_inactive', false)
            ->orderBy('domain')
            ->get(['id', 'domain']);

        $templates = ClientReportTemplate::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return view('client-reports::index', [
            'reports' => $paginated,
            'filters' => $filters,
            'periods' => $periods,
            'summary' => $browser->summarize($periodReports),
            'outdatedCount' => $outdatedCount,
            'sites' => $sites,
            'templates' => $templates,
        ]);
    }

    /**
     * Compile and store a new client report.
     */
    public function generate(Request $request, ClientReportCompiler $compiler): RedirectResponse
    {
        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'template_id' => ['nullable', 'integer', 'exists:client_report_templates,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'client_email' => ['nullable', 'email', 'max:255'],
            'custom_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $site = Site::query()->findOrFail($validated['site_id']);
        $start = Carbon::parse($validated['period_start']);
        $end = Carbon::parse($validated['period_end']);

        $template = ! empty($validated['template_id'])
            ? ClientReportTemplate::find($validated['template_id'])
            : null;

        $sectionsData = $compiler->compile(
            site: $site,
            periodStart: $start,
            periodEnd: $end,
            customNotes: $validated['custom_notes'] ?? null,
            sections: $template ? $template->sections : null
        );

        $title = "Maintenance Report: {$site->domain} ({$start->format('M Y')})";

        $report = ClientReport::create([
            'site_id' => $site->id,
            'template_id' => $template?->id,
            'title' => $title,
            'period_start' => $start,
            'period_end' => $end,
            'sections_data' => $sectionsData,
            'client_name' => $validated['client_name'] ?? null,
            'client_email' => $validated['client_email'] ?? null,
            'status' => 'generated',
        ]);

        return redirect()->route('client-reports.show', $report)
            ->with('status', 'Report generated successfully.');
    }

    /**
     * Preview report for operator.
     */
    public function show(ClientReport $report, Request $request, ReportBrowser $browser): View
    {
        $report->load('site.client', 'schedule');

        return view('client-reports::reports.show', [
            'report' => $report,
            'isOperatorPreview' => true,
            'nav' => $this->navigation($report, $request, $browser),
        ]);
    }

    /**
     * The email exactly as the client gets it. The preview page lays some
     * sections out differently, so this is what to check before sending.
     */
    public function email(ClientReport $report, Request $request, ReportBrowser $browser): View
    {
        $report->load('site.client', 'schedule');
        $mail = new ClientReportMail($report);
        $content = $mail->content();
        $envelope = $mail->envelope();
        // With no From on the envelope, Laravel sends from the global address.
        $from = $envelope->from ?? new Address((string) config('mail.from.address'), (string) config('mail.from.name'));

        return view('client-reports::reports.email', [
            'report' => $report,
            'from' => $from->name !== null && $from->name !== '' ? "{$from->name} <{$from->address}>" : $from->address,
            'subject' => $envelope->subject,
            'html' => $mail->render(),
            // Plain-text clients get this part instead.
            'text' => view($content->text, $content->with)->render(),
            'nav' => $this->navigation($report, $request, $browser),
        ]);
    }

    /**
     * Public read-only client-facing report view.
     */
    public function publicShow(string $token): View
    {
        $report = ClientReport::query()
            ->where('public_token', $token)
            ->with('site')
            ->firstOrFail();

        return view('client-reports::reports.show', ['report' => $report, 'isOperatorPreview' => false]);
    }

    /**
     * Recompile an unsent scheduled report for its own period from current
     * data, replacing (superseding) the old copy. It never sends anything:
     * the new copy is checked for review like a scheduled run's, and goes
     * out only when someone sends it from the history table.
     */
    public function recompile(ClientReport $report, ScheduledReportRunner $runner): RedirectResponse
    {
        if (! $report->canRecompile()) {
            return back()->with('status_error', match (true) {
                $report->schedule_id === null => 'Only scheduled reports can be recompiled. Generate a new report for this period instead.',
                $report->status === ClientReport::STATUS_SUPERSEDED => 'A newer report for this period replaced this one. Recompile that one instead.',
                default => 'This report has already been sent to someone, so it can\'t be recompiled.',
            });
        }

        try {
            $run = $runner->recompile($report);
        } catch (InvalidArgumentException $e) {
            return back()->with('status_error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error("Recompiling client report #{$report->id} failed: {$e->getMessage()}", ['exception' => $e]);

            return back()->with('status_error', "Recompiling failed, so the report was left as it was: {$e->getMessage()}");
        }

        $fresh = $run->report;
        $message = 'Recompiled from current data for '.$fresh->period_start->format('M j').' – '.$fresh->period_end->format('M j, Y').'. Nothing was sent.';
        $message .= $run->held()
            ? ' It is held for review: '.implode(' ', $run->heldReasons)
            : ' Nothing needs review; send it when ready.';

        // Keep the list context the operator came from (filters, client page).
        $context = array_filter(
            (array) request()->only(['period', 'status', 'category', 'q', 'client', 'from', 'superseded']),
            fn ($value) => is_string($value) && $value !== '',
        );

        return redirect()->route('client-reports.show', [$fresh, ...$context])->with('status', $message);
    }

    /**
     * Email report to client.
     */
    public function send(ClientReport $report, Request $request, ClientReportSender $sender): RedirectResponse
    {
        if ($report->status === ClientReport::STATUS_SUPERSEDED) {
            return back()->with('status_error', 'A newer report for this period replaced this one. Send the newer one instead.');
        }

        // Compiled under older report rules and not yet with anyone: its
        // figures were worked out the old way. A partly sent or sent report
        // can still finish delivering (or be resent) the copy its other
        // recipients already have.
        if (empty($report->sent_to) && $report->isStale()) {
            return back()->with('status_error', $report->canRecompile()
                ? 'This report was compiled before the latest report fixes. Press Recompile, check the new copy, then send that one.'
                : 'This report was compiled before the latest report fixes. Generate a new report for this period instead.');
        }

        $recipients = $request->filled('email')
            ? ClientReport::parseRecipients((string) $request->input('email'))
            : $report->recipientsToSend();

        if ($recipients === [] && $report->recipients() !== []) {
            return back()->with('status', 'Everyone on this report already has it.');
        }

        if ($recipients === []) {
            return back()->with('status_error', 'This report has no recipient. Enter an email address to send it to.');
        }

        foreach ($recipients as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return back()->with('status_error', "Invalid email address: '{$email}'.");
            }
        }

        if (! $report->client_email) {
            $report->update(['client_email' => implode(', ', $recipients)]);
        }

        $failed = $sender->send($report, $recipients);
        $delivered = array_values(array_diff($recipients, $failed));

        // A held or draft report sent by hand counts as the schedule's send,
        // so its next clean report goes out by itself.
        if ($failed === [] && $report->schedule !== null) {
            $report->schedule->update(['last_sent_at' => now()]);
        }

        if ($failed !== []) {
            $deliveredNote = $delivered === [] ? '' : ' Delivered to '.implode(', ', $delivered).'.';

            return back()->with('status_error', 'Failed sending to '.implode(', ', $failed).' (see the log).'.$deliveredNote);
        }

        return back()->with('status', 'Report emailed to '.implode(', ', $delivered).'.');
    }

    /**
     * Email the client's exact email to the signed-in operator, and only to
     * them, with "[TEST]" in the subject. Nothing about the report or its
     * schedule changes: it isn't marked sent, nobody is added to sent_to and
     * the schedule's last send stays as it was.
     */
    public function sendTest(ClientReport $report, Request $request): RedirectResponse
    {
        $to = (string) ($request->user()?->email ?? '');
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return back()->with('status_error', 'Your account has no valid email address to send a test copy to.');
        }

        try {
            Mail::to($to)->send(new ClientReportTestMail($report));
        } catch (Throwable $e) {
            Log::error("Test copy of client report #{$report->id} failed to send: {$e->getMessage()}", ['exception' => $e]);

            return back()->with('status_error', "The test copy to {$to} failed: {$e->getMessage()}");
        }

        return back()->with('status', "Test copy sent to {$to} only, with [TEST] in the subject. The client got nothing and the report is unchanged.");
    }

    /**
     * Recompile a batch of unsent scheduled reports (every outdated one of a
     * period, or the ones ticked on the list) through the same
     * ScheduledReportRunner::recompile() as the single Recompile button. It
     * never sends anything and never moves a schedule on.
     */
    public function recompileMany(Request $request, ReportBrowser $browser, ScheduledReportRunner $runner): RedirectResponse
    {
        $filters = ReportListFilters::fromRequest($request, ReportListFilters::ALL);
        $back = redirect()->route('client-reports.reports', $filters->toQuery());

        if ($request->input('scope') === 'selected') {
            $ids = collect((array) $request->input('ids', []))
                ->filter(fn ($id) => is_scalar($id) && ctype_digit((string) $id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
            if ($ids->isEmpty()) {
                return $back->with('status_error', 'Tick the reports to recompile first.');
            }
        } else {
            $ids = $browser->list(new ReportListFilters(period: $filters->period, status: ReportState::OUTDATED))
                ->filter(fn (ClientReport $r) => $r->canRecompile())
                ->pluck('id')
                ->values();
            if ($ids->isEmpty()) {
                return $back->with('status', 'No outdated reports to recompile in this period.');
            }
        }

        @set_time_limit(600);
        $remaining = max(0, $ids->count() - self::RECOMPILE_BATCH);
        $recompiled = $held = $skipped = 0;
        $failed = [];

        foreach ($ids->take(self::RECOMPILE_BATCH) as $id) {
            $report = ClientReport::query()->find($id);
            if ($report === null || ! $report->canRecompile()) {
                $skipped++;

                continue;
            }

            try {
                $run = $runner->recompile($report);
                $recompiled++;
                $held += $run->held() ? 1 : 0;
            } catch (Throwable $e) {
                $failed[] = $report->site?->domain ?? "#{$report->id}";
                Log::error("Recompiling client report #{$report->id} failed: {$e->getMessage()}", ['exception' => $e]);
            }
        }

        $message = "Recompiled {$recompiled} report(s) from current data, {$held} of them held for review.";
        if ($skipped > 0) {
            $message .= " Skipped {$skipped} that can't be recompiled (sent, replaced or not scheduled).";
        }
        if ($remaining > 0) {
            $message .= " {$remaining} more are left: press Recompile again to continue.";
        }
        $message .= ' Nothing was sent.';

        return $failed === []
            ? $back->with('status', $message)
            : $back->with('status_error', $message.' Failed: '.implode(', ', $failed).' (see the log).');
    }

    /**
     * Where the preview and email pages lead: back to the list (or client
     * page) they were opened from with its filters, and to the previous and
     * next report of that same filtered list. Opened on its own, a report
     * steps through its own period.
     *
     * @return array{query: array<string, string>, previous: ?int, next: ?int, position: ?int, count: int, back_url: string, back_label: string}
     */
    protected function navigation(ClientReport $report, Request $request, ReportBrowser $browser): array
    {
        $hasContext = collect(['period', 'status', 'category', 'q', 'client', 'superseded'])
            ->contains(fn (string $key) => $request->query->has($key));
        $filters = $hasContext
            ? ReportListFilters::fromRequest($request, ReportListFilters::ALL)
            : new ReportListFilters(
                period: $report->period_start->format('Y-m'),
                showSuperseded: $report->status === ClientReport::STATUS_SUPERSEDED,
            );

        $ids = $browser->list($filters)->pluck('id')->values();
        $position = $ids->search($report->id, true);

        if ($filters->from === 'client' && $filters->client !== null) {
            $backUrl = route('client-reports.clients.show', $filters->client);
            $backLabel = 'Back to client';
        } else {
            $query = $filters->toQuery();
            if ($position !== false && $position >= ReportBrowser::PER_PAGE) {
                $query['page'] = (string) (intdiv($position, ReportBrowser::PER_PAGE) + 1);
            }
            $backUrl = route('client-reports.reports', $query);
            $backLabel = 'Back to list';
        }

        return [
            'query' => $filters->toQuery(),
            'previous' => $position !== false && $position > 0 ? $ids[$position - 1] : null,
            'next' => $position !== false && $position < $ids->count() - 1 ? $ids[$position + 1] : null,
            'position' => $position === false ? null : $position + 1,
            'count' => $ids->count(),
            'back_url' => $backUrl,
            'back_label' => $backLabel,
        ];
    }
}
