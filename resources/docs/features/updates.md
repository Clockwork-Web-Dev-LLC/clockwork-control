---
title: Updates
section: Features
order: 35
updated: 2026-11-05
author: Aaron Reimann
tags: [updates, plugins, themes, wp-core, translations, care-plan, pressable]
tracks: [app/Services/Updates/UpdateGrouping.php, app/Services/Updates/UpdateFailureStreakRecorder.php, app/Services/Updates/ThemeIdentityGuard.php, app/Services/Updates/ObservedUpdateRecorder.php, app/Http/Controllers/UpdatesController.php, app/Http/Controllers/MaintenanceHistoryController.php, app/Http/Controllers/SitesController.php, app/Jobs/**, app/Models/PluginUpdateJob.php, app/Models/PluginUpdateIgnore.php, app/Models/PluginUpdateFailureStreak.php, app/Console/Commands/RunNightlyPluginUpdates.php, app/Console/Commands/PushUpdateExceptions.php, app/Console/Commands/NightlyUpdateSummary.php, app/Console/Commands/RefreshCompanionSnapshot.php, app/Console/Commands/DetectStuckCompanionState.php, app/Console/Commands/ReapStaleUpdateJobs.php, app/Services/Companion/ClockworkCompanionClient.php, app/Services/Companion/CompanionInstaller.php, modules/Pressable/src/PressableCompanionInstaller.php, database/migrations/**add_state_to_plugin_update_jobs*, database/migrations/**plugin_update_failure_streaks*, database/migrations/**add_failure_tracking_to_plugin_update_ignores_table*]
---

Fleet-wide page for plugin / theme / WP core / translation updates. Replaces the per-site click-through workflow with a single grouped-by-name view modeled after ManageWP Orion. Lives at **`/updates`** (top-nav between Issues and Security).

Not to be confused with [Features → System updates](/docs/features/system-updates) at `/settings/updates` — that page updates Clockwork Control itself; this one updates the WordPress sites you monitor.

## What it shows

Four tabs, four stat cards. Numbers come from each site's cached Companion snapshot — refreshed nightly at 01:30 ET via `clockwork:refresh-companion-snapshot`, and immediately after any update batch finishes (so post-update versions show up on the page within seconds of the worker completing).

| Tab | Grouping | Source |
|---|---|---|
| **Plugins** | by plugin slug across the fleet (`Beaver Builder × 25`, expand to see sites) | `companion_snapshot.plugins.plugins[].update_available` |
| **Themes** | by theme slug, same shape | `companion_snapshot.themes.items[].update_available` |
| **WordPress** | flat per-site list (one core, no grouping) | `companion_snapshot.wp_core.update_available` |
| *(Core tab, before→after version)* | — | `UpdateGrouping::siteRow()` reads `$item['version'] ?? $item['current_version']` for the "current" column to display before and after versions (e.g. 7.0.4 → 7.1). |
| **Translations** | flat per-site list | `companion_snapshot.translations.count` |

Themes / Core / Translations require **Companion mu-plugin v1.15.0+** to surface data. Earlier versions don't include those keys in `/snapshot`, so those tabs show 0.

This page is host-agnostic by construction — it reads Companion's snapshot, not a hosting-provider API, so Pressable sites show real pending-update data here exactly like SpinupWP sites do. (Pressable's own native update API exists but isn't used for this — Companion/wp-cli already covers it.) The one place hosting provider leaks through is the per-site Overview tab, where the SpinupWP-inventory update pills (`wp_core_update` etc., a different data source from this page) are hidden for Pressable sites rather than shown permanently false.

### Premium-plugin visibility (Companion 1.21.4+)

Plugins that ship updates via Crocoblock's Jet Dashboard (Jet Engine, Jet Search, Jet Smart Filters, etc.), Yoast Premium, Elementor Pro, WPMU DEV, and other Freemius-style updaters gate their `pre_set_site_transient_update_plugins` hook on `is_admin()` — which a REST request fails. That means **wp-admin saw 3 pending updates while Clockwork saw 1**, because two of them were licensed plugins that only surface their updates when an admin page actually loads.

Companion 1.21.4 fixes this with a loopback admin-ajax call: `PluginsRoute::payload()` triggers `TransientRefresher::refresh()`, which makes a `wp_remote_post()` to the site's own `/wp-admin/admin-ajax.php?action=clockwork_refresh_updates`. The admin-ajax request runs through the real admin lifecycle (`WP_ADMIN`, `DOING_AJAX`, `admin_init`), so licensed plugins initialize, their filter callbacks fire, and the `update_plugins` / `update_themes` transients get the full list before `/snapshot` reads them.

Rate-limited to once per 30 min via the transient's own `last_checked`; the loopback is HMAC-signed against the Companion secret so it's not externally invokable. Soft-fails — a loopback failure (DNS, CF block, timeout) doesn't kill the snapshot; you just keep whatever the cron-driven transient already had.

**Sites pre-1.21.4 will show fewer plugin updates than wp-admin reveals.** The gap closes the moment you `clockwork:install-companion --site=<domain>` and refresh the snapshot.

To ensure cached plugin-update transients remain fresh, `RefreshCompanionSnapshot` calls `->plugins()` immediately before `->snapshot()` for every capable site on each run — non-fatal on failure, ensuring the freshest available plugin inventory is captured.


## Filters

Top-of-page filter chips:

- **Care plan**: `Care plan only` (default — the operational case), `Not on plan`, `All`. Per-site, not per-server — `dedicatedhost.example` (care plan) and `ads.dedicatedhost.example` (no plan, same dedicated server) are separately included or filtered.
- **Server**: `All`, `Dedicated`, `Shared` — driven by tags applied to the server (server-level, not per-site).
- **Show ignored**: dimmed rows for entries previously marked Ignore.

Filter selections are query-string state — bookmark `/updates?care_plan=on&server_tier=Dedicated` for "the Tuesday update window view."

## How updates run

Async via Laravel queue. The page never blocks while waiting on Companion HTTP — each click writes `plugin_update_jobs` rows and dispatches one job per row onto a dedicated `plugin-updates` queue.

```
operator clicks Update Selected
    ↓
plugin_update_jobs (status=pending, batch_id=uuid)
    ↓
queue:work --queue=plugin-updates  (4 parallel workers under supervisor)
    ↓
Cache::lock(site_update:{id}, 300)   ← per-site serialization
    ↓
ClockworkCompanionClient::updatePlugin()  /etc.
    ↓
plugin_update_jobs row writeback + action_logs row (mirrors to Companion)
    ↓
when last live row in batch finishes → dispatch RefreshCompanionSnapshot
                                       per affected site
```

**Per-site lock**: only one update can run against a given site at a time. If you bulk-select 5 plugin updates on the same site, they execute serially (the Cache::lock ensures it). Two different sites = parallel. Lock TTL is 300s — bounded so a crashed worker can't strand the site forever.

**No retries.** `tries=1`. WP file replacement is partially-idempotent at best; auto-retry on a partial failure usually makes things worse. Failed rows surface in the page; manually re-queue if you want to retry.

**Stale-job reaper.** If a worker dies mid-update, `clockwork:reap-stale-update-jobs` (hourly) flips rows stuck `running` >10 min to `failed`. Belt-and-suspenders for OOM-kill / host reboot.

## Care plan UX

The per-site row shows a Care plan / No plan pill driven by `sites.care_plan_enabled`. The pill is currently display-only on this page — to flip a site's care-plan state, go to `/sites/{id}/settings`. Bulk-flip from this page is on the deferred list.

Bill.com sync drives `care_plan_enabled` automatically based on invoice line items matching `CLOCKWORK_BILL_COM_CARE_PLAN_ITEM_REGEX`. Manual flips set `care_plan_override` non-null, which the next Bill.com sync respects (won't override your manual choice).

## Ignoring an update

`Ignore Selected` writes to `plugin_update_ignores` keyed on `(site_id, target_kind, target_slug)`. Once ignored, that pair never appears in the pending list (and doesn't count toward the urgent Issues nav badge) until you `Resume` or `Unignore`.

### Manual ignore vs. Auto-ignore

- **Manual ignore**: Triggered by an operator clicking *Ignore* on `/updates`. Sets `source = manual` and `client_visible = false`. It is kept private to Control by default (an agency decision like "hold off updating WooCommerce this week" is internal).
- **Auto-ignore**: Triggered automatically when a plugin or theme repeatedly fails nightly automated updates and crosses the streak threshold. Sets `source = auto_failure` and `client_visible = true`.
- **Held as a different theme**: Written by the nightly loop when the installed theme doesn't look like the theme on offer (see *Theme slug collisions* below). Sets `source = identity_mismatch` and `client_visible = false`, and holds like a manual ignore (shown as one on the site's Updates tab, with the reason underneath).

### Auto-ignore after repeated nightly failures

When a stubborn plugin or theme fails the nightly update queue night after night, Clockwork stops retrying that plugin, marks it auto-ignored, and surfaces the paused status directly in wp-admin via Companion and Renegade so site administrators can see which plugins are still on automatic updates.

#### What counts as a "try"
- Only **nightly automated updates** increment consecutive failure streaks (`plugin_update_jobs.batch_id` starts with `nightly-` and `requested_by_user_id` is null).
- Manual `/updates` or per-site tab attempts are operator-initiated — they write action logs but **never increment** the auto-ignore streak (though manual successes **do** reset it).

#### Increment vs. Skip
- **Increments streak**: Plugin-level failures where Companion returns an error response body, stalled no-ops (`after_version === before_version !== target_version`), or post-update reactivation failures. A stalled no-op still counts even when Companion's own `ok` is true, because Control reclassifies it and stamps an `error` onto the result.
- **Does not increment**: Infrastructure, site, or transport issues. Connection timeouts, DNS/TLS errors, HTTP transport throwables without response bodies, per-site cache lock contention, stale-job reaper flips, and the case where Companion succeeded (`ok=true`, no error) but a later Control-side step threw do **not** march a plugin toward auto-ignore.

#### Threshold and Kill Switch
- **Threshold**: Default 5 consecutive nightly failures (min 3, max 20), configurable via the `updates.auto_ignore_after_failures` setting.
- **Kill switch**: `updates.auto_ignore_enabled` (default `true`). When set to `false`, consecutive failure streaks are still tracked for operator visibility, but auto-ignore rows are never created (and the site-level pause below never fires).

#### File-permission failures pause the site, not each plugin
Each streak stores the `reason_code` of its latest failure (see *Stalled no-op detection*), and how many counted failures in a row were `fs_permission` ("Could not copy file", "Could not create directory …/wp-content/upgrade/…") in `consecutive_fs_failures`, with the job that began that run in `fs_streak_started_job_id`. A counted failure for any other reason ends the run. When the run of `fs_permission` failures itself reaches the threshold and no plugin or theme update on that site has succeeded since the run began (uncounted transport or reaped failures in between don't move its start), the problem is the site's file ownership, not the plugin. A plugin that failed for other reasons first and then once with a file-permission error is not enough. Instead of auto-ignoring each plugin in turn, Control:
- sets `sites.auto_updates_paused = true` with a "Filesystem not writable…" reason (the same flag as the manual per-site pause, so the nightly loop skips the whole site);
- writes one `auto_updates_toggled` action log (`actor = auto`, `details.reason_code = fs_permission`);
- sends one ops chat alert.

No per-plugin ignore is created and nothing is pushed to wp-admin. Fix the file ownership/permissions for the PHP user, then resume the site with the per-site auto-update toggle. If the site is already paused (by an operator or an earlier failure), nothing changes and no second alert is sent. If another update on the site did succeed during the streak, the plugin gets the normal per-plugin auto-ignore.

#### Reset and Resume
- Any **successful update** (nightly or manual) resets `consecutive_failures` back to 0. When it also lands on its target version, an auto-ignore for that slug is removed, the streak's pause markers are cleared, and the shorter exceptions list is pushed so the wp-admin notice clears. Manual ignores are never removed this way.
- An auto-ignore does not block forever. The nightly loop still tries an auto-ignored plugin or theme when the snapshot offers a **different version** from the one last tried (`plugin_update_failure_streaks.last_target_version`), once per new version: queueing the retry records that version as the last target (and `last_retry_queued_at`), so a retry that times out, is reaped or hits a transport error still counts as its one attempt. It also tries an auto-ignored plugin whose installed version is **vulnerable** when the offered version is at or above the highest `patched_in` of the matching advisories: at once for a fix version not tried yet, and otherwise at most once every 7 days (`RunNightlyPluginUpdates::SECURITY_RETRY_BACKOFF_DAYS`, counted from the later of the last failure and the last queued retry). A fix version that last failed with `download_unauthorized`, `no_package` or `not_offered` is not retried at all until a newer version is offered, because a licence or offer problem needs an operator. Otherwise the slug stays paused until resumed or until one of those conditions is met.
- Clicking **Resume** (or unignore) in Control deletes the ignore record, resets the consecutive failure streak to 0, and immediately pushes an updated exceptions payload to the WordPress site.
- Converting an auto-ignore into a **manual ignore** keeps nightly retries paused but sets `client_visible=false` and pushes an updated exceptions list so the slug drops out of wp-admin.
- Manual ignores are never rewritten as auto-failure, even if the slug later fails nightly.

#### What clients see in wp-admin (Companion & Renegade)
Sites that advertise the `update-exceptions` capability (Companion / Renegade builds that include the Update coverage page) receive the full list of active update exceptions at `POST /wp-json/clockwork/v1/update-exceptions` (or `/wp-json/clockwork-renegade/v1/update-exceptions`), stored in `wp_options['clockwork_update_exceptions']`. Older plugin versions without the capability are a no-op until upgraded; Control still pauses nightly retries.
- **Update coverage page**: Visible to `manage_options` administrators under the branded Clockwork menu.
  - *Empty state*: "Automatic plugin and theme updates are on for this site when a care plan is active. Nothing is currently paused."
  - *Populated state*: Displays a clear table of paused plugins, version stopped on, date paused, and public explanation.
- **Plugins / themes notice**: A dismissible warning notice on `plugins.php` and `themes.php` alerts administrators when paused items exist (plugins and/or themes) and links to Update coverage.
- **Manual updates remain unlocked**: WordPress's native "Update now" links and controls are never disabled. Clients or other vendors can still apply updates manually by hand.

#### Client email drafts (Phase 3)
Email alerts are optional, opt-in per site, and generate a draft for operator review rather than auto-sending. In-dashboard wp-admin transparency via Companion / Renegade is the primary record.

## Bulk size limits

Hard cap of **500 targets per bulk action** to keep the validation loop bounded. Past that, split into multiple submissions. In practice the fleet has ~190 plugin updates across all sites at the high water mark, so this is room to spare.

## Operator setup

The queue worker needs a daemon. One-time supervisor entry:

```ini
[program:clockwork-plugin-updates]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/clockwork/artisan queue:work --queue=plugin-updates --tries=1 --timeout=300 --backoff=30 --max-time=3600
autostart=true
autorestart=true
numprocs=4
user=clockwork
redirect_stderr=true
stdout_logfile=/var/log/clockwork/plugin-updates.log
```

`numprocs=4` is the recommended concurrency. Higher burns more CPU on the Clockwork box during a batch and risks multiple simultaneous WP upgrades on the same shared host. Lower is safer but slow.

**Local dev**: `php artisan queue:work --queue=plugin-updates` in a second terminal. The default `database` driver is fine.

## Audit trail

Every completed update writes to `action_logs` with `action_type='plugin_update'` (or `theme_update` / `core_update` / `translations_update`). The `ActionLogger` mirrors to the per-site Companion endpoint, so the client's wp-admin "Activity" page shows what we've done.

### Updates made outside Control

WordPress's own auto-updates, another management tool or someone in wp-admin can update a plugin before Control does. Those updates leave no job, so `App\Services\Updates\ObservedUpdateRecorder` finds them in the snapshots instead: whenever a new Companion snapshot replaces the previous one (the `Site` model's `updated` hook, so every place that writes a snapshot is covered), each installed plugin, theme or WordPress core version that went **up** gets one `action_logs` row — `plugin_update_observed`, `theme_update_observed` or `core_update_observed`, `actor = observed`, `ran_at` = the new snapshot's time, details `{slug, name, from_version, to_version, observed_at, previous_snapshot_at}` (core uses the slug `wordpress`). The update happened somewhere between `previous_snapshot_at` and `observed_at`.

A change is not logged when Control made it — a `plugin_update_jobs` row for the same site and slug (any core job for core) created in the last 48 hours, or since the previous snapshot if that is older, that completed, is still live, or ended on exactly the new version — nor for downgrades (rollbacks, restores), items added or removed, or a change already logged with the same new version (a snapshot that flips back and forth logs once).

These rows are informational. They are in `ActionLog::OBSERVED_UPDATE_TYPES`, not `UPDATE_TYPES` (which means "updates Control ran"), and in `ActionLog::LOCAL_ONLY_TYPES`, so neither `ActionLogger` nor `clockwork:backfill-companion-action-log` mirrors them to the client's wp-admin. They never touch update jobs, failure streaks, auto-pauses or chat alerts.

The `plugin_update_jobs` table is the **live** progress substrate — once a batch finishes, those rows are useful for "what just ran" forensics for a few days, then can be safely archived. Action logs are the immutable record.

**"History"** in the page header (top-right, next to Care-plan auto-updates) links into `MaintenanceHistoryController` (`/maintenance-history`, pre-filtered to `action_type=_updates` — a synthetic filter value that expands to `plugin_update`/`theme_update`/`core_update`/`translations_update` together) — the full cross-site `action_logs` log, filterable by site/type/care-plan/outcome/actor, month-scoped with a pill-bar month picker. Originally built as a billing-reconciliation view ("what to bill, what's covered" — hence the covered/billable stats strip), it reads the exact same table this page's audit trail writes to, so it doubles as the fleet-wide update history without any separate data path. Each site's Overview tab also links here directly (`?site_id={id}`) from its Recent Activity card, which is itself capped at 25 rows — useful when you need the full, unpaginated per-site history rather than just the last couple dozen actions.

## Nightly care-plan auto-updates

Plugins-only, opt-in per site, runs every night between 02:00 and 06:00 ET. The operator enables it per site; it's off by default.

### How it works

1. `clockwork:run-nightly-plugin-updates` (02:00 ET) discovers every care-plan site with `auto_updates_paused = false` and `companion_installed = true`, queries its Companion snapshot for pending plugin updates, dedupes against existing `plugin_update_jobs` rows and `plugin_update_ignores`, and queues one `PluginUpdateJob` per plugin with `batch_id = "nightly-YYYY-MM-DD-..."` (the America/New_York date; a `--site` run uses `nightly-YYYY-MM-DD-site-...`). The fleet-wide run happens at most once per local date: if a fleet-wide nightly batch was already queued today (as on the DST fall-back night, when 01:30 happens twice), it logs and skips. `--force` overrides that, and `--site` runs are neither blocked nor counted. The run reuses the same `plugin-updates` Supervisor queue as manual batches. At the end of every run — whether or not any jobs were queued — the loop stamps `auto_updates_last_run_at` on **all eligible** care-plan sites. This gives a daily heartbeat: "3 hours ago" means the loop ran and found nothing to do; a stale or null timestamp means the loop stopped firing, not that the site was up to date.
2. Vulnerable plugins (matched by `PluginVulnerabilityMatcher` on the plugin's directory, the part of the snapshot's `dir/file.php` slug before the slash) are sorted first so security patches land before cosmetic updates.
3. `clockwork:nightly-update-summary` (06:15 ET) reads every job whose `batch_id` starts with `nightly-` since the last summary run and emails the operator a success/failure breakdown. No jobs → no email.
4. If any job ends in `failed` and its `batch_id` is a nightly batch, `AbstractRunUpdate` fires a Mattermost `pluginUpdateFailed()` ping immediately — manual bulk failures stay quiet since the operator is watching the page.

### Theme slug collisions

WordPress asks wordpress.org about every installed theme by folder name. A custom theme whose folder shares its name with an unrelated directory theme is offered that theme's releases as updates, and applying one replaces the custom theme wholesale — the custom code is gone and the site can fatal-error. Before queueing a theme, the nightly loop asks `App\Services\Updates\ThemeIdentityGuard`:

1. If wordpress.org lists a theme for that folder (`api.wordpress.org/themes/info/1.2/`, one request per folder per run), the installed theme's name must match the listed name (case, punctuation, HTML entities and a trailing "Theme" ignored). A different name means a different theme.
2. If the folder isn't listed (a premium theme's own updater makes the offer) or the directory can't be reached, a child theme is refused: a snapshot `template`/`parent` field naming another theme, or — since today's snapshots carry no such field — the word "child" in the theme's name. Anything else is queued as before.

A held-back theme is recorded once: a `skipped` `plugin_update_jobs` row with `reason_code = identity_mismatch` in that night's batch (so the 06:15 summary email lists it with the reason), an ignore with `source = identity_mismatch` (so later nights and the Updates page's bulk actions leave it alone), a local-only `theme_update_refused` action log, and one chat message. It never counts toward a failure streak or an auto-pause. If the theme really is the one on offer, unignore it on the site's Updates tab and update it by hand; the nightly loop holds it again the next time an update is offered. Plugins are not checked: the snapshot has nothing comparable for them, and wordpress.org plugin names carry taglines and edition names, so a name comparison would hold back legitimate updates. Manual updates from `/updates` are not checked either, but they skip any ignored theme.

### Opt-in toggle

`sites.auto_updates_paused` — newly-imported sites via `clockwork:import-spinupwp` and `clockwork:import-pressable`, and newly enrolled standalone / Renegade sites via Sites → Add Site, start with auto-updates enabled (`auto_updates_paused = false`), while never overriding an existing manual pause setting. Flip from:

- **`/updates/care-plan`** — fleet curation page. One iOS-style red/green toggle per care-plan site. AJAX, no page reload. Counter strip at top updates live.
- **`/sites/{id}/settings`** — per-site Auto-updates ON/OFF toggle inside the care-plan card.

The `/updates` main page shows a small **Auto: ON / Paused** badge per plugin row so you can tell at a glance which sites will pick it up tonight.

### Schema additions (sites table)

| Column | Type | Notes |
|---|---|---|
| `auto_updates_paused` | bool | `true` = paused. Schema default `true`, but both import commands and standalone enrollment override to `false` for newly-created sites — see above. |
| `auto_updates_paused_reason` | string? | Optional note shown as tooltip in the curation UI. |
| `auto_updates_last_run_at` | timestamp? | When the nightly loop last considered this site. Stamped on **all eligible** care-plan sites each run, even when no updates are pending — acts as a daily heartbeat. A stale or null value means the loop stopped running. |

## Update safety net — state verification and repair

Starting with Companion **1.21.3**, every successful update is followed by a verification step that checks whether plugins or the active theme changed unexpectedly during the update, and repairs them if they did.

### How it works

**Before the update** (`AbstractRunUpdate::captureStateBefore`):

The job reads the site's cached `companion_snapshot` from the local database — zero extra API calls, zero added latency. It extracts:

- `active_plugins` — slugs where `active === true` (e.g. `akismet/akismet.php`)
- `stylesheet` — slug of the active theme
- `template` — parent-theme slug (defaults to `stylesheet` for non-child themes)
- `captured_at` / `snapshot_age_minutes` — for diagnostics

This is stored in the `state_before` column on the `plugin_update_jobs` row immediately (even before the Companion call), so there's an auditable record of expected state regardless of what happens next.

**After the update** (if `ok === true` and the site has Companion ≥ 1.21.3):

Clockwork calls `POST /post-update-verify` on the site's Companion with the expected state. Companion:

1. Gets current `get_option('active_plugins')` and `get_stylesheet()`.
2. For each plugin that was active before but isn't now (and is still installed): calls `activate_plugin()` and records a `plugin_reactivated` or `plugin_reactivate_failed` repair.
3. If the active theme changed: calls `switch_theme()` and records a `theme_restored` repair.
4. Returns `{ ok: true, repairs: [{type, slug, detail}] }`.

Repairs are stored in the `repairs` column on the `plugin_update_jobs` row and summarised in the `action_logs` entry (e.g. `[2 repairs: plugin_reactivated: my-plugin/my-plugin.php, theme_restored: my-theme]`).

**This step is best-effort.** A verify failure never marks the update as failed — the update row keeps whatever status the actual upgrade set.

### Capability gate

The verify call only runs when `post-update-verify` appears in the site's `companion_capabilities`. Sites running Companion < 1.21.3 silently skip the verify step. `state_before` is still captured for all sites (it costs nothing — it reads from the local DB).

### Multisite theme protection (Companion 1.21.2+)

WordPress's `validate_current_theme()` can fire during the filesystem replacement window of a theme upgrade and switch the active theme to a fallback — particularly on multisite where each sub-site can have an independent active theme. Starting in 1.21.2, `ThemeUpdateRoute` takes a pre-update snapshot of which sub-sites use the slug being upgraded, then after the upgrade walks each of those sub-sites and restores the theme if WordPress silently changed it. The number of sub-sites repaired is included in the update's action log summary.

### Schema additions (plugin_update_jobs table)

| Column | Type | Notes |
|---|---|---|
| `state_before` | JSON? | Active plugins + stylesheet/template captured from local snapshot before the update runs. |
| `repairs` | JSON? | Array of `{type, slug, detail}` objects from the post-update verify call. `null` if no repairs were needed or the site doesn't support post-update-verify yet. |
| `reason_code` | string? | Cause of a failed job: `fs_permission`, `download_unauthorized`, `bad_package`, `no_package`, `not_offered` or `upgrader_error`. `null` for successes and for failures with no upgrader evidence (transport, reactivation). Also stored on `plugin_update_failure_streaks` for the latest counted failure. |

### Stalled no-op detection

Companion's update runner re-checks WordPress's own `update_plugins`/`update_themes` transient fresh, right before applying the update — this deliberately bypasses our cached snapshot, which can be several hours stale. If that fresh check no longer offers an update for the slug, Companion correctly reports `ok: true` with `after_version === before_version` ("already up to date" from its own point of view) — but the fleet Updates page reads our *separately*-cached snapshot, which can still say the update is pending. The result looked like a bug: a green "complete" job sitting right next to a still-pending update for the same plugin.

Root cause: for licensed plugins where update feeds may require an active license check, a plugin might report `ok: true, before: 8.7.2, after: 8.7.2` while the job's `target_version` was `9.0.1`.

`AbstractRunUpdate::handle()` catches this: if `ok === true` but `after_version === before_version` **and** that doesn't match the job's `target_version`, the job is reclassified as failed with an explicit error explaining why, instead of reporting a misleading success. Only fires when there's a real `target_version` to compare against — plugin/theme/core jobs have one; translations don't, so this never applies to that kind. A genuine "already at target" no-op (e.g. a duplicate queued job) is unaffected, since `after_version` matches `target_version` in that case.

This is a Laravel-side reinterpretation of Companion's response — no Companion plugin change or fleet redeploy involved. The underlying flakiness (a premium plugin's own update source going stale) isn't something this app can fix directly; it usually means the plugin's license/purchase code needs attention in that site's wp-admin.

**Upgrader errors are not "no longer offered".** Companion/Renegade builds in the field also return `ok: true` (and `upgrade_completed: true`) when the WordPress upgrader itself failed and logged an `Error: …` line. Inside the stalled-no-op branch, Control looks for that line in `messages`. When there is one, the job's `error` is the last `Error:` line plus the line before it (the detail, e.g. which file could not be copied), `upgrade_completed` is set to `false` so post-update verify/repair is skipped, and `reason_code` records the cause:

| `reason_code` | Upgrader output | Usually means |
|---|---|---|
| `fs_permission` | "Could not copy file", "Could not create directory", "Could not remove the old …" | PHP cannot write `wp-content/plugins` or `wp-content/upgrade` (file ownership). A site problem: see the site-level pause above. |
| `download_unauthorized` | "Download failed. Unauthorized / Forbidden", licence wording | Premium licence inactive or expired on that site. |
| `bad_package` | PCLZIP errors, "The package could not be installed" | The vendor returned something that is not a zip (often a licence error page). |
| `no_package` | "A valid URL was not provided", "Update package not available" | WordPress had no download URL (unlicensed premium plugin). |
| `upgrader_error` | Any other `Error:` line | Read the error text. |
| `not_offered` | No `Error:` line at all | The original stalled no-op described above. |

When Companion reports the failure itself (`ok: false`), its own error text is kept and `reason_code` is still derived from the upgrader output. The action-log summary follows the job's verdict, so a stalled no-op is logged as "Plugin update failed", not "Plugin updated: 1.0 → 1.0".

**Download URLs are redacted.** The upgrader's "Downloading update from <url>…" lines carry licence keys, vendor tokens/JWTs and signed download URLs. `App\Support\UpgraderOutput::redact()` runs on every update result before anything is stored or sent (`plugin_update_jobs.messages`/`error`, action-log details/summary/error and their Companion activity mirror, failure streaks, ignores, chat alerts): download URLs are cut to `scheme://host/[redacted]`, other URLs lose credentials, query string and fragment (token-like path segments are masked), and JWTs, bearer tokens and secret-named `key=value` pairs are masked anywhere. Download lines are never copied into error text. Rows written before this change still hold the unredacted lines in Control's database, but every push to the Companion activity mirror (`ActionLogger` and `clockwork:backfill-companion-action-log`, including `--reset-since` re-pushes) redacts the summary, details and error on the way out, so those rows can't write licence keys back into a client's wp-admin.

## What's NOT here

- **Safe Update / backup-before-update / rollback**. Not building this. If an update breaks a site, recover from the host's normal backup (BlogVault, SpinupWP).
- **Per-plugin auto-update policy** (e.g. "always auto-update Akismet on care-plan sites").
- **Email/Slack digest** after a manual batch completes. The nightly summary covers auto-batches; manual ones are watch-the-page.
- **Per-language translation partials** — we update everything pending in one call.
- **Promoting `care_plan_enabled` into a generic site-tag system** — separate refactor.
- **Live progress polling on the page** — hit refresh to see updates land. The infrastructure is there (`/updates/batches/{uuid}/status`); the JS polling layer hasn't been wired up.

## Troubleshooting

**A site shows updates available but the page is empty.** Snapshot is stale. Force-refresh: `php artisan clockwork:refresh-companion-snapshot --site=domain.com`.

**wp-admin shows more updates than `/updates` does.** Almost always a licensed plugin (Crocoblock Jet, Yoast Premium, Elementor Pro, WPMU DEV, etc.) on a site running Companion < 1.21.4 — those updaters need admin context to surface. Upgrade Companion: `php artisan clockwork:install-companion --site=domain.com`, then refresh the snapshot.

**"Queued N updates" but nothing seems to happen.** Worker isn't running. Check `supervisorctl status clockwork-plugin-updates`, or for local dev: `ps -ef | grep "queue:work"`.

**Job stuck `running` for hours.** The reaper will flip it to `failed` within an hour. Or run manually: `php artisan clockwork:reap-stale-update-jobs`.

**Plugin update returns 404 from Companion.** Companion < 1.14 doesn't have the endpoint. Roll out current version: `php artisan clockwork:rotate-companion-secret --all` (bundles the latest mu-plugin).

**Theme/core/translation updates show 0 across the fleet.** Companion v1.15.0+ required for those. Check fleet versions: `php artisan clockwork:companion-health --all`.

**Same-version entries in the update queue ("1.185.0 → 1.185.0").** WordPress's `update_plugins` transient can retain a slug in `response[]` after the site was already updated to that version. Companion 1.30.3+ filters these out — `update_available` is only set when `new_version` is strictly greater than the installed version (`version_compare`). If you still see same-version entries, force a snapshot refresh: `php artisan clockwork:refresh-companion-snapshot --site=domain.com`. Sites on Companion < 1.30.3 will still produce phantom entries until upgraded.

**Companion call throws "returned a non-JSON or malformed body."** `ClockworkCompanionClient` centralizes response decoding through `getJson()`/`postJson()`; a 2xx response whose body doesn't decode as JSON now throws a clear exception with a body excerpt instead of silently returning null. Usually means something on the site is injecting output before Companion's JSON — e.g. when an errant plugin notice or redirect snippet wraps the response. The client first tries to salvage by decoding from the first `{`/`[` onward before giving up, so a prefixed-but-otherwise-valid body still works; a genuinely broken response (nginx serving the homepage for every path, etc.) still throws.
