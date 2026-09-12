<?php

use App\Services\Ingest\IngestScheduleGate;
use App\Support\Settings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Core\ModuleRegistry;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Must stay first among every-minute jobs. A cheap Settings write that
// proves crontab actually spawned `schedule:run`. Detection of a missing
// tick happens on web requests — a scheduled command cannot watch itself.
Schedule::command('clockwork:scheduler-heartbeat')
    ->everyMinute()
    ->onOneServer();

Schedule::command('clockwork:sync-allowed-bots')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('clockwork:poll-servers')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('clockwork:tail-nginx-logs')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

// Per-site HTTP uptime probe — fires Mattermost on transitions (down/up).
// Sequential probing of ~150 sites takes ~2-3 min; well inside the 5-min tick.
// Runs alongside poll-servers (DO API) and tail-nginx-logs (SSH); they're
// independent network operations and don't compete for the same resources.
//
// Interval is configurable from /monitoring/settings (`monitoring.uptime_interval_minutes`).
// Changes apply on the next scheduler restart — Laravel parses this file once
// at boot, so a setting change without a restart leaves the old cron in place.
//
// rescue(): this file loads on EVERY app boot — including test runs and fresh
// installs where app_settings doesn't exist yet. A missing/unready DB falls
// back to the 5-minute default instead of taking the whole boot down.
$uptimeIntervalMin = (int) rescue(fn () => app(Settings::class)->get('monitoring.uptime_interval_minutes', 5), 5, report: false);
$uptimeIntervalMin = in_array($uptimeIntervalMin, [1, 5, 10, 15], true) ? $uptimeIntervalMin : 5;
Schedule::command('clockwork:check-site-uptime')
    ->cron("*/{$uptimeIntervalMin} * * * *")
    ->withoutOverlapping(60)
    ->onOneServer()
    ->runInBackground();

// clockwork:import-spinupwp moved to Modules\SpinupWp\SpinupWpServiceProvider::scheduledTasks() (Phase 7).

// Catches servers that import-spinupwp couldn't IP-cross-reference (manual
// adds, servers SpinupWP didn't know about yet). Runs after import so the
// pool of unlinked rows is current.
Schedule::command('clockwork:reconcile-provider')
    ->dailyAt('03:45')
    ->withoutOverlapping()
    ->onOneServer();

// Closes the SpinupWP/Companion snapshot gap: import-spinupwp (03:30) marks
// wp_plugin_updates=true for sites that have new updates, but the full
// companion snapshot refresh (01:30 ET ≈ 05:30 UTC) already ran using the
// Companion's cached state from before the updates were available. This
// targeted re-pull hits only sites where SpinupWP says updates exist but the
// cached snapshot still shows zero — the Companion does a fresh WP check on
// each snapshot request, so this is all that's needed.
Schedule::command('clockwork:refresh-companion-snapshot --pending-updates-only')
    ->dailyAt('03:50')
    ->withoutOverlapping()
    ->onOneServer();

// Apt-update visibility: the SpinupWP mirror only gives us a boolean
// `upgrade_required` for SpinupWP-managed servers, and nothing sets that
// flag at all for GridPane/Hetzner/custom-VPS boxes. This command SSHs to
// (a) SpinupWP servers the mirror flagged with upgrade_required=true, and
// (b) every non-SpinupWP-managed server unconditionally, pulling the actual
// count + security split + reboot-required package list, so the per-server
// Updates tab can show "12 updates, 3 security" instead of just a yes/no
// pill. Runs after import-spinupwp so the trigger boolean is fresh.
Schedule::command('clockwork:poll-system-updates')
    ->dailyAt('04:15')
    ->withoutOverlapping()
    ->onOneServer();

// Weekly full-fleet sweep as a safety net — catches SpinupWP servers whose
// mirrored upgrade_required flag is stale/wrong, which the daily job above
// would otherwise skip. --all checks every monitored server regardless of
// that flag. (Historically this sweep was load-bearing for every
// non-SpinupWP server too, before the daily job above was fixed to include
// them directly — confirmed 2026-09-03: only 2 of 45 monitored servers had
// upgrade_required=true, and the other 43 hadn't been polled since
// 2026-06-16.)
Schedule::command('clockwork:poll-system-updates --all')
    ->weeklyOn(1, '04:30')
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command('clockwork:check-ssl-certs')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onOneServer();

// Capture / refresh website homepage screenshots via Automattic mShots
Schedule::command('clockwork:capture-site-screenshots')
    ->dailyAt('04:45')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground();

// Watchdog: if the queue worker launchd service has no PID (crashed or
// throttled into a backoff loop), kick it back alive. Runs every 5 min so
// the gap between a crash and recovery is at most 5 minutes.
Schedule::command('clockwork:ensure-queue-worker')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Safety net for the Updates page job pipeline — flips jobs stuck in
// 'running' for >10 min to 'failed'. Most useful after a worker crash
// or unexpected reboot of the queue host.
Schedule::command('clockwork:reap-stale-update-jobs')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Sibling safety net for the fleet system-update pipeline. Sat through
// web47 being stuck "running" for 4 weeks before anyone noticed —
// this reaper flips servers stuck past 2h back to 'failed' so they can
// be re-queued from Operations → System updates.
Schedule::command('clockwork:reap-stale-server-updates')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// WordPress 7.0 auto-upgrade can leave wp-includes/php-ai-client/ files
// with truncated filenames (caught 4 affected sites across 3 different
// servers mid-June 2026). Sweep + auto-repair weekly so a Saturday-night
// upgrade casualty gets patched before Monday morning.
Schedule::command('clockwork:scan-wp7-truncation --repair')
    ->weeklyOn(0, '05:30')
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('clockwork:prune-server-metrics')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->onOneServer();

// Chunked delete of raw nginx rows older than the saved retention window
// (default 30 days). site_traffic_daily rollups stay. First catch-up can
// run for hours on a bloated table — background + long mutex so it cannot
// stack, and it never OPTIMIZE TABLEs.
Schedule::command('clockwork:prune-threat-logs')
    ->dailyAt('04:32')
    ->withoutOverlapping(240)
    ->runInBackground()
    ->onOneServer();

// Probe each WP site over SSH for active security plugins. SpinupWP's API doesn't
// expose plugin inventory, so this is the only way to keep llar_enabled /
// wordfence_enabled honest on the inventory page.
Schedule::command('clockwork:detect-wp-plugins')
    ->dailyAt('04:45')
    ->withoutOverlapping()
    ->onOneServer();

// Refresh fail2ban's ignoreip whitelist (Cloudflare edge ranges + every server
// in our fleet's own public IP) on every provisioned server. Weekly because
// CF ranges change rarely; the cost of running it is low (~30s per server).
// Without this, LLAR lockouts on CF-proxied sites end up banning CF edges and
// the site flaps with 521s — see the Phase 1 SweepCfBans for the cleanup story.
Schedule::command('clockwork:refresh-fail2ban-ignoreip')
    ->weeklyOn(0, '05:30')
    ->withoutOverlapping()
    ->onOneServer();

// Push the nginx CF-real-IP snippet (set_real_ip_from for every CF range +
// real_ip_header CF-Connecting-IP) to every server. Same weekly cadence as the
// ignoreip refresh; CF range additions are rare. After this snippet is applied,
// PHP REMOTE_ADDR is the actual visitor IP — fail2ban/LLAR/Wordfence all start
// banning the right people. The snippet is idempotent and skips reload when
// content is unchanged, so most weekly runs are cheap no-ops.
Schedule::command('clockwork:refresh-cloudflare-real-ip')
    ->weeklyOn(0, '05:45')
    ->withoutOverlapping()
    ->onOneServer();

// Hourly: refresh today's rollup so the per-site traffic charts stay current.
// Day-boundary catch-up is handled by --backfill=2 (covers today + yesterday).
Schedule::command('clockwork:rollup-traffic --backfill=2')
    ->hourly()
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('clockwork:check-cloudflare')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->onOneServer();

// Tick frequently and let the multi-source gate decide whether to actually run.
// Each source has its own enable toggle and last_run_at, but they share the night window
// and cadence configured at /settings/ingest.
Schedule::command('clockwork:pull-llar-lockouts')
    ->everyFifteenMinutes()
    ->when(fn () => app(IngestScheduleGate::class)->shouldRunNow(IngestScheduleGate::SOURCE_LLAR))
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('clockwork:pull-wordfence-blocks')
    ->everyFifteenMinutes()
    ->when(fn () => app(IngestScheduleGate::class)->shouldRunNow(IngestScheduleGate::SOURCE_WORDFENCE))
    ->withoutOverlapping(30)
    ->onOneServer();

// Refresh per-site Companion snapshot (plugins + admins + cron + comments) into
// sites.companion_snapshot. Runs once a day, late at night, after Sucuri (02:00
// Monday) and checksums (02:30 daily) but well before the Companion-capabilities
// pull (06:25). Plugin/theme/core/translation update flags only need to be
// fresh enough that the operator sees yesterday's releases when they sit down
// in the morning — hourly polls are wasted work and add no operator value.
//
// Manual update flows still get fresh data immediately: when a batch from the
// Updates page completes, RefreshSnapshotsAfterBatch dispatches a per-site
// refresh job, so the UI shows post-update versions within seconds. The
// scheduled run only catches changes Clockwork didn't initiate (auto-updates,
// wp-admin clicks, plugin installs/removals).
// Moved earlier (was 03:00 UTC) so the snapshot is fresh BEFORE the
// nightly auto-update loop reads `wp_plugin_updates` and the per-site
// snapshot plugin list. Pinned to America/New_York so DST shifts don't
// drift the dependency.
//
// clockwork:run-nightly-plugin-updates is chained via ->then() rather than
// given its own dailyAt() time — it used to fire independently 30 minutes
// later, on the assumption a full-fleet refresh always finished inside that
// window. Real timing on 2026-09-04: the refresh started 01:35 ET and didn't
// finish its last site until 02:43 ET — 64 of 165 sites (39% of the fleet)
// were still stale when the update loop read their snapshot at 02:00 and
// silently skipped every pending update on them (not a failure — just
// invisible to the candidate query, since it reads companion_snapshot as it
// stands at read time). ->then() guarantees the update loop only starts once
// refresh has actually exited, however long that takes, instead of racing a
// fixed offset. The chained call intentionally skips withoutOverlapping()/
// onOneServer() of its own — it inherits both from this parent event, since
// the whole refresh+then-update sequence only ever fires once per this
// event's single daily occurrence.
Schedule::command('clockwork:refresh-companion-snapshot')
    ->dailyAt('01:30')
    ->timezone('America/New_York')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->then(fn () => Artisan::call('clockwork:run-nightly-plugin-updates'));

// Pull per-site CPU/memory hourly rollups from every Companion site that
// advertises `resource-sampler` (1.17.0+). Feeds the per-site leaderboard on
// /capacity. Every 15 min matches the per-server metrics cadence — fast HTTPS,
// idempotent UPSERT, current-hour bucket re-pulls every cycle so the open
// bucket stays fresh. Operator can pause collection from the /capacity
// section header via the `monitoring.site_metrics_enabled` setting.
Schedule::command('clockwork:pull-site-metrics')
    ->cron('*/15 * * * *')
    ->withoutOverlapping(15)
    ->onOneServer()
    ->when(fn () => (bool) app(Settings::class)->get('monitoring.site_metrics_enabled', true));

// Idempotently inject CLOCKWORK_COMPANION_TRUST_PROXY into wp-config.php
// on every care-plan auto-update site so Companion's per-IP rate limiter
// sees real client IPs behind Cloudflare. No-op when already present;
// the daily cadence catches newly-eligible sites without manual ops.
// Runs ahead of the 02:00 nightly update loop so the constant is in
// place before any other Companion work touches the site.
Schedule::command('clockwork:ensure-companion-trust-proxy')
    ->dailyAt('01:45')
    ->timezone('America/New_York')
    ->withoutOverlapping(30)
    ->onOneServer();

// Fleet-wide "silently stuck" sweep — failed installs never retried, or a
// previously-installed Companion gone silent for days. Runs after the
// snapshot refresh above so its staleness check sees today's freshest data.
// See DetectStuckCompanionState's docblock for the two conditions and the
// 2026-08-31 incident (two dead Pressable installs with zero trace anywhere)
// that prompted this.
Schedule::command('clockwork:detect-stuck-companion-state')
    ->dailyAt('01:50')
    ->timezone('America/New_York')
    ->withoutOverlapping(30)
    ->onOneServer();

// Nightly auto-update loop. Queues plugin-only updates for every care-plan
// site that has updates pending and isn't paused. CVE-flagged plugins
// jump to the front of the queue. Workers (Supervisor-managed
// plugin-updates queue) chew through the batch during the window.
//
// No longer scheduled here directly — chained via ->then() off the
// clockwork:refresh-companion-snapshot event above (see that comment for
// why: a fixed 30-minute offset silently dropped ~39% of the fleet's
// updates on 2026-09-04 because the refresh routinely runs past an hour).

// End-of-window summary: one email to clockwork.alerts.email with the
// success/failure breakdown. 06:15 ET gives a 15-minute buffer past the
// nominal 06:00 cut-off to absorb slow-tail jobs.
Schedule::command('clockwork:nightly-update-summary')
    ->dailyAt('06:15')
    ->timezone('America/New_York')
    ->withoutOverlapping(30)
    ->onOneServer();

// Refresh per-site Companion capabilities (companion_capabilities + companion_version)
// once a day. Capabilities only ever change when a new plugin version is rolled
// out — daily is plenty. Runs ahead of the 06:30 backups push so retention/care-plan-
// aware features can rely on fresh cap data. Diff-aware: unchanged sites are silent.
Schedule::command('clockwork:refresh-companion-capabilities')
    ->dailyAt('06:25')
    ->withoutOverlapping(30)
    ->onOneServer();

// clockwork:push-companion-backups (06:30, SpinupWP-only) moved to
// Modules\SpinupWp\SpinupWpServiceProvider::scheduledTasks() (Phase 7 followup).

// Pressable counterpart (06:32) moved to Modules\Pressable\PressableServiceProvider::scheduledTasks() (Phase 7)
// — pulls real backup run history straight from Pressable's API (no separate
// inventory-import dependency, unlike the SpinupWP version above).

// Push 30-day traffic rollup to each Companion-equipped site so clients can
// see their wp-admin → Clockwork → Traffic page populated. Daily at 06:35 —
// after the access-log rollup (clockwork:rollup-traffic) has finalised the
// previous day's row. Matches the "Refreshed nightly" banner the page renders.
Schedule::command('clockwork:push-companion-traffic')
    ->dailyAt('06:35')
    ->withoutOverlapping(60)
    ->onOneServer();

// Pressable counterpart (06:37) moved to Modules\Pressable\PressableServiceProvider::scheduledTasks() (Phase 7)
// — pulls page-view stats straight from Pressable's API (no nginx
// access-log rollup dependency, unlike the SpinupWP version).

// clockwork:pressable-security-summary-report (06:39, Pressable-only: known plugin/theme
// vulnerabilities + Defensive Mode status) moved to
// Modules\Pressable\PressableServiceProvider::scheduledTasks() (Phase 7).

// Backup relay (Pressable -> S3 Glacier, replacing ManageWP's 90-day
// Pressable retention): clockwork:push-backup-relay-targets (04:58) and
// clockwork:pull-backup-relay-report (06:40) moved to
// Modules\Pressable\PressableServiceProvider::scheduledTasks() (Phase 7 followup).

// clockwork:find-orphan-sites (03:35, SpinupWP-only) moved to
// Modules\SpinupWp\SpinupWpServiceProvider::scheduledTasks() (Phase 7 followup).

// Drains queued apt-update jobs. limit=1 by default — each run can take minutes, so we
// process one server per tick to avoid stacking long SSH sessions.
Schedule::command('clockwork:process-server-updates')
    ->everyMinute()
    ->withoutOverlapping(20)
    ->onOneServer();

// Drains the queued_for_ban queue. Small batch + every minute so even a 100-IP bulk
// approve clears in a few minutes without ever blocking a request.
Schedule::command('clockwork:process-pending-bans')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->onOneServer();

// Promotes pending review-queue entries to queued_for_ban when the IP has 2+ lockouts
// fleet-wide. Runs every minute so a freshly-ingested second sighting auto-bans within
// ~60 seconds. Internally gated by Settings('auto_approve_repeats_enabled') — disabling
// from the UI makes this command a no-op without removing it from the schedule.
Schedule::command('clockwork:auto-approve-repeats')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Pre-compute the /settings/weird-stats threat_logs-derived stats so the
// page always serves warm cache. Cold compute is ~30s on a 2M+ row threat_logs
// window — past PHP-FPM's typical max_execution_time. Cache TTL is 10 min;
// we warm every 9 to never let it expire under a real visit. runInBackground
// so the long compute doesn't block other every-minute scheduler ticks.
Schedule::command('clockwork:warm-weird-stats')
    ->cron('*/9 * * * *')
    ->withoutOverlapping(15)
    ->runInBackground()
    ->onOneServer();

// Weekly security checks. Both fail loudly via exit code so a CI hookup or
// a watchdog could pick up regressions; the audit also posts to Mattermost
// when CVE-laden deps appear. Mondays 06:00 + 06:30 — out of the way of the
// daily 03:00–05:45 window.
Schedule::command('clockwork:composer-audit')
    ->weeklyOn(1, '06:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('clockwork:security-check --ssh --quiet-ok')
    ->weeklyOn(1, '06:30')
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer();

// Sucuri SiteCheck — replaces the security scan ManageWP used to run as part
// of its care plans. Same engine ManageWP shells out to (Sucuri are GoDaddy
// siblings); the free public API is rate-limited around 30 req/min so the
// command sleeps 250ms between sites. Daily cadence so all care-plan scans
// (Sucuri + checksums + blacklists) match the once-a-day operator expectation.
Schedule::command('clockwork:scan-sitecheck')
    ->dailyAt('02:00')
    ->when(fn () => (bool) app(Settings::class)->get('security_scans.sitecheck_enabled', true))
    ->withoutOverlapping(60)
    ->onOneServer()
    ->runInBackground();

// Server-side core-file integrity check via wp-cli verify-checksums over SSH.
// Catches the case Sucuri can't see — PHP shells / base64 backdoors dropped
// into wp-includes or wp-admin that aren't rendered into the public HTML.
// Daily because this is the highest-value gap ManageWP didn't fill, and
// because each scan is just one SSH session per WP site.
Schedule::command('clockwork:verify-wp-core-checksums')
    ->dailyAt('02:30')
    ->when(fn () => (bool) app(Settings::class)->get('security_scans.checksums_enabled', true))
    ->withoutOverlapping(60)
    ->onOneServer()
    ->runInBackground();

// In-WP malware probe — Companion plugin endpoint preferred, SSH wp-cli
// fallback. Bypasses Cloudflare so CF-fronted sites get real signal instead
// of the 403 wall Sucuri's external scanner hits. Scoped to care-plan only,
// like the other scans. Runs after checksums (02:30) so the two security
// scans don't overlap on SSH sessions to the same fleet.
Schedule::command('clockwork:run-companion-malware-scans')
    ->dailyAt('02:45')
    ->when(fn () => (bool) app(Settings::class)->get('security_scans.companion_malware_enabled', true))
    ->withoutOverlapping(60)
    ->onOneServer()
    ->runInBackground();

// Domain blacklist check — recovers the most useful part of Sucuri SiteCheck
// (the "is this domain blacklisted?" signal) without depending on Sucuri being
// able to fetch the homepage. CF WAFs in front of our sites 403 Sucuri's
// scanner; this path queries blacklist sources directly. URLHaus + Spamhaus
// DBL always; Google Web Risk if CLOCKWORK_GOOGLE_WEB_RISK_KEY is set,
// else legacy Safe Browsing v4 if CLOCKWORK_GOOGLE_SAFE_BROWSING_KEY is set.
// Hosting-tier feature, runs against every site, not gated on care plan.
Schedule::command('clockwork:check-blacklists')
    ->dailyAt('02:15')
    ->when(fn () => (bool) app(Settings::class)->get('security_scans.blacklist_enabled', true))
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground();

// Refresh local mirror of plugin vulnerabilities from wpvulnerability.net.
// Walks every fleet site's installed plugin slugs, hits the public free
// per-plugin REST endpoint, replaces the plugin_vulnerabilities mirror in
// one transaction. Used by the Issues page to flag sites with installed
// plugin versions matching a known CVE. Free, no API key, ~30-60s for the
// whole fleet at one request per unique slug.
Schedule::command('clockwork:refresh-plugin-vulnerabilities')
    ->dailyAt('03:15')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->runInBackground();

// Sync CISA Known Exploited Vulnerabilities (KEV) catalog.
// Downloads the official CISA JSON feed daily to detect active in-the-wild
// exploitation of CVEs present in installed plugins, enriching vulnerability
// modals and alert emails with an 'Actively exploited (CISA KEV)' badge.
Schedule::command('clockwork:refresh-cisa-kev')
    ->dailyAt('03:20')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground();

// Check WordPress.org plugin directory status for closed/zombieware plugins.
// Queries the public official plugin information API once per unique slug
// fleet-wide. Closed plugins receive zero security patches and are surfaced
// on the Issues page. Runs weekly on Mondays at 03:30 UTC.
Schedule::command('clockwork:refresh-closed-plugins')
    ->weeklyOn(1, '03:30')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->runInBackground();

// Lighthouse / PageSpeed scan, weekly per site via nightly rotation. Replaces
// the ManageWP "Performance Check" feature with a modern Lighthouse score
// (Google's authoritative SEO ranking surface) plus Core Web Vitals. The
// command short-circuits when no engine key is configured; the schedule
// gate also lets the toggle on /settings/performance-scans (future) act as
// a global kill-switch without removing the schedule entry.
//
// As of 2026-07-14 the nightly run scans 1/7th of the fleet (--weekly-
// rotation), so each site gets one scan per week. Why: GTmetrix (Core tier)
// refills 10 API credits daily at ~04:27 UTC and credits don't bank, so the
// old full-fleet nightly run burned all credits on the first ~10 sites and
// silently dropped the other ~65 onto the PSI mobile fallback — the "why is
// my dashboard score horrible when gtmetrix.com says A" complaint. The run
// time moved 03:30 → 04:45 UTC to land just AFTER the credit refill, so all
// 10 fresh credits are available (at 03:30 the run spent whatever was left
// of the PREVIOUS day's refill).
//
// As of 2026-06-30 we run ONE scan per site, not two. GTmetrix (the primary
// engine) doesn't expose device emulation on our paid tier — both "mobile"
// and "desktop" strategies submit identical desktop-Chrome scans. Running
// twice would double quota usage for identical data. The `--strategy=mobile`
// flag is preserved purely for historical continuity with prior PSI rows
// that used the same value. If GTmetrix unlocks mobile device simulation
// later (or we move back to PSI primary), the second scheduled entry can
// be re-added.
Schedule::command('clockwork:run-performance-scans --strategy=mobile --weekly-rotation')
    ->dailyAt('04:45')
    ->when(fn () => (bool) app(Settings::class)->get('performance_scans.enabled', true))
    ->withoutOverlapping(60)
    ->onOneServer()
    ->runInBackground();

// clockwork:sync-bill-customers (01:00) and clockwork:sync-bill-care-plans
// (01:30) moved to Modules\BillCom\BillComServiceProvider::scheduledTasks()
// (Phase 8 followup).

Schedule::command('clockwork:check-domain-expirations')
    ->dailyAt('06:05')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->runInBackground();

Schedule::command('clockwork:check-robots-txt')
    ->dailyAt('07:15')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->runInBackground();

Schedule::command('clockwork:send-telemetry')
    ->weeklyOn(1, '06:15')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->runInBackground()
    ->when(fn () => (bool) config('clockwork.telemetry.enabled', true)
        && (bool) app(Settings::class)->get('telemetry.enabled', true));

// Every module's own scheduledTasks() contribution (SpinupWp's nightly
// import, Pressable's 3 report commands, etc.) — registration order
// relative to the hardcoded entries above doesn't matter: each entry fires
// on its own configured time regardless of where in this file it was
// registered.
app(ModuleRegistry::class)->scheduleAll(Schedule::getFacadeRoot());
