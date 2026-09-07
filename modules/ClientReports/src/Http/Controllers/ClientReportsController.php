<?php

namespace Modules\ClientReports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Modules\ClientReports\Mail\ClientReportMail;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Services\ClientReportCompiler;

class ClientReportsController extends Controller
{
    /**
     * Display reports dashboard.
     */
    public function index(): View
    {
        $reports = ClientReport::query()
            ->with('site')
            ->orderByDesc('created_at')
            ->paginate(20);

        $sites = Site::query()
            ->where('is_inactive', false)
            ->orderBy('domain')
            ->get(['id', 'domain']);

        return view('client-reports::index', compact('reports', 'sites'));
    }

    /**
     * Compile and store a new client report.
     */
    public function generate(Request $request, ClientReportCompiler $compiler): RedirectResponse
    {
        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'client_email' => ['nullable', 'email', 'max:255'],
            'custom_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $site = Site::query()->findOrFail($validated['site_id']);
        $start = Carbon::parse($validated['period_start']);
        $end = Carbon::parse($validated['period_end']);

        $sectionsData = $compiler->compile(
            site: $site,
            periodStart: $start,
            periodEnd: $end,
            customNotes: $validated['custom_notes'] ?? null
        );

        $title = "Maintenance Report: {$site->domain} ({$start->format('M Y')})";

        $report = ClientReport::create([
            'site_id' => $site->id,
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
        $report->load('site');

        return view('client-reports::report', compact('report'));
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

        return view('client-reports::report', compact('report'));
    }

    /**
     * Email report to client.
     */
    public function send(ClientReport $report, Request $request): RedirectResponse
    {
        $email = $report->client_email ?: $request->input('email');
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return back()->with('status_error', 'Please provide a valid client email address.');
        }

        try {
            $reportUrl = route('client-reports.public', ['token' => $report->public_token]);
            Mail::to($email)->send(new ClientReportMail($report, $reportUrl));

            $report->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            return back()->with('status', "Report emailed to {$email}.");
        } catch (\Throwable $e) {
            return back()->with('status_error', "Failed sending email: {$e->getMessage()}");
        }
    }
}
