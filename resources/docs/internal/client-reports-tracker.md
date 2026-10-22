---
title: Client reports tracker
section: Internal
order: 30
updated: 2026-10-22
author: Aaron Reimann
tags: [client-reports, tracker, roadmap, known-issues]
---

The running to-do list for the Client Reports module. For how the feature works, see [Features → Client reports](/docs/features/client-reports). Keep this page current: when something here gets fixed, move it to **Done** with the commit or PR, and bump `updated:`.

## Where things stand (2026-10-22)

- **Goal:** recreate the ManageWP client report in Control, then retire ManageWP.
- **Branch:** `feature/client-reports` (draft PR #50). Code-complete for 2026-11-01, **not merged or deployed yet**; see the checklist below. What's left is mostly operations (deploy, AWS key, data fixes, decisions).
- **2026-10-22:** each schedule's first report is now held for review automatically, so the first send can't go out unreviewed even if nobody switches the schedules to draft. Also fixed: first-month backup counts, the Security intro wording (#20), the email preview (#21), partial vulnerability-feed refreshes (#14), and re-sending a partly sent report.
- The original plan, `plans/client-reports-templates-scheduling.md`, is **fully shipped** now: phases 1–3 (scheduler fixes, templates, scheduling UI) were already live, and phase 4 (conditional delivery) shipped as **hold for review**. That plan's own "not yet implemented" header is out of date. Treat this page as the source of truth.
- **Decisions (2026-10-15):** reports are for **care-plan customers only**; the **first send on 2026-11-01 goes out as drafts** for review; after that, clean reports send themselves and flagged ones are held (**review only the exceptions**); unchecked sections are hidden from the client and flagged in the preview.

## Heads-up: first send on 2026-11-01

As of the 2026-10-15 production DB snapshot there are **72 schedules**, all enabled, `monthly`, `auto`, on the default template, none ever sent, and all with `next_run_at = 2026-11-01 00:00`. **45** of those sites have a care plan and will get a report; the other **27** are skipped until someone decides about them (checklist).

None of them has ever sent, so the 06:00 UTC run holds **every** report for review ("This is the first report from this schedule"), plus any other reasons. **Nothing is emailed automatically on Nov 1.** Switching the schedules to Draft is no longer needed.

### On the day (runbook)

1. **06:00 UTC:** `clockwork:send-client-reports` compiles October for the 45 care-plan sites and holds each one. The 27 without a care plan are skipped and move on to Dec 1. An `auto` schedule with no recipients is skipped and retried daily, so give it one. Check the output in Scheduled Jobs, or run `php artisan clockwork:send-client-reports` by hand to see it.
2. **Client reports → history:** every report shows **Needs review**. Open each one (↗). The amber box lists why it was held. Click **Email version** to see exactly what the client gets.
3. **Only "first report" listed:** send it with the paper-plane button.
4. **Other reasons too:** fix the cause (or confirm it's fine), then either send as-is, or press **Send now** on the schedule to recompile from current data. That copy is held again as a first report; check it and send by hand. The older copy is marked superseded.
5. **From December:** sending a report by hand stamps the schedule's `last_sent_at`, so the Dec 1 run emails clean reports by itself and holds only flagged ones.

## Open issues

Numbers stay stable as items are fixed; fixed ones move to **Done**.

| # | Issue | Why it matters | Where |
|---|---|---|---|
| 1 | **Weekly schedules report on the wrong period.** The scheduled path hard-codes "last calendar month", whatever the frequency. | A weekly schedule would email the same monthly report every week. No weekly schedules exist yet. | `ScheduledReportRunner::run()` |
| 10 | **The work log is empty** (`site_work_logs` has 0 rows), but `work_log` is in the default template. | The section is hidden unless hours get logged, so ManageWP-style "extra work" never appears. | Data / process |
| 11 | **⚠️ Off-site backups have stopped (operations).** Every backup-relay run since 2026-09-13 failed with S3 `403 Forbidden`. Listing the bucket with the key in the production `.env` copy fails with `InvalidAccessKeyId`, so the key was deleted or rotated in AWS and not updated in Control. | Unhosted sites aren't being backed up, and their report section shows **Could not check** (held for review). | `AWS_ACCESS_KEY_ID`, `modules/BackupRelay/` |
| 12 | **Vulnerability count is "as of the snapshot", not the month.** No history of installed plugin versions is kept. It's labelled "(as of …)". | The count can differ from what was true during the month. Acceptable. | `ClientReportCompiler::compileSecurity()` |
| 15 | **4 scheduled sites are flagged `is_wordpress = 0` but run WordPress** (ids 315, 330, 331, 332). The report detects them via Companion's `wp_core`, but the flag keeps the core-checksum scanner off them. | No core-file checks run on those sites. | Data fix on `sites.is_wordpress` |
| 16 | **20 SpinupWP sites have no backups in the Spaces bucket** (first recorder run, 2026-10-15); 2 have report schedules. List kept locally, not in the repo. | Their backups section is hidden and the report held. Either SpinupWP backups are off for them or stored elsewhere. | SpinupWP dashboard / `SpacesClient` |
| 17 | **Harmless "PHP in uploads" malware warnings hold reports.** Plugins such as WP All Import, Sucuri, BackWPup and Smush put PHP files in `uploads/`; the scan marks them for review, which holds that site's report. | Clean sites get held every month until the paths are marked safe. | `clockwork:suppress-malware-path` (operator) |
| 18 | **Pressable can skip a month's speed test.** Pressable tests each site about once a month on its own schedule (no way to trigger one); about 1 site in 70 had a gap in Aug–Sep. | That site's Speed section is hidden for that month. Acceptable unless it becomes common. | Pressable |
| 19 | **Non-Pressable sites get desktop speed only.** GTmetrix on our plan has no phone emulation. | No mobile score for the ~17 non-Pressable report sites. Options: GTmetrix plan with mobile, or PSI for mobile only (noisier). | `RunPerformanceScans` |
| 21 | **The operator preview's layout still differs from the email** below the header (e.g. updates as a table of plugin file names). Reviewers now have **Email version** (the real email), so this is cosmetic. | The preview is what prints as the PDF. | `reports/show.blade.php` |

## Before 2026-11-01 (deploy checklist)

- [ ] **Merge PR #50 and deploy this week** (by 2026-10-28 to be safe), then run `php artisan migrate` (adds `site_backups` + `site_backup_checks`) before the scheduler fires again. Pressable keeps daily backups for only ~8 days and the email lists Oct 22–31, so the recorder must have run by about Oct 29 for Oct 22 to still be readable. (The month's "backed up on N days" count is left out automatically for sites whose records start mid-month.)
- [ ] Run `php artisan clockwork:record-site-backups` once by hand straight after deploy to backfill; check the output for `[fail]` lines. (Local run 2026-10-15: 261 sites, 251 OK, ~5 min; the 10 failures were all S3, see #11.)
- [ ] Put a valid AWS key in production `.env` (#11). Without it the unhosted sites' backups can't be read **or** made.
- [x] ~~Switch the first run to drafts~~: no longer needed. Every schedule's first report is held for review automatically (2026-10-22). Leave the schedules on Auto.
- [ ] Decide each of the **27 scheduled sites with no care plan**: turn the care plan back on, or delete the schedule. Until then each month is skipped.
- [ ] Set the report colour in production: **Settings → Companion & White Label → Client Reports → Primary `#5B21B6`** (it's a setting, not code).
- [ ] Mark the known-safe "PHP in uploads" paths with `clockwork:suppress-malware-path` (#17), and look at anything that isn't a known plugin's files by hand.
- [ ] Set `is_wordpress = 1` for the 4 misflagged sites (#15): `UPDATE sites SET is_wordpress = 1 WHERE id IN (315, 330, 331, 332);` (check the ids match the domains first).
- [ ] Optional: log any October extra work on the site pages (#10) before Nov 1, or the Additional work section stays hidden.

## Ideas and backlog

- **SEO Ranking section** (ManageWP parity; decided for after Nov 1). The SEMrush data behind the monthly SEO reports is the obvious source.
- **Google Analytics section** (ManageWP parity; after Nov 1). Control only has server-log visit counts; GA4 needs an integration.
- **PDF attachment** for clients who forward the report.
- **A personal note per month** on the schedule (the field exists on manual reports only).
- **Sent-email audit.** `sent_to` records *who* got each report, not *when* or *by whom*. `action_logs` entries would show it on the site's activity log.
- **A "preview the next batch" view.** What the next scheduled run would send, and to whom.
- **Remove the public `/reports/view/{token}` route, or decide its purpose.** Clients can't reach it and the email doesn't link to it.

## Done

| Date | What | Ref |
|---|---|---|
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
