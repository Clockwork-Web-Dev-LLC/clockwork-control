---
title: Cloudways
section: Integrations
order: 24
updated: 2026-09-06
author: Aaron Reimann
tags: [integrations, cloudways, hosting, cloud-provider, wordpress]
tracks: [modules/Cloudways/src/**, app/Console/Commands/CloudwaysTest.php]
---

Cloudways is our fifth WordPress hosting provider, and architecturally different from Pressable/WP Engine/Kinsta: it provisions **real servers**, each hosting multiple apps — the same `Server` hasMany `Site` shape SpinupWP uses. That's why `Modules\Cloudways\CloudwaysServiceProvider` is the only module in this codebase implementing **both** the `HostingProvider` and `CloudProvider` contracts from one class pair — Cloudways is simultaneously "a place sites live with SSH access" and "a fleet of cloud instances to poll metrics from and reconcile for deletion." See [Concepts → Server, Site, Care plan, Hosting tier](/docs/concepts/server-site-care-plan#two-independent-axes-hosting-provider-and-cloud-provider) for where Cloudways sits relative to the other four hosting providers and the three single-purpose cloud providers.

Cloudways provisions on top of DigitalOcean, AWS, GCP, Vultr, or Linode, per the customer's choice at server-creation time — but this app never holds credentials for whichever of those a given Cloudways server actually runs on. **All metrics and alive/dead state for Cloudways servers come from Cloudways' own API**, tracked under `servers.provider = 'cloudways'`, never the underlying cloud's real identity.

> [!TIP]
> **Looking for Testers!**
> Have a live Cloudways account? You can help test this integration! Follow our [Contributing & Module Testing Guide](/docs/getting-started/contributing) to plug in credentials, verify endpoints, vibe code any fixes with Claude, and open a PR back.

## Honesty about what's confirmed here

**This module has not been exercised against a live Cloudways account**, and carries more open questions than the other three new modules combined:

- **API version**: Cloudways' v1 API has reached end-of-life; this module targets v2 exclusively. The specific request/response shapes below are modeled on Cloudways' documented API specifications.
- **The SSH/sudo model is the largest structural risk in this module.** Unlike WP Engine/Kinsta (which needed brand-new per-site SSH transports built from scratch), Cloudways sites *do* have a real `servers` row, so `CloudwaysHostingProvider` reuses the **existing** `App\Services\Ssh\SshCommandRunner` and `App\Services\Companion\CompanionInstaller` directly — no new transport code. But those existing classes assume SpinupWP's model: one root/sudo-capable SSH login per server, then `sudo -u {site_user}` to drop privileges per site. Cloudways' actual SSH model is a per-*application* "Master Credentials" user (commonly `master_xxxxx`), and whether that user can `sudo -u {other_site_user}` to reach a sibling app on the same server — or is sandboxed per-app with no cross-app sudo at all — is **unknown without testing against a live account**. If Cloudways sandboxes per-app SSH (the more likely shape, given app isolation is one of its selling points), the existing `CompanionInstaller` will fail for any Cloudways site whose `site_user` doesn't match the SSH login's own app, and a Cloudways-specific installer (or a per-app credential model instead of one shared `Server::ssh_password`) would be needed instead.
- **The monitoring/metrics endpoint shape is entirely guessed** — `serverMonitorSummary` and `/server/diskUsage` have no confirmed v2 shape; `CloudwaysMetricsParser` is defensive about missing/malformed keys specifically because of this.
- Several other endpoints (`/server/{id}`, `/app/{id}`, backup/restore, SSL) carry the same "shape assumption" flag in `CloudwaysClient`'s docblocks.

Populating `Site::site_user` and `Server::ssh_password` correctly for Cloudways sites, and confirming the sudo/isolation model, are prerequisites this module assumes but does not itself solve. Do not point this module at a production Cloudways account without testing the SSH path against a real server first.

## Setup

1. Generate an API key in the Cloudways platform under Account → API Keys.
2. Set in `.env`:

   ```env
   CLOCKWORK_CLOUDWAYS_API_KEY=...
   CLOCKWORK_CLOUDWAYS_EMAIL=...
   # Safe-by-default: Set to false only when you are ready to enable writes and Companion deployment
   CLOCKWORK_CLOUDWAYS_VIEW_ONLY=true
   ```

3. Test:

```bash
php artisan clockwork:cloudways-test
# Or using the standardized alias:
php artisan clockwork:test-cloudways
```

Displays operating mode (`Full Access` or `View Only`) and reports total servers and apps.

## View-Only (Read-Only) Mode

Cloudways defaults to **View-Only Mode** (`CLOCKWORK_CLOUDWAYS_VIEW_ONLY=true`) to safeguard unverified accounts:

- **Zero API Mutations**: Any call to `POST`, `PUT`, or `DELETE` throws a `CloudwaysReadOnlyException` before sending HTTP requests (OAuth access token exchanges are permitted so read calls succeed).
- **Zero Remote Writes**: `commandRunner()` and `companionInstaller()` return `null`, disabling SSH command execution and Companion mu-plugin deployment.
- **Gated Capabilities**: `CAP_SSH` and `CAP_COMPANION` report `false`. Server and site inventory linkage and cloud metrics collection continue operating normally.
- **Diagnostic Reporting**: Diagnostics at `/settings/diagnostics` append `· Mode: View Only`.

To enable write actions and Companion deployment on verified accounts, set:
```env
CLOCKWORK_CLOUDWAYS_VIEW_ONLY=false
```

## Auth

OAuth2-shaped but Cloudways-specific: `POST /oauth/access_token` with `{email, api_key}` (form-encoded) returns a short-lived bearer `access_token` (~24h validity per Cloudways' docs). `CloudwaysClient::token()` caches it in-memory for the instance's lifetime and refreshes on expiry — mirrors `AzureClient::token()`'s cache-until-expiry pattern, adapted to Cloudways' simpler two-field credential exchange.

SSH/command execution goes through the **existing** `App\Services\Ssh\SshCommandRunner` and `App\Services\Companion\CompanionInstaller` — the same classes SpinupWP uses — not a new transport, per the SSH-model caveat above.

## Endpoints we call

Base URL `https://api.cloudways.com/api/v2`.

| Method | Path | Purpose | Confidence |
|---|---|---|---|
| POST | `/oauth/access_token` | Token exchange. | Documented v1 shape, unconfirmed for v2. |
| GET | `/server` | All servers + their nested apps. `CloudwaysCheck`'s probe. Also feeds `aliveProviderIds()`/`instancesByIp()`. | Shape assumption. |
| GET | `/server/{id}` | Single server detail. | Shape assumption. |
| GET | `/app/{id}?server_id={id}` | Single app detail. | Shape assumption. |
| POST | `/app/manage/takeBackup` | Trigger an on-demand backup. Async — returns an `operation_id`. Not wired into any report command yet. | Shape assumption. |
| POST | `/app/manage/restoreBackup` | Restore the most recent backup per component (Cloudways' v1 docs don't expose picking an arbitrary historical backup). | Shape assumption. |
| GET | `/ssl_certificate/{appId}?server_id={id}` | Cert state for an app. | Shape assumption. |
| POST | `/ssl_certificate/install/letsEncrypt` | Install a Let's Encrypt cert. | Shape assumption. |
| GET | `/server/monitorSummary` | Time-series CPU/RAM/load — one call per metric type (`type=cpu\|ram\|load`). Feeds `CloudwaysCloudProvider::metrics()`. | **Guessed** — no confirmed v2 shape. |
| GET | `/server/diskUsage` | Current disk usage snapshot (`used_mb`/`total_mb`). | **Guessed** — no confirmed v2 shape. |

`CloudwaysCloudProvider::metrics()` fetches all four independently with a try/catch per metric — one failing monitor call returns `null` for that value without failing the other three.

## Files

- `modules/Cloudways/src/CloudwaysClient.php` — the REST client (OAuth2 token exchange + caching, servers/apps/backups/SSL/monitoring).
- `modules/Cloudways/src/CloudwaysReadOnlyException.php` — thrown when mutating operations are attempted in View-Only mode.
- `modules/Cloudways/src/Commands/CloudwaysTest.php` (`app/Console/Commands/CloudwaysTest.php`) — verification CLI command (`clockwork:cloudways-test` / `clockwork:test-cloudways`).
- `modules/Cloudways/src/CloudwaysHostingProvider.php` — the `HostingProvider` half. Reuses `App\Services\Ssh\SshCommandRunner` and `App\Services\Companion\CompanionInstaller` — see the SSH/sudo-model caveat above before trusting this in production. `panelUrl()` returns `null` (no confirmed Cloudways Platform deep-link pattern).
- `modules/Cloudways/src/CloudwaysCloudProvider.php` — the `CloudProvider` half: `id()` returns `Server::PROVIDER_CLOUDWAYS`, `sizeTier()` returns the raw slug unchanged (no confirmed Cloudways-specific tier naming — it labels servers by whichever underlying cloud's own instance sizes were chosen at provisioning), `isDeletedAtProvider()` follows the DO/Hetzner alive-ID-membership pattern (Cloudways servers are ephemeral/deletable, unlike Azure's stable resource-ID paths).
- `modules/Cloudways/src/CloudwaysMetricsParser.php` — parses the guessed monitoring/disk-usage payload shapes into the scalar values `metrics()` needs.
- `modules/Cloudways/src/CloudwaysCheck.php` — diagnostics probe (OAuth2 exchange + `GET /server`).
- `modules/Cloudways/src/CloudwaysServiceProvider.php` — registers the client binding, manifest, **both** `hostingProvider()` and `cloudProvider()`, and the diagnostics check.
- Config: `config/clockwork.php` → `cloudways` key.
- Site identifier: `sites.cloudways_app_id` (unique, nullable); server identity: `servers.provider = 'cloudways'` — see the `2026_09_03_014554_add_wpengine_kinsta_cloudways_fields_to_sites` migration and `App\Models\Server::PROVIDER_CLOUDWAYS`.
