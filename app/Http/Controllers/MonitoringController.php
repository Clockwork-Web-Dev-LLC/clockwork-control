<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteUptimeEvent;
use App\Services\Uptime\UptimeStateUpdater;
use App\Services\Uptime\UptimeStatsCalculator;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
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
            ->orderByRaw("CASE
                WHEN uptime_state = 'down' AND uptime_ignored_at IS NULL THEN 1
                WHEN uptime_state = 'unknown' THEN 2
                WHEN uptime_state = 'down' THEN 3
                ELSE 4
            END")
            ->orderBy('domain')
            ->get();

        $now = now();
        $stats24h = $calc->bulkUptime($sites, $now->copy()->subDay(), $now);
        $stats7d = $calc->bulkUptime($sites, $now->copy()->subDays(7), $now);
        $stats30d = $calc->bulkUptime($sites, $now->copy()->subDays(30), $now);

        // Down-count headline excludes ignored sites — they're suppressed
        // from Issues / nav badge and the headline should match that.
        // Sites that are technically `uptime_state=down` but ignored render
        // in the table with a muted badge so they're still visible.
        $currentlyDown = $sites->where('uptime_state', 'down')->whereNull('uptime_ignored_at')->count();
        $currentlyUp = $sites->where('uptime_state', 'up')->count();
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

        return view('monitoring.index', compact(
            'sites',
            'stats24h',
            'stats7d',
            'stats30d',
            'currentlyDown',
            'currentlyUp',
            'unknown',
            'currentlyIgnored',
            'avg7d',
            'avg30d',
            'recentEvents',
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

        return view('monitoring.settings', compact('intervalMin', 'failureThreshold', 'disabledSites'));
    }

    /**
     * Run `clockwork:check-site-uptime` on demand. Same command the
     * scheduler runs every few minutes, just triggered now. Useful when
     * an operator has just flipped DNS / restarted a server / or otherwise
     * wants the up/down status to reflect reality immediately instead of
     * waiting for the next cron tick.
     */
    public function refresh(): RedirectResponse
    {
        try {
            $exitCode = Artisan::call('clockwork:check-site-uptime');
            $output = trim((string) Artisan::output());
        } catch (\Throwable $e) {
            return back()->with('status_error', 'Uptime refresh error: '.$e->getMessage());
        }

        // Pull the "Done. up=N down=M elapsed=Xs" line from the artisan output.
        $summary = '';
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (str_starts_with(trim($line), 'Done.')) {
                $summary = trim($line);
                break;
            }
        }

        if ($exitCode === 0) {
            return back()->with('status', 'Uptime refresh complete. '.$summary);
        }

        return back()->with('status_error', 'Uptime refresh failed (exit '.$exitCode.'). '.$summary);
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
