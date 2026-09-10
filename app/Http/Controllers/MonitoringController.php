<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use App\Models\Site;
use App\Models\SiteUptimeEvent;
use App\Services\ActionLog\ActionLogger;
use App\Services\Process\BackgroundArtisan;
use App\Services\Scheduler\SchedulerHeartbeat;
use App\Services\Uptime\UptimeStateUpdater;
use App\Services\Uptime\UptimeStatsCalculator;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Top-level Monitoring section. Two tabs:
 *
 *   - **Uptime Activity** — fleet-wide status board. Currently-down banner,
 *     24h/7d/30d uptime % per site, recent transition events.
 *   - **Settings** — global probe interval + failure threshold + per-site
 *     override hints. Per-site `uptime_monitoring_enabled` is the existing
 *     kill switch already wired into Site::query() filters in the runner.
 *
 * Settings live in `app_settings` (Settings store) under the `monitoring.*`
 * namespace so the schedule loader and the state updater can read the same
 * canonical values without a config-file deploy.
 */
class MonitoringController extends Controller
{
    public const SETTING_INTERVAL_MIN = 'monitoring.uptime_interval_minutes';

    public const SETTING_FAILURE_THRESHOLD = 'monitoring.uptime_failure_threshold';

    public const DEFAULT_INTERVAL_MIN = 5;

    public const DEFAULT_FAILURE_THRESHOLD = UptimeStateUpdater::FAILURE_THRESHOLD_FOR_DOWN;

    public function index(UptimeStatsCalculator $calc): View
    {
        // Sites whose servers aren't ignored AND have monitoring enabled —
        // mirrors what the actual probe runner sees.
        // Severity-first: actively-down sites at the top (the operator's pain),
        // then unknowns, then ignored-down (suppressed but still listed muted),
        // then healthy. Alphabetical within each tier.
        $sites = Site::query()
            ->with('server')
            ->where('uptime_monitoring_enabled', true)
            ->hostMonitored()
            ->orderBy('domain')
            ->get();

        $latestDownEvents = $this->latestDownEventsBySite($sites);

        $sites = $sites->sortBy(function (Site $site) use ($latestDownEvents) {
            $excused = $this->siteOutageIsExcused($site, $latestDownEvents);
            $tier = match (true) {
                $site->uptime_state === 'down' && $site->uptime_ignored_at === null && ! $excused => 1,
                $site->uptime_state === 'unknown' => 2,
                $site->uptime_state === 'down' && $excused => 3,
                $site->uptime_state === 'down' => 4,
                $site->uptime_state === 'maintenance' => 5,
                default => 6,
            };

            return sprintf('%d-%s', $tier, mb_strtolower((string) $site->domain));
        })->values();

        $now = now();
        $stats24h = $calc->bulkUptime($sites, $now->copy()->subDay(), $now);
        $stats7d = $calc->bulkUptime($sites, $now->copy()->subDays(7), $now);
        $stats30d = $calc->bulkUptime($sites, $now->copy()->subDays(30), $now);

        // Down-count headline excludes ignored and SLA-exempt (not our fault) sites.
        // Legit down sites require immediate action.
        // Excused = standing site policy OR this incident's down event.
        $currentlyDown = $sites->filter(function (Site $site) use ($latestDownEvents) {
            return $site->uptime_state === 'down'
                && $site->uptime_ignored_at === null
                && ! $this->siteOutageIsExcused($site, $latestDownEvents);
        })->count();
        $currentlyNotOurFault = $sites->filter(function (Site $site) use ($latestDownEvents) {
            return $site->uptime_state === 'down'
                && $this->siteOutageIsExcused($site, $latestDownEvents);
        })->count();
        $currentlyUp = $sites->where('uptime_state', 'up')->count();
        $currentlyMaintenance = $sites->where('uptime_state', 'maintenance')->count();
        $unknown = $sites->where('uptime_state', 'unknown')->count();
        $currentlyIgnored = $sites->whereNotNull('uptime_ignored_at')->count();

        // Fleet aggregate uptime: arithmetic mean of per-site uptimes,
        // ignoring sites with null (no history). Geometric mean would be more
        // honest about a single bad site dragging the average, but the
        // arithmetic version is what every status-page tool surfaces and is
        // the number operators expect.
        $avg7d = $this->averageOfNonNull($stats7d);
        $avg30d = $this->averageOfNonNull($stats30d);

        // Recent fleet-wide events for the "Latest events" feed.
        $recentEvents = SiteUptimeEvent::query()
            ->with('site:id,domain')
            ->orderByDesc('event_at')
            ->limit(50)
            ->get();

        $schedulerHeartbeat = app(SchedulerHeartbeat::class)->status();

        return view('monitoring.index', compact(
            'sites',
            'stats24h',
            'stats7d',
            'stats30d',
            'currentlyDown',
            'currentlyNotOurFault',
            'currentlyUp',
            'currentlyMaintenance',
            'unknown',
            'currentlyIgnored',
            'avg7d',
            'avg30d',
            'recentEvents',
            'schedulerHeartbeat',
            'latestDownEvents',
        ));
    }

    public function settings(Settings $settings): View
    {
        $intervalMin = (int) $settings->get(self::SETTING_INTERVAL_MIN, self::DEFAULT_INTERVAL_MIN);
        $failureThreshold = (int) $settings->get(self::SETTING_FAILURE_THRESHOLD, self::DEFAULT_FAILURE_THRESHOLD);

        // Sites toggled off (per-site override). Surface so the operator can quickly
        // re-enable from the same screen as the global settings.
        $disabledSites = Site::query()
            ->with('server')
            ->where('uptime_monitoring_enabled', false)
            ->hostMonitored()
            ->orderBy('domain')
            ->get();

        $schedulerHeartbeat = app(SchedulerHeartbeat::class)->status();

        return view('monitoring.settings', compact('intervalMin', 'failureThreshold', 'disabledSites', 'schedulerHeartbeat'));
    }

    /**
     * Run `clockwork:check-site-uptime` on demand. Same command the
     * scheduler runs every few minutes, just triggered now. Useful when
     * an operator has just flipped DNS / restarted a server / or otherwise
     * wants the up/down status to reflect reality immediately instead of
     * waiting for the next cron tick.
     */
    public function refresh(BackgroundArtisan $background): RedirectResponse
    {
        $result = $background->start(
            'monitoring.check_site_uptime',
            ['clockwork:check-site-uptime'],
            900,
            'uptime-refresh-bg',
        );

        if ($result->alreadyRunning()) {
            return back()->with('status', 'A fleet re-probe is already running. Refresh this page in a couple of minutes.');
        }

        if ($result->failed()) {
            return back()->with('status_error', $result->error ?? 'Could not start the uptime re-probe.');
        }

        return back()->with('status', 'Re-probe started in the background. This page will show new results as sites complete (~2–3 min for the full fleet).');
    }

    public function updateSettings(Request $request, Settings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'interval_minutes' => 'required|integer|in:1,5,10,15',
            'failure_threshold' => 'required|integer|min:1|max:6',
        ]);

        $settings->putMany([
            self::SETTING_INTERVAL_MIN => $validated['interval_minutes'],
            self::SETTING_FAILURE_THRESHOLD => $validated['failure_threshold'],
        ]);

        return redirect()
            ->route('monitoring.settings')
            ->with('status', 'Monitoring settings saved. The new probe interval applies on the next scheduler restart; the failure threshold takes effect immediately on the next probe.');
    }

    /**
     * Classify an active or recent outage on a site as "Legit Outage" (counts toward SLA)
     * or "Not Our Fault" (SLA exempt — e.g. client DNS, domain expired).
     */
    public function classifyOutage(Site $site, Request $request, ActionLogger $logger): RedirectResponse
    {
        $validated = $this->validateOutageClassification($request);

        $isExempt = (bool) $validated['is_sla_exempt'];
        $reason = $isExempt ? ($validated['exemption_reason'] ?? SiteUptimeEvent::REASON_CLIENT_DNS) : null;
        $notes = ! empty($validated['exemption_notes']) ? trim((string) $validated['exemption_notes']) : null;
        $actor = (string) (Auth::user()->email ?? 'manual');

        // Update the active or most recent down event for this site
        $activeDownEvent = SiteUptimeEvent::query()
            ->where('site_id', $site->id)
            ->where('event_type', SiteUptimeEvent::TYPE_DOWN)
            ->orderByDesc('event_at')
            ->first();

        if ($activeDownEvent) {
            $activeDownEvent->forceFill([
                'is_sla_exempt' => $isExempt,
                'exemption_reason' => $reason,
                'exemption_notes' => $notes,
                'exempted_at' => $isExempt ? now() : null,
                'exempted_by' => $isExempt ? $actor : null,
            ])->save();
        }

        // Incident classification tags the event. Standing site.uptime_sla_exempt
        // (future downs inherit) is only set from site-settings ignore+exempt.
        // Alerts: silence while this outage is excused; restore when marked legit.
        if ($isExempt) {
            $reasonText = $reason ? (SiteUptimeEvent::EXEMPTION_REASONS[$reason] ?? $reason) : 'Not our fault';
            $siteUpdates = [
                'uptime_ignored_at' => $site->uptime_ignored_at ?? now(),
                'uptime_ignore_reason' => $notes ?: $reasonText,
            ];
        } else {
            $siteUpdates = [
                'uptime_ignored_at' => null,
                'uptime_ignore_reason' => null,
                'uptime_sla_exempt' => false,
                'uptime_exemption_reason' => null,
            ];
        }

        $site->forceFill($siteUpdates)->save();

        $reasonLabel = $reason ? (SiteUptimeEvent::EXEMPTION_REASONS[$reason] ?? $reason) : 'Legit outage';
        $summary = $isExempt
            ? "Outage classified as Not Our Fault ({$reasonLabel}) for {$site->domain} — SLA protected."
            : "Outage classified as Legit Outage for {$site->domain} — counted in SLA.";

        $logger->record(
            actionType: $isExempt ? ActionLog::TYPE_UPTIME_IGNORED : ActionLog::TYPE_UPTIME_TRANSITION,
            summary: $summary,
            site: $site,
            details: [
                'is_sla_exempt' => $isExempt,
                'reason' => $reason,
                'notes' => $notes,
            ],
            ok: true,
            actor: $actor,
        );

        $flashMessage = $isExempt
            ? "Marked as Not Our Fault ({$reasonLabel}) for {$site->domain}. Outage is excluded from uptime ratings and SLA averages."
            : "Marked as Legit Outage for {$site->domain}. Outage is included in uptime ratings and SLA averages.";

        return back()->with('status', $flashMessage);
    }

    /**
     * Classify an individual historical uptime event as SLA exempt or legit.
     */
    public function classifyEvent(SiteUptimeEvent $event, Request $request, ActionLogger $logger): RedirectResponse
    {
        $validated = $this->validateOutageClassification($request);

        $isExempt = (bool) $validated['is_sla_exempt'];
        $reason = $isExempt ? ($validated['exemption_reason'] ?? SiteUptimeEvent::REASON_CLIENT_DNS) : null;
        $notes = ! empty($validated['exemption_notes']) ? trim((string) $validated['exemption_notes']) : null;
        $actor = (string) (Auth::user()->email ?? 'manual');

        $event->forceFill([
            'is_sla_exempt' => $isExempt,
            'exemption_reason' => $reason,
            'exemption_notes' => $notes,
            'exempted_at' => $isExempt ? now() : null,
            'exempted_by' => $isExempt ? $actor : null,
        ])->save();

        $site = $event->site;
        $reasonLabel = $reason ? (SiteUptimeEvent::EXEMPTION_REASONS[$reason] ?? $reason) : 'Legit outage';

        $logger->record(
            actionType: ActionLog::TYPE_UPTIME_TRANSITION,
            summary: "Event #{$event->id} for {$site?->domain} classified as ".($isExempt ? "Not Our Fault ({$reasonLabel})" : 'Legit Outage'),
            site: $site,
            details: ['event_id' => $event->id, 'is_sla_exempt' => $isExempt, 'reason' => $reason],
            ok: true,
            actor: $actor,
        );

        return back()->with('status', 'Event outage classification updated.');
    }

    /**
     * @return array{is_sla_exempt: mixed, exemption_reason: ?string, exemption_notes: ?string}
     */
    private function validateOutageClassification(Request $request): array
    {
        return $request->validate([
            'is_sla_exempt' => 'required|boolean',
            'exemption_reason' => ['nullable', 'string', Rule::in(array_keys(SiteUptimeEvent::EXEMPTION_REASONS))],
            'exemption_notes' => 'nullable|string|max:1000',
        ]);
    }

    /**
     * Latest TYPE_DOWN event per currently-down site. Used so a monitoring
     * "this outage" classify (event-only) still drives badges and KPIs.
     *
     * @param  Collection<int, Site>  $sites
     * @return Collection<int, SiteUptimeEvent>
     */
    private function latestDownEventsBySite(Collection $sites): Collection
    {
        $downIds = $sites->where('uptime_state', 'down')->pluck('id')->all();
        if ($downIds === []) {
            return collect();
        }

        return SiteUptimeEvent::query()
            ->whereIn('site_id', $downIds)
            ->where('event_type', SiteUptimeEvent::TYPE_DOWN)
            ->orderByDesc('event_at')
            ->orderByDesc('id')
            ->get(['id', 'site_id', 'is_sla_exempt', 'exemption_reason', 'event_at'])
            ->unique('site_id')
            ->keyBy('site_id');
    }

    /**
     * @param  Collection<int, SiteUptimeEvent>  $latestDownEvents
     */
    private function siteOutageIsExcused(Site $site, Collection $latestDownEvents): bool
    {
        return (bool) $site->uptime_sla_exempt || (bool) $latestDownEvents->get($site->id)?->is_sla_exempt;
    }

    /**
     * @param  array<int, ?float>  $values
     */
    private function averageOfNonNull(array $values): ?float
    {
        $valid = array_filter($values, fn ($v) => $v !== null);
        if ($valid === []) {
            return null;
        }

        return round(array_sum($valid) / count($valid), 2);
    }
}
