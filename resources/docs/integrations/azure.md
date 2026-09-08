---
title: Azure
section: Integrations
order: 12
updated: 2026-09-08
author: Aaron Reimann
tags: [integrations, azure, monitoring]
tracks: [modules/Azure/src/**, app/Console/Commands/{PollServers,ReconcileProvider,AzureTest}.php, app/Models/Server.php]
---

The third cloud provider Clockwork pulls metrics from, alongside [DigitalOcean](/docs/integrations/digitalocean) and [Hetzner Cloud](/docs/integrations/hetzner). Same role: read-only, monitoring-only, never used for power actions. No credentials needed to ship — polling is a no-op until `CLOCKWORK_AZURE_*` values are set.

> [!TIP]
> **Looking for Testers!**
> Host virtual machines on Microsoft Azure? Help us verify Azure VM metrics and token management. Check out our [Contributing & Module Testing Guide](/docs/getting-started/contributing) to test with live credentials, vibe code fixes with Claude, and submit a PR back!

## Why we use it

Same reasoning as Hetzner: some fleet servers live on Azure rather than DigitalOcean, and `clockwork:poll-servers` needs a metrics path for whichever cloud actually hosts a given box. `servers.provider = 'azure'` (`Server::PROVIDER_AZURE`) is the branch key; `getProviderLabelAttribute()` renders it as "Azure VM" on the server header.

## What's different from DigitalOcean and Hetzner

- **Auth is OAuth 2.0 client-credentials**, not a static bearer token — a Service Principal (Azure AD / Entra ID app registration) with at least Reader access on the subscription or resource group. `AzureClient::token()` caches the bearer token in-memory for its lifetime (typically 1 hour, refreshed 60s early).
- **Metrics are CPU + memory only** — `Percentage CPU` and `Available Memory Bytes` via Azure Monitor. No disk or load series, unlike DO (which has both) or Hetzner (which has disk IO but not memory). `AzureMetricsParser::memoryUsedPercent()` derives a used-percentage from the available-bytes sample and the server's stored `memory_mb`.
- **VM existence isn't re-checked every poll.** DO and Hetzner both re-verify a server still exists on every `poll-servers` run (to catch provider-side deletes); Azure resource IDs are treated as stable long-lived paths and skip that check. If decommissioned Azure VMs become a real problem, add the check — it doesn't exist today.
- **IP matching goes through an extra hop.** Azure doesn't expose a public IP directly on the VM resource — it lives on a separate Network Interface Card (NIC) resource. `ReconcileProvider::fetchAzureByIp()` reads the public IP resource's `ipConfiguration.id` (which contains the NIC's resource path) and extracts the VM name from the NIC name **by naming convention** (NIC named after its VM — true for standard SpinupWP-style deployments, not guaranteed by the Azure API itself). A NIC that doesn't follow that convention won't match, and the server stays `provider_id = null`.

## Setup

1. Register a Service Principal (Azure Portal → Entra ID → App registrations → New registration), or use an existing one.
2. Grant it **Reader** on the subscription (or the specific resource group holding the VMs): Subscription → Access control (IAM) → Add role assignment.
3. Create a client secret for the app registration (App registrations → your app → Certificates & secrets → New client secret).
4. Collect four values: Tenant ID, Application (client) ID, the client secret, and the Subscription ID.
5. Set in `.env`:

   ```
   CLOCKWORK_AZURE_TENANT_ID=...
   CLOCKWORK_AZURE_CLIENT_ID=...
   CLOCKWORK_AZURE_CLIENT_SECRET=...
   CLOCKWORK_AZURE_SUBSCRIPTION_ID=...
   ```

6. Test:

```bash
php artisan clockwork:azure-test
```

Authenticates, prints the resolved subscription name, and lists VMs + public IPs in the subscription.

## Auth

OAuth 2.0 client-credentials against `login.microsoftonline.com/{tenant}/oauth2/v2.0/token`, scope `https://management.azure.com/.default`. `Modules\Azure\AzureClient` handles token acquisition and caching; every ARM/Monitor call goes out with `Authorization: Bearer <token>`.

## Endpoints we call

Base URL `https://management.azure.com` (`CLOCKWORK_AZURE_BASE_URL`); auth against `https://login.microsoftonline.com` (`CLOCKWORK_AZURE_LOGIN_URL`).

| Method | Path | Purpose |
|---|---|---|
| POST | `/{tenant}/oauth2/v2.0/token` | Client-credentials token acquisition. |
| GET | `/subscriptions/{id}` | Auth probe for `clockwork:azure-test`. |
| GET | `/subscriptions/{id}/providers/Microsoft.Compute/virtualMachines` | List VMs — resource ID (used as `provider_id`), name, location, `vmSize`, NIC references. Paginated via `nextLink`. |
| GET | `/subscriptions/{id}/providers/Microsoft.Network/publicIPAddresses` | List public IPs, for `ReconcileProvider`'s IP→VM matching. |
| GET | `{vmResourceId}/providers/microsoft.insights/metrics` | Azure Monitor metrics — `Percentage CPU` + `Available Memory Bytes`, 1-minute intervals, `Average` aggregation, over the same window (`CLOCKWORK_METRICS_WINDOW_MINUTES`) DO and Hetzner use. |

## Files

- `modules/Azure/src/AzureClient.php` — the HTTP client (auth, VM/IP listing, metrics).
- `modules/Azure/src/AzureMetricsParser.php` — normalizes the Monitor response to `cpu_pct` / `memory_pct`.
- `modules/Azure/src/AzureCloudProvider.php` — the `CloudProvider` adapter.
- `modules/Azure/src/AzureServiceProvider.php` — registers the client binding, `CloudProvider`, and diagnostics check with the module registry.
- `app/Console/Commands/PollServers.php` — branches on `Server::provider`, calls the matching client. Azure is a third parallel branch alongside DO and Hetzner.
- `app/Console/Commands/ReconcileProvider.php` — Azure IP matching via public IP → NIC name → VM resource ID.
- `app/Console/Commands/AzureTest.php` — connectivity check.
- Config: `config/clockwork.php` → `azure` key.

## Scheduled jobs that depend on it

| Cadence | Command |
|---|---|
| every 5 min | `clockwork:poll-servers` — same job as DO/Hetzner; one tick per server, branched by provider. |
| daily 03:45 | `clockwork:reconcile-provider` — matches `provider_id`-less servers against DO droplets, Hetzner servers, and now Azure VMs. |

## Why we don't use the Azure API for power actions

Same reasoning as DO and Hetzner — a hard VM restart via the ARM API risks filesystem corruption. We use `sudo shutdown -r +1` over SSH so the OS flushes cleanly, regardless of which cloud is underneath.

## Gotchas

- **No disk or load metrics.** Only CPU and memory. The server detail page's disk/load panels stay "no data" for Azure VMs, same story as Hetzner's missing panels.
- **NIC-name-matching is a convention, not an API guarantee.** If an Azure VM's NIC isn't named after the VM (a manually-provisioned box, a non-standard deployment), `ReconcileProvider` won't link it automatically — check `--dry-run` output and link manually if a server stays unmatched.
- **VM deletion isn't detected.** Unlike DO/Hetzner, a decommissioned Azure VM doesn't get flagged during `poll-servers` — its `provider_id` just starts erroring on the next metrics call. Worth building the existence check if this bites in practice.
- **Client secret expires.** Azure app-registration secrets have a mandatory expiry (max 2 years, often shorter by org policy) — set a calendar reminder, unlike DO/Hetzner's non-expiring tokens.
- **`isConfigured()` requires all four values.** Tenant ID, client ID, client secret, and subscription ID all need to be set — a partial config (e.g. secret rotated but subscription ID typo'd) makes the whole Azure branch silently skip, same "no-op until fully configured" pattern as every other integration here.
