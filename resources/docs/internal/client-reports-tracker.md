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

Found by reading the code on 2026-10-15. Numbers stay stable as items are fixed; fixed ones move to **Done**.

| # | Issue | Why it matters | Where |
|---|---|---|---|
| 1 | **Weekly schedules report on the wrong period.** The scheduled path hard-codes "last calendar month", whatever the frequency. | A weekly schedule would email the same monthly report every week. No weekly schedules exist yet. | `ScheduledReportRunner::run()` (now the one place to fix it) |
| 4 | **Forms shows a 100% pass rate when zero tests ran.** | Claims testing that didn't happen. | `ClientReportCompiler::compileForms()` |
| 5 | **Checksum status defaults to `ok` when there's no scan.** | Claims a clean scan that never ran. | `ClientReportCompiler::compileSecurity()` |
| 6 | **Backups shows "enabled" from flags alone.** It's true if the care plan is on, even with no evidence a backup ran, and the "Host Automated Snapshots" label is an assumption. | Promises backups we can't verify. | `ClientReportCompiler::compileBackups()` |
| 7 | **Performance ignores the report period.** It always uses the latest scan ever, which may be months old. | October's report could show June numbers. | `ClientReportCompiler::compilePerformance()` |
| 8 | **The security CVE count is the site's *current* snapshot**, not the period. | Numbers shift depending on when you generate. | `ClientReportCompiler::compileSecurity()` |
| 10 | **The work log is empty** (`site_work_logs` has 0 rows), but `work_log` is in the default template. | Every report shows an empty "Work log" section unless hours get logged. | Data / template choice |

## Ideas and backlog

- **Phase 4: conditional delivery.** Hold an `auto` send for review if the site is down or its latest security scan flagged something. See the plan file for the original design.
- **Sent-email audit.** `sent_to` now records *who* got each report, but not *when* or *by whom* (scheduler vs. an operator clicking Send). Writing `action_logs` entries would make that visible on the site's activity log.
- **A "preview the next batch" view.** Show what the next scheduled run would send, and to whom, before it fires.
- **Remove the public `/reports/view/{token}` route, or decide its purpose.** Clients can't reach it, and the email deliberately doesn't link to it.

## Done

| Date | What | Ref |
|---|---|---|
| 2026-10-15 | Wrote the feature doc page and this tracker | `b652081` |
| 2026-10-15 | **#2:** Send splits the comma-joined `client_email` and emails every recipient. When a report has no address, the button asks for one and saves it. | `feature/client-reports` |
| 2026-10-15 | **#3:** Each delivery is recorded in `client_reports.sent_to` as it happens. A failed run keeps the schedule due, and the next run resumes the same report (`schedule_id` + period) and emails only the missing recipients. History shows **Partly sent**. | `feature/client-reports` |
| 2026-10-15 | **#9:** The command and **Send now** both go through `ScheduledReportRunner` + `ClientReportSender`. | `feature/client-reports` |
