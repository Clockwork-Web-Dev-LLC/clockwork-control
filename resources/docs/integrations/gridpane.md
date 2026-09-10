---
title: GridPane
section: Integrations
order: 25
updated: 2026-09-09
author: Aaron Reimann
tags: [integrations, gridpane, hosting, control-panel, wordpress]
tracks: [modules/GridPane/**, app/Console/Commands/ImportGridPane.php, app/Console/Commands/GridPaneTest.php]
---

GridPane is a dedicated WordPress server management panel and fleet source for Clockwork Control. Architecturally, GridPane mirrors SpinupWP and Cloudways: it provisions and manages **real Linux servers** running Nginx/OpenLiteSpeed, Percona MySQL, and Redis, with each server hosting multiple isolated WordPress installations (`Server` hasMany `Site`).

Clockwork Control interacts with GridPane across two complementary layers:
1. **GridPane REST API v1**: Discovers servers, WordPress sites, system users, and backup schedules.
2. **SSH / Companion Transport**: Executes WP-CLI commands and installs the Clockwork Companion plugin via direct SSH access (`root` or system user) with WordPress document roots at `/var/www/{domain}/htdocs`.

> [!TIP]
> **Hardware Telemetry Pairing**
> Because GridPane servers run on cloud VPS infrastructure (such as DigitalOcean, Hetzner, Vultr, Linode, or Azure), pairing your GridPane integration with the corresponding Cloud VPS provider in Clockwork Control unlocks 5-minute CPU, RAM, disk, and droplet health telemetry directly on your server dashboard.

---

## Configuration & Credentials

You can supply your GridPane API token either via environment variables or through the web UI under **Settings → Integrations**.

### 1. Environment Variables (`.env`)

Add your API token (generated from your GridPane Account Settings → API Keys):

```env
GRIDPANE_API_KEY=your_api_token_here
# Optional (defaults to https://my.gridpane.com/oauth/api/v1):
GRIDPANE_BASE_URL=https://my.gridpane.com/oauth/api/v1
# Safe-by-default: Set to true to prohibit any remote mutations (WP-CLI, SSH writes, Companion installs):
GRIDPANE_VIEW_ONLY=true
```

### 2. Database Integration Credentials

In Clockwork Control's web interface, navigate to **Settings → Integrations** and locate the **GridPane** integration card under *Server Management Panels*. Enter your API Token, toggle View-Only Mode if desired, and click **Save Changes**.

---

## View-Only (Read-Only) Mode

When using an API key provided by a third-party partner, client, or agency where you only have permission to monitor or audit their fleet, Clockwork Control provides a hardened **View-Only Mode**:

- **Default Setting**: `GRIDPANE_VIEW_ONLY=true` (safe-by-default).
- **Zero API Mutations**: Any call to `POST`, `PUT`, `DELETE`, or remote WP-CLI invocation (`/site/run-wp-cli`) is immediately intercepted and throws a `GridPaneReadOnlyException` before touching the network.
- **Zero Remote File Writes**: Direct SSH command execution (`commandRunner()`) and Clockwork Companion mu-plugin installation (`companionInstaller()`) return `null`, preventing any remote modifications or file deployments.
- **Capabilities Automatically Gated**: `CAP_SSH` and `CAP_COMPANION` report `false` to the application, while read-only inventory syncing (`CAP_SERVER_LINKAGE`), certificate tracking (`CAP_CERT_SYNC`), and orphan detection (`CAP_ORPHAN_DETECTION`) continue operating normally.
- **Visual Feedback**: The diagnostic runner at `/settings/diagnostics` and CLI commands explicitly report `Mode: View Only`.

To grant full management permissions (such as automated plugin updates or companion installations), explicitly set:
```env
GRIDPANE_VIEW_ONLY=false
```

---

## Verification & Diagnostic Probes

To verify that your GridPane API token is valid and able to reach the API:

```bash
php artisan clockwork:test-gridpane
```

This command calls `GET /user`, `GET /server`, and `GET /site`, displaying your account email, total visible servers, and sites.

Additionally, the automated health checker at `/settings/diagnostics` includes the `GridPaneCheck` probe, continuously reporting token validity and round-trip latency.

---

## Inventory Import

To discover and synchronize your GridPane fleet into Clockwork Control:

```bash
php artisan clockwork:import-gridpane
# Or simulate the import without database writes:
php artisan clockwork:import-gridpane --dry-run
```

### How the Import Works:
1. **Server Discovery**: Fetches all active servers from `GET /server`. For each server, an entry is created or updated in the `servers` table with `provider = 'gridpane'`, `provider_id = {gridpane_server_id}`, SSH user (`root`), and port (`22`).
2. **Site Discovery**: Fetches all WordPress applications from `GET /site`. Each site is mapped to its parent `server_id`, setting `hosting_provider = 'gridpane'`, `gridpane_site_id = {site_id}`, `wp_path = '/var/www/{domain}/htdocs'`, and the assigned system user.
3. **Idempotency**: Existing servers and sites are matched by provider IDs and primary domain, updating attributes without creating duplicate records.

---

## Endpoints Called

All API calls target base URL `https://my.gridpane.com/oauth/api/v1` using HTTP Bearer authentication (`Authorization: Bearer <api_key>`).

| Method | Endpoint | Purpose in Clockwork Control |
|---|---|---|
| `GET` | `/user` | Validates API token and retrieves account profile for diagnostic checks. |
| `GET` | `/server` | Discovers all managed VPS servers for inventory import and status polling. |
| `GET` | `/server/{id}` | Fetches individual server details, IP addresses, and provisioned services. |
| `GET` | `/site` | Discovers all WordPress sites, domains, and assigned system users. |
| `GET` | `/site/{id}` | Retrieves specific site configurations and document roots. |
| `GET` | `/system-user` | Inspects system users configured on servers. |
| `GET` | `/backups/schedules/site/{id}` | Inspects automated local and remote backup schedules. |
| `PUT` | `/site/run-wp-cli/{id}` | Dispatches remote WP-CLI commands through GridPane's API when SSH is disabled. |

---

## Capabilities & Transport

GridPane implements `Modules\Core\Contracts\HostingProvider` with the following capability flags:

- `CAP_SSH`: **Supported** in Full Access mode; disabled (`false`) when View-Only mode is active.
- `CAP_SERVER_LINKAGE`: **Supported**. Sites link directly to physical/virtual `servers` records.
- `CAP_CERT_SYNC`: **Supported**. External SSL certificate states are tracked and monitored.
- `CAP_ORPHAN_DETECTION`: **Supported**. Alerts if a site's parent server or panel record is removed.
- `CAP_COMPANION`: **Supported** in Full Access mode; disabled (`false`) when View-Only mode is active.
- `CAP_PERFORMANCE_SCAN`: **Delegated**. PageSpeed / Lighthouse scans run through standard web engines.
