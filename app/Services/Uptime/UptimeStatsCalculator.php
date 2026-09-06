<?php

namespace App\Services\Uptime;

use App\Models\Site;
use App\Models\SiteUptimeEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes uptime % per site over a window from `site_uptime_events`.
 *
 * Algorithm (per site):
 *   1. State-at-window-start: latest event STRICTLY BEFORE window start.
 *      No prior event → assume "up" (optimistic for sites that have always
 *      been good — pessimistic would punish brand-new sites).
 *   2. Walk events inside the window in chronological order. Each "down"
 *      event opens a downtime interval; each "up" event closes one. Sum.
 *   3. If the window ENDS while in 'down' state (no closing 'up' event),
 *      close the open interval at window end.
 *   4. uptime% = (window_seconds - downtime_seconds) / window_seconds × 100.
 *
 * Performance: for fleet-wide rendering of N sites × M windows, eager-load
 * events for all sites once, group by site_id, then run the per-site math
 * in memory. One query per window instead of N queries.
 */
class UptimeStatsCalculator
{
    /**
     * Compute uptime % for one site over [start, end]. Returns null when the
     * site has no events at all AND no usable last-up timestamp — we can't
     * honestly say "100%" if the site has never been observed.
     */
    public function siteUptime(Site $site, Carbon $start, Carbon $end): ?float
    {
        $priorEvent = SiteUptimeEvent::query()
            ->where('site_id', $site->id)
            ->where('event_at', '<', $start)
            ->orderByDesc('event_at')
            ->first();

        $events = SiteUptimeEvent::query()
            ->where('site_id', $site->id)
            ->whereBetween('event_at', [$start, $end])
            ->orderBy('event_at')
            ->get();

        if ($priorEvent === null && $events->isEmpty()) {
            // No history at all in or before the window. If the site exists
            // and is being monitored, the next probe will populate state,
            // but for this window we have no signal.
            return null;
        }

        return $this->computeFromEvents($priorEvent, $events, $start, $end);
    }

    /**
     * Bulk-compute uptime stats for many sites in one pass. Used by the
     * fleet-wide /monitoring page to avoid N+1 queries.
     *
     * @param  Collection<int, Site>  $sites
     * @return array<int, ?float> keyed by site_id
     */
    public function bulkUptime(Collection $sites, Carbon $start, Carbon $end): array
    {
        $siteIds = $sites->pluck('id')->all();
        if ($siteIds === []) {
            return [];
        }

        // One query for prior-state lookup: latest event per site BEFORE the
        // window. MySQL doesn't have a clean LATERAL join, so we approximate
        // with a subquery that picks max(event_at) per site, then re-join.
        $priorByEachSite = SiteUptimeEvent::query()
            ->whereIn('site_id', $siteIds)
            ->where('event_at', '<', $start)
            ->orderByDesc('event_at')
            ->get()
            ->groupBy('site_id')
            ->map(fn ($group) => $group->first());

        // One query for in-window events.
        $eventsBySite = SiteUptimeEvent::query()
            ->whereIn('site_id', $siteIds)
            ->whereBetween('event_at', [$start, $end])
            ->orderBy('event_at')
            ->get()
            ->groupBy('site_id');

        $out = [];
        foreach ($sites as $site) {
            $prior = $priorByEachSite->get($site->id);
            $events = $eventsBySite->get($site->id, collect());

            if ($prior === null && $events->isEmpty()) {
                $out[$site->id] = null;

                continue;
            }
            $out[$site->id] = $this->computeFromEvents($prior, $events, $start, $end);
        }

        return $out;
    }

    /**
     * @param  Collection<int, SiteUptimeEvent>  $events
     */
    private function computeFromEvents(
        ?SiteUptimeEvent $priorEvent,
        Collection $events,
        Carbon $start,
        Carbon $end,
    ): float {
        // Carbon 3 returns SIGNED diffs by default — `$end->diffInSeconds($start)`
        // is negative when $start is in the past, then `max(1, …)` clamps to 1
        // and uptimeSec / 1 explodes the percentage to millions. Always call
        // diffInSeconds on the EARLIER timestamp so the receiver-vs-argument
        // direction matches Carbon 3's "this until that" semantics.
        $totalSec = (int) max(1, $start->diffInSeconds($end));

        // Window starts in the state of the most recent prior event, OR 'up'
        // if no prior history exists at all (handled by caller, but
        // defensive-default to 'up' here too).
        $cursorState = $priorEvent !== null ? $priorEvent->event_type : 'up';
        $cursorAt = $start;
        $downtimeSec = 0;

        foreach ($events as $event) {
            if ($cursorState === 'down') {
                $downtimeSec += (int) $cursorAt->diffInSeconds($event->event_at);
            }
            $cursorState = $event->event_type;
            $cursorAt = $event->event_at;
        }

        // Trailing window: if we're still 'down' at window end, count the rest.
        if ($cursorState === 'down') {
            $downtimeSec += (int) $cursorAt->diffInSeconds($end);
        }

        $uptimeSec = max(0, $totalSec - $downtimeSec);

        return round(($uptimeSec / $totalSec) * 100, 2);
    }
}
