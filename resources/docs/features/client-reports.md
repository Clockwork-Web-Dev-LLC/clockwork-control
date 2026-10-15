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

**Templates** (`/client-reports/templates`) control which sections go into a report. A template is just a name plus a list of section keys. Exactly one template is the default. You can't delete the default or the last remaining template, and un-ticking "default" on the only default is silently ignored.

**Scheduling** (`/client-reports/schedules`) allows one schedule per site, enforced by a unique index on `site_id`. Each schedule has a frequency (`weekly` / `monthly`), a recipients list, a template, and a delivery mode:

- **`auto`** compiles and emails every recipient right away. It needs at least one recipient.
- **`draft`** only compiles, so someone can review the report before sending it by hand.

Each row also has **Pause/Enable** and **Send now** buttons.

## A report is a snapshot

This is the key thing to understand. `ClientReportCompiler::compile()` queries the monitoring tables once and writes everything into `client_reports.sections_data` as JSON. That includes the branding (colours, logo, company name), captured at generation time. The preview page and the email both render from that JSON and never from live data.

So if you change the branding or fix a data bug, existing reports won't change. Generate a new one.

## Where each section's numbers come from

| Key | Source | Respects the report period? |
|---|---|---|
| `updates` | `action_logs` rows in `ActionLog::UPDATE_TYPES` with `ok = true` | Yes |
| `uptime` | `site_uptime_events` where `event_type = 'down'`; each event counts its `duration_seconds`, or 5 min if that's null | Yes |
| `security` | `site_security_scans`, `blocked_ips`, plus CVEs and checksum status from the site's *current* state | Partly |
| `performance` | Latest OK mobile/desktop `site_performance_scans` + latest phone `site_field_metrics` | **No** (always latest) |
| `forms` | `contact_form_test_runs` | Yes |
| `traffic` | `site_traffic_daily` (visits, requests, bandwidth) | Yes |
| `backups` | `sites.backup_relay_enabled` / `care_plan_enabled` / `backup_relay_last_archived_at` | **No** (flags only) |
| `work_log` | `site_work_logs` (hours entered on the site page) | Yes |

Section keys and labels are defined once in `ClientReportTemplate::SECTIONS`. Adding a section means adding it there, adding a `compileX()` method in the compiler, and adding a block to both views and the email.

## Scheduled sending

`clockwork:send-client-reports` is registered by the module itself (`ClientReportsServiceProvider::scheduledTasks()`), not in `routes/console.php`. It runs daily at **06:00 app time** (UTC on our install). On each run:

1. It skips paused schedules, inactive sites, and schedules whose `next_run_at` is still in the future.
2. It skips `auto` schedules that have no recipients.
3. It compiles **last calendar month** using the schedule's template, and saves the report.
4. For `auto` schedules, it sends one email per recipient, marks the report `sent`, and stamps `last_sent_at`.
5. It moves `next_run_at` forward: the 1st of next month for monthly schedules, or now plus 7 days at midnight for weekly ones. The midnight snap is on purpose, so the next 06:00 run sees it as due. Before this, runs used to slip a day every cycle.

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
