---
title: Client reports tracker
section: Internal
order: 30
updated: 2026-10-15
author: Aaron Reimann
tags: [client-reports, tracker, roadmap, known-issues]
---

The running to-do list for the Client Reports module. For how the feature works, see [Features → Client reports](/docs/features/client-reports). Keep this page current: when something here gets fixed, move it to **Done** with the commit or PR, and bump `updated:`.

## Where things stand (2026-10-15)

- The original plan, `plans/client-reports-templates-scheduling.md`, is **mostly shipped**: phases 1–3 (scheduler fixes, templates, scheduling UI) are all live with tests. Only phase 4 (conditional delivery) is left. That plan's own "not yet implemented" header is out of date. Treat this page as the source of truth.
- Working branch: `feature/client-reports`.

## ⚠️ Heads-up: first mass send on 2026-11-01

As of the 2026-10-15 production DB snapshot:

- **72 schedules**, all enabled, all `monthly`, all **`auto`**, all on the default template.
- **None has ever sent** (`last_sent_at` is null everywhere).
- All 72 have `next_run_at = 2026-11-01 00:00`, so the 06:00 UTC run that day will email October reports to all 72 sites' recipients in one go.
- Only 4 reports exist so far: 3 test reports against our own site and 1 sent to a client.

**Decision needed:** is that intended? And should the first cycle go out as `draft`, so someone can eyeball the reports before clients see them? The open issues below affect what those 72 emails would say.

## Open issues

Found by reading the code on 2026-10-15. Not yet reproduced in tests.

| # | Issue | Why it matters | Where |
|---|---|---|---|
| 1 | **Weekly schedules report on the wrong period.** Both scheduled paths hard-code "last calendar month", whatever the frequency. | A weekly schedule would email the same monthly report every week. No weekly schedules exist yet. | `SendScheduledClientReports::handle()`, `SchedulesController::sendNow()` |
| 2 | **Manual Send fails for most drafts.** The Send button posts no email field, so `send()` relies on `client_email`. Scheduled reports store recipients as `"a@x.com, b@y.com"`, which fails `FILTER_VALIDATE_EMAIL` whenever there's more than one, and reports with no email can't be sent at all. | `draft` mode is unusable for any site with more than one recipient. | `ClientReportsController::send()`, `index.blade.php` |
| 3 | **A partial send failure re-emails everyone.** Recipients are mailed one at a time. If one throws, the report stays `generated` and `next_run_at` isn't advanced, so the next day's run compiles a **new** report and emails everyone again. | Duplicate emails to clients, and duplicate report rows. | `SendScheduledClientReports::handle()` |
| 4 | **Forms shows a 100% pass rate when zero tests ran.** | Claims testing that didn't happen. | `ClientReportCompiler::compileForms()` |
| 5 | **Checksum status defaults to `ok` when there's no scan.** | Claims a clean scan that never ran. | `ClientReportCompiler::compileSecurity()` |
| 6 | **Backups shows "enabled" from flags alone.** It's true if the care plan is on, even with no evidence a backup ran, and the "Host Automated Snapshots" label is an assumption. | Promises backups we can't verify. | `ClientReportCompiler::compileBackups()` |
| 7 | **Performance ignores the report period.** It always uses the latest scan ever, which may be months old. | October's report could show June numbers. | `ClientReportCompiler::compilePerformance()` |
| 8 | **The security CVE count is the site's *current* snapshot**, not the period. | Numbers shift depending on when you generate. | `ClientReportCompiler::compileSecurity()` |
| 9 | **Generate-and-send logic is duplicated** in the command and in `sendNow()`. | Every fix to 1 or 3 has to land twice. Pull it into one service. | Both files above |
| 10 | **The work log is empty** (`site_work_logs` has 0 rows), but `work_log` is in the default template. | Every report shows an empty "Work log" section unless hours get logged. | Data / template choice |

## Ideas and backlog

- **Phase 4: conditional delivery.** Hold an `auto` send for review if the site is down or its latest security scan flagged something. See the plan file for the original design.
- **Sent-email audit.** Nothing records *which* recipients actually got a report. `client_email` is a comma-joined string and `sent_at` is set once. It would help to have a per-recipient record (or `action_logs` entries).
- **A "preview the next batch" view.** Show what the next scheduled run would send, and to whom, before it fires.
- **Remove the public `/reports/view/{token}` route, or decide its purpose.** Clients can't reach it, and the email deliberately doesn't link to it.

## Done

| Date | What | Ref |
|---|---|---|
| 2026-10-15 | Wrote the feature doc page and this tracker | `feature/client-reports` |
