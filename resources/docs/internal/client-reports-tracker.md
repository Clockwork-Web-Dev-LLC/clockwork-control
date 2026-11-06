---
title: Client reports tracker
section: Internal
order: 30
updated: 2026-11-06
author: Aaron Reimann
tags: [client-reports, tracker, roadmap, known-issues]
---

The running to-do list for the Client Reports module. For how the feature works, see [Features → Client reports](/docs/features/client-reports). Keep this page current: when something here gets fixed, move it to **Done** with the commit or PR, and bump `updated:`.

## Where things stand (2026-11-06)

- **Goal:** recreate the ManageWP client report in Control, then retire ManageWP.
- **Shipped:** PR #50 (the Nov 1 work) and PR #63 (Clients tab, report verification fixes, schema 7) are merged into `main` as **v1.12.1**. The Nov 1 run held every report as a first report, as planned.
- **Dec 1 is the first unattended run.** Reports sent by hand in November stamped their schedule's `last_sent_at`, so on Dec 1 **clean reports email the client with nobody looking**. Only flagged ones are held.
- **2026-11-06 audit for Dec 1** (5 reviewers over the run path, every section, the emails and the operator screens; each finding checked against the code before fixing). The fixes are on `feature/client-reports`, **not merged or deployed yet**; see Done and the checklist below. What wasn't fixed is listed under Open issues (#23 on).
- The original plan, `plans/client-reports-templates-scheduling.md`, is fully shipped; this page is the source of truth.
- **Decisions (2026-10-15):** reports are for **care-plan customers only**; after the first send, clean reports send themselves and flagged ones are held (**review only the exceptions**); unchecked sections are hidden from the client and flagged in the preview.

## Before 2026-12-01 (checklist)

- [ ] **Merge `feature/client-reports` and deploy** before Nov 30. Then:
  - `php artisan migrate` (adds `site_backups.database_bytes`, widens `client_reports.client_email` to text).
  - `php artisan clockwork:record-site-backups` once, so SpinupWP rows get their database size.
  - `php artisan clockwork:refresh-plugin-vulnerabilities` once, to start the per-plugin lookup record (#22).
  - Report schema is now **8**: any unsent report is outdated. `php artisan clockwork:recompile-held-client-reports --outdated --dry-run`, then without `--dry-run` (never sends).
  - `npm run build`, clear the view cache, `php artisan queue:restart`.
- [ ] **Expect more holds on Dec 1 than "first report" only.** New holds: SiteCheck hits, earlier core-file/blocklist problems, no malware or blocklist scan all month, stale vulnerability list, never-looked-up plugins, files-only backups, a failed or early backup read, 429 blocks or maintenance of hours, a care plan switched off on Dec 1. Each one is a client email that would have been wrong or unexplained.
- [ ] **Off-site backups (#11):** put a valid AWS key in production `.env`. Until then every relay site is held.
- [ ] **Check the 04:45 and 05:30 UTC jobs finish before 06:00** (#34): Scheduled Jobs → `pressable-traffic-report` and `record-site-backups` end times in November.
- [ ] **Logo:** if the report logo was uploaded in Settings, check the Email version shows it. It's now embedded from disk; before, an uploaded logo's `/storage/…` link was broken in every inbox.
- [ ] Mark the known-safe "PHP in uploads" paths with `clockwork:suppress-malware-path` (#17).
- [ ] After `migrate`, confirm sites 315, 330, 331 and 332 now have `is_wordpress = 1` (#15).
- [ ] Don't **Run** schedules or **Create all** between 7 PM ET on Nov 30 and the 06:00 UTC run (#28).

## Open issues

Numbers stay stable as items are fixed; fixed ones move to **Done**.

| # | Issue | Why it matters | Where |
|---|---|---|---|
| 1 | **"Weekly" still exists.** Every run reports on the last calendar month; a weekly schedule re-checks it each week and never emails a month twice. The form no longer offers weekly for new schedules (2026-11-06), but wp-admin can still set it. | Decide: drop weekly everywhere (Control, Companion's wp-admin screen and the sync), or build a real weekly report. | `ScheduledReportRunner::run()`, `ReportRecipientsSyncService` |
| 10 | **The work log is empty** (`site_work_logs` had 0 rows), but `work_log` is in the default template. | The section is hidden unless hours get logged, so ManageWP-style "extra work" never appears. | Data / process |
| 11 | **⚠️ Off-site backups have stopped (operations).** Every backup-relay run since 2026-09-13 failed with S3 `403 Forbidden`; the production key returns `InvalidAccessKeyId`. | Unhosted sites aren't being backed up, and their reports are held. | `AWS_ACCESS_KEY_ID`, `modules/BackupRelay/` |
| 12 | **Vulnerability count is "as of the snapshot", not the month.** It's labelled "(as of …)". | Acceptable. | `ClientReportCompiler::compileSecurity()` |
| 15 | **4 scheduled sites flagged `is_wordpress = 0` but run WordPress** (ids 315, 330, 331, 332). v1.12.1's backfill migration should fix custom-host Companion sites; check these four after `migrate`. | No core-file checks on them until it's fixed. | `sites.is_wordpress` |
| 16 | **20 SpinupWP sites have no backups in the Spaces bucket**; 2 have report schedules. | Their reports are held. | SpinupWP dashboard / `SpacesClient` |
| 17 | **Harmless "PHP in uploads" warnings hold reports** (WP All Import, Sucuri, BackWPup, Smush). | Clean sites held every month until the paths are marked safe. | `clockwork:suppress-malware-path` |
| 18 | **Pressable can skip a month's speed test.** | That site's Speed section is hidden that month. Acceptable. | Pressable |
| 19 | **Non-Pressable sites get desktop speed only** (GTmetrix plan). | No mobile score for ~17 sites. | `RunPerformanceScans` |
| 21 | **The preview/PDF still differs from the email** in small ways: no blocklist row; raw ISO dates (updates, work log, "Checked …"); "1 scans"; "LCP: ms" when missing; downtime in minutes; "Not checked this period". | Cosmetic, but it's what prints as the PDF. | `reports/show.blade.php` |
| 23 | **wp-admin recipient sync compares against the schedule's `updated_at`**, which every run and hand send bumps. A client's wp-admin edit made just before a run can be pushed back over. | A recipient change made in wp-admin around report day may be lost. | `ReportRecipientsSyncService::reconcileFromSnapshot()` |
| 24 | **Re-enabling a paused schedule** whose `next_run_at` is in the past sends the missed month next morning (one click on the Paused pill, no confirm). | A stale month's report goes out. | `SchedulesController::toggle()` |
| 25 | **The Reports list follows the newest period with any report**: a one-off generated with this month's dates switches the list and its badge to it. | November's held queue hides behind the period picker. | `ReportBrowser::periods()` |
| 26 | **Deleting and recreating a schedule** resets it to "first report", and its old reports become one-offs that can't be recompiled. | Everything is held again. | `SchedulesController::destroy()` |
| 27 | **The Sent tile counts partly sent reports** (also counted under Waiting). | Tile and list disagree. | `index.blade.php` |
| 28 | **Before 06:00 UTC on the 1st it's still the evening before in New York.** A manual Run, Send now or a schedule created between 7 PM ET and the 06:00 run compiles the month before it has ended there, or schedules the first report a month later. | Partial last day, or a missed month. | `ScheduledReportRunner::run()`, `ClientReportSchedule::computeNextRun()` |
| 29 | **Updates made outside Control are recorded only from the v1.12.1 deploy.** WordPress auto-updates between Nov 1 and the deploy aren't listed in November. | Undercount; a site with nothing else could read "No updates were applied this month". | `ObservedUpdateRecorder` |
| 30 | **A missing `update_available` key counts as "nothing waiting"** (Companion before 1.15.0 doesn't send it). | Such a site could read "Everything was up to date". | `ClientReportCompiler::pendingUpdates()` |
| 31 | **A failed Spamhaus DNS lookup reads as "not listed"**; with no Google key, "Not listed" can rest on one timed-out lookup. | Blocklist row overstates. | `BlacklistChecker` |
| 32 | **"Data transferred" for a site that moved host mid-month** counts only the old host's days. | Understated figure, no hold. | `compileTraffic()` |
| 33 | **The work-log form defaults to the UTC date**, so hours logged after 7 PM ET land on the next day (and month). | Late Nov 30 hours miss November. | `_widget-work_logs.blade.php` |
| 34 | **04:45 and 05:30 UTC jobs run one after another** with the SSH-heavy jobs due at the same minute; nothing makes the 06:00 run wait. | If late, reports are held (safe), not wrong. Check the job end times. | `routes/console.php`, `PressableServiceProvider` |
| 35 | **Wording between the two emails:** "1 live test submissions", "· 1 hrs", outages "125 min" in text vs "2h 5m" in HTML, section order differs; the greeting is worked out from the report's stored recipients, not a typed address. | Cosmetic. | `emails/report-text.blade.php`, `ClientReportMail` |
| 36 | **Silent skips:** an inactive site or an auto schedule with no recipients is skipped with no action-log entry and the run still exits OK. | A site can quietly never get a report. | `SendScheduledClientReports` |

## Ideas and backlog

- **SEO Ranking section** (ManageWP parity; decided for after Nov 1). The SEMrush data behind the monthly SEO reports is the obvious source.
- **Google Analytics section** (ManageWP parity). Control only has server-log visit counts; GA4 needs an integration.
- **PDF attachment** for clients who forward the report.
- **A personal note per month** on the schedule (the field exists on manual reports only).
- **Sent-email audit.** `sent_to` records *who* got each report, not *when* or *by whom*.
- **A "preview the next batch" view.** What the next scheduled run would send, and to whom.
- **Remove the public `/reports/view/{token}` route, or decide its purpose.** Clients can't reach it and the email doesn't link to it.

## Done

| Date | What | Ref |
|---|---|---|
| 2026-11-06 | **Audit for the first unattended run (Dec 1)**, 5 reviewers, every finding checked against the code. Below, from "Schedule form" to "Report schema 8". | `feature/client-reports` |
| 2026-11-06 | **Schedule form:** unchecking **Enable** did nothing, and saving a paused schedule switched it back on (an unchecked box sends nothing; the controller read that as on). | `feature/client-reports` |
| 2026-11-06 | **Security holds:** a Sucuri SiteCheck hit is held even when the malware row comes from a clean Companion scan; a core-file or blocklist problem earlier in the month is held when a later clean check hides it; a month in which no malware scan or blocklist check finished is held (when those scans are on). | `feature/client-reports` |
| 2026-11-06 | **#22:** the vulnerability refresh records each plugin's last successful lookup; a report with a plugin never looked up is held, naming it. A vulnerability list older than 48h holds every report instead of sending "Not checked". | `feature/client-reports` |
| 2026-11-06 | **Uptime:** 429-blocked time of an hour or more, and maintenance mode of 2 hours or more, are held (both count as up); uptime is rounded down, so a 2-minute outage reads 99.99%, not 100%; a site added late on Nov 1 (a 25-hour day) no longer counts as a partial month. | `feature/client-reports` |
| 2026-11-06 | **Updates run from a site's Updates tab** showed the raw log line ("Updated example-forms/example-forms.php 6.0.6 → 6.1 on …") in the email; now the plugin's name, and the tab logs in the jobs' wording. | `feature/client-reports` |
| 2026-11-06 | **Backups:** SpinupWP's database half is stored apart (`database_bytes`) so a broken dump can't hide behind the files; files-only months, a failed latest read and a read from before the month ended are held. | `feature/client-reports` |
| 2026-11-06 | **Small guards:** a speed test with no score is skipped (it showed a red 0/100); requests with zero visits are suspect traffic; vulnerable plugin names are entity-decoded ("&amp;amp;"); the inbox preview text follows the template. | `feature/client-reports` |
| 2026-11-06 | **Sending:** editing a schedule's recipients updates its unsent reports (Send used the compile-time list); recipients are de-duplicated ignoring case; sends to one report are locked, so a double click can't email twice; Send on a sent report warns first; a draft-mode Run doesn't add a second live report beside a sent one. | `feature/client-reports` |
| 2026-11-06 | **Logo:** an uploaded logo is embedded from disk; a relative or Control-host logo URL is never hot-linked (it was broken in every inbox). | `feature/client-reports` |
| 2026-11-06 | **Care plan ended with the month:** Bill.com's 01:30 UTC sync on the 1st turned it off before the run, so the month's report was skipped. It's now compiled and held; the sync logs its flips (local only). | `feature/client-reports` |
| 2026-11-06 | **Recipient push** no longer writes an older Companion snapshot back over a newer one; `client_reports.client_email` is text (8+ long addresses crashed the insert under MySQL strict mode). | `feature/client-reports` |
| 2026-11-06 | **Report schema 8**, so unsent reports compiled before these checks are recompiled before sending. | `feature/client-reports` |
| 2026-11-06 | **Done since the last update:** Clients tab, Send test to me, Recompile, Mark findings reviewed, schema 7 report-content fixes (v1.12.1); #20, #21 (mostly) and #14 earlier. | PR #63 |
| 2026-10-22 | **Independent review of the Nov 1 changes** (5 reviewers, each finding checked by a skeptic; 7 confirmed, all fixed). Below, from "Sent reports reused" to "Plain-text email". | PR #50 |
| 2026-10-22 | **Sent reports reused for their period:** a run that was finished by hand after a failure, or **Send now** pressed again, no longer recompiles and emails everyone a second copy. | PR #50 |
| 2026-10-22 | **"Already up to date" only when nothing was waiting at month end:** themes and WordPress core now count (only plugins did), updates applied after the period count as waiting then, and a Companion snapshot more than 48h older than the period end makes it unknown and holds the report. | PR #50 |
| 2026-10-22 | **Uptime only for sites the probe checks:** sites on ignored/staging servers or ignore-listed domains no longer show "100%, checked around the clock"; a site the probe should check but hasn't lately is held. The preview follows the same rule. | PR #50 |
| 2026-10-22 | **Report period on the 29th–31st:** `subMonth()` overflowed into the current month (Send now on Dec 31 compiled December); now `subMonthNoOverflow()`, in the Generate form's default dates too. | PR #50 |
| 2026-10-22 | **The batch exits as failed when a site's report crashes or a recipient's email fails** (say, a mail outage on Dec 1), so Scheduled Jobs shows it (it always said OK). | PR #50 |
| 2026-10-22 | **Second review** of those fixes (4 reviewers + skeptics): 4 low findings confirmed and fixed (mail failures now turn the job red, Generate form dates, weekly description, snapshot-timing wording); 5 refuted. | PR #50 |
| 2026-10-22 | **Plain-text email no longer HTML-escapes** names and notes ("O&#039;Brien &amp; Sons"); the Email version page shows the text part too and says when everyone already has the report. | PR #50 |
| 2026-10-22 | **A schedule's first report is always held for review** (auto schedules), so the Nov 1 run can't email anyone unreviewed. A report sent by hand stamps its schedule's `last_sent_at`, so December's clean reports send themselves. | PR #50 |
| 2026-10-22 | **First-month backup count:** Pressable keeps ~8 days, so the first month's email would have said "backed up on 9 days this month". The count is now left out unless our records cover the whole period (`covers_period`). | PR #50 |
| 2026-10-22 | **#20:** the Security intro only says "block attackers at the server" when something reports blocks. | PR #50 |
| 2026-10-22 | **#21 (mostly):** **Email version** page shows the real email with To, Subject and review reasons. The preview's own layout is unchanged, but it no longer says "All software components were up-to-date" while updates wait (same rule as the email). | PR #50 |
| 2026-10-22 | **#14:** a partial vulnerability-feed refresh keeps the previous entries of plugins whose lookup failed (and lists them); a run where every lookup fails changes nothing and exits as failed. | PR #50 |
| 2026-10-22 | **Partly sent reports sent by hand** only email the recipients who don't have them yet (before, everyone got it again). | PR #50 |
| 2026-10-15 | Wrote the feature doc page and this tracker | `b652081` |
| 2026-10-15 | **#2:** Send splits the comma-joined `client_email` and emails every recipient. When a report has no address, the button asks for one and saves it. | `d6fb99c` |
| 2026-10-15 | **#3:** Each delivery is recorded in `client_reports.sent_to` as it happens. A failed run keeps the schedule due, and the next run resumes the same report and emails only the missing recipients. History shows **Partly sent**. | `d6fb99c` |
| 2026-10-15 | **#9:** The command and **Send now** both go through `ScheduledReportRunner` + `ClientReportSender`. | `d6fb99c` |
| 2026-10-15 | **#8:** Vulnerabilities come from `PluginVulnerabilityMatcher` (before, every report said "None"; on 2026-10-15 data, 26 of 72 scheduled sites have 59 known matches). "N found in X plugins (as of …)", or "Not checked". | `9baccf8` |
| 2026-10-15 | **#5:** Core-file status is the latest completed in-period `core_checksums` scan, or "Not checked this month" (it used to default to "Ok"). Failed scans no longer count as "run". | `9baccf8` |
| 2026-10-15 | **#4:** Forms counts `status = 'success'` (it looked for `'ok'`, so every site was told "0% delivered"). Lab runs no longer count as delivered. | `9baccf8` |
| 2026-10-15 | **#7:** Performance only uses tests from inside the period. Host reports (Pressable) are dated by when the host ran the test, not when we read it (`f2aea21`). | `9baccf8` |
| 2026-10-15 | **Honesty review** (19 findings, 14 confirmed): real down→up outage minutes, Malware scan row, stale-feed "Not checked", Companion `wp_core` counts as WordPress, `meta.schema_version`. | `9baccf8` |
| 2026-10-15 | **Backups from real sources (#13, replaces #6):** `site_backups` + `site_backup_checks`, filled daily by `clockwork:record-site-backups` from Pressable's API, SpinupWP's Spaces bucket and our S3 archives. Last 10 days listed; hidden and flagged when none were found or the read failed. | `2056b1b`, `bcc327a` |
| 2026-10-15 | **Unhosted sites weren't being backed up** in `external_agent` mode: `clockwork:backup-relay-run --direct-only` runs daily at 04:30; sweeps honour the restore / Backup Now locks. | `43c1b1c`, `6cd7db8` |
| 2026-10-15 | **ManageWP parity, cheap items:** outage list, "Spam & malware blocklists" line, no-op updates left out, WordPress version from the right key. | `bcc327a` |
| 2026-10-15 | **Backups review** (18 findings, 14 confirmed): no double-counted sizes (incl. SpinupWP full-backup days, `3b618d0`), stale backups flagged, SpinupWP labels, empty archives ignored, outages marked carried-in/ongoing, recorder robustness. | `bcc327a`, `3b618d0` |
| 2026-10-15 | **Blocklist check skipped every serverless site** (58 of 72 report sites); **"Data transferred: 0 MB"** on Pressable (no byte counts). Both fixed. | `997aca8` |
| 2026-10-15 | **"Threats blocked" only when something reports blocks** (no misleading 0). | `c6ac653` |
| 2026-10-15 | **The malware scan flagged Companion's own scanner file** on sites where Companion is a regular plugin. Fixed. | `d22457a` |
| 2026-10-15 | **Care-plan only + hold for review (phase 4):** sending skips sites without a care plan; a report that hasn't reached anyone is checked first and held as **Needs review** for malware/core/blocklist flags, backup problems, a site down at month end, or a vulnerable plugin with an update waiting. Older unsent copies are **superseded**. GTmetrix scores labelled desktop. | `309e8ff`, `8dcf34b` |
| 2026-10-15 | **No "already up to date" while updates wait** (found on a site whose premium-plugin updates failed all month). | `8dcf34b` |
| 2026-10-15 | **Pressable speed reports read nightly**, mobile and desktop, so a new monthly test lands within a day (15 of 28 Pressable report sites had no October score under the weekly rotation). | `f2aea21` |
| 2026-10-15 | **Brand-colour header on the operator preview**, matching the email. | `044ca6e` |
