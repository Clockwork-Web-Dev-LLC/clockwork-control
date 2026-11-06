<?php

namespace Modules\ClientReports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportSchedule;
use Modules\ClientReports\Models\ClientReportTemplate;
use Modules\ClientReports\Services\ReportBrowser;
use Modules\ClientReports\Services\ReportRecipientsSyncService;
use Modules\ClientReports\Services\ScheduledReportRunner;
use Throwable;

class SchedulesController extends Controller
{
    /** Schedule list filters: who won't get a report, and why. */
    public const FILTERS = [
        'all' => 'All',
        'wont_send' => 'Won\'t send: no care plan',
        'no_recipients' => 'No recipients',
        'disabled' => 'Disabled',
        'draft' => 'Drafts for review',
    ];

    public function index(Request $request, ReportBrowser $browser): View
    {
        // A schedule outlives its site's archiving; those sites are left out.
        $all = ClientReportSchedule::query()
            ->with(['site.client:id,name,company_name', 'template'])
            ->get()
            ->filter(fn (ClientReportSchedule $s) => $s->site !== null)
            ->sortBy(fn (ClientReportSchedule $s) => Str::lower($s->site->domain))
            ->values();

        $matches = [
            'all' => fn (ClientReportSchedule $s) => true,
            'wont_send' => fn (ClientReportSchedule $s) => ! ScheduledReportRunner::siteIsEligible($s->site),
            'no_recipients' => fn (ClientReportSchedule $s) => array_filter((array) $s->recipients) === [],
            'disabled' => fn (ClientReportSchedule $s) => ! $s->is_enabled,
            'draft' => fn (ClientReportSchedule $s) => $s->delivery_mode === ClientReportSchedule::MODE_DRAFT,
        ];
        $counts = array_map(fn (callable $match) => $all->filter($match)->count(), $matches);

        $show = is_string($request->query('show')) && array_key_exists($request->query('show'), self::FILTERS) ? $request->query('show') : 'all';
        $q = is_string($request->query('q')) ? trim(mb_substr($request->query('q'), 0, 100)) : '';
        $needle = Str::lower($q);
        $schedules = $all->filter($matches[$show])
            ->filter(fn (ClientReportSchedule $s) => $needle === '' || Str::contains(Str::lower(implode(' ', [
                $s->site->domain, $s->site->client?->name, $s->site->client?->company_name, ...array_filter((array) $s->recipients),
            ])), $needle))
            ->values();

        // Each schedule's latest report (newest period, newest copy), so its
        // state shows next to it.
        $latestReports = $browser->get($browser->query()
            ->whereIn('schedule_id', $all->pluck('id'))
            ->where('status', '!=', ClientReport::STATUS_SUPERSEDED))
            ->sortByDesc(fn (ClientReport $r) => $r->period_start->format('Y-m-d').'-'.str_pad((string) $r->id, 12, '0', STR_PAD_LEFT))
            ->unique('schedule_id')
            ->keyBy('schedule_id');

        // Care-plan sites that get no report at all: the coverage gap.
        $sync = app(ReportRecipientsSyncService::class);
        $missingSites = Site::query()
            ->where('is_inactive', false)
            ->carePlanEligible()
            ->whereNotIn('id', ClientReportSchedule::query()->select('site_id'))
            ->with('client')
            ->orderBy('domain')
            ->get()
            ->map(fn (Site $site) => ['site' => $site, 'recipients' => $sync->resolvePayloadForSite($site)['report_recipients']]);

        return view('client-reports::schedules.index', [
            'schedules' => $schedules,
            'counts' => $counts,
            'show' => $show,
            'q' => $q,
            'latestReports' => $latestReports,
            'missingSites' => $missingSites,
        ]);
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

        // A held report keeps the recipients it was compiled with, and Send
        // emails those: bring this schedule's unsent copies in line, so the
        // fix the operator just made is the list that gets the report.
        // Partly sent ones too; who already has it stays in sent_to.
        if ($recipients !== []) {
            ClientReport::query()
                ->where('schedule_id', $schedule->id)
                ->where('status', ClientReport::STATUS_GENERATED)
                ->get()
                ->each(fn (ClientReport $report) => $report->update(['client_email' => implode(', ', $recipients)]));
        }

        if ($schedule->site) {
            app(ReportRecipientsSyncService::class)->pushToSite($schedule->site, $schedule);
        }

        return redirect()->route('client-reports.schedules.index')
            ->with('status', "Schedule for {$schedule->site->domain} updated successfully.");
    }

    public function destroy(ClientReportSchedule $schedule): RedirectResponse
    {
        $site = $schedule->site;
        $domain = $site->domain ?? 'site';
        $schedule->delete();

        if ($site) {
            app(ReportRecipientsSyncService::class)->pushToSite($site, null, scheduleRemoved: true);
        }

        return redirect()->route('client-reports.schedules.index')
            ->with('status', "Reporting schedule for {$domain} deleted.");
    }

    public function toggle(Request $request, ClientReportSchedule $schedule): RedirectResponse
    {
        $schedule->update([
            'is_enabled' => ! $schedule->is_enabled,
        ]);

        if ($schedule->site) {
            app(ReportRecipientsSyncService::class)->pushToSite($schedule->site, $schedule);
        }

        $state = $schedule->is_enabled ? 'enabled' : 'paused';

        // The client page toggles in place and stays where it was.
        $redirect = $request->input('return') === 'back'
            ? back()
            : redirect()->route('client-reports.schedules.index');

        return $redirect->with('status', "Schedule for {$schedule->site->domain} has been {$state}.");
    }

    /**
     * One-click schedule for a care-plan site that has none: the default
     * template, monthly, sent to the client's contacts (the same fallback
     * WordPress is shown) or, with no contacts on file, as drafts for
     * review. Its first report always waits for someone to send it.
     */
    public function quickStore(Site $site): RedirectResponse
    {
        if ($site->is_inactive) {
            return back()->with('status_error', "{$site->domain} is inactive, so it gets no reports.");
        }

        if (! ScheduledReportRunner::siteIsEligible($site)) {
            return back()->with('status_error', "{$site->domain} has no care plan. Client reports are only sent for care-plan sites.");
        }

        if (ClientReportSchedule::query()->where('site_id', $site->id)->exists()) {
            return back()->with('status', "{$site->domain} already has a report schedule.");
        }

        $schedule = $this->createDefaultSchedule($site);
        $recipients = (array) $schedule->recipients;

        $message = $schedule->delivery_mode === ClientReportSchedule::MODE_AUTO
            ? "Created a monthly report schedule for {$site->domain}, to ".implode(', ', $recipients).'. Its first report ('.$schedule->next_run_at->format('M j').') waits for you to check and send it.'
            : "Created a monthly report schedule for {$site->domain} as drafts for review: the client has no contacts on file. Add recipients to send it.";

        return back()->with('status', $message);
    }

    /**
     * The same one-click schedule for every active care-plan site that has
     * none. Nothing is sent: each schedule's first report waits for review.
     */
    public function quickStoreAll(): RedirectResponse
    {
        $sites = Site::query()
            ->where('is_inactive', false)
            ->carePlanEligible()
            ->whereNotIn('id', ClientReportSchedule::query()->select('site_id'))
            ->orderBy('domain')
            ->get();

        if ($sites->isEmpty()) {
            return back()->with('status', 'Every care-plan site already has a report schedule.');
        }

        $drafts = $sites->map(fn (Site $site) => $this->createDefaultSchedule($site))
            ->filter(fn (ClientReportSchedule $s) => $s->delivery_mode === ClientReportSchedule::MODE_DRAFT)
            ->count();

        return back()->with('status', "Created {$sites->count()} monthly report schedule(s)"
            .($drafts > 0 ? ", {$drafts} of them as drafts for review because the client has no contacts on file" : '')
            .'. Each first report waits for you to check and send it. Nothing was sent.');
    }

    /** Default template, monthly, client contacts (auto) or none (drafts). */
    protected function createDefaultSchedule(Site $site): ClientReportSchedule
    {
        $sync = app(ReportRecipientsSyncService::class);
        $recipients = $sync->resolvePayloadForSite($site)['report_recipients'];
        $template = ClientReportTemplate::query()->orderByDesc('is_default')->orderBy('name')->first();

        $schedule = ClientReportSchedule::create([
            'site_id' => $site->id,
            'template_id' => $template?->id,
            'frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'delivery_mode' => $recipients !== [] ? ClientReportSchedule::MODE_AUTO : ClientReportSchedule::MODE_DRAFT,
            'recipients' => $recipients,
            'is_enabled' => true,
            'next_run_at' => (new ClientReportSchedule(['frequency' => ClientReportSchedule::FREQUENCY_MONTHLY]))->computeNextRun(),
        ]);

        $sync->pushToSite($site, $schedule);

        return $schedule;
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

        return ClientReport::uniqueAddresses(array_filter(array_map('trim', (array) $raw)));
    }
}
