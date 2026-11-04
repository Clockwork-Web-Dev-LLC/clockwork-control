<?php

namespace Modules\ClientReports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportTemplate;
use Modules\ClientReports\Services\ClientReportCompiler;
use Modules\ClientReports\Services\ClientReportSender;
use Modules\ClientReports\Services\ScheduledReportRunner;
use Throwable;

class ClientReportsController extends Controller
{
    /**
     * Display reports dashboard.
     */
    public function index(): View
    {
        $reports = ClientReport::query()
            ->with(['site', 'template'])
            ->orderByDesc('created_at')
            ->paginate(20);

        $sites = Site::query()
            ->where('is_inactive', false)
            ->orderBy('domain')
            ->get(['id', 'domain']);

        $templates = ClientReportTemplate::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return view('client-reports::index', compact('reports', 'sites', 'templates'));
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
    public function show(ClientReport $report): View
    {
        $report->load('site', 'schedule');

        return view('client-reports::reports.show', ['report' => $report, 'isOperatorPreview' => true]);
    }

    /**
     * The email exactly as the client gets it. The preview page lays some
     * sections out differently, so this is what to check before sending.
     */
    public function email(ClientReport $report): View
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
            : ' Nothing needs review; send it from the history table when ready.';

        return redirect()->route('client-reports.show', $fresh)->with('status', $message);
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
}
