---
title: SpinupWP
section: Integrations
order: 20
updated: 2026-09-08
author: Aaron Reimann
tags: [integrations, spinupwp, inventory, wordpress]
tracks: [modules/SpinupWp/src/**, app/Console/Commands/ImportSpinupWp.php, app/Console/Commands/SpinupWpTest.php]
---

SpinupWP is the WordPress hosting control plane sitting under our fleet. We use its API for **inventory bootstrap** — initial server + site import, periodic resync, single-site refresh on demand. All runtime traffic (banning, plugin probing, log tailing) goes over SSH directly. This module is **verified** and in active production use.

## Why we use it

A daily SpinupWP import keeps our local view of the fleet honest as the operator adds, removes, or re-tiers sites. SpinupWP also exposes the SSL renewal date, which we feed into the per-site cert state machine. Additionally, SpinupWP integrates with our multi-provider [Backup Relay](/docs/features/backup-relay) pipeline via `SpinupWpBackupRelayAdapter` (`CAP_BACKUP_RELAY`), streaming backups to S3 Glacier Instant Retrieval.

What SpinupWP **cannot** tell us:

- Plugin inventory per site — exposed only as boolean update flags. Our `WpPluginDetector` does an SSH + wp-cli probe instead.
- Backup history — only configuration. We list DigitalOcean Spaces directly.
- DB credentials — only `database.table_prefix`. We extract the rest from `wp-config.php` over SSH on first onboarding.

## Setup

1. Generate an API token in SpinupWP's account settings.
2. Set in `.env`:

   ```env
   CLOCKWORK_SPINUPWP_TOKEN=...
   # Optional: set to true to block remote mutations and SSH execution
   CLOCKWORK_SPINUPWP_VIEW_ONLY=false
   ```

3. Test:

```bash
php artisan clockwork:spinupwp-test
# Or using the standardized alias:
php artisan clockwork:test-spinupwp
```

Displays operating mode (`Full Access` or `View Only`) and lists servers and sites.

## View-Only (Read-Only) Mode

When inspecting or auditing a third-party or client-owned SpinupWP fleet:

- **Configuration**: Set `CLOCKWORK_SPINUPWP_VIEW_ONLY=true` in `.env` or toggle View-Only Mode in **Settings → Integrations**. Default is `false`.
- **Zero API Mutations**: `POST`, `PUT`, and `DELETE` requests are blocked and throw `SpinupWpReadOnlyException` before hitting the API.
- **Zero Remote Writes**: SSH command runner and Companion plugin deployment are disabled (`commandRunner()` and `companionInstaller()` return `null`).
- **Gated Capabilities**: `CAP_SSH` and `CAP_COMPANION` evaluate to `false` when view-only mode is active.
- **Dry-Run Import**: Test fleet discovery without database commits using `php artisan clockwork:import-spinupwp --dry-run`.
- **Diagnostic Reporting**: Diagnostics at `/settings/diagnostics` append `· Mode: View Only`.

## Auth

Bearer token. `Modules\SpinupWp\SpinupWpClient` reads it via `CredentialResolver` (`spinupwp.token` — database first, `config('clockwork.spinupwp.token')`/`.env` fallback).

## Endpoints we call

Base URL `https://api.spinupwp.app/v1`.

| Method | Path | Purpose |
|---|---|---|
| GET | `/servers`, `/servers/{id}` | Inventory + per-server refresh. |
| GET | `/sites`, `/sites/{id}` | Same for sites. The site response includes `backups` config (no history). |
| GET | `/sites/{id}/events` | Recent events with graceful fallback for older accounts. |
| POST | `/sites` | **Migration runner only** — create the destination site for a cutover. |
| POST | `/sites/{id}/domains` | **Migration runner only** — add an additional domain. |

## Files

- `modules/SpinupWp/src/SpinupWpClient.php` — the HTTP client.
- `modules/SpinupWp/src/SpinupWpHostingProvider.php` — the `HostingProvider` adapter (`Modules\Core\Contracts\HostingProvider`): capabilities, credential fields, diagnostics check.
- `modules/SpinupWp/src/SpinupWpServiceProvider.php` — registers the client credential binding, `HostingProvider`, and diagnostics check with the module registry.
- `app/Console/Commands/ImportSpinupWp.php` — daily idempotent import.
- `app/Console/Commands/SpinupWpTest.php` — connectivity check.
- `app/Services/Sites/WpConfigExtractor.php` — first-time DB credential extraction (SSH).
- Config: `config/clockwork.php` → `spinupwp` key.

## Scheduled jobs that depend on it

| Cadence | Command |
|---|---|
| daily 03:30 | `clockwork:import-spinupwp` — re-run inventory; idempotent on `servers.spinupwp_id` and `sites.domain`. |
| daily 03:35 | `clockwork:find-orphan-sites` — runs right after to reclassify sites whose SpinupWP linkage was lost. |
| daily 04:15 | `clockwork:poll-system-updates` — uses the `upgrade_required` boolean SpinupWP imports as a gate, then SSHs to the small subset of servers actually flagged. The SpinupWP API only exposes the boolean — the count + per-package list comes from `apt-check` + `apt list --upgradable` over SSH and lands in `server_update_snapshots`. |

## Idempotency

- `hosting_provider` is set explicitly to `Site::HOSTING_PROVIDER_SPINUPWP` on every site the import creates. It used to fall back to a table-level default of `'spinupwp'`; that default has since been dropped (the column is `NOT NULL` with no default now) so a future creation path that forgets to set it fails loudly instead of silently mislabeling a site as SpinupWP-hosted.
- `servers.spinupwp_id` is the natural key for re-import.
- **Manual-add reconciliation**: a server added via `/servers/new` has no `spinupwp_id`. When the SpinupWP record for the same box later imports, `upsertServer` falls back to matching by `(hostname, ssh_port)` against rows where `spinupwp_id IS NULL`, adopts the SpinupWP id onto the manual row, and merges the SpinupWP-sourced fields. Without this, the import would collide on the `servers_hostname_ssh_port_unique` index.
- **Deletion sweep**: at the end of every import, any local Site or Server row whose `spinupwp_id` is *not* in the API response has its `spinupwp_id` nulled. This is what surfaces SpinupWP-side deletes to `clockwork:find-orphan-sites` (the import itself never deletes rows — it only severs the linkage so the orphan-finder can classify on the next tick). Counts surface in the `spinupwp_id_nulled` field of the `Servers:` and `Sites:` summary lines.
- `sites.domain` is the unique natural key. Always **bypass the `notArchived` global scope** when looking up sites by domain in import paths: `Site::withoutGlobalScopes()->firstOrNew(...)`. Without that bypass, a same-domain archived row collides at `save()`-time on the unique index.
- All sites are imported, not just WordPress sites. Non-WP sites get `is_wordpress=false` and skip WP-specific data sources but still participate in nginx log tailing and IP banning.
- **New staging/dev domains get uptime monitoring auto-disabled on creation.** `isStagingDomain()` matches `staging.*`, `dev.*`, `*.staging.*`, and anything matching `/-dev\./i` — these should never fire a down alert. Only applies on first creation, never overrides a manual re-enable on an existing site. See [Features → Uptime monitoring](/docs/features/uptime-monitoring).
- **New sites start with `auto_updates_paused = false`** (creation-only, never overriding an existing manual setting). This ensures newly-imported sites participate in updates immediately unless intentionally paused. See [Features → Updates](/docs/features/updates).

## On-demand refresh

The 03:30 daily import is the steady state, but two paths trigger an immediate re-run:

- **`POST /servers/refresh-spinupwp`** — fleet-wide button, surfaces on the dashboard and every server header. Runs `clockwork:import-spinupwp` followed by `clockwork:poll-servers` so a freshly-imported server flips out of `unknown` status into its right severity bucket on the same click.
- **`ServersController::store`** — auto-runs `import-spinupwp` after a manual server creation so any sites that already belonged to that box (per SpinupWP) attach immediately.
