---
title: Kinsta
section: Integrations
order: 23
updated: 2026-09-06
author: Aaron Reimann
tags: [integrations, kinsta, hosting, wordpress]
tracks: [modules/Kinsta/src/**, app/Console/Commands/KinstaTest.php]
---

Kinsta is our fourth WordPress hosting provider. Same shape as WP Engine and Pressable — **no server concept at all** — but Kinsta's resource hierarchy is a level deeper: Company → Site → Environment, where a "site" is the logical WordPress project and each "environment" (live, staging, ...) is the thing that actually has files, a database, and SSH access. Clockwork's `sites.kinsta_environment_id` addresses an environment directly. See [Concepts → Server, Site, Care plan, Hosting tier](/docs/concepts/server-site-care-plan) for how the no-server shape reshapes the Server/Site relationship.

> [!TIP]
> **Looking for Testers!**
> Have a live Kinsta account? You can help test this integration! Follow our [Contributing & Module Testing Guide](/docs/getting-started/contributing) to plug in credentials, verify endpoints, vibe code any fixes with Claude, and open a PR back.

## Honesty about what's confirmed here

**This module has not been exercised against a live Kinsta account.** Auth (a single static Bearer API key, no OAuth dance) and the `ssh/set-status` / `ssh/generate-password` / `ssh/set-allowed-ips` endpoint paths were confirmed to exist during this module's research, but one piece is a genuine, explicitly-flagged guess:

- **`sshConnectionInfo()` — the biggest unverified assumption in this module.** Kinsta's API clearly has *some* notion of per-environment SSH state (the three endpoints above prove that), but no endpoint that returns the connection **host/port/username** was confirmed live. This method guesses that `GET /sites/environments/{id}` carries SSH connection details nested under one of `ssh_connection`/`ssh`/`sftp`, with `host`/`hostname`, `username`/`user`, and `port` fields inside. If that guess is wrong, `KinstaSshCommandRunner` and `KinstaCompanionInstaller` fail cleanly with a `RuntimeException` — neither has a hardcoded fallback host that could mask the gap silently.
- The WP root is assumed to be `public` relative to the SSH landing directory — also unconfirmed.

Confirm both against a real environment before relying on SSH-based features (command execution, Companion install) in production.

## Setup

1. Create an API key in the MyKinsta dashboard under Company Settings → API Keys.
2. Set in `.env`:

   ```env
   CLOCKWORK_KINSTA_API_KEY=...
   # Safe-by-default: Set to false only when you are ready to enable writes and Companion deployment
   CLOCKWORK_KINSTA_VIEW_ONLY=true
   CLOCKWORK_KINSTA_SSH_PASSWORD=...   # optional — see below
   ```

3. Test:

```bash
php artisan clockwork:kinsta-test
# Or using the standardized alias:
php artisan clockwork:test-kinsta
```

Displays operating mode (`Full Access` or `View Only`) and reports API token validation status.

## View-Only (Read-Only) Mode

Kinsta defaults to **View-Only Mode** (`CLOCKWORK_KINSTA_VIEW_ONLY=true`) to safeguard unverified accounts and client-owned environments:

- **Zero API Mutations**: Any call to `POST`, `PUT`, or `DELETE` throws a `KinstaReadOnlyException` before sending HTTP requests (including SSH status toggles, password generation, or backup restores).
- **Zero Remote Writes**: `commandRunner()` and `companionInstaller()` return `null`, preventing SSH command execution and Clockwork Companion mu-plugin deployment.
- **Gated Capabilities**: `CAP_COMPANION` reports `false`. Read-only site and environment discovery operates normally.
- **Diagnostic Reporting**: Diagnostics at `/settings/diagnostics` append `· Mode: View Only`.

To enable write actions and Companion deployment on verified accounts, set:
```env
CLOCKWORK_KINSTA_VIEW_ONLY=false
```

### SSH password: bring your own, or let it be generated on demand

Kinsta doesn't do key-based SSH — access is by password, toggled and rotated through the API. If `CLOCKWORK_KINSTA_SSH_PASSWORD` is unset, `KinstaSshCommandRunner`/`KinstaCompanionInstaller` call `setSshStatus(true)` then `generateSshPassword()` on demand for the target environment rather than failing. Set the env var if you'd rather pin a single password and manage SSH-enablement yourself.

## Auth

- **REST API**: static Bearer token (`CLOCKWORK_KINSTA_API_KEY`) — no expiry to manage, unlike Pressable's OAuth2 `client_credentials` flow.
- **SSH**: password-based via `Modules\Core\Support\SshConnector` — the same generic, `Server`-independent SSH primitive WP Engine's module uses (built because Kinsta/WP Engine sites have no `Server` row for `App\Services\Ssh\SshClient` to hang off of).

## The 401-vs-anything-else check

Kinsta's site-listing endpoint requires a `company` query param this module doesn't collect as a credential, so a *valid* key still comes back 400/422 against a bare `GET /sites` — only a 401 means the key itself was rejected. `KinstaClient::ping()` (and `KinstaCheck`, which calls it) is built around that asymmetry rather than a simple pass/fail check: anything other than 401 counts as "key accepted."

## Endpoints we call

Base URL `https://api.kinsta.com/v2`.

| Method | Path | Purpose |
|---|---|---|
| GET | `/sites` | `KinstaCheck`'s probe — see the 401-vs-anything-else note above. Also lists sites when a `company` id is supplied (not collected as a credential today). |
| GET | `/sites/{id}/environments` | Environments belonging to a site. |
| GET | `/sites/environments/{id}` | A single environment's detail — also where `sshConnectionInfo()` looks for SSH host/port/username (unconfirmed shape, see above). |
| GET | `/sites/environments/{id}/domains` | Domains attached to an environment. |
| GET | `/sites/environments/{id}/backups` | Backup history, newest first. Not wired into any report command yet. |
| POST | `/sites/environments/{id}/restore/{backupId}` | Restore from a backup — async; Kinsta returns an `operation_id` to poll, not implemented here since no caller needs it yet. |
| POST | `/sites/environments/{id}/ssh/set-status` | Toggle SSH on/off for an environment. |
| POST | `/sites/environments/{id}/ssh/generate-password` | Mint a fresh one-time SSH password. |
| POST | `/sites/environments/{id}/ssh/set-allowed-ips` | Restrict SSH to an IP allowlist. Not called by anything today — kept for future use (e.g. a one-time setup command allowlisting this app server's outbound IP). |

## Files

- `modules/Kinsta/src/KinstaClient.php` — the REST client (Bearer auth, sites/environments/backups/SSH-management).
- `modules/Kinsta/src/KinstaReadOnlyException.php` — thrown when mutating operations are attempted in View-Only mode.
- `modules/Kinsta/src/Commands/KinstaTest.php` (`app/Console/Commands/KinstaTest.php`) — verification CLI command (`clockwork:kinsta-test` / `clockwork:test-kinsta`).
- `modules/Kinsta/src/KinstaSshCommandRunner.php` — `Modules\Core\Contracts\SiteCommandRunner` adapter resolving connection details via `KinstaClient::sshConnectionInfo()` and authenticating with the configured (or on-demand-generated) password.
- `modules/Kinsta/src/KinstaCompanionInstaller.php` — installs the Companion mu-plugin over that same SSH transport, same shape as `WPEngineCompanionInstaller`.
- `modules/Kinsta/src/KinstaHostingProvider.php` — the `HostingProvider` adapter. `CAP_SSH` is `false` **despite Kinsta having real per-environment SSH** — the capability's own contract docblock ties it specifically to `server_id` being populated, which Kinsta sites never have (same as WP Engine). `panelUrl()` returns `null` (no confirmed MyKinsta deep-link pattern).
- `modules/Kinsta/src/KinstaCheck.php` — diagnostics probe (the 401-vs-anything-else `GET /sites` check).
- `modules/Kinsta/src/KinstaServiceProvider.php` — registers the client/SSH-runner/Companion-installer bindings, manifest, `HostingProvider`, and diagnostics check.
- Config: `config/clockwork.php` → `kinsta` key.
- Site identifier: `sites.kinsta_environment_id` (unique, nullable) — see the `2026_09_03_014554_add_wpengine_kinsta_cloudways_fields_to_sites` migration.
