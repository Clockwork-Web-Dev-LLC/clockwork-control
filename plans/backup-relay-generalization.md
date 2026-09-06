# Plan: Generalize backup relay beyond Pressable, beyond one agency

## Context

"Backup relay (Pressable → S3 Glacier)" exists today to work around ManageWP's 90-day backup
retention limit. But as currently built it (a) only ever selects Pressable sites, and (b) depends
entirely on a **separate, private, out-of-repo project** (`clockwork-backup-relay`) running on its
own DigitalOcean droplet with its own AWS credentials to do the actual archiving. This repo's only
role today is writing/reading two small JSON control files in S3 (`targets.json`, `last-report.json`)
— a deliberately indirect handoff, chosen because this specific app instance isn't internet-facing.
Neither constraint (Pressable-only, external-droplet-required) is acceptable once other agencies are
expected to self-host this product: they can't just "turn the feature on," they'd need to fork and
run a second private codebase.

**Current implementation (confirmed):**
- `app/Console/Commands/PushBackupRelayTargets.php` (scheduled 04:58 UTC daily) — hardcodes
  `Site::where('hosting_provider', Site::HOSTING_PROVIDER_PRESSABLE)->where('care_plan_enabled',
  true)`, writes `{site_id, pressable_site_id, domain}` to S3.
- The external droplet's own cron (Sun+Wed) reads that, archives each site's newest Pressable
  backup to Glacier IR, writes `last-report.json` back.
- `app/Console/Commands/PullBackupRelayReport.php` (scheduled 06:40 UTC daily) — reads the report,
  dedupes by `finished_at`, records a `BackupRelayRun` row (`app/Models/BackupRelayRun.php`,
  table `backup_relay_runs`), updates `App\Support\Settings` keys, does staleness alerting via
  `ChatNotifier` (same silence-detection pattern as `clockwork:detect-stuck-companion-state`).
- Both commands are scheduled from `modules/Pressable/src/PressableServiceProvider::scheduledTasks()`
  — scheduling lives inside the Pressable module, not anywhere generic.
- `BackupRelayRun` and the `backup_relay.*` config/Settings namespace are **already**
  provider-agnostically named — only the site-selection query and the scheduling location are
  Pressable-specific.
- Site selection reuses the unrelated `sites.care_plan_enabled` boolean as its enablement flag —
  there is no dedicated `backup_relay_enabled` column.
- `modules/Core/src/Contracts/HostingProvider.php` already defines capability constants
  (`CAP_SSH`, `CAP_SERVER_LINKAGE`, `CAP_CERT_SYNC`, `CAP_PERFORMANCE_SCAN`, `CAP_ORPHAN_DETECTION`,
  `CAP_COMPANION`) plus `supports()`/`commandRunner()`/`companionInstaller()`/`panelUrl()`, each
  implemented per provider module — but has **no backup-related capability at all** today.

## Recommendation

### (A) Provider generality

Add `HostingProvider::CAP_BACKUP_RELAY` plus a new `backupRelayAdapter(): ?BackupRelayAdapter`
method to the existing contract (null by default, following the existing null-capability pattern
used by `commandRunner()`/`companionInstaller()`). Each provider module that supports remote backup
archiving implements a small adapter describing how to locate and stream that provider's backups.
`PushBackupRelayTargets` stops hardcoding Pressable and instead iterates the `HostingProviderRegistry`,
filtering to providers where `supports(CAP_BACKUP_RELAY)`, asking each adapter for its
site-selection/metadata. Replace the reused `care_plan_enabled` flag with a dedicated
`sites.backup_relay_enabled` boolean column — **backfilled** from
`hosting_provider = pressable AND care_plan_enabled = true` so nothing currently relying on the
feature silently stops working.

### (B) Multi-agency portability — bring archiving in-repo as the default, keep the external-agent mode as an opt-out

A brand-new agency installing this product cannot be expected to stand up and maintain a second
private codebase on a second droplet just to get backup archiving working — that's an adoption
blocker for the entire feature, not a nice-to-have. The actual work (download a backup stream,
multipart-upload to S3 with `GLACIER_IR` storage class) is ordinary Laravel queue-job territory once
it's provider-adapter-driven instead of Pressable-API-specific — and this repo already runs a queue
and already holds AWS-shaped credentials for other things.

So: build `modules/BackupRelay/` as a real module containing a queued `ArchiveSiteBackupJob` that
calls the provider adapter directly and uploads to S3 itself, driven by the app's own scheduler —
zero external infrastructure required, "just add S3 credentials to `.env`." For the original agency
(and any future agency with the same not-internet-facing constraint the current docs describe), the
existing external-droplet/indirect-S3-handoff mode is **not deleted** — it becomes an explicit
second `backup_relay.mode` (`in_repo` default vs `external_agent`), still driven off the same
generalized, schema-versioned `targets.json`/`last-report.json`, so the current live droplet keeps
working completely unmodified through the whole migration. Both modes write to the same
`backup_relay_runs` table, so history and the settings UI stay mode-agnostic.

## Phased implementation

**Phase 1 — Contract, schema, backward-compatible backfill**
1. `modules/Core/src/Contracts/HostingProvider.php` — add `CAP_BACKUP_RELAY` constant and
   `backupRelayAdapter(): ?BackupRelayAdapter` (null default).
2. New `modules/Core/src/Contracts/BackupRelayAdapter.php` — `latestBackupRef(Site $site): ?BackupRef`
   (identify the newest backup without downloading it) and
   `openBackupStream(Site $site, BackupRef $ref)` (used only by in-repo mode). `BackupRef` carries
   `{provider, external_id, created_at, size_bytes}`.
3. New migration: add nullable `backup_relay_enabled` boolean (default `false`) to `sites`, matching
   the style of the existing `add_care_plan_enabled_to_sites` migration.
4. New data-only migration: `UPDATE sites SET backup_relay_enabled = true WHERE hosting_provider =
   'pressable' AND care_plan_enabled = true` — the load-bearing backward-compat step, preserving
   today's exact site population automatically.
5. `app/Models/Site.php` — add `backup_relay_enabled` to casts/fillable, plus a
   `scopeBackupRelayEnabled()` local scope.
6. Cut `PushBackupRelayTargets` over to the new `backup_relay_enabled` flag alone first (no provider
   generality or mode work yet) — ship and independently verify this step before anything else
   lands.

**Phase 2 — In-repo `modules/BackupRelay/` module (default mode), proven with a second provider**
1. `modules/BackupRelay/composer.json` — standard module shape (`clockwork/backup-relay`,
   PSR-4 `Modules\BackupRelay\`, requires `clockwork/core`).
2. `modules/BackupRelay/src/BackupRelayServiceProvider.php extends Modules\Core\ModuleServiceProvider`
   — registers `scheduledTasks()` (moved here from `PressableServiceProvider`, per the "module owns
   what it contributes" convention), `manifest()` (S3 bucket/region/prefix fields on
   `/settings/integrations`), `navItems()`.
3. `modules/BackupRelay/src/Jobs/ArchiveSiteBackupJob.php` — queued job: resolves the site's
   provider adapter, calls `latestBackupRef()`, skips if already archived (dedupe by
   `external_id`/`created_at`), else pipes `openBackupStream()` into a new
   `Modules\BackupRelay\src\Services\GlacierUploader` (S3 multipart upload, `GLACIER_IR` storage
   class) against a **new**, separately-configured `s3-backup-relay` disk (distinct from any
   general-purpose `s3` disk, so an agency's backup destination credentials stay cleanly separable).
4. `modules/BackupRelay/src/Console/RunBackupRelayNow.php`
   (`clockwork:backup-relay-run`) — the in-repo mode's scheduled entry point (replaces the external
   cron): dispatches one job per enabled site across every `CAP_BACKUP_RELAY` provider, writes a
   `BackupRelayRun` row directly (no S3 round-trip needed — it's the same process).
5. New `modules/SpinupWp/src/SpinupWpBackupRelayAdapter.php` — first non-Pressable adapter, wired
   into `SpinupWpHostingProvider::backupRelayAdapter()`. Chosen because SpinupWP already has a real
   server/SSH story in this codebase, proving the adapter shape isn't accidentally Pressable-shaped.
6. `config/clockwork.php` — add `backup_relay.mode` (`in_repo` default | `external_agent`),
   `backup_relay.schema_version` (starts at `2`), and an `s3-backup-relay` disk block in
   `config/filesystems.php` with its own env-driven credentials.

**Phase 3 — Generalize the control-channel schema, keep external-agent mode alive**
1. Rework the target-push command (`external_agent` mode only now) to iterate all
   `CAP_BACKUP_RELAY` providers, emitting `{schema_version: 2, sites: [{site_id, provider,
   external_ref: {...}, domain}]}` — `provider`+`external_ref` replace the hardcoded
   `pressable_site_id` field.
2. Rework the report-pull command to accept `schema_version` in `last-report.json`; `schema_version:
   1` (today's shape) is still accepted for a deprecation window, so the *existing* droplet keeps
   working completely unmodified. Log a one-time `ChatNotifier` deprecation notice on any v1 report
   seen.
3. Note (out of scope for this repo): the sister external-agent project should adopt schema v2 for
   multi-provider fields when convenient, but isn't required to for continued Pressable-only
   operation.

**Phase 4 — Settings UI**
1. `app/Http/Controllers/Settings/BackupRelaySettingsController.php` — index (per-site/provider
   toggle table, run history, current mode), update (bulk toggle), runNow (dispatches the in-repo
   job when `mode=in_repo`, shows "handled by external agent" when `mode=external_agent`).
2. `resources/views/settings/backup-relay.blade.php` — sites table (domain, provider, toggle, last
   archived timestamp), mode indicator, run-history list, "Run now" button gated to `in_repo` mode.
3. Routes: `GET/PATCH /settings/backup-relay`, `POST /settings/backup-relay/run-now`.

**Phase 5 — Docs + remaining provider adapters**
1. Rewrite `resources/docs/features/backup-relay.md` — drop the "Pressable-only, droplet-required"
   framing; document both modes, the schema-version bridge, the per-site toggle, and how an agency
   points at their own bucket (already just per-install env config, confirm and state explicitly).
2. Update `resources/docs/reference/env-vars.md` — new `S3_BACKUP_RELAY_*` disk vars,
   `CLOCKWORK_BACKUP_RELAY_MODE`; note `CLOCKWORK_BACKUP_RELAY_S3_PREFIX` is `external_agent`-mode
   only.
3. WP Engine, Kinsta, Cloudways adapters as non-blocking follow-ups via the existing
   CONTRIBUTING.md "adding a new hosting provider" recipe.

## Files

**Modified:** `modules/Core/src/Contracts/HostingProvider.php`,
`app/Console/Commands/PushBackupRelayTargets.php`,
`app/Console/Commands/PullBackupRelayReport.php`, `app/Models/Site.php`, `config/clockwork.php`,
`config/filesystems.php`, `resources/docs/features/backup-relay.md`,
`resources/docs/reference/env-vars.md`.

**New:** `modules/Core/src/Contracts/BackupRelayAdapter.php`, `modules/BackupRelay/**` (composer.json,
ServiceProvider, job, uploader service, console command), `modules/SpinupWp/src/SpinupWpBackupRelayAdapter.php`,
two migrations (add column, backfill), `app/Http/Controllers/Settings/BackupRelaySettingsController.php`,
`resources/views/settings/backup-relay.blade.php`.

## Verification

- **Capability-based selection** — a fake multi-provider registry test asserting sites are selected
  only when `backup_relay_enabled=true` AND the provider's `supports(CAP_BACKUP_RELAY)` is true.
- **Backfill migration test** — run against a seeded pre-migration state (Pressable+`care_plan_enabled`
  true, SpinupWP+`care_plan_enabled` true, Pressable+`care_plan_enabled` false) and assert only the
  first row flips `backup_relay_enabled` on.
- **In-repo archival job test** — `Storage::fake('s3-backup-relay')` + a stub `BackupRelayAdapter`,
  asserting the uploaded object key/storage-class and a resulting `BackupRelayRun` row.
- **Schema-version bridge test** — extend the existing report-pull test with `schema_version`
  absent/1/2 cases, asserting v1 still parses and v2 requires `provider` per target entry.
- **Manual verification** — toggle a site on in the new settings UI, click "Run now" against a fake
  disk locally, confirm a `backup_relay_runs` row and object appear; separately, replay a captured
  real v1 `last-report.json` fixture through the report-pull command to confirm the existing
  droplet's actual output is still accepted untouched.
