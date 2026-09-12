# Implementation plan for Gemini: Glacier restore, closed plugins + KEV, PHP EOL

**Status:** Ready to build. Written 2026-09-11 by Claude after verifying the current state of both
repos file-by-file. This supersedes the "what already exists" assumptions in
[`next-cycle-restore-closed-plugins-eol.md`](./next-cycle-restore-closed-plugins-eol.md) — read that
file for product rationale and hard constraints (they all still apply); read THIS file for what to
actually type. Where the two disagree on current state, this file is correct (verified 2026-09-11).

**Repos:**
- Control: `/Users/aaronr/Development/clockwork-control` (Laravel, Pest, PHPStan, Pint)
- Companion: `/Users/aaronr/Projects/clockwork-companion` (WP plugin, plain PHPUnit 11.5, `composer test`)

**Ground rules for Gemini (non-negotiable):**
1. Create a NEW branch in each repo for this work (suggested: `feature/restore-closed-plugins-eol`
   in both). Control's current branch `feature/companion-standalone-and-s3-glacier-backups` already
   holds committed work — branch off it, don't commit onto it.
2. The Control working tree has three uncommitted files that are NOT yours:
   `plans/README.md` (modified), `resources/docs/getting-started/local-dev.md` (modified),
   `plans/next-cycle-restore-closed-plugins-eol.md` (untracked), plus this file. **Do not sweep any
   `plans/` or unrelated dirty files into your commits.** Stage files explicitly, never `git add -A`.
3. **Ask Aaron before every commit and before opening any PR.** Do not push to main.
4. Quality gate per repo before asking to commit — Control: `./vendor/bin/pint`, `composer phpstan`,
   `./vendor/bin/pest`. Companion: `composer test` (note `phpunit.xml` sets `failOnWarning="true"` —
   a stray PHP warning fails the suite).
5. Update `CHANGELOG.md` `[Unreleased]` in both repos, and Control's `resources/docs/` pages, as part
   of each workstream — not as a final batch. Control's docs CoverageChecker flags any new
   `app/Console/Commands/*`, `app/Http/Controllers/*`, or `*Client.php` file that no doc page
   `tracks:` — so every new command/client needs a `tracks:` entry somewhere.
6. Do not commit `.env`, secrets, or WP passwords. Never write this plan's text into code comments.
7. Out of scope (do not touch): DNSBL/RBL, Green Web, SPF/DKIM/DMARC, Safe Updates / visual
   regression / rollback, RunCloud/Ploi/Bunny, multipart >5GB, Companion optimize-database or
   uninstall-on-drop. `resources/docs/features/updates.md` explicitly says no backup-before-update —
   do not reopen.

**Build order:** 1A residuals (≈1–2 h) → 1B restore (the big one) → 2A closed plugins → 2B KEV → 3 EOL.
Ship each workstream as its own reviewable unit.

---

## Verified current state (2026-09-11) — read before starting

Most of the source plan's Workstream 1A is **already done and committed**:

**Control** (all in commit `c1f2061`):
- `PushCompanionBackupsReport::pushForCustomSites()` exists (`app/Console/Commands/PushCompanionBackupsReport.php:173`),
  runs before the SpinupWP `isConfigured()` bail-out, sends `source=clockwork-companion`,
  `history_scope=combined`, `config => []`, `type=full` history rows, and `offsite_archive` with
  presigned URLs when `supportsPresignedUrls()`.
- `tests/Feature/Console/PushCompanionBackupsReportTest.php` exists (392 lines) and already covers the
  custom-site happy path (line ~249), capability gating, retention, presign gating, `--site`/`--server`.
- `CLOCKWORK_BACKUP_RELAY_ARCHIVE_PREFIX` is already documented in
  `resources/docs/reference/env-vars.md:199`. It is NOT yet mentioned in
  `resources/docs/features/backup-relay.md` (env block at lines 193–202).

**Companion** (all in commit `30f7bcb`, v1.36.0, working tree clean):
- `HmacVerifier::verify(): bool|\WP_Error` — the PHP 8.2-only `true|` type is gone.
- `show_advanced_plugins` hook removed. `WhiteLabel::bundledAssetUrl()` exists; no executable
  `WPMU_PLUGIN_URL` usage remains.
- `BackupArchiver` uses ZipArchive when available, else **one-shot** PclZip `create()` with
  `PCLZIP_ATT_FILE_NAME`/`PCLZIP_ATT_FILE_NEW_FULL_NAME`. No per-file PclZip add remains.
- `BackupsPage` copy already branches on `source === 'clockwork-companion'` (no "twice a week" for
  direct Glacier), combined Date/Type/Size table, single download button handling.

**Real gaps this plan closes** (found during verification):
- **No per-archive sha256 exists anywhere.** `BackupArchiveEnumerator` entries carry no hash; the only
  stored hash is `sites.backup_relay_last_sha256` (latest archive only, written by
  `ArchiveSiteBackupJob::markArchived()`).
- **No extraction code exists in Companion** (`extractTo` has zero hits). The live proof host
  (desatechpr.com, site 315, PHP 8.1) has **no php-zip**, so extraction MUST work via PclZip.
- **Companion `composer.json` requires `php >= 8.2`** while 1.36.0 explicitly fixed an 8.1 fatal and
  the source is 8.1-clean. The plugin header has no `Requires PHP:` line at all.
- `HmacVerifier` burns each POST signature into a replay-guard transient — **an identical signed POST
  cannot be retried within 5 minutes; status polling must be a GET** (GETs don't burn the guard).
- Issues page vuln matching (`$vulnsBySiteId`) only runs for sites with pending plugin updates —
  closed plugins show "up to date", so 2A needs its own independent pass.
- Control tests run on **SQLite in-memory** (`phpunit.xml`); production is MySQL. Avoid raw
  `JSON_EXTRACT`/MySQL-only SQL in new queries — filter in PHP over `companion_snapshot` instead.

---

## Workstream 1A — residuals (hours)

1. **`resources/docs/features/backup-relay.md`:** add `CLOCKWORK_BACKUP_RELAY_ARCHIVE_PREFIX` to the
   env-var block (lines ~193–202), noting prod uses `_control/backup-relay/archives` because Control's
   IAM can only `PutObject` under `_control/backup-relay/` (see source plan "S3 / IAM"). While there:
   `resources/docs/reference/env-vars.md` is missing rows for `CLOCKWORK_BACKUP_RELAY_DISK`,
   `_FREQUENCY`, `_RETENTION_DAYS` (all in `config/clockwork.php:279-286`) — add them. Bump `updated:`
   on both pages.
2. **Companion PHP support reconciliation:** change `composer.json` `"php": ">=8.2"` → `">=8.1"` and
   add `Requires PHP: 8.1` to the plugin header in `clockwork-companion.php`. The source tree is
   verified 8.1-clean (no standalone `true|false|null` return types, no readonly classes, no enums).
   Also fix the stale `CLOCKWORK_COMPANION_VERSION = '1.33.0'` hardcode in `tests/bootstrap.php:8`
   to track the real constant. CHANGELOG entry.
3. Nothing else — the other 1A items listed in the source plan are verified done (see above). Do not
   redo them.

---

## Workstream 1B — Restore (custom + relay + Companion only)

### Product constraints (from the source plan — unchanged)

- Only for `hosting_provider = custom` + `backup_relay_enabled` + Companion installed with the
  `backup-restore` capability. Spinup/Pressable get **no** restore UI for Glacier.
- Operator types the site domain to confirm (same pattern as site archive). Optional note.
- Restore never deletes the S3 object, never changes ingest exclusions, never enrolls/unenrolls.
- No cross-domain restore, no search-replace, no host-snapshot restore, no >5GB multipart, no
  auto-rollback, no clone.
- Assume the Control→Companion HTTP request dies at ~60s (nginx proxy) even though PHP gets
  `set_time_limit(900)`. Everything long-running must survive a dead HTTP connection.

### Locked design decisions (do not re-litigate; flag to Aaron if one proves impossible)

**D1 — Per-archive sha256 via S3 sidecar, and restore requires a known hash.**
- Going forward, after a successful archive, Control writes a tiny sidecar object next to the zip:
  `{archiveKey}.sha256.json` containing `{"sha256": "...", "size_bytes": N, "created_at": "ISO8601"}`.
  Write it in `ArchiveSiteBackupJob::markArchived()` (or immediately after the adapter returns) via
  `Storage::disk($enumerator->diskName())->put(...)` — IAM allows PutObject under the archive prefix.
  `BackupArchiveEnumerator` already skips `*.json` in listings, so sidecars stay invisible; nothing
  else changes.
- At restore time, expected hash resolution order: (1) read the sidecar for the selected key;
  (2) if the selected key is the site's **newest** archive and `backup_relay_last_sha256` is set, use
  that (this covers site 315's existing archive); (3) otherwise **no hash → no restore**: the UI shows
  the Restore button disabled with "No integrity hash on record for this archive", and the server path
  422s. This satisfies "apply refuses when hash was never verified" without a size-only loophole.
  Older hashless archives become restorable as new sidecars accumulate.

**D2 — Companion routes: three sub-routes, not one action-switch route.** Matches the existing
`/resource/sub-action` convention (`/backup/create`, `/plugins/update`):
- `POST /wp-json/clockwork/v1/backup/restore/stage`
- `GET  /wp-json/clockwork/v1/backup/restore/status`
- `POST /wp-json/clockwork/v1/backup/restore/apply`
All with `permission_callback => [HmacVerifier::class, 'verify']`. Status is a GET **deliberately** —
GETs don't burn the HMAC replay guard, so Control can poll every few seconds.

**D3 — Survive the 60s proxy with `ignore_user_abort` + polled status, not WP-cron.** Companion has
zero WP-cron usage today; don't introduce it. Stage and apply handlers each do:
`ignore_user_abort(true); @set_time_limit(900); @ini_set('memory_limit','512M');`, take an
`OperationLock` (`src/Support/OperationLock.php`, 429 `{'ok':false,'error':'busy'}` on contention,
release in `finally`), and write progress into a state option (`clockwork_companion_restore_state`)
as they go. If nginx kills the response at 60s, PHP-FPM keeps running and Control learns the outcome
from `/backup/restore/status`. Control sends the initiating POST with `timeout => 55, retries => 0`
(model: `ClockworkCompanionClient::createBackup()` — `retries => 0` means exactly one attempt, which
is what you want for a non-idempotent mutation) and treats a timeout as "poll status", not "failed".

**D4 — Control drives the whole flow from a background console command, not inline in the request.**
`QUEUE_CONNECTION=sync` in prod, so the established pattern is `BackgroundArtisan` (see
`SitesController::runBackupNow()` at `app/Http/Controllers/SitesController.php:2136` — copy it).
One command, `clockwork:backup-restore {--site=} {--key=} {--phase=stage|apply}`, launched via
`BackgroundArtisan->start('backup_restore.site.'.$site->id, [...], 3600, 'backup-restore-site-'.$id)`.
The `Cache::add` lock inside BackgroundArtisan doubles as the per-site in-flight guard the source plan
asked for — **no new table, no new lock subsystem**. Restore progress for the UI lives in
`Cache::put("backup_restore.site.{$site->id}.state", [...], now()->addHours(2))`, updated by the
command; durable audit lives in ActionLog rows.

**D5 — SQL import scope: current-prefix tables only, fail closed.** `DatabaseDumper` dumps **every
table in the database** (`SHOW TABLES`, no prefix filter) — on a shared DB the dump can contain
neighbours' tables. Apply must parse the target table from each `DROP TABLE IF EXISTS \`x\``,
`CREATE TABLE \`x\``, `INSERT INTO \`x\`` statement and execute **only** statements whose table starts
with the current `$wpdb->prefix`; pass through `SET ...` statements; count and report
`skipped_tables`. If **zero** dumped tables match the current prefix → fail closed, leave maintenance
on, report `prefix_mismatch` (per the source plan). Known v1 limitation to document, not fix: the dump
uses `esc_sql` string escaping with no `_binary`/hex handling, so binary column data doesn't
round-trip cleanly.

**D6 — File apply is copy-over, not mirror.** Copy staging `wp-content/` over live `WP_CONTENT_DIR`
(files added to the site after the backup are NOT deleted — document this limitation explicitly in
the docs page). Never copy `wp-config.php` (it sits at the staging root, outside `wp-content/` —
just don't touch it). Skip on copy: `uploads/clockwork-backups/` (the staging area itself),
`upgrade/`, cache dirs (reuse `BackupArchiver::DEFAULT_EXCLUDES` names), `debug.log`. Copy
Companion's own directory (`CLOCKWORK_COMPANION_DIR`) **last**, and never delete it.

**D7 — Maintenance mode is flipped in-process** via `MaintenanceGuard` option writes (the
`clockwork_companion_maintenance_mode` option), not via a second HTTP call.
`MaintenanceGuard::shouldBypass()` already whitelists `/wp-json/clockwork/` paths, so the restore
routes and status polls cannot lock themselves out. Maintenance lifts **only on full success**; any
failure after SQL import began leaves it on. (Bonus: Control's existing `stuck_maintenance` Issues
section will surface a site left in maintenance.)

### Companion implementation (target v1.37.0)

New files under `src/Backup/` and `src/Rest/` — follow the existing single-class-per-route shape.

1. **`src/Backup/Paths.php`** — extract the staging-dir logic currently inlined in
   `BackupCreateRoute.php:55-61` into a shared helper: `stagingDir(): string` (wp_upload_dir +
   `/clockwork-backups`, `0755`, drop `index.php` + `.htaccess Deny from all` on create, use the
   race-safe `! @mkdir(...) && ! is_dir(...)` idiom) and `sweepStale(int $olderThanSeconds): int`
   (unlink `db-*`, `backup-*`, `restore-*` older than the cutoff — today an OOM/timeout leaves
   orphans forever). Refactor `BackupCreateRoute` to use it; call `sweepStale(86400)` at the top of
   stage.
2. **`src/Backup/ArchiveDownloader.php`** — curl download to file (`CURLOPT_FILE`), https-only URL
   validation, progress callback (update state every few seconds / few MB), **resume support**: if a
   partial temp file for the same `archive_key` exists, send a `Range` header and append. Static
   `public static $testDownloader` test seam, mirroring `S3DirectUploader::$testUploader`
   (`src/Backup/S3DirectUploader.php:13`).
3. **`src/Backup/ArchiveExtractor.php`** — `ZipArchive::extractTo()` when the class exists, else
   PclZip `->extract(PCLZIP_OPT_PATH, $dir)` **one-shot** (load
   `ABSPATH.'wp-admin/includes/class-pclzip.php'` like `BackupArchiver.php:185`). The PclZip path is
   the one that runs on the live proof host — it must be first-class, not an afterthought.
4. **`src/Backup/RestoreState.php`** — thin wrapper over a single option
   `clockwork_companion_restore_state`: `{staged_id, phase, status, archive_key, expected_sha256,
   actual_sha256, hash_verified, bytes_total, bytes_done, has_sql, has_files, table_prefix,
   skipped_tables, error, updated_at}`. Statuses: `downloading → verifying → extracting → scanning →
   staged` then `applying_sql → applying_files → finalizing → applied`, or `failed` (+ `error` code:
   `bad_url`, `download_failed`, `hash_mismatch`, `extract_failed`, `prefix_mismatch`, `sql_failed`,
   `files_failed`).
5. **`src/Backup/SqlImporter.php`** — stream the gzipped dump (`gzopen`/`gzgets`), buffer to
   statement boundaries (`;\n` — the dump format is Companion's own, one `CREATE` per table and one
   multi-row `INSERT` per 500-row batch, no column list), apply decision D5, execute via
   `$wpdb->query()`. The dump filename is random (`database/db-{token}.sql.gz`) — enumerate the
   `database/` folder in staging, don't hardcode.
6. **`src/Rest/BackupRestoreRoute.php`** — registers the three routes (D2).
   - **stage**: params `download_url` (required, `FILTER_VALIDATE_URL` + https, else
     `WP_Error('invalid_download_url', 400)`), `archive_key` (required string), `expected_sha256`
     (required — see D1), `expected_bytes` (int, optional). Lock → sweep → download (resume-aware) →
     `hash_file('sha256', ...)` verify (mismatch: delete file, state `failed/hash_mismatch`) →
     extract to `restore-{staged_id}/` → pre-scan (`has_sql`: any `database/*.sql[.gz]`; `has_files`:
     `wp-content/` dir present; `table_prefix`: first `DROP TABLE IF EXISTS`/`CREATE TABLE` name in
     the dump) → state `staged`. Response when the connection survives:
     `{ok, staged_id, bytes, sha256, has_sql, has_files, table_prefix}`.
   - **status**: returns the state option plus a liveness sanity check (staging dir still exists).
   - **apply**: params `staged_id` (must match state, status must be `staged`, `hash_verified` must
     be true — else `WP_Error` 409/422). Lock → maintenance option ON (D7) → SQL import (D5; on
     failure: state `failed/sql_failed`, maintenance stays on, return) → file copy-over (D6; same
     fail-closed) → flush (`wp_cache_flush()`, reuse whatever `CacheFlushRoute` calls) → maintenance
     OFF → delete staging dir + zip → state `applied`. On failure, staging is kept for diagnosis.
7. **`src/Plugin.php`**: add `'backup-restore'` to `Plugin::CAPABILITIES` (with the `// 1.37.0`
   inline comment style) and register the route in the `rest_api_init` closure. Only `/health`
   publishes capabilities — that's fine; Control refreshes them via
   `clockwork:refresh-companion-capabilities`.
8. **Version bump** to 1.37.0 (plugin header + `CLOCKWORK_COMPANION_VERSION`), CHANGELOG.
9. **Tests** (`tests/BackupRestoreRouteTest.php`, modeled on `tests/BackupCreateRouteTest.php` —
   direct `$route->handle($request)` calls, anonymous-class `$wpdb`, real zips built in-test):
   - stage rejects a non-https / invalid URL; rejects missing `expected_sha256`.
   - stage with `$testDownloader` writing a real zip: wrong hash → `failed/hash_mismatch`, temp file
     unlinked; right hash → `staged`, staging dir populated, `has_sql`/`has_files`/`table_prefix`
     correct.
   - apply rejects unknown `staged_id`; rejects when `hash_verified` is false.
   - apply with a fixture dump containing a foreign-prefix table: foreign statements skipped and
     counted; all-foreign dump → `prefix_mismatch`, maintenance option still enabled.
   - status returns the state; concurrent stage while lock held → 429 busy.

### Control implementation

1. **Sidecar write (D1):** in `modules/BackupRelay/src/Jobs/ArchiveSiteBackupJob.php`, after a
   successful Companion upload (the adapter result already carries `sha256` + `size_bytes`), `put`
   the `{key}.sha256.json` sidecar on the enumerator's disk. Non-fatal on failure (log, don't fail
   the job).
2. **`ClockworkCompanionClient`** (`app/Services/Companion/ClockworkCompanionClient.php`): three new
   methods next to `createBackup()`:
   `stageBackupRestore(array $payload): array` → `postJson('/backup/restore/stage', $payload,
   ['timeout' => 55, 'retries' => 0])`; `backupRestoreStatus(): array` →
   `getJson('/backup/restore/status')`; `applyBackupRestore(string $stagedId): array` →
   `postJson('/backup/restore/apply', ['staged_id' => $stagedId], ['timeout' => 55, 'retries' => 0])`.
3. **Console command `clockwork:backup-restore`** (`app/Console/Commands/BackupRestoreCommand.php`,
   attribute-style `#[Signature('clockwork:backup-restore {--site=} {--key=} {--phase=}')]`):
   - Common guards: site exists, `isCustom()`, `backup_relay_enabled`,
     `in_array('backup-restore', $site->companion_capabilities ?? [], true)`,
     `$enumerator->supportsPresignedUrls()`.
   - `--phase=stage`: resolve expected sha256 per D1 (sidecar → last-archive fallback → abort);
     mint presigned GET via `BackupArchiveEnumerator::getDownloadUrl()` (never hardcode a prefix);
     call `stageBackupRestore()`; on response OR timeout, poll `backupRestoreStatus()` every 5s
     (cap ~10 min) until `staged`/`failed`; mirror each transition into the
     `backup_restore.site.{id}.state` cache entry; ActionLog `backup_restore_staged` or
     `backup_restore_failed` (details: archive key, sha256, actor passed via `--actor=` option).
   - `--phase=apply`: read staged_id from the cache state (must be `staged`); call
     `applyBackupRestore()`; poll until `applied`/`failed`; ActionLog `backup_restore_applied` /
     `backup_restore_failed`. On failure, set state flag `maintenance_left_on = true` when the
     Companion status shows the failure happened after maintenance was enabled.
4. **`ActionLog`**: add constants `TYPE_BACKUP_RESTORE_STAGED = 'backup_restore_staged'`,
   `TYPE_BACKUP_RESTORE_APPLIED = 'backup_restore_applied'`,
   `TYPE_BACKUP_RESTORE_FAILED = 'backup_restore_failed'` in `app/Models/ActionLog.php`, plus
   human labels in `MaintenanceHistoryController`'s constant→label map.
5. **Controller + routes** (in `SitesController`, routes in the site-actions block of
   `routes/web.php` ~lines 278–331):
   - `POST /sites/{site}/backup-relay/restore/stage` → `sites.backup-relay.restore.stage`
   - `POST /sites/{site}/backup-relay/restore/apply` → `sites.backup-relay.restore.apply`
   - `GET  /sites/{site}/backup-relay/restore/status` → `sites.backup-relay.restore.status`
     (two path segments after `{site}`, so the `sites.show` `/{tab?}` catch-all can't swallow it)
   - Stage validates `['archive_key' => required|string, 'confirm_domain' => required|string,
     'note' => nullable|string|max:255]`; domain check is
     `hash_equals($site->domain, $validated['confirm_domain'])` →
     `back()->with('status_error', 'Confirmation text did not match the site domain.')` on mismatch
     (exact `SitesController::archive()` pattern, line ~1713). Guards: `abort_unless($site->isCustom(), 403)`,
     422 JSON/redirect when relay disabled, capability missing, presign unsupported, or no known
     sha256 for the key — **no S3 GET is ever minted in those cases**.
   - Both mutating actions launch the command via `BackgroundArtisan` (copy `runBackupNow()`,
     including the `alreadyRunning() → 409` and dual JSON/redirect response). The status route just
     returns the cache state JSON.
6. **Overview widget** (`resources/views/dashboard/site/widgets/_widget-backups.blade.php`, custom
   branch only, archive rows ~lines 286–306 next to the existing Download link):
   - Per-archive **Restore…** button → confirm panel (type domain, optional note) → posts stage.
   - Poll `sites.backup-relay.restore.status` while a restore is in flight; disable all
     backup/restore buttons during it (the 409 from `BackgroundArtisan` is the backstop).
   - When state is `staged`: show "Staged — ready to apply" with an **Apply restore** button (second
     confirm) and a discard option (just clears the Control cache state; Companion staging is swept
     by `sweepStale`).
   - When state failed with `maintenance_left_on`: render a red banner on the widget ("Restore
     failed after import began — site left in maintenance mode; investigate before lifting it").
   - Archives with no known sha256: Restore button disabled + tooltip.
7. **Pest tests** (`tests/Feature/` — `Http::fake()` for Companion, follow existing fixture style:
   PHP helpers, not JSON files):
   - confirm-domain mismatch → `status_error`, `Http::assertNothingSent()`, no BackgroundArtisan run.
   - custom + relay + capability + sidecar happy path: stage endpoint 200, command (invoked
     synchronously in the test) transitions state to `staged`, ActionLog row written.
   - Spinup/Pressable site → no Restore UI (view assertion) and 403/422 on direct POST.
   - missing `backup-restore` capability → 422, nothing sent.
   - no presign support (non-s3 disk) → 422; no sha256 on record → 422.
   - apply failure path (Companion status returns `failed/sql_failed`) → state carries
     `maintenance_left_on`, ActionLog `backup_restore_failed`.
   - restore lock held → 409.
8. **Docs:**
   - `resources/docs/features/backup-relay.md`: new "Restore" section (operator-confirm, custom-only,
     two-step stage/apply, fail-closed maintenance, copy-over limitation, sha256 requirement, what v1
     will not do). **Delete/rewrite line ~185** ("restore is download from Glacier when you need it" —
     no longer true). Extend `tracks:` with the new command + route surface; bump `updated:`.
   - Check `resources/docs/reference/api-endpoints-we-call.md` — it tracks
     `ClockworkCompanionClient.php`; add the three new Companion endpoints wherever that page lists
     the Companion HMAC surface. Bump `updated:`.
   - CHANGELOG (both repos): "restore completes the Glacier lifecycle; it does not delete the host
     site or the S3 object."

---

## Workstream 2A — WP.org closed / zombieware plugins

**Problem recap:** a closed WP.org slug offers no newer version, so everything shows "up to date."
API: `https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]={slug}`.
Closed slugs return `{"error":"closed","description":"..."}` — parse `error === 'closed'`; a missing
slug returns a different error string → `not_found`. **`not_found` ≠ `closed`** (premium/custom
plugins must never alert). Only `api.wordpress.org/core/checksums/` is called today
(`WpCoreChecksumVerifier`) — there is no plugin-info client; build one.

1. **Migration** `create_plugin_directory_statuses_table` (anonymous-class style, round timestamp):
   `slug` string unique, `status` string (`open|closed|not_found|error`), `reason` text nullable
   (the API `description` for closed slugs), `closed_date` string nullable if the API provides it,
   `checked_at` timestamp. Model `app/Models/PluginDirectoryStatus.php` + factory (a factory is
   required — `AllModelFactoriesRoundTripTest` covers every model).
2. **Client** `app/Services/Security/PluginDirectoryClient.php` — model it on
   `WpVulnerabilityClient`: `Http::timeout(10)->retry(2, 500, throw: false)`, a
   `Clockwork-Monitoring/1.0` UA, `usleep(100 * 1000)` between requests on both success and failure
   paths, upsert rows per slug (don't truncate — statuses should persist through partial failures;
   mark unreachable slugs `error` without clobbering a previous `closed`).
3. **Slug discovery:** reuse the exact dedupe approach of `WpVulnerabilityClient::discoverSlugs()`
   (chunk sites where `companion_snapshot` not null, read `$snap['plugins']['plugins']`,
   `strtok($p['slug'], '/')`, dedupe) but restrict to **active, non-archived** sites
   (`is_inactive = false`; archived sites are already hidden by the global scope). One HTTP call per
   unique slug fleet-wide, never per site.
4. **Command** `app/Console/Commands/RefreshClosedPlugins.php`,
   `#[Signature('clockwork:refresh-closed-plugins')]`, method-inject the client, progress bar.
   Schedule in `routes/console.php`: `weeklyOn(1, '03:30')->withoutOverlapping(60)->onOneServer()
   ->runInBackground()` (03:20–03:40 is a free slot; keep the explanatory comment style used at
   lines 453–463).
5. **Matching service** `app/Services/Security/ClosedPluginAuditor.php` —
   `forSites(iterable $sites): array<int siteId, list<finding>>` reading the same snapshot shape as
   `PluginVulnerabilityMatcher` (`companion_snapshot['plugins']['plugins']`, field is **`active`**,
   not `is_active`; dir-slug via `strtok`). A finding = an **active** (activated) installed plugin
   whose slug status is `closed`. Load the whole status table once (`where('status','closed')`),
   match in PHP — no JSON_EXTRACT SQL (SQLite tests).
6. **Issues integration** — new category `plugins_closed`, four touch points, each with the literal
   `// KEEP IN SYNC with App\Support\IssueCounter::total().` comment convention:
   - `IssueCounter::calculateTotal()`: count sites (`is_inactive = false`) having ≥1 finding —
     compute via the auditor in PHP, mirroring however `plugins_outdated` handles serverless custom
     sites (do NOT add a `whereHas('server')` that would exclude custom sites). Routine-maintenance
     class issue → **is_inactive suppresses it** (unlike malware).
   - `IssuesController::index()`: same query mirrored, `$totals['plugins_closed']`, pass findings to
     the view.
   - Blade chip in `resources/views/dashboard/issues.blade.php:11-33` + new
     `<section id="section-plugins_closed">` following the standard card/table shape (`sortableTable`,
     `x-sort-th`). Title: "Plugin closed on WordPress.org". Show the API `description` (reason) —
     trademark/author-request closures are informative, not zero-days; **no "malware" framing** unless
     the description itself says security.
   - **Do not** reuse `$vulnsBySiteId` or gate on pending updates — closed plugins usually have none.
   - Per-site ignore: extend `IgnoredIssue` with `TYPE_PLUGIN_CLOSED = 'plugin_closed'`, add it to the
     `in:` whitelist in `IssuesController::ignore()` (line ~433), reuse the existing inline
     ignore-form pattern (`issues.blade.php:397-399`), and exclude ignored site_ids in both counter
     and controller (SEO's `whereNotIn` subquery pattern at `IssueCounter:102`).
7. **Tests** (Pest, `Http::fake` with file-local helpers like
   `RefreshPluginVulnerabilitiesTest.php`): closed + open + not-found responses; two sites sharing a
   slug → exactly one HTTP call (`Http::assertSentCount`); closed active plugin → site appears in
   count/section; same plugin deactivated → no finding; `is_inactive` site excluded; premium
   `not_found` slug → no alert; refresh idempotent; ignore/unignore round-trip.
8. **Docs:** section under `resources/docs/features/security-scans.md` (its `tracks:` glob
   `app/Services/Security/**` auto-covers the new client/auditor — verify) or
   `wordpress-plugin-inventory.md`; make sure the **command** file is covered by some page's
   `tracks:`. Prose entry in `api-endpoints-we-call.md` (name the client file, "no auth, keyless,
   100ms spacing, weekly Monday 03:30"). Row in `resources/docs/reference/scheduled-jobs.md`.
   Optional but cheap: a `DiagnosticCheck` probing `plugins/info/1.2` with a known slug, registered
   in `DiagnosticsController` (pattern: `WpVulnerabilityCheck`). CHANGELOG.

---

## Workstream 2B — CISA KEV badge

API: `https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json` (free,
no key, ~1.7k entries). JSON feed only — never scrape HTML.

1. **Migration** `create_cisa_kev_entries_table`: `cve` string unique, `vendor_project` string,
   `product` string, `date_added` date, timestamps. Model + factory.
2. **Client** `app/Services/Security/CisaKevClient.php` — single fetch
   (`Http::timeout(30)->retry(2, 500, throw: false)`), then the `WpVulnerabilityClient` swap pattern:
   `DB::transaction` + `DELETE FROM` (not TRUNCATE — the comment in `WpVulnerabilityClient:84-92`
   explains why) + chunked insert. Idempotent by construction.
3. **Command** `clockwork:refresh-cisa-kev`, scheduled
   `dailyAt('03:20')->withoutOverlapping(30)->onOneServer()->runInBackground()` — right next to the
   03:15 vuln-mirror refresh it enriches.
4. **Matching is query-time, no column on `plugin_vulnerabilities`** (that table is truncated and
   reinserted daily, so a stored flag would be wiped; `cve` is already indexed). Where the Issues
   controller/email already has `$vulns` per site: collect the CVE strings, one
   `CisaKevEntry::whereIn('cve', $cves)->pluck('cve')` per render, pass the set to the view.
   `PluginVulnerabilityMatcher::forSite()` remains the gate — a KEV CVE with no installed vulnerable
   slug+version match must produce nothing.
5. **UI:** in the vuln modal in `resources/views/dashboard/issues.blade.php`, next to the CVE link at
   ~line 1797 (inside the existing pill row at 1806–1830): a red pill
   `Actively exploited (CISA KEV)` styled like the existing `status-pill status-red` usage. Same
   badge in `resources/views/emails/site-vulnerability-report.blade.php` (CVE link at 113–115).
   Copy nowhere promises queue-wide prioritization — the WP-plugin ∩ KEV intersection is small; it
   highlights the rare hit.
6. **Tests:** fixture KEV JSON (file-local helper) with one CVE that matches a mirrored
   `PluginVulnerability` + an installed in-range plugin version → badge rendered; CVE in KEV but
   plugin not installed / version out of range → no badge, no issue; refresh idempotent (run twice,
   same row count).
7. **Docs:** paragraph on the plugin-vuln portion of the Issues/security docs page; prose entry in
   `api-endpoints-we-call.md`; row in `scheduled-jobs.md`; optional `DiagnosticCheck`. CHANGELOG.

---

## Workstream 3 — PHP (and WP) EOL on Capacity

API: `https://endoflife.date/api/v1/products/php` and `/api/v1/products/wordpress` — **v1 schema, no
`.json` suffix** (the old roadmap URL 404s). Before coding, fetch the live endpoint once and pin your
fixture to the real response shape (release entries carry the cycle name plus EOL / security-support
dates and boolean flags; `plans/gemini-research-assessment.md` has notes). No API key.

**Where versions already live:** `companion_snapshot['environment']['php_version']` (rendered today
in `_widget-forms.blade.php:67` and `ClientReportCompiler.php:56`) and
`['environment']['wp_version']`. There is **no server-level PHP source** — do not add an SSH probe;
site-level is v1.

1. **Fetch + cache** — `app/Services/Runtime/EndOfLifeClient.php` + command
   `clockwork:refresh-runtime-eol` scheduled `dailyAt('05:10')` (05:05–06:00 is free). Store the
   parsed cycle tables in `app_settings` via `app/Support/Settings.php`
   (`runtime_eol.php_cycles`, `runtime_eol.wordpress_cycles`, `runtime_eol.fetched_at`) — the
   Capacity page **only reads Settings, never fetches** (test cache store is `array`, and the page
   must never block on HTTP). If a fetch fails, keep the previous value and just don't bump
   `fetched_at`.
2. **Evaluator** `app/Services/Runtime/RuntimeEolEvaluator.php`: normalize `8.1.2 → 8.1`
   (major.minor) for cycle matching; classify each site as `eol` / `security_only` /
   `active_support` / `unknown` (no snapshot or unmatched cycle → omitted/unknown). Pure function of
   (cycles, version, today) so tests pin `Carbon::setTestNow()`.
3. **Capacity card** (`CapacityController::index()` + `resources/views/dashboard/capacity.blade.php`):
   a compact "Runtime EOL" section following the existing `<section id="…">` card pattern — count
   pills (EOL / security-only / current / unknown) and a sortable click-through table of domains +
   version + "security support ended {date}" / "ends {date}". **Supply the new view vars on BOTH
   return paths** — `index()` has an early return at lines 86–97 when the `Shared` tag is missing.
   When `runtime_eol.fetched_at` is absent or >48h old, render the card with an "EOL data stale /
   unavailable" note — never 500, never fetch inline.
4. **Site pill:** in `_widget-forms.blade.php` next to the existing PHP version at line 67, a quiet
   amber/red pill when that site's PHP is security-only/EOL. **No Issues section in v1** (keeps
   inactive/staging noise down, per the source plan).
5. **Copy:** facts only — version, support-end date, days since/until. No "retainer generator"
   language.
6. **Tests:** fixture cycles + `Carbon::setTestNow()`: a site on 8.1 classifies as security-only or
   EOL depending on pinned dates; site without snapshot omitted; Settings empty → Capacity renders
   with the stale note (no 500, `Http::assertNothingSent()` on page load); refresh command failure
   leaves previous Settings value intact.
7. **Docs:** section in `resources/docs/features/traffic-and-capacity.md` (already tracks
   `CapacityController` — extend `tracks:` for the new service/command), prose entry in
   `api-endpoints-we-call.md`, row in `scheduled-jobs.md`. CHANGELOG.

---

## Schedule slots claimed by this cycle (routes/console.php)

| Time | Command | Cadence |
|---|---|---|
| 03:20 | `clockwork:refresh-cisa-kev` | daily |
| 03:30 | `clockwork:refresh-closed-plugins` | weekly, Monday |
| 05:10 | `clockwork:refresh-runtime-eol` | daily |

All with `->withoutOverlapping()->onOneServer()->runInBackground()` and a why-comment, matching the
existing entries at `routes/console.php:447-519`.

---

## Cross-cutting conventions crib sheet (verified, follow exactly)

- **Console commands:** attribute style (`#[Signature]`, `#[Description]`), services method-injected
  into `handle()`, `return self::SUCCESS`, live in `app/Console/Commands/`, `clockwork:` prefix.
- **Migrations:** anonymous `return new class extends Migration`, round timestamps for hand-written
  files, named composite indexes, heavy why-comments.
- **HTTP fixtures in Control tests:** PHP helpers (file-local functions or `tests/Fixtures/*` static
  classes returning arrays) + `Http::fake()` — **not** JSON files on disk.
- **Companion tests:** plain PHPUnit, call `$route->handle(new WP_REST_Request(...))` directly,
  `$GLOBALS['wpdb']` anonymous class, static `$test*` seams for network I/O, reset globals in
  `setUp()`/`tearDown()`.
- **SQLite test DB:** no MySQL-only SQL in anything you want test-covered; prefer PHP-side filtering
  of `companion_snapshot`.
- **Issues page:** every new category = counter + controller mirror (+ `KEEP IN SYNC` comments) +
  `$totals` key + chip + `<section id="section-{key}">`; `is_inactive` suppresses routine-maintenance
  categories only.
- **Docs:** filesystem-discovered (no index to register); required frontmatter
  `title, section, order, updated, author, tags`, optional `tracks:` globs; bump `updated:` when you
  touch a page or StalenessChecker flags it; CoverageChecker flags untracked new
  commands/controllers/clients.
- **ActionLogger:** inject `ActionLogger`, call `->record(actionType:, summary:, site:, target:,
  details:, actor:)`; it never throws and mirrors to Companion automatically.

---

## Acceptance (human — Aaron runs these)

- Restore a staging copy of a custom site (or site 315's archive onto a throwaway) through Control:
  confirm domain, stage completes (poll survives a killed HTTP response), apply brings files + DB,
  maintenance lifts only on success.
- A 504 during stage never applies a partial zip; re-staging the same key resumes or replaces the
  temp file.
- Spinup/Pressable Overview shows no Restore for Glacier; host backups untouched.
- Closed plugin: snapshot-fixture a closed slug → Issues section + badge count; a premium
  off-directory slug does not appear.
- KEV: fixture a matching CVE → red badge on the Issues vuln modal and the vuln email; no match →
  no badge.
- Capacity: a site with snapshot PHP 8.0/8.1 shows in the EOL breakdown with zero live HTTP on page
  load; endoflife.date being down shows "EOL data stale", not a 500.
