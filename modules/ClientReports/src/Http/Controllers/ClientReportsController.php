<?php

namespace Modules\ClientReports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportTemplate;
use Modules\ClientReports\Services\ClientReportCompiler;
use Modules\ClientReports\Services\ClientReportSender;

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

        return view('client-reports::reports.email', [
            'report' => $report,
            'subject' => $mail->envelope()->subject,
            'html' => $mail->render(),
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
     * Email report to client.
     */
    public function send(ClientReport $report, Request $request, ClientReportSender $sender): RedirectResponse
    {
        if ($report->status === ClientReport::STATUS_SUPERSEDED) {
            return back()->with('status_error', 'A newer report for this period replaced this one. Send the newer one instead.');
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
