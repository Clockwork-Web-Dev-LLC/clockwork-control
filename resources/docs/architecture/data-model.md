---
title: Data model
section: Architecture
order: 20
updated: 2026-09-06
author: Aaron Reimann
tags: [architecture, database, schema, pressable, modules]
tracks: [database/migrations/**, app/Models/**]
---

A table-by-table map of the database. If you're trying to figure out where some piece of state lives, this is the page.

## Inventory tables

These are the natural-key tables — everything else hangs off them.

### `servers`

One row per managed host. Natural key: `spinupwp_id`. Notable columns:

- Identity: `name`, `hostname`, `spinupwp_id`.
- Cloud provider: `provider` (`digitalocean|hetzner|…`), `provider_id`, `size_slug`, `vcpus`, `memory_mb`, `disk_gb`. Populated daily by `clockwork:import-spinupwp` from SpinupWP's `provider_name` field, cross-referenced against the matching cloud's API.
- SSH: `ssh_user`, `ssh_port`, `ssh_private_key` (encrypted), `ssh_password` (encrypted).
- Monitoring state: `status` (`green|yellow|red|unknown`), `last_polled_at`, `last_alert_at`, `last_ssh_ok_at`.
- Provisioning: `clockwork_jail_provisioned_at`, `last_provision_log`.
- Operations: `is_ignored`, `ignore_reason`, `auto_ban_llar`, `auto_ban_wordfence`, `last_llar_pull_at`, `last_wordfence_pull_at`, `upgrade_required`, `reboot_required`, `update_status`, `scheduled_reboot_at`.

### `sites`

One row per WordPress (or non-WP) site. Natural key: `domain` (unique). Notable columns:

- Linkage: `server_id` (**nullable** — null for Pressable sites, which have no server concept), `spinupwp_id`, `hosting_provider` (`spinupwp` | `pressable`, default `spinupwp`), `pressable_site_id` (nullable, unique — mirrors `spinupwp_id`), `site_user`, `wp_path`.
- Database: `db_host`, `db_port`, `db_name`, `db_user`, `db_password` (encrypted), `table_prefix`.
- Identity: `is_wordpress`, `wordfence_enabled`, `llar_enabled`, `wp_plugins_detected_at`, `wp_core_update`, `wp_theme_updates`, `wp_plugin_updates`. The `wp_core_update`/`wp_theme_updates`/`wp_plugin_updates` booleans are SpinupWP-inventory-only — always false for Pressable sites even when real pending updates exist (visible instead via `companion_snapshot`). UI hides those specific pills for Pressable rather than showing a false "up to date."
- Cert: `cert_source`, `cert_expires_at`, `cert_renews_at`, `cert_state`, `cert_state_changed_at`, `cert_notes`.
- Cloudflare: `cloudflare_state`, `cloudflare_checked_at`, `resolved_a_record`, `resolved_ns_record`.
- Lifecycle: `is_inactive` (default `false`) + `inactive_reason` (nullable). Distinct from `archived_at`: an archived site is hidden from every listing entirely, an inactive site stays fully visible (Sites list, server page, search) but is excluded from the Issues page / nav badge counting and from every site-scoped Mattermost/Slack alert — same "still visible, not monitored" shape as `Server.is_ignored`, generalized across every issue category instead of one narrow toggle. Toggled via `ActionLog::TYPE_SITE_DEACTIVATED`/`TYPE_SITE_REACTIVATED`.
- Care plan: `care_plan_enabled`, `care_plan_override` (NULL = Bill.com may write; bool = manual override sync respects).
- Backup relay: `backup_relay_enabled` (boolean, default `false`) marks whether the site is enrolled in scheduled off-host backup archiving to S3 Glacier Instant Retrieval. `backup_relay_last_archived_at` (nullable timestamp) tracks when an archive run was most recently completed for the site.
- Bill.com: `bill_com_customer_id`, `bill_com_customer_name`, `bill_com_linked_via_invoice`, `bill_com_linked_at`.
- Companion: `companion_installed`, `companion_version`, `companion_capabilities`, `companion_secret` (encrypted), `companion_last_seen_at`, `companion_snapshot` (JSON), `companion_snapshot_at`, `companion_stuck_since` + `companion_stuck_reason` (both nullable). Set by `clockwork:detect-stuck-companion-state` (daily sweep) when a site transitions into a stuck state — a failed install never retried past 24h, or an installed Companion gone silent past the snapshot-staleness threshold (3 days). Cleared back to null on recovery. The pair exists purely to make that transition detectable for alerting. Companion traffic visibility can also be conditionally gated via `canViewCompanionTraffic()`.
- Uptime: `uptime_monitoring_enabled`, `uptime_state`, `uptime_last_checked_at`, `uptime_last_up_at`, `uptime_last_status_code`, `uptime_consecutive_failures`, `uptime_down_since`.
- Performance: `performance_scan_region` (GTmetrix location ID override; NULL = global default), `psi_unavailable_at` + `psi_unavailable_reason` (circuit-breaker after 3 consecutive failed scans — name is historical, applies to any engine; auto-cleared on the next successful scan).

`Site::query()` excludes archived rows by the `notArchived` global scope. Bypass with `Site::withoutGlobalScopes()` in import / migration paths — see [Concepts → Server, Site, Care plan, Hosting tier](/docs/concepts/server-site-care-plan).

`Site::isPressable()` / `Site::isSpinupWp()` branch on `hosting_provider` — both `@deprecated` as of the modularization roadmap's Phase 5 in favor of `Site::host()->supports(HostingProvider::CAP_*)` for behavior gating (e.g. `CAP_SSH`, `CAP_CERT_SYNC`, `CAP_BACKUP_RELAY`), though for display-only "which provider is this" checks they're still fine and most call sites haven't migrated yet. `Site::scopeHostMonitored()` is true for a SpinupWP site whose server isn't ignored/staging (the old check), OR any Pressable site (which has no server-level ignore concept to check) — this replaced `whereHas('server', fn ($q) => $q->monitored())` across every fleet-wide monitoring command (uptime, SSL, security scans, Companion snapshot/capabilities refresh, resource metrics, action-log backfill) so Pressable sites get the same coverage without provider-specific branching in each command.

### `tags` + `server_tag`

Three canonical tags: `Dedicated`, `Shared`, `Staging`. The Capacity page filters on `Shared`. Server tier lives here, not as a column on `servers`.

### `users`

The auth allowlist. A row exists ⇒ allowed. `revoked_at IS NULL` ⇒ active. `password` is nullable + unused. Supports multiple OAuth providers via `google_id`, `github_id`, and `microsoft_id` (handled via `App\Services\Auth\OAuthLoginHandler`). `theme` (nullable string, default `'light'`) stores the user's UI theme preference (`light`, `dark`, `midnight`, `high-contrast`), cast and managed via `User::THEMES`.

### `installed_modules`

One row per tracked module (`slug`, `is_enabled`, timestamps) via `App\Models\InstalledModule`. Manages runtime module activation and deactivation (`ModuleStateResolver::isEnabled($slug)`), driven by `/settings/modules` and the setup picker at `/setup/modules`.

### `app_settings`

Singleton key/value store via `App\Support\Settings`. Holds the ingest schedule, per-source enable + last_run_at, `auto_approve_repeats_enabled`, monitoring thresholds. Driven by `/settings/ingest` and `/monitoring/settings`.

### `integration_credentials`

One row per `(integration, key)` pair — e.g. `('pressable', 'client_secret')`, `('digitalocean', 'token')` — via `App\Models\IntegrationCredential`, `value` **encrypted at rest** (`'encrypted'` cast) and `$hidden` so it's never serialized back out. Deliberately a separate table from `app_settings`: that one is plain unencrypted JSON, fine for toggles but wrong for secrets. `unique(['integration', 'key'])`. Read through `App\Support\CredentialResolver` — DB-first, falling back to `config('clockwork.{integration}.{key}')`/`.env` when no row exists, so every client keeps working `.env`-only forever; `/settings/integrations` is the UI. Every credential-holding client resolves through this instead of reading `config()` directly.


## Threat-detection + bans

### `nginx_log_cursors`

One row per `(site_id, log_path)`. Tracks `inode + offset` so the tailer survives `logrotate`. Without this, every rotation re-reads from byte 0 and we'd double-ingest.

### `threat_logs`

Append-only stream of every interesting nginx line. Indexed on `(site_id, event_at)`. Pruned to ~30 days (raw is the prune candidate; rollups are durable forever).

### `allowed_bots`

Refreshed daily from `arcjet/well-known-bots`. UA-string match only in v1 — known to be spoofable; reverse-DNS verification is planned.

### `review_queue`

Pending lockouts/blocks waiting on a human (or the auto-approve gate). Promoted to `queued_for_ban` on approve.

### `blocked_ips`

What's currently or historically banned. `source` ∈ `wordfence|llar|nginx|manual`. `decision` ∈ `approved|dismissed|auto`. `unbanned_at` set on undo so the UI stays honest.

## Metrics + rollups

### `server_metrics`

Append-only DO-API metrics. Indexed on `(server_id, recorded_at)`. Pruned to 90 days.

### `server_update_snapshots`

One row per server, unique on `server_id`. Latest poll wins — populated by `clockwork:poll-system-updates` (daily 04:15) which SSHs to every server SpinupWP flagged with `upgrade_required=true`. Stores `total_updates` + `security_updates` (from `apt-check`), `reboot_required` + `reboot_required_pkgs[]` (from `/var/run/reboot-required[.pkgs]`), `upgradable_pkgs[]` (parsed `apt list --upgradable`), `poll_status` (`ok|ssh_failed|parse_failed`), `poll_error`, `polled_at`. Fills the count + per-package gap that SpinupWP's API doesn't expose.

### `site_traffic_daily`

Per-site WPE-style daily rollup. Unique on `(site_id, date)`. Idempotent upsert. Includes `requests`, `unique_ips`, `visits`, `bytes_sent`, status-code buckets, `top_paths` + `top_ips` JSON.

The "visit" definition is DISTINCT IP per UTC day, excluding 403s and static-asset paths. We deliberately don't filter known bots at rollup time — adding that made the per-day query 50–100× slower.

## Site lifecycle

### `site_uptime_events`

Transition log (down/up), NOT one row per probe. Indexed on `(site_id, event_at)`.

### `site_security_scans`

Polymorphic by `scan_type` ∈ `sitecheck|core_checksums|blacklist|companion_malware`. One coarse `status` (`clean|issues_found|failed`) drives every dashboard regardless of scan type. Adding a Phase-2 type is a new constant on `SiteSecurityScan`, not a migration.

`companion_malware` is the in-WP scanner that bypasses Cloudflare — runs via the Companion plugin's HMAC-signed endpoint when available, falls back to SSH wp-cli. Replaces SiteCheck's role on Cloudflare-fronted sites where Sucuri's external scanner gets 403'd at the edge.

### `site_performance_scans`

One row per Lighthouse run. Stores Performance score + LCP/FCP/TBT/SI/CLS + page weight + request count, plus `engine` (`gtmetrix` / `psi` / `psi-fallback` / `pressable`) — GTmetrix is primary, PSI only fills in when GTmetrix errors; `pressable` is Pressable's own always-available report (no fallback chain). **CLS is stored ×1000 as integer** to avoid float drift in MySQL — divide on read.

`accessibility_score` / `best_practices_score` / `seo_score` (nullable) — currently populated for `pressable` rows only; GTmetrix/PSI clients weren't wired up to extract them yet. `source_generated_at` (nullable) — the report's own generation time, distinct from poll time; null for GTmetrix/PSI (every call is a fresh scan), set for Pressable (whose report only regenerates ~monthly). `PerformanceScanRecorder` uses it to skip inserting a duplicate row when Pressable's underlying report hasn't actually changed since the last poll — see [Features → Performance scans](/docs/features/performance-scans).

### `contact_form_tests` + `contact_form_test_runs`

Per-site contact-form configuration and smoke-test history (the $9/mo care-plan feature, decoupled into `modules/ContactForms`). `contact_form_tests` stores configured forms (up to 3 per care-plan site, frequency weekly/daily, failure streak, status), while `contact_form_test_runs` logs every execution attempt and result.

## Audit + ops

### `action_logs`

Generic audit trail. `action_type` is a string; `details` is freeform JSON. Indexed on `(site_id, ran_at)` and `(action_type, ran_at)`. Written by `App\Services\ActionLog\ActionLogger` from controllers; surfaces on the per-site Overview Recent activity card and `/maintenance-history`. Logger never throws — logging an action must not break the action.

`ActionLog::UPDATE_TYPES` groups the four update-job constants (`plugin_update`, `theme_update`, `core_update`, `translations_update`) — the maintenance-history "All updates" quick filter and the `/updates` page's History link both use it instead of re-enumerating the list at each call site. Recent additions to the constant list: `TYPE_COMPANION_UNINSTALL` and `TYPE_SERVER_UPDATE_FAILED` (a live `apt-get` failure now logs distinctly from the reaper's stuck-and-timed-out `server_update_reaped` case), `TYPE_SITE_DEACTIVATED`/`TYPE_SITE_REACTIVATED` (logged when a site's `is_inactive` flag is toggled — see the `sites` table above), `TYPE_THEME_CHANGED` (recorded when a user changes their UI theme), and `TYPE_BACKUP_RELAY_SYNCED`/`TYPE_BACKUP_RELAY_ARCHIVED` (recorded during backup relay sync and archival runs).

### `backup_relay_runs`

One row per run of the backup relay pipeline that archives off-host site backups across supported hosting providers (Pressable, SpinupWP, and any provider implementing `HostingProvider::CAP_BACKUP_RELAY` via `Modules\Core\Contracts\BackupRelayAdapter`) directly to S3 Glacier Instant Retrieval, replacing host-limited retention windows. Supports two operational modes: in-repo mode (default, executing `Modules\BackupRelay\Jobs\ArchiveSiteBackupJob` natively via Laravel queues) and external-agent mode (coordinating with a standalone droplet via S3 `targets.json` and `last-report.json`). Columns: `sites_total`, `sites_archived`, `sites_skipped`, `sites_failed`, `failures` (JSON array of `{domain, error}`), `started_at`, `finished_at`. `pull-backup-relay-report` (in external-agent mode) or the in-repo runner also detects relay silence — 6+ days since the last row fires a Mattermost/Slack alert once, cleared on the next fresh run. See [Features → Backup relay](/docs/features/backup-relay).

### `bill_com_customers` + `bill_com_care_plan_items`

Local cache of the daily Bill.com sync. Customers PK is Bill.com's own ID (`0cu...`); items PK is Bill.com's Item ID. Drives `sites.care_plan_enabled` automation.

## Encrypted columns

Anything sensitive is `Crypt`-cast in the model, ciphertext at rest:

- `servers.ssh_private_key`, `servers.ssh_password`
- `sites.db_password`, `sites.companion_secret`

Never log them. Never render them in views or API responses.

## Migrations

Migrations live in `database/migrations/`. They are deliberately renumbered (`192640`, `192641`, …) so foreign keys resolve in dependency order, not chronological order. Don't reorder without checking the FK chain.

For a fresh dev DB:

```bash
php artisan migrate:fresh
```

That **destroys data** — confirm before using outside dev.
