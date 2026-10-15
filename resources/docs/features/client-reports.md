---
title: Client reports
section: Features
order: 101
updated: 2026-10-15
author: Aaron Reimann
tags: [client-reports, reporting, email, care-plans, scheduling, templates]
tracks: [modules/ClientReports/**]
---

Client reports are the monthly "here's what we did for your site" email. Everything lives in the `client_reports` module (`modules/ClientReports/`), and the sidebar entry is **Client reports** (`/client-reports`). Turn the module on or off from the Module Directory like any other module. When it's off, the routes and nav item go away.

Open issues, decisions and next steps are tracked separately in [Internal → Client reports tracker](/docs/internal/client-reports-tracker).

## The three tabs

**Reports** (`/client-reports`) holds the history table and the manual **Generate** form. Pick a site, a template, a date range, and optionally a client name, email and a custom note. You land on a preview page with a **Print / Download PDF** button.

The paper-plane button in the history table emails a report to every address in its `client_email`, which scheduled reports fill with all their recipients, comma-separated. If a report has no address saved, the button asks for one (you can enter several, comma-separated) and saves it on the report.

**Templates** (`/client-reports/templates`) control which sections go into a report. A template is just a name plus a list of section keys. Exactly one template is the default. You can't delete the default or the last remaining template, and un-ticking "default" on the only default is silently ignored.

**Scheduling** (`/client-reports/schedules`) allows one schedule per site, enforced by a unique index on `site_id`. Each schedule has a frequency (`weekly` / `monthly`), a recipients list, a template, and a delivery mode:

- **`auto`** compiles and emails every recipient right away. It needs at least one recipient.
- **`draft`** only compiles, so someone can review the report before sending it by hand.

Each row also has **Pause/Enable** and **Send now** buttons.

## A report is a snapshot

This is the key thing to understand. `ClientReportCompiler::compile()` queries the monitoring tables once and writes everything into `client_reports.sections_data` as JSON. That includes the branding (colours, logo, company name), captured at generation time. The preview page and the email both render from that JSON and never from live data.

So if you change the branding or fix a data bug, existing reports won't change. Generate a new one.

## Where each section's numbers come from

The rule for every section: **if we didn't check it during the period, the report says so** ("Not checked this month") or leaves it out. It never shows a passing default, because the email goes to clients as-is.

| Key | Source | Period | When there's no data |
|---|---|---|---|
| `updates` | `action_logs` rows in `ActionLog::UPDATE_TYPES` with `ok = true`, minus runs that changed nothing. `pending_plugins` lists updates still waiting on the Companion snapshot. | In period | "Everything was already up to date" only when nothing is waiting; otherwise "No updates were applied this month" |
| `uptime` | `site_uptime_events`: each `down` runs until the next `up`/`maintenance`, clamped to the period (an outage already open at the start counts from the start). SLA-exempt outages are skipped. The 5 most recent outages are listed with start time and length, marked when one began before the period ("Down since before …") or was still open at its end. | In period | Hidden if the site isn't monitored |
| `security` scans | `site_security_scans`, excluding `failed` scans (they never completed); warnings = `warning` + `issues_found` | In period | Count of 0 |
| `security` threats blocked | `blocked_ips` rows banned in the period (lockouts pulled from Gatekeeper or Limit Login Attempts Reloaded) | In period | Left out (tile and row) when nothing on the site reports blocks to Control. A 0 is shown only when a blocker is reporting. |
| `security` blocklists | Latest completed `blacklist` scan in the period | In period | "Not checked this month" |
| `security` malware | Latest completed `companion_malware` scan in the period, falling back to `sitecheck` | In period | "Not checked this month" |
| `security` core files | Latest *completed* `core_checksums` scan in the period. A site counts as WordPress if `is_wordpress` is set **or** Companion reports a `wp_core`. | In period | "Not checked this month"; row left out for non-WordPress sites |
| `security` vulnerabilities | `PluginVulnerabilityMatcher` (installed plugins from the Companion snapshot vs. the vulnerability feed). Matches in inactive plugins are noted separately. | **Snapshot date**, shown as "as of …" | "Not checked" when there's no plugin list, **or** the feed hasn't refreshed in 48h (an empty or stale feed would otherwise read as "None found") |
| `performance` | Latest OK mobile/desktop `site_performance_scans` + phone `site_field_metrics`. **GTmetrix scans count as desktop** whatever strategy they were filed under: our GTmetrix plan has no phone emulation, so its "mobile" rows are desktop Chrome. Only Pressable's and Google's mobile results show as Mobile. | In period | Section hidden |
| `forms` | `contact_form_test_runs`. Only **live** runs test delivery: delivered = `status = 'success'` **and** `mail_outcome = 'sent'`. **Lab** runs suppress the email, so they're reported as "accepted N of M" and never as delivered. | In period | Section hidden (`pass_rate` is null) |
| `traffic` | `site_traffic_daily` (visits, requests, bandwidth) | In period | Section hidden |
| `backups` | `site_backups`: what the site's **real** backup source reported, recorded daily by `clockwork:record-site-backups` (05:30). Pressable → Pressable's backup API (files daily, plus one database backup per day); SpinupWP → the DigitalOcean Spaces bucket SpinupWP writes to; unhosted sites → our own S3 archives (Companion uploads). The email lists the **last 10 days of the period**, one row per day, plus how many days that month had a backup. A day's size counts one backup of each kind (the newest scheduled one), so extra on-demand or hourly backups don't inflate it. | In period | Section hidden from the client. The preview shows **No backups found** (checked, none), **No backups in the last 10 days** (backups stopped mid-period) or **Could not check** (read failed, or no clean check in 48h), with the error. |
| `work_log` | `site_work_logs` (hours entered on the site page) | In period | Section hidden |

Every compiled report carries `meta.schema_version` (currently **3**: 2 = honesty fixes, 3 = recorded backups list). Reports compiled before the honesty fixes have no version. The views treat their vulnerability line as "Not checked" and hide their forms numbers, because those were computed wrongly.

### Backup recording

`clockwork:record-site-backups` (core, `App\Services\Backups\SiteBackupRecorder`) reads every active site's backups from its source and upserts them into `site_backups`, keyed by source and the source's own id. It also writes one `site_backup_checks` row per site saying whether the read worked. Rows are **kept after the source prunes them**, so re-generating an old report still has the evidence. A failed read never deletes or zeroes anything; it only marks the check as failed, logs `site_backups.record_failed`, and makes the run exit as failed so it shows red in Scheduled Jobs. It's all reads: Pressable's backup endpoints are allowed in view-only mode.

Pressable's database backups are hourly, so one row per UTC day is kept, keyed `day:YYYY-MM-DD`; a later run's newer pick replaces it. A SpinupWP run is labelled by what's actually in the bucket (files, database, or both), and a run first seen mid-upload is replaced once the rest lands. Empty (0-byte) objects don't count as backups.

Section keys and labels are defined once in `ClientReportTemplate::SECTIONS`. Adding a section means adding it there, adding a `compileX()` method in the compiler, and adding a block to both views and the email.

## Scheduled sending

`clockwork:send-client-reports` is registered by the module itself (`ClientReportsServiceProvider::scheduledTasks()`), not in `routes/console.php`. It runs daily at **06:00 app time** (UTC on our install). On each run:

1. It skips paused schedules, inactive sites, and schedules whose `next_run_at` is still in the future. It also skips sites **without a care plan**: reports are a care-plan service, and those sites get no nightly updates or security scans, so their report would be empty or wrong. The schedule still moves on to next month, so turning the care plan back on never sends a report for a month the site didn't have one. The Schedules list marks these **No care plan — won't send**, and **Send now** refuses them.
2. It skips `auto` schedules that have no recipients.
3. It compiles **last calendar month** using the schedule's template, and saves the report with `schedule_id` set. For `auto`, if an unfinished report from this schedule already exists for the same period, it **resumes that one** instead (see below).
4. **Hold for review.** For `auto`, a freshly compiled report is checked by `ReportReviewFlags`. If anything needs a person first, it's kept as a draft instead of emailed, the reasons are saved in `meta.review_reasons`, and the schedule moves on to next month. The history table shows **Needs review**, and the preview lists the reasons. Send it by hand from the history table once it's checked. Held reports are never sent automatically: running the schedule again (**Send now**, `--force`) recompiles from current data and checks again, and the older copy is marked **superseded** so it can't be sent by mistake. Draft-mode reports get the same check, so the first-run drafts show **Needs review** too, and any report that hasn't reached anyone is checked again before an auto send. The reasons show only in our preview, never on the public view. The rules:
   - malware scan, core files or blocklist came back `warning` / `issues_found`
   - backups: none found, stopped before the last 10 days, or couldn't be checked
   - the site was still down at the end of the month
   - a plugin with a known vulnerability has an update waiting (the update isn't going through, often an unlicensed premium plugin)

   Known plugin vulnerabilities don't hold a report (about a third of sites have one at any time).
5. For `auto` schedules, it sends one email per recipient who isn't already in the report's `sent_to`. Once everyone has it, it marks the report `sent` and stamps `last_sent_at`.
6. It moves `next_run_at` forward: the 1st of next month for monthly schedules, or now plus 7 days at midnight for weekly ones. The midnight snap is on purpose, so the next 06:00 run sees it as due. Before this, runs used to slip a day every cycle. If any recipient failed, `next_run_at` **doesn't** move, so tomorrow's run retries.

The command and the **Send now** button share the same code: `ScheduledReportRunner`, with delivery in `ClientReportSender`.

### When a send fails part-way

Every successful delivery is written to `client_reports.sent_to` straight away, before the next recipient is tried. If one address fails, the report stays `generated` (shown as **Partly sent** in the history table), the failure is logged, and the schedule stays due. The next run picks up the **same report** and only emails the addresses that are still missing. Clients who already got it never get a second copy, and you don't end up with a pile of duplicate reports while a mail outage lasts.

Manual runs:

```bash
php artisan clockwork:send-client-reports                   # only what's due
php artisan clockwork:send-client-reports --force           # ignore next_run_at
php artisan clockwork:send-client-reports --site=example.com  # one site (id or domain)
```

Careful: `--force` and **Send now** send for real on an `auto` schedule. On a dev copy, check `MAIL_MAILER=log` first.

## The email

`ClientReportMail` puts the whole report in the email body: an HTML view plus a plain-text one (`emails/report*.blade.php`). The logo is embedded inline via `BrandLogo` so it still shows when remote images are blocked. Update rows are cleaned up into client-friendly lines ("TranslatePress 3.3.6 → 3.3.7"). Reply-To is the report brand's support email.

There's deliberately **no "view online" link**: Control lives on a private network clients can't reach. That's also why the public `/reports/view/{token}` route exists but isn't linked anywhere useful.

## Branding

Colours, logo, company name and support details come from **Settings → Companion & White Label → Client Reports** (`/settings/companion?tab=reports`, stored as `reports.branding.*`). Blank fields fall back to the shared Companion identity. See [Companion & White Label](/docs/features/companion-branding).

## Tests

`tests/Feature/Modules/` contains `ClientReportsControllerTest`, `ClientReportTemplatesTest`, `ClientReportSchedulesTest` and `ClientReportMailTest`.
