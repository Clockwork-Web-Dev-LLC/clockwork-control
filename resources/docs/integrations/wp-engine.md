---
title: WP Engine
section: Integrations
order: 22
updated: 2026-09-06
author: Aaron Reimann
tags: [integrations, wpengine, hosting, wordpress]
tracks: [modules/WPEngine/src/**, app/Console/Commands/WPEngineTest.php]
---

WP Engine is our third WordPress hosting provider, alongside SpinupWP, Pressable, Kinsta, and Cloudways. Like Pressable and Kinsta, **WP Engine has no server concept** — every operation is addressed by an "install" name, not a `server_id`. Unlike Pressable, WP Engine does expose a real per-install SSH gateway, which this module uses for command execution and Companion installs. See [Concepts → Server, Site, Care plan, Hosting tier](/docs/concepts/server-site-care-plan) for how the server-vs-no-server split reshapes the Server/Site relationship, and why `CAP_SSH` is still `false` for WP Engine despite that real SSH access existing.

> [!TIP]
> **Looking for Testers!**
> Have a live WP Engine account? You can help test this integration! Follow our [Contributing & Module Testing Guide](/docs/getting-started/contributing) to plug in credentials, verify endpoints, vibe code any fixes with Claude, and open a PR back.

## Honesty about what's confirmed here

**This module has not yet been exercised against a live WP Engine account.** Every endpoint path and response shape in `WPEngineClient` is built from WP Engine's published API reference, not a real response. Two things in particular are flagged as genuine unknowns rather than confirmed behavior:

- **`backups()`'s response envelope** — WP Engine's docs describe a `/installs/{id}/backups` resource, but whether results are flat or wrapped the same `{previous, next, count, results}` shape `installs()`/`domains()` use is unconfirmed.
- **SSL certificates** — no dedicated SSL endpoint appears in WP Engine's published API reference at all. `sslCertificates()`/`requestSslCertificate()` are this module's best guess at where such a resource would live if/when exposed. `WPEngineCheck` deliberately does **not** call these methods for its diagnostic probe, and `WPEngineHostingProvider::supports(CAP_CERT_SYNC)` currently returns `true` on the strength of the domains endpoint alone — treat cert-sync fidelity for WP Engine as unverified until this is checked against a real account.

Confirm both against a real install before leaning on them in production.

## Setup

1. Mint an API User ID / Password pair at `my.wpengine.com/api_access` — **not** the portal login, and not the per-install SSH keypair (see below).
2. Generate (or reuse) an SSH keypair authorized against the install(s) you want command execution/Companion install for.
3. Set in `.env`:

   ```env
   CLOCKWORK_WPENGINE_API_USER_ID=...
   CLOCKWORK_WPENGINE_API_PASSWORD=...
   # Safe-by-default: Set to false only when you are ready to enable writes and Companion deployment
   CLOCKWORK_WPENGINE_VIEW_ONLY=true
   CLOCKWORK_WPENGINE_SSH_PRIVATE_KEY=...
   CLOCKWORK_WPENGINE_SSH_PRIVATE_KEY_PASSPHRASE=...   # optional
   ```

4. Test:

```bash
php artisan clockwork:wpengine-test
# Or using the standardized alias:
php artisan clockwork:test-wpengine
```

Displays operating mode (`Full Access` or `View Only`) and reports total accessible installs.

## View-Only (Read-Only) Mode

WP Engine defaults to **View-Only Mode** (`CLOCKWORK_WPENGINE_VIEW_ONLY=true`) to safeguard unverified accounts and client-owned installs:

- **Zero API Mutations**: Any call to `POST`, `PUT`, or `DELETE` throws a `WPEngineReadOnlyException` before sending HTTP requests.
- **Zero Remote Writes**: `commandRunner()` and `companionInstaller()` return `null`, preventing SSH command execution and Clockwork Companion mu-plugin deployment.
- **Gated Capabilities**: `CAP_COMPANION` reports `false`. Read-only install discovery, domain mapping, and cert monitoring operate normally.
- **Diagnostic Reporting**: Diagnostics at `/settings/diagnostics` append `· Mode: View Only`.

To enable write actions and Companion deployment on verified accounts, set:
```env
CLOCKWORK_WPENGINE_VIEW_ONLY=false
```

## Auth

Two independent credentials, not one:

- **REST API**: HTTP Basic, using the API User ID/Password pair above.
- **SSH**: key-based only (no password fallback) against `{install}@{install}.ssh.wpengine.net:22`, via `Modules\Core\Support\SshConnector` — the same generic, `Server`-independent SSH primitive Kinsta's module uses, built specifically because WP Engine/Kinsta sites have no `Server` row for `App\Services\Ssh\SshClient` to hang off of (confirmed by reading that class's `connect(Server $server)` signature).

## Endpoints we call

Base URL `https://api.wpengine.com/v1`.

| Method | Path | Purpose |
|---|---|---|
| GET | `/installs` | Paginated install listing. `WPEngineCheck`'s probe (`limit=1`). |
| GET | `/installs/{id}` | Single install detail. |
| GET | `/installs/{id}/domains` | Domains attached to an install. |
| GET | `/installs/{id}/backups` | Backup history — **envelope shape unconfirmed**, see above. Not wired into any report command yet. |
| GET | `/installs/{id}/ssl_certificates` | Best-guess path — **no confirmed SSL endpoint exists in WP Engine's docs**. |
| POST | `/installs/{id}/ssl_certificates` | Request a Let's Encrypt cert — same caveat. |
| POST | `/ssh_keys` | Register an SSH public key account-wide. Not called automatically by anything in this module — implemented for future/manual use only. |

## Files

- `modules/WPEngine/src/WPEngineClient.php` — the REST client (Basic Auth, install listing/detail/domains/backups/SSL).
- `modules/WPEngine/src/WPEngineReadOnlyException.php` — thrown when mutating operations are attempted in View-Only mode.
- `modules/WPEngine/src/Commands/WPEngineTest.php` (`app/Console/Commands/WPEngineTest.php`) — verification CLI command (`clockwork:wpengine-test` / `clockwork:test-wpengine`).
- `modules/WPEngine/src/WPEngineSshCommandRunner.php` — `Modules\Core\Contracts\SiteCommandRunner` adapter over real per-install SSH via `SshConnector`. Key-based auth only; throws if `wpengine_install_name` is empty or no private key is configured.
- `modules/WPEngine/src/WPEngineCompanionInstaller.php` — installs the Companion mu-plugin over that same SSH transport: tarball extract, `wp-cli` DB-query upsert of the shared secret, cache flush — mirroring `App\Services\Companion\CompanionInstaller`'s approach but without the sudo/`site_user` wrapping SpinupWP needs. Assumes the WP root is `sites/{install}` relative to the SSH landing directory — flagged as needing live confirmation.
- `modules/WPEngine/src/WPEngineHostingProvider.php` — the `HostingProvider` adapter: capabilities (`CAP_SSH` and `CAP_SERVER_LINKAGE` both `false` — see [Concepts](/docs/concepts/server-site-care-plan#hosting-provider) for why that holds even though real SSH exists), credential fields, diagnostics check. `panelUrl()` returns `null` (no confirmed My WP Engine deep-link pattern).
- `modules/WPEngine/src/WPEngineCheck.php` — diagnostics probe (`GET /installs`, `limit=1`).
- `modules/WPEngine/src/WPEngineServiceProvider.php` — registers the client/SSH-runner/Companion-installer bindings, manifest, `HostingProvider`, and diagnostics check.
- Config: `config/clockwork.php` → `wpengine` key.
- Site identifier: `sites.wpengine_install_name` (unique, nullable) — see the `2026_09_03_014554_add_wpengine_kinsta_cloudways_fields_to_sites` migration.
