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

Found by reading the code on 2026-10-15 (#4–8 confirmed against the production copy by an investigate-then-refute pass). Numbers stay stable as items are fixed; fixed ones move to **Done**.

| # | Issue | Why it matters | Where |
|---|---|---|---|
| 1 | **Weekly schedules report on the wrong period.** The scheduled path hard-codes "last calendar month", whatever the frequency. | A weekly schedule would email the same monthly report every week. No weekly schedules exist yet. | `ScheduledReportRunner::run()` (now the one place to fix it) |
| 10 | **The work log is empty** (`site_work_logs` has 0 rows), but `work_log` is in the default template. | Every report shows an empty "Work log" section unless hours get logged. | Data / template choice |
| 11 | **⚠️ Backups are failing (operations, not a report bug).** Every backup-relay run since 2026-09-13 failed for every site with S3 `HeadObject 403 Forbidden`, and there have been no runs since 2026-09-16. Only 1 scheduled site has a per-site archive timestamp at all. | Since #6, reports no longer *claim* backups that didn't happen, but the backups themselves still aren't happening. Most likely the S3 credentials or bucket policy changed. | `backup_relay_runs`, `modules/BackupRelay/` |
| 12 | **Vulnerability count is "as of the snapshot", not the month.** No history of installed plugin versions is kept, so the report can only show the state on the Companion snapshot date. It's labelled "(as of …)". | The count can differ from what was true during the month. Acceptable, but worth knowing. | `ClientReportCompiler::compileSecurity()` |
| 13 | **Backups can't be verified in `external_agent` mode.** Production runs the backup relay as an external agent. In that mode nothing writes the per-site `backup_relay_last_archived_at` (only `ArchiveSiteBackupJob` does, in `in_repo` mode), and `PullBackupRelayReport` only records run totals. So even once #11 is fixed, #6's check would hide the Backups section for every site. It needs real per-site archive history: read in-period archives from the S3 manifests (`BackupArchiveEnumerator`), or have the agent report per-site archive times. | Clients never see backups, even when they're working. | `ClientReportCompiler::compileBackups()`, `modules/BackupRelay/` |
| 14 | **A partial vulnerability-feed refresh silently drops matches.** `WpVulnerabilityClient::refresh()` deletes the whole `plugin_vulnerabilities` table, re-inserts only the plugins it fetched, and always reports success. The report's 48h freshness check catches a total failure (empty table), but not a few plugins timing out. | Those plugins' vulnerabilities vanish from reports with no sign anything is missing. | `app/Services/Security/WpVulnerabilityClient.php` |
| 15 | **4 scheduled sites are flagged `is_wordpress = 0` but run WordPress** (ids 315, 330, 331, 332). The report now detects them via Companion's `wp_core`, but the flag also keeps the core-checksum scanner from covering them. | No core-file checks ever run on those sites. | Data fix on `sites.is_wordpress` |

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
| 2026-10-15 | **#8:** Vulnerabilities come from `PluginVulnerabilityMatcher`. Before, the report read a snapshot key nothing ever writes, so every report said "None"; on the 2026-10-15 data, 26 of 72 scheduled sites have 59 known matches. It shows "N found in X plugins (as of …)", or "Not checked" with no plugin list. | `feature/client-reports` |
| 2026-10-15 | **#6:** Backups are only claimed when the relay archived inside the period. No more "Host Automated Snapshots" assumption. The preview shows "Not verified this period". For September only 1 of 72 sites qualifies, because of #11. | `feature/client-reports` |
| 2026-10-15 | **#5:** Core-file status is the latest completed in-period `core_checksums` scan, or "Not checked this month" (it used to default to "Ok"). The row is left out for non-WordPress sites. The preview's hard-coded "Clean / Passed" tile is gone. Also: failed scans no longer count as "run", and `warn_scans` counts `warning`/`issues_found` (it looked for `warn`, which never occurs). | `feature/client-reports` |
| 2026-10-15 | **#4:** Forms counts `status = 'success'`. It looked for `'ok'`, which is never written, so every tested site was told "0% delivered". The pass rate is null when nothing ran. | `feature/client-reports` |
| 2026-10-15 | **#7:** Performance only uses scans and field data from inside the period, so stale scores no longer appear under this month's heading. | `feature/client-reports` |
| 2026-10-15 | **Review follow-ups** (a 3-lens review with a skeptic checking each finding: 19 findings, 14 confirmed). Fixed here: **uptime** counted every outage as 5 min because it read a non-existent `duration_seconds` column; it now measures down→up intervals, carries an outage open at the start, and skips SLA-exempt ones. **Forms**: lab runs (email suppressed) no longer count as "delivered", and a live run only counts when the mail was actually sent. Added a **Malware scan** row (flagged findings used to be invisible). The vulnerability line is "Not checked" when the **feed is stale**, and notes matches in **inactive plugins**. Sites with a Companion `wp_core` count as **WordPress**. `meta.schema_version = 2` stops old stored reports re-showing "None found" and "0% delivered". Empty performance sections are gone from the text email and the preview. | `feature/client-reports` |
| 2026-10-15 | **#9:** The command and **Send now** both go through `ScheduledReportRunner` + `ClientReportSender`. | `feature/client-reports` |
