<?php

namespace Modules\ClientReports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Modules\ClientReports\Models\ClientReportSchedule;
use Modules\ClientReports\Models\ClientReportTemplate;
use Modules\ClientReports\Services\ReportRecipientsSyncService;
use Modules\ClientReports\Services\ScheduledReportRunner;
use Throwable;

class SchedulesController extends Controller
{
    public function index(): View
    {
        $schedules = ClientReportSchedule::query()
            ->with(['site', 'template'])
            ->get()
            ->sortBy(fn ($s) => $s->site->domain);

        $scheduledSiteIds = $schedules->pluck('site_id')->all();

        $unscheduledSites = Site::query()
            ->where('is_inactive', false)
            ->whereNotIn('id', $scheduledSiteIds)
            ->orderByDesc('care_plan_enabled')
            ->orderBy('domain')
            ->get(['id', 'domain', 'care_plan_enabled']);

        return view('client-reports::schedules.index', compact('schedules', 'unscheduledSites'));
    }

    public function create(Request $request): View|RedirectResponse
    {
        $selectedSiteId = $request->query('site_id');
        if ($selectedSiteId) {
            $existing = ClientReportSchedule::where('site_id', (int) $selectedSiteId)->first();
            if ($existing) {
                return redirect()->route('client-reports.schedules.edit', $existing);
            }
        }

        $scheduledSiteIds = ClientReportSchedule::pluck('site_id')->all();

        $sites = Site::query()
            ->where('is_inactive', false)
            ->whereNotIn('id', $scheduledSiteIds)
            ->orderBy('domain')
            ->get(['id', 'domain']);

        if ($sites->isEmpty()) {
            return redirect()->route('client-reports.schedules.index')
                ->with('status_error', 'All active sites already have reporting schedules configured.');
        }

        $templates = ClientReportTemplate::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $defaultTemplate = $templates->firstWhere('is_default', true) ?: $templates->first();

        $schedule = new ClientReportSchedule([
            'site_id' => $selectedSiteId ? (int) $selectedSiteId : null,
            'template_id' => $defaultTemplate?->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => ClientReportSchedule::MODE_AUTO,
            'is_enabled' => true,
        ]);

        return view('client-reports::schedules.form', compact('schedule', 'sites', 'templates'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id', 'unique:client_report_schedules,site_id'],
            'template_id' => ['nullable', 'integer', 'exists:client_report_templates,id'],
            'frequency' => ['required', 'string', 'in:weekly,monthly'],
            'delivery_mode' => ['required', 'string', 'in:auto,draft'],
            'recipients' => ['nullable', 'string'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        $recipients = $this->parseRecipients((string) $request->input('recipients'));

        if ($validated['delivery_mode'] === ClientReportSchedule::MODE_AUTO && empty($recipients)) {
            return back()->withInput()->withErrors([
                'recipients' => 'At least one recipient email address is required for automatic delivery mode.',
            ]);
        }

        foreach ($recipients as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return back()->withInput()->withErrors([
                    'recipients' => "Invalid email address format: '{$email}'.",
                ]);
            }
        }

        $frequency = $validated['frequency'];
        $nextRun = (new ClientReportSchedule(['frequency' => $frequency]))->computeNextRun();

        $schedule = ClientReportSchedule::create([
            'site_id' => $validated['site_id'],
            'template_id' => $validated['template_id'] ?? null,
            'frequency' => $frequency,
            'delivery_mode' => $validated['delivery_mode'],
            'recipients' => $recipients,
            'is_enabled' => $request->boolean('is_enabled', true),
            'next_run_at' => $nextRun,
        ]);

        if ($schedule->site) {
            app(ReportRecipientsSyncService::class)->pushToSite($schedule->site, $schedule);
        }

        return redirect()->route('client-reports.schedules.index')
            ->with('status', "Reporting schedule for {$schedule->site->domain} created successfully.");
    }

    public function edit(ClientReportSchedule $schedule): View
    {
        $schedule->load(['site', 'template']);

        $sites = Site::query()
            ->where('id', $schedule->site_id)
            ->get(['id', 'domain']);

        $templates = ClientReportTemplate::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return view('client-reports::schedules.form', compact('schedule', 'sites', 'templates'));
    }

    public function update(Request $request, ClientReportSchedule $schedule): RedirectResponse
    {
        $validated = $request->validate([
            'template_id' => ['nullable', 'integer', 'exists:client_report_templates,id'],
            'frequency' => ['required', 'string', 'in:weekly,monthly'],
            'delivery_mode' => ['required', 'string', 'in:auto,draft'],
            'recipients' => ['nullable', 'string'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        $recipients = $this->parseRecipients((string) $request->input('recipients'));

        if ($validated['delivery_mode'] === ClientReportSchedule::MODE_AUTO && empty($recipients)) {
            return back()->withInput()->withErrors([
                'recipients' => 'At least one recipient email address is required for automatic delivery mode.',
            ]);
        }

        foreach ($recipients as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return back()->withInput()->withErrors([
                    'recipients' => "Invalid email address format: '{$email}'.",
                ]);
            }
        }

        $updates = [
            'template_id' => $validated['template_id'] ?? null,
            'frequency' => $validated['frequency'],
            'delivery_mode' => $validated['delivery_mode'],
            'recipients' => $recipients,
            'is_enabled' => $request->boolean('is_enabled', true),
        ];

        // If schedule has no next_run_at, set one based on updated frequency
        if ($schedule->next_run_at === null) {
            $updates['next_run_at'] = (new ClientReportSchedule(['frequency' => $validated['frequency']]))->computeNextRun();
        }

        $schedule->update($updates);

        if ($schedule->site) {
            app(ReportRecipientsSyncService::class)->pushToSite($schedule->site, $schedule);
        }

        return redirect()->route('client-reports.schedules.index')
            ->with('status', "Schedule for {$schedule->site->domain} updated successfully.");
    }

    public function destroy(ClientReportSchedule $schedule): RedirectResponse
    {
        $site = $schedule->site;
        $domain = $site?->domain ?? 'site';
        $schedule->delete();

        if ($site) {
            app(ReportRecipientsSyncService::class)->pushToSite($site, null, scheduleRemoved: true);
        }

        return redirect()->route('client-reports.schedules.index')
            ->with('status', "Reporting schedule for {$domain} deleted.");
    }

    public function toggle(ClientReportSchedule $schedule): RedirectResponse
    {
        $schedule->update([
            'is_enabled' => ! $schedule->is_enabled,
        ]);

        if ($schedule->site) {
            app(ReportRecipientsSyncService::class)->pushToSite($schedule->site, $schedule);
        }

        $state = $schedule->is_enabled ? 'enabled' : 'paused';

        return redirect()->route('client-reports.schedules.index')
            ->with('status', "Schedule for {$schedule->site->domain} has been {$state}.");
    }

    public function sendNow(ClientReportSchedule $schedule, ScheduledReportRunner $runner): RedirectResponse
    {
        $site = $schedule->site;
        if (! $site || $site->is_inactive) {
            return back()->with('status_error', 'Cannot generate report: site is inactive.');
        }

        if (! ScheduledReportRunner::siteIsEligible($site)) {
            return back()->with('status_error', 'This site has no care plan. Client reports are only sent for care-plan sites.');
        }

        $recipients = array_values(array_filter((array) $schedule->recipients));
        $isAuto = $schedule->delivery_mode !== ClientReportSchedule::MODE_DRAFT;

        if ($isAuto && empty($recipients)) {
            return back()->with('status_error', 'Cannot run auto delivery: no recipient email addresses configured.');
        }

        try {
            $run = $runner->run($schedule);

            if ($run->held()) {
                return redirect()->route('client-reports.show', $run->report)
                    ->with('status_error', 'Held for review instead of sending: '.implode(' ', $run->heldReasons).' Check it, then send it from the history table.');
            }

            if ($run->isDraft) {
                return redirect()->route('client-reports.show', $run->report)
                    ->with('status', 'Draft report compiled successfully. Preview and review below before sending.');
            }

            if (! $run->complete()) {
                $delivered = $run->sentTo === [] ? '' : ' Delivered to '.implode(', ', $run->sentTo).'.';

                return back()->with('status_error', 'Report failed to send to '.implode(', ', $run->failed).'.'
                    .$delivered.' Running it again will only retry the failed addresses.');
            }

            if ($run->sentTo === []) {
                return back()->with('status', 'Every recipient already has this month\'s report, so nothing was sent.');
            }

            return back()->with('status', 'Report successfully generated and emailed to '.implode(', ', $run->sentTo).'.');
        } catch (Throwable $e) {
            Log::error("Manual run failed for scheduled report on {$site->domain}: {$e->getMessage()}", [
                'exception' => $e,
            ]);

            return back()->with('status_error', "Failed generating report: {$e->getMessage()}");
        }
    }

    /**
     * @return array<string>
     */
    protected function parseRecipients(string $input): array
    {
        $raw = preg_split('/[\r\n,]+/', $input);

        return array_values(array_unique(array_filter(array_map('trim', (array) $raw))));
    }
}
