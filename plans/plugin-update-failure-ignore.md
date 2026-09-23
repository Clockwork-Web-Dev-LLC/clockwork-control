---
title: Auto-ignore plugins after repeated update failures
status: proposed
updated: 2026-09-19
author: Aaron Reimann
---

# Auto-ignore plugins after repeated update failures

**Status:** proposed — do not build until Aaron approves.  
**Repos:** Clockwork Control (this repo), Clockwork Companion (`~/Projects/clockwork-companion`), Clockwork Renegade (`~/Projects/clockwork-renegade`).  
**Why:** After we keep trying the same plugin on the same site and it keeps failing, we should stop putting it in the nightly queue, record why, and **show the client in Companion/Renegade** so they can see which plugins are still on automatic updates and which ones we paused. That beats silently skipping the same update every night.

---

## What already exists (do not rebuild)

| Piece | Where | What it does today |
|---|---|---|
| Per-site ignore list | `plugin_update_ignores` / `PluginUpdateIgnore` | Operator clicks Ignore on `/updates`. Nightly + bulk skip that `(site, kind, slug)`. **Forever until unignored.** Not pushed to WordPress. |
| Job history | `plugin_update_jobs` | One row per attempt: `complete` / `failed`. No streak column. `tries=1` — we never auto-retry a single job. |
| Audit | `action_logs` (`plugin_update`, …) | Immutable. Mirrored to Companion Activity when the site can receive it. |
| Nightly loop | `clockwork:run-nightly-plugin-updates` | Care-plan + `auto_updates_paused=false` + Companion. Plugins **and** themes. |
| Operator alert | `ChatNotifier::pluginUpdateFailed()` | Nightly failures only. Not clients. |
| Client reports | `ClientReportCompiler::compileUpdates()` | **Successes only.** Failures never appear in the monthly PDF/email. |
| Protected plugins | `companion.protected_plugins` | Blocks deactivate/delete. **Does not** skip updates. |
| Push pattern | branding / backups-report / traffic / action-log | HMAC POST → `wp_options` → wp-admin renders locally. This is the pattern to copy. |

**Confirmed gap:** there is no consecutive-failure counter per plugin per site. History exists only as loose job rows. Other features already do streaks (`sites.uptime_consecutive_failures`, contact-form failure streak). Copy that idea; do not invent a second ignore table if we can extend the one we have.

---

## Product goal

1. Count **consecutive nightly failures** for `(site_id, target_kind, target_slug)`.
2. At a configurable threshold (**default 5**), **automatically add** that pair to `plugin_update_ignores` with a machine-written note.
3. **Push that fact to Companion and Renegade** so a `manage_options` user sees, in wp-admin, that Clockwork has stopped applying automatic updates to that plugin, and why.
4. Optionally generate (not auto-send) a client email draft. Companion/Renegade is the default client surface; email is opt-in per site.
5. Keep a one-click **Resume management** in Control. That unignores, resets the streak, and updates the wp-admin list.

Success looks like: a stubborn plugin fails five nights in a row, drops off the nightly queue, shows as “Updates paused by Clockwork” in wp-admin, and an operator can explain the decision from Control without digging through `plugin_update_jobs`.

---

## What counts as a “try”

Only **nightly auto-updates** increment the streak (`plugin_update_jobs.batch_id` starts with `nightly-` and `requested_by_user_id` is null).

That is the path where Clockwork is acting as the manager. Manual `/updates` or per-site tab attempts are operator-initiated — they write action logs but **do not** increment the auto-ignore streak (they also do not reset it, unless the attempt **succeeds**).

### Increment (plugin-level failure)

Count these as “we tried this plugin and it did not update”:

- Companion returned `ok=false` with a plugin/theme error.
- Stalled no-op: `ok=true` but `after_version === before_version !== target_version` (already reclassified as failed in `AbstractRunUpdate`).
- Reactivation failure: was active, not active after, not repaired.

### Do not increment (site/transport, not the plugin)

These should **not** march a plugin toward ignore:

- Companion unreachable / HMAC / HTTP timeout / DNS / TLS.
- Per-site `Cache::lock` contention (`release(15)`, still `pending`).
- Reaper flipping `pending`/`running` to `failed` after a dead worker (`clockwork:reap-stale-update-jobs`) — that is infrastructure, not “this plugin is un-updatable.”
- Job `failed()` timeout path with no Companion body — treat as transport unless we already have a plugin-level error on the row.

Heuristic: increment only when `AbstractRunUpdate` has a Companion response (or the stalled-no-op / reactivation classifiers). If the catch block is a transport `Throwable` with no response body, skip the streak.

### Reset

- A **successful** update of that `(site, kind, slug)` (nightly **or** manual) resets `consecutive_failures` to 0.
- Unignore / “Resume management” resets the streak.
- A **new `target_version`** does **not** auto-unignore. If we already ignored the slug, we stay ignored until a human resumes. Otherwise we would retry forever on plugins that ship a new broken version every week.

When a **new version** appears on an already auto-ignored slug, Control should badge it: “New version available — still ignored after 5 failures.” Operator can resume (we try the new version) or leave it.

---

## Scope of targets (v1)

| Target | In v1? | Why |
|---|---|---|
| Plugins | Yes | The request. |
| Themes | Yes | Nightly already queues them through the same job base. Same ignore table (`target_kind=theme`). |
| Core | No | Not in the nightly loop. A failed core update is a different conversation. |
| Translations | No | Same. |

Do not invent a global “ignore this slug on every site” list in v1. Failure is almost always site-specific (conflict, custom code, disk, a mu-plugin). Fleet-wide ignore can be a later button: “Ignore this slug on all care-plan sites.”

---

## Data model

### New table: `plugin_update_failure_streaks`

One row per `(site_id, target_kind, target_slug)`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `site_id` | FK sites | cascade |
| `target_kind` | string | `plugin` \| `theme` |
| `target_slug` | string | |
| `consecutive_failures` | unsigned int | default 0 |
| `last_target_version` | string nullable | version we failed to reach |
| `last_from_version` | string nullable | version that stayed installed |
| `last_error` | text nullable | truncated, no secrets |
| `last_failed_at` | timestamp nullable | |
| `last_job_id` | FK nullable | `plugin_update_jobs.id` |
| `ignored_at` | timestamp nullable | when we crossed the threshold |
| `ignore_id` | FK nullable | `plugin_update_ignores.id` |
| `companion_pushed_at` | timestamp nullable | last successful wp-admin push |
| `email_draft_id` | nullable | if we add drafts |
| `created_at`, `updated_at` | | |

Unique: `(site_id, target_kind, target_slug)`.

### Extend `plugin_update_ignores`

Add columns (nullable so existing manual ignores stay valid):

| Column | Notes |
|---|---|
| `source` | `manual` (default) \| `auto_failure` |
| `failure_count` | copy of streak at ignore time |
| `last_error` | short operator-facing reason |
| `client_visible` | bool, default `true` for `auto_failure`, `false` for manual (operator may have ignored for “don’t touch Woo yet,” which is not a client story) |

Manual Ignore on `/updates` stays `source=manual`, `client_visible=false` unless the operator ticks “Show in wp-admin.” Auto-ignore is always client-visible.

### Settings (app_settings)

| Key | Default | |
|---|---|---|
| `updates.auto_ignore_after_failures` | `5` | min 3, max 20 |
| `updates.auto_ignore_enabled` | `true` | kill switch |
| `updates.auto_ignore_email_default` | `false` | new sites inherit; per-site can override |

Per-site (sites table or a small JSON settings blob — prefer two explicit columns if we already have `auto_updates_paused`):

| Column | Default | |
|---|---|---|
| `update_ignore_email_opt_in` | `false` | generate a draft when we auto-ignore |
| `client_email` | already exists | required to generate a draft |

### Do not add

- Auto-retry of `AbstractRunUpdate` (`tries` stays 1).
- Backup-before-update / rollback (explicitly out of scope in updates docs).
- A second ignore system beside `plugin_update_ignores`.

---

## Control behavior

### Write path

Hook **one place:** after `AbstractRunUpdate` persists the job row (success or classified failure), call a small service, e.g. `App\Services\Updates\UpdateFailureStreakRecorder`.

```
if nightly && plugin|theme:
  if success → reset streak
  else if plugin-level failure → increment
    if consecutive_failures >= threshold && auto_ignore_enabled:
      upsert plugin_update_ignores (source=auto_failure, client_visible=true)
      stamp streak.ignored_at + ignore_id
      ActionLogger (new type: plugin_update_auto_ignored)
      ChatNotifier (operator): “Stopped managing {name} on {domain} after N failures”
      queue PushUpdateExceptionsJob for that site
      if site.update_ignore_email_opt_in && client_email: create draft (do not send)
```

Nightly’s `filterIgnored()` already drops ignored tuples, so the next night we stop trying. No change to the dispatcher beyond the recorder.

### Operator UX

**`/updates`**
- Ignored rows (already dimmed) get a pill: `Auto-ignored · 5 failures` vs `Ignored`.
- Tooltip: last error + last attempted version + date.
- Unignore button becomes **Resume management** for auto rows (same POST as today, plus streak reset + Companion push).
- Filter chip: `Auto-ignored` so the pile is reviewable.

**`/updates/exceptions` (new, short page) or a card on `/updates`**
- Table of auto-ignored `(site, plugin)` with streak, last error, last push time, email-draft status.
- Bulk resume.
- “Preview client notice” (the same copy Companion will show).

**Site Updates tab + Settings**
- List of ignored plugins for this site.
- Toggle: “Email a draft to the client when we auto-ignore” (off by default).

**`/issues`**
- Today “WordPress plugins out of date” uses `companion_snapshot.plugins.counts.updates_available` — that count **includes ignored slugs**. After this ships, either:
  - subtract ignored slugs from the Issues count, **or**
  - add a separate non-urgent section “Updates paused (auto-ignored)” and keep the raw pending count honest.
- Recommendation: **subtract ignored slugs from the urgent out-of-date count**, and list them in a quieter section. Otherwise we keep paging ourselves for plugins we deliberately stopped touching.

**Maintenance history / action log**
- New action type `plugin_update_auto_ignored` / `theme_update_auto_ignored` so client reports *can* mention it later. v1: show in Control history; do **not** auto-add to monthly client report until the copy is approved.

**Capacity / Settings**
- Threshold editor lives on `/updates` settings or `/settings` Fleet Policies — next to care-plan auto-update policy. Not a new hub.

### Email drafts (phase 3, optional)

- Default **off**. Companion notice is the product.
- When on: store a draft (`client_email_drafts` or a JSON column on the streak): subject, body, to=`sites.client_email`, status=`draft`.
- Operator reviews and clicks Send (Mailgun, same as vulnerability report). Never send from the nightly job.
- Draft copy (editable):

  Subject: Update about a plugin on {domain}

  We automatically apply WordPress plugin updates on your site as part of the care plan.  
  The plugin **{name}** failed to update {N} nights in a row (last attempt: {from} → {to} on {date}).  
  We have **stopped automatically updating that plugin** so we do not keep applying a change that is not completing cleanly. Other plugins on the site are still managed.  
  You can see this in WordPress admin under {Clockwork / branded menu} → Update coverage.  
  If you want us to resume automatic updates for this plugin, reply to this email.

Sanitize `last_error` for clients (no file paths, no HMAC, no stack traces). Operators still see the raw error in Control.

---

## Companion + Renegade (the part that matters)

### Why wp-admin, not email first

Email is easy to miss and easy to look like marketing. A panel in the same plugin they already use for Connection / Activity is:

- always there for whoever has `manage_options`
- white-labelable via existing branding
- the same payload on Companion (private) and Renegade (wp.org) if we keep it display-only

Renegade constraint: **display stored options only**. No remote code, no “Control can rewrite wp-admin arbitrarily.” Same as backups-report / branding.

### New capability + route

| | Companion | Renegade |
|---|---|---|
| Capability | `update-exceptions` (advertise on `/health` or capabilities) | same name |
| REST | `POST /wp-json/clockwork/v1/update-exceptions` | `POST /wp-json/clockwork-renegade/v1/update-exceptions` |
| Storage | `wp_options['clockwork_update_exceptions']` | same option key **or** `clockwork_renegade_update_exceptions` — pick one key and use it in both so Control’s payload is identical |
| Auth | existing HMAC | existing HMAC |

Payload (Control → WP):

```json
{
  "generated_at": "2026-09-19T06:20:00Z",
  "site_domain": "example.com",
  "items": [
    {
      "kind": "plugin",
      "slug": "broken-seo",
      "name": "Broken SEO",
      "stopped_at": "2026-09-19",
      "failure_count": 5,
      "from_version": "3.2.0",
      "attempted_version": "3.2.1",
      "reason_public": "Automatic updates did not complete after 5 attempts. Clockwork is no longer applying updates to this plugin.",
      "status": "paused"
    }
  ]
}
```

Push the **full current list** for that site (empty array = “nothing paused”). Do not send incremental patches; last write wins, like branding.

Triggers:

- Auto-ignore created
- Resume / unignore
- Nightly catch-up: `clockwork:push-update-exceptions` (daily, cheap, heals missed pushes)
- Companion install / variant switch (same as branding)

Control client: `ClockworkCompanionClient::pushUpdateExceptions(Site $site, array $payload)` — namespace-aware, same as branding.

### wp-admin UI (both plugins)

New submenu under the existing Clockwork / white-label menu, visible to `manage_options`:

**Title:** “Update coverage” (or branded: “{Agency} update coverage”)

**Empty state:** “Clockwork is applying automatic plugin updates on this site when a care plan is active. Nothing is currently paused.”

**When items exist:** a table — plugin name, version we stopped on, date stopped, short public reason. No operator jargon, no file paths.

**Also:** a dismissible admin notice on `plugins.php` when `items` is non-empty:

> Clockwork has paused automatic updates for {N} plugin(s) after repeated failures. [View details]

Do **not** hide the plugin’s own “update now” row in WordPress. The client (or another vendor) can still update by hand. We are documenting **our** automation, not locking the site.

### What we do not show in wp-admin (v1)

- Manual ignores (`client_visible=false`) — “don’t update checkout this week” is an agency decision, not a client notice.
- Raw Companion errors.
- “You are not on a care plan” (already a different story).
- A promise that the site is secure because we ignored a plugin.

### Versioning

Ship Control first with pushes no-op if capability missing (log + `companion_pushed_at` stays null). Then ship Companion + Renegade with the route. Sites on old Companion just won’t show the panel until upgraded — Control still ignores them. That is fine.

---

## Copy rules (keep this tight)

Public / client-facing sentences should say:

- We **attempted automatic updates**.
- They **did not complete** after N attempts.
- We have **stopped automatically updating this plugin** on this site.
- Other managed updates continue.
- They can ask us to resume.

Do **not** say:

- “This plugin is abandoned / malware / unsafe” unless a different scanner already said that (closed-plugin / KEV are separate).
- “WordPress will not update this plugin” — we did not disable WP’s own updater.

---

## Phased build (recommend this order)

### Phase 1 — Control only (useful even before plugin UI)

1. Migration: streaks table + ignore columns + settings keys.
2. `UpdateFailureStreakRecorder` from `AbstractRunUpdate`.
3. Auto-insert `plugin_update_ignores` at threshold.
4. `/updates` pills + exceptions list + resume resets streak.
5. Issues: exclude auto-ignored slugs from the urgent out-of-date count.
6. Mattermost/Slack on auto-ignore.
7. Pest: increment / reset / threshold / transport ignored / manual ignore unchanged / nightly skips auto-ignored.

**Ship this** if Companion work is a week out. Operators get the ignore; clients do not see it yet.

### Phase 2 — Companion + Renegade (the transparency piece)

1. Capability + POST route + option storage in **both** plugin repos.
2. Admin page + `plugins.php` notice.
3. Control `pushUpdateExceptions` + install/nightly catch-up.
4. Feature tests on Control (mocked HTTP). Plugin tests in each plugin repo.
5. Docs: `resources/docs/features/updates.md`, companion-plugin.md, clockwork-renegade.md.

### Phase 3 — Optional email drafts

1. Per-site opt-in.
2. Draft generator + Send button.
3. Do not add to monthly client report until Aaron signs off on that paragraph.

Do not start Phase 3 before Phase 2. Email without wp-admin is the weaker record.

---

## Tests (Control)

Use `example.com` fixtures only. No client domains.

- Nightly plugin-level fail × 4 → no ignore; × 5 → ignore `source=auto_failure`, nightly no longer queues it.
- Success after 3 fails → streak 0, no ignore.
- Transport timeout × 5 → no ignore.
- Reaper-failed row → no increment.
- Manual `/updates` fail → no increment; manual success → streak reset.
- Manual Ignore still `source=manual`, `client_visible=false`, not in push payload.
- Resume → ignore gone, streak 0, push empty list.
- New version on auto-ignored slug → still ignored, UI badge.
- Issues count does not include ignored slugs.
- Threshold setting 3 vs 5 honored.
- Kill switch `updates.auto_ignore_enabled=false` never inserts.

Companion/Renegade: HMAC reject, option written, empty list clears notice, `manage_options` only.

---

## Docs + settings copy

Update `resources/docs/features/updates.md`:

- What a “try” is.
- Threshold and kill switch.
- Auto-ignore vs manual ignore.
- What clients see in Companion/Renegade.
- Email is opt-in draft, not automatic.

`tracks:` on that doc should include the new service + the two plugin repos.

---

## Explicit non-goals

- Changing `AbstractRunUpdate::$tries` (still 1).
- Auto-sending client email from cron.
- Global slug denylist in v1.
- Treating closed-on-wp.org or KEV as this feature (already Issues).
- Hiding pending updates from the client’s WordPress updater.

---

## Approval checklist

If this is a yes, approve these decisions (change any before build):

1. Threshold default **5**, setting `updates.auto_ignore_after_failures`.
2. Count **nightly plugin-level failures only**; reset on any success.
3. Auto-ignore the **slug** (not “this version only”); new versions stay ignored until Resume.
4. **Client-visible in Companion + Renegade** is the default record. Email drafts are opt-in, phase 3.
5. Manual ignores stay **hidden** from wp-admin unless the operator opts that row in.
6. Issues urgent count **excludes** ignored slugs.
7. Build **Phase 1 then Phase 2**. Do not start Phase 3 without a second yes.

If approved, next step is a Gemini/implementation brief that maps each phase to concrete files (`AbstractRunUpdate`, `PluginUpdateIgnore`, `ClockworkCompanionClient`, Companion/Renegade route + admin page) without expanding scope.
