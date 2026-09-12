# Next cycle: Glacier restore, closed plugins + KEV, PHP EOL

**Status:** Implementation plan for Claude. Do not treat `plans/feature-roadmap-board.md` (dated 2026-09-05) as current — RDAP, noindex, theme system, and mShots already shipped.

**Owner:** Aaron. **Repos:** Clockwork Control (`/Users/aaronr/Development/clockwork-control`) and Clockwork Companion (`/Users/aaronr/Projects/clockwork-companion`). Same feature-branch name is fine; do not mix this work into an unrelated dirty tree without asking.

**Quality gate (both repos as applicable):** `./vendor/bin/pint`, `composer phpstan`, `./vendor/bin/pest`. Update `CHANGELOG.md` `[Unreleased]` and the in-app docs under `resources/docs/` when behavior changes. Tests are Pest `describe`/`it`/`expect()`, factories in `database/factories/`.

**Do not write this plan into code comments.** Implement the workstreams below. Ask Aaron before committing or opening a PR.

---

## Why this cycle

Standalone enroll + Companion → S3 Glacier Instant Retrieval backups just landed. That is take-backup only. ManageWP’s actual superpower is restore. After the backup loop is closable, two cheap security enrichments (closed WP.org plugins, CISA KEV) and PHP EOL on Capacity are the highest-signal, lowest-architecture follow-ons.

**Out of scope this cycle:** DNSBL/RBL, Green Web Foundation, SPF/DKIM/DMARC, Safe Updates / visual regression / automatic rollback, module-submission GitHub templates, RunCloud/Ploi/Bunny, S3 multipart >5GB, Companion “optimize database,” Companion uninstall-on-drop.

`resources/docs/features/updates.md` already says Clockwork is **not** building ManageWP Safe Update / backup-before-update / rollback. Do not reopen that.

---

## Hard constraints (read before coding)

### Product

- Glacier schedule UI stays on **Overview** for **custom/unhosted** sites only. SpinupWP and Pressable keep host-native backups. Do not put backup settings back on Site Settings. Do not resurrect `dashboard/site/_card-backups.blade.php`.
- Restore in v1 is **custom + `backup_relay_enabled` + Companion** only. Hosted Spinup/Pressable sites already have host restore; do not invent a second path.
- Restore is **operator-initiated**, not a literal one-click. Confirm by typing the site domain (same pattern as archive / destroy-server).
- Restore does **not** delete the S3 object. It does not change ingest exclusions. It does not enroll/unenroll the site.

### Companion / live reality

Companion may be a **regular plugin** (`wp-content/plugins/clockwork-companion/`), not an mu-plugin. Never use `WPMU_PLUGIN_URL` as the default asset base.

Live proof site (custom): `desatechpr.com`, Control site id **315**. Host PHP **8.1**, **no `php-zip`**, nginx ~60s gateway timeout. An ~81MB PclZip backup already 504’d when adding files one-by-one. Restore of that archive is harder than create.

Create already does `@set_time_limit(900)` and `memory_limit=512M` in `BackupCreateRoute`. Restore must assume the HTTP request can still die at the **proxy** (~60s) even if PHP wants 15 minutes. Design for that (see Phase 1b).

### S3 / IAM

Bucket: `clockwork-pressable-backups` (`us-east-2`). Control IAM can `PutObject` under `_control/backup-relay/` and **cannot** write `archives/{domain}/` unless IAM is widened.

Local/prod `.env` must use:

```
CLOCKWORK_BACKUP_RELAY_ARCHIVE_PREFIX=_control/backup-relay/archives
```

Presigned **GET** for restore must use the same prefix / enumerator Control already uses (`Modules\BackupRelay\Services\BackupArchiveEnumerator`). Do not hardcode `archives/{domain}/`. Do not “fix” 403s by writing to a prefix IAM denies.

### Working tree

Control may already have uncommitted standalone enroll, Glacier frequency/size/sha256, and `site_ingest_exclusions`. `git status` first. Do not fold unrelated files into this work. Do not commit secrets or WP passwords.

---

## Workstream 1 — Close the backup loop

Do **1A leftovers first** (hours, not days). Then restore.

### 1A — Finish what is already half-true

1. **Custom backups-report push.** `clockwork:push-companion-backups` (`app/Console/Commands/PushCompanionBackupsReport.php`) already has a `pushForCustomSites()` path and docs in `resources/docs/features/backup-relay.md` claiming custom Glacier history is pushed even without a SpinupWP token. **Verify with tests** (`tests/Feature/Console/PushCompanionBackupsReportTest.php`): a `custom` + Companion + `backup_relay_enabled` site gets `source=clockwork-companion`, `history_scope=combined`, type `full` rows, real presigned `offsite_archive` when the disk can mint them. If the code is missing or the test is missing, add it. Payload must include a `config` array (empty object is fine) — Companion 400s on a missing/non-array `config`.

2. **IAM / prefix.** Document the required `CLOCKWORK_BACKUP_RELAY_ARCHIVE_PREFIX` in `resources/docs/features/backup-relay.md` and `resources/docs/reference/env-vars.md` if not already there. Do not commit `.env`.

3. **Companion release-ready patches** (repo `~/Projects/clockwork-companion`, already drafted live — make sure they are in git and tested):
   - `HmacVerifier::verify()`: PHP 8.1-safe return type (`bool|\WP_Error`, not `true|\WP_Error`).
   - Remove the `show_advanced_plugins` WhiteLabel hook (WP passes a boolean; it whitescreened `plugins.php`).
   - `WhiteLabel::bundledAssetUrl()` for regular-plugin installs; stop using `WPMU_PLUGIN_URL` as default.
   - `BackupArchiver`: ZipArchive **or** one-shot PclZip with `PCLZIP_ATT_FILE_NAME` / `PCLZIP_ATT_FILE_NEW_FULL_NAME`. Never per-file PclZip `add()` (O(n²) + 504).
   - Client Backups page: combined Date / Type / Size when source is `clockwork-companion` or rows are type `full` without `database_bytes`; single “Download latest backup” when only one URL.

4. **Off-host copy** on Companion Backups: stop saying Pressable-flavored “host backups copied twice a week” for `source=clockwork-companion`. Say it is an off-site Glacier archive Clockwork pushed.

### 1B — Restore: product shape (v1)

**Not** “stream zip from S3 and explode it over a live site in one HMAC request.” That will 504 and can leave files written / SQL not imported.

v1 is a **two-step Companion flow**, driven from Control:

| Step | Who | What |
|---|---|---|
| **Stage** | Control → Companion `POST /wp-json/clockwork/v1/backup/restore` `action=stage` | Presigned S3 **GET**, expected `sha256`, size. Companion downloads to `wp-content/uploads/clockwork-backups/`, verifies hash, extracts to a sibling staging dir, does **not** touch live `wp-content` or the DB. Returns `{ ok, staged_id, bytes, sha256, has_sql, has_files }`. |
| **Apply** | Control → same route `action=apply` + `staged_id` | Enable existing Companion maintenance (`POST /maintenance-mode`, already exists). Import SQL (batch / `$wpdb`, table prefix from the dump or current `$table_prefix` — if they disagree, fail closed and leave maintenance on). Replace `wp-content` from staging with a conservative exclude list (`uploads/clockwork-backups`, `upgrade`, `cache`, `debug.log`). Flush caches. Disable maintenance. Delete staging + zip. |

Control UI (Overview backups widget only, custom + relay enabled):

- Archive list already comes from `BackupArchiveEnumerator` (presigned GET when disk is S3).
- Each archive: **Restore…** opens a confirm panel. Operator types the **site domain**. Optional note.
- Control records an `ActionLog` (`backup_restore_staged` / `backup_restore_applied` / `backup_restore_failed`) with archive key, sha256, actor.
- Buttons disabled while a restore job is in flight (site-level lock / `companion_stuck_*` style or a dedicated nullable `backup_restore_started_at` — prefer a small column or reuse a lock table if one exists; do not invent a new subsystem if `cache()->lock()` is enough for the HTTP action).
- After apply: flash success/failure. Do not auto-unarchive or change hosting_provider.

**Fail closed**

- Hash mismatch → do not apply.
- Stage timeout / 504 → Companion may still be downloading. Next stage with the same key should resume or replace the temp file; do not apply a partial zip.
- Apply SQL fails after files started → leave **maintenance on**, log, surface a red banner on Overview. Do not silently disable maintenance.
- Missing Companion / missing `backup-restore` capability → 422 in Control, no S3 GET minted.
- Confirm domain mismatch → same as archive (no work done).

**Timeouts**

Assume the Control → Companion HMAC call can be killed at ~60s. Stage must be restartable. If a single request cannot finish an 80MB download, split stage into `action=stage-start` (returns immediately after enqueueing a WP cron / shutdown function) + `action=stage-status` polled by Control. Prefer that if a synchronous download of the live ~81MB archive cannot be proven under 60s. Do not pretend `@set_time_limit(900)` fixes nginx.

**SQL import**

Reuse dump format from `ClockworkCompanion\Backup\DatabaseDumper` (gzipped SQL). Import in chunks. No shell `mysql` required (WP Engine / no SSH). Do not run arbitrary `DROP DATABASE`. Prefer dropping/replacing tables named in the dump only.

**Excludes on apply (files)**

Do not overwrite: `wp-content/uploads/clockwork-backups/`, Companion’s own plugin directory if that would delete the running plugin mid-apply (copy Companion aside or apply Companion last). Do not overwrite `wp-config.php` (it is not in `wp-content/` anyway).

**What v1 will not do**

- Restore onto a different domain (no search-replace of serialized URLs).
- Restore Spinup/Pressable host snapshots.
- Multipart download of >5GB objects.
- Automatic rollback if the site looks wrong after apply.
- Clone / Away.

### 1B — Implementation map

**Companion** (`~/Projects/clockwork-companion`)

- New `Rest\BackupRestoreRoute` registered next to `BackupCreateRoute`.
- HMAC required (`HmacVerifier`).
- Capability advertised in health/snapshot so Control can hide the button (`backup-restore`).
- Tests: stage rejects bad URL / hash; apply rejects unknown `staged_id`; apply refuses when hash was never verified.
- PHP 8.1-safe types. PclZip extract must be one-shot / documented, not per-file add.

**Control**

- `ClockworkCompanionClient`: `stageBackupRestore()` / `applyBackupRestore()` (or one method + action).
- Overview widget: Restore confirm UI on custom + relay + capability.
- Controller action on the site (POST, auth, confirm_domain). Use `BackgroundArtisan` or a queued job if the HMAC call is long — same pattern as other fleet actions that cannot block the request.
- Enumerator already knows how to mint download URLs — reuse `supportsPresignedUrls()`. If the disk cannot presign, do not offer Restore (same reason client download links are omitted).
- Pest: confirm mismatch; custom site happy-path with Http::fake to Companion; Spinup site has no Restore button; missing capability 422.

**Docs**

- `resources/docs/features/backup-relay.md`: restore is operator-confirm, custom-only, two-step, fail-closed maintenance.
- Companion architecture doc if Control documents the HMAC surface.
- Artisan/web-routes if you add a route.
- Changelog: restore completes the Glacier lifecycle; it does not delete the host site.

---

## Workstream 2 — Closed plugins + CISA KEV

Do this after 1A. Can proceed in parallel with 1B if two people; if one agent, finish 1B first unless restore is blocked on Companion timeouts — then ship 2.

These are enrichments of data Clockwork already has. Do not build a new “threat intel platform.”

### 2A — WP.org closed / zombieware plugins

**Problem:** A plugin closed on WordPress.org does not offer a newer version, so WP and Clockwork show “up to date” while the slug is abandoned or pulled for a security reason.

**API:** `https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]={slug}`  
Closed slugs return `{"error":"closed","description":"..."}` — **not** `closed: true`. Parse `error === 'closed'` and keep `description` as the reason. See `plans/gemini-research-assessment.md`.

**Mechanism**

- Deduplicate slugs from `companion_snapshot['plugins']['plugins']` across **active** (non-archived) WordPress sites. Same snapshot shape `PluginVulnerabilityMatcher` already reads.
- Weekly scheduled command, e.g. `clockwork:refresh-closed-plugins`. 100ms+ spacing (mirror `WpVulnerabilityClient`). Cache results in a small table (`plugin_directory_status`: slug, status `open|closed|not_found|error`, reason, checked_at). Do not hit WP.org per site.
- `/issues`: a site with an **active** installed plugin whose slug is `closed`. Not Critical-for-every-close. Title like “Plugin closed on WordPress.org.” Show the API description. Trademark / author-request closes are still worth knowing; do not call them zero-days unless the description says so.
- Wire `IssueCounter` + Issues page section. Honor `is_inactive` the same way other routine plugin issues do (quiet). Malware-style “always alert” is wrong here.
- Per-site ignore via existing `ignored_issues` if that pattern is easy; otherwise a slug-level mute on the status table is enough. Do not invent a third ignore system if `IgnoredIssue` can take a new type + optional slug in `reason`.

**Do not**

- Query every slug on every page load.
- Treat “plugin not on WP.org” (premium / custom) as closed. `not_found` ≠ `closed`.
- Duplicate wpvulnerability.net. This feed is orthogonal (no CVE required).

**Tests:** fake WP.org closed + open; fleet with two sites sharing a slug = one HTTP call; Issues shows the site; inactive site excluded from badge; premium slug `not_found` does not alert.

**Docs:** new short feature page or a section under plugin inventory / security scans. Changelog. `api-endpoints-we-call.md` + scheduled-jobs.

### 2B — CISA KEV badge

**Problem:** Pending plugin updates are a pile. A CVE that CISA lists as known-exploited should sort to the top.

**API:** `https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json` (free, no key). Daily cache.

**Mechanism**

- New small table or `app_settings` JSON cache of KEV CVE IDs (the feed is ~1.7k rows — a table of `cve` + `vendor_project` + `product` + `date_added` is fine).
- Command `clockwork:refresh-cisa-kev` daily, near `clockwork:refresh-plugin-vulnerabilities` (03:15).
- `plugin_vulnerabilities.cve` is **already indexed**. Matching is `WHERE cve IN (kev_cves)`. Add `in_kev` as a query-time helper or a boolean column refreshed after each KEV pull — query-time is simpler and stays correct when the vuln mirror is replaced (that command truncates + reinserts).
- UI: red “Actively exploited (CISA KEV)” badge next to the CVE on `/issues` plugin vulns (see existing CVE link around `resources/views/dashboard/issues.blade.php` ~1797) and on the Updates / vuln report email if that view lists CVEs.
- Intersection with WordPress plugin CVEs will be **small**. Copy must not promise “this prioritizes your whole update queue.” It highlights the rare hit.

**Do not**

- Alert on KEV entries that do not match an installed vulnerable slug+version (`PluginVulnerabilityMatcher::forSite()` is the gate).
- Scrape CISA HTML. Use the JSON feed only.

**Tests:** fixture KEV JSON with one CVE that exists on a mirrored vuln + an installed plugin version in range → badge; CVE in KEV but not installed → no site issue; refresh is idempotent.

**Docs:** one paragraph on the plugin-vuln / Issues page. Changelog. `api-endpoints-we-call.md`.

---

## Workstream 3 — PHP (and WP) EOL on Capacity

Do this last. Half-day if 2A/2B are in. Sales-useful, not a new product.

**API:** `https://endoflife.date/api/v1/products/php` — **no** `.json` suffix (the roadmap board URL 404s). Schema is v1, not the deprecated v0. Same product endpoint pattern for WordPress if you also show core EOL (`/api/v1/products/wordpress`).

**Where the versions already are**

- Site PHP: `companion_snapshot['environment']['php_version']` (already shown on the forms widget and client reports).
- Site WP: `companion_snapshot['environment']['wp_version']`.
- Servers: check poll/metrics snapshots for a PHP version before adding SSH. If there is no reliable server-level PHP, **do not invent a new SSH probe this cycle** — site-level Companion data is enough for v1.

**Mechanism**

- Cache endoflife.date (daily command or on-demand with 24h cache in `app_settings`). Never block `/capacity` on a live HTTP call.
- Normalize versions to major.minor (8.1.2 → 8.1) for cycle matching.
- `/capacity`: a compact “Runtime EOL” card — counts of sites on EOL PHP, in security-only window, and on current. Click-through list of domains + version + “security support ended {date}” / “ends {date}.”
- Site Overview or Settings: a quiet pill if that site’s PHP is EOL. Not a Critical Issues firehose unless you also add an Issues section — prefer Capacity + site pill in v1 so inactive/staging noise stays down. If you add Issues, exclude `is_inactive` and staging servers.

**Do not**

- Call this a “retainer generator” in UI copy. State facts: version, support end date, days since/until.
- Fail the Capacity page if endoflife.date is down (show “EOL data stale”).
- Require an API key.

**Tests:** fixture PHP cycles; site on 8.1 counts as security-window or EOL depending on the fixture dates (pin `Carbon::setTestNow`); site without snapshot omitted; HTTP failure does not 500 Capacity.

**Docs:** `resources/docs/features/traffic-and-capacity.md`. Changelog. `api-endpoints-we-call.md`.

---

## Suggested build order

| Order | Work | Done when |
|---|---|---|
| 1 | Workstream 1A | Custom backups-report tested; prefix documented; Companion 8.1 / PclZip / assets / copy committed in the Companion repo |
| 2 | Workstream 1B | Stage + apply restore on custom Glacier archives; confirm-domain; fail-closed maintenance; Pest + Companion tests; docs |
| 3 | Workstream 2A | Weekly closed-plugin pass; Issues for active closed slugs; `not_found` ≠ closed |
| 4 | Workstream 2B | Daily KEV cache; badge only when matcher hits |
| 5 | Workstream 3 | Capacity EOL card + site pill from snapshot PHP; stale-safe |

Ship 1A even if 1B slips. Do not ship a restore button that calls a single “download and overwrite” request.

---

## Acceptance (human)

- Restore a **staging copy** of a custom site (or site 315’s archive onto a throwaway) through Control: confirm domain, stage completes, apply brings files+DB, maintenance lifts only on success.
- A 504 during stage does not apply a partial zip.
- Spinup/Pressable Overview has no Restore for Glacier (host backups unchanged).
- Closed plugin: install (or snapshot-fixture) a closed slug → Issues; a premium off-directory slug does not.
- KEV: fixture a matching CVE → badge; no match → no badge.
- Capacity: a site with snapshot PHP 8.0/8.1 shows in the EOL breakdown without a live HTTP request on page load.

---

## Files to start from (do not boil the ocean)

**Control:** `modules/BackupRelay/src/Services/BackupArchiveEnumerator.php`, `modules/BackupRelay/src/Jobs/ArchiveSiteBackupJob.php`, `app/Services/Companion/ClockworkCompanionClient.php`, `resources/views/dashboard/site/widgets/_widget-backups.blade.php`, `app/Console/Commands/PushCompanionBackupsReport.php`, `app/Services/Security/WpVulnerabilityClient.php`, `app/Services/Security/PluginVulnerabilityMatcher.php`, `app/Models/PluginVulnerability.php`, `app/Support/IssueCounter.php`, `app/Http/Controllers/IssuesController.php`, `resources/views/dashboard/issues.blade.php`, `app/Http/Controllers/CapacityController.php`, `resources/views/dashboard/capacity.blade.php`.

**Companion:** `src/Rest/BackupCreateRoute.php`, `src/Backup/BackupArchiver.php`, `src/Backup/DatabaseDumper.php`, `src/Rest/MaintenanceModeRoute.php`, `src/Auth/HmacVerifier.php`, `src/Admin/Pages/BackupsPage.php`, `src/Plugin.php` (route registration + capabilities).
