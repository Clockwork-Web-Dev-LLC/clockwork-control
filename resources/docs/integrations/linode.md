---
title: Linode (Akamai)
section: Integrations
order: 14
updated: 2026-09-09
author: Aaron Reimann
tags: [integrations, linode, akamai, monitoring]
tracks: [modules/Linode/src/**, app/Console/Commands/PollServers.php, app/Console/Commands/ReconcileProvider.php]
---

The fifth cloud provider Clockwork polls, alongside [DigitalOcean](/docs/integrations/digitalocean), [Hetzner](/docs/integrations/hetzner), [Azure](/docs/integrations/azure), and [Vultr](/docs/integrations/vultr). Same role: read-only, monitoring-only, never used for power actions. Linode (branded Akamai Cloud Computing) is the one cloud provider here with a real CPU metrics endpoint, closer to Hetzner's shape than DO's.

> [!TIP]
> **Looking for Testers!**
> Run servers on Linode (Akamai)? Help us verify normalized multi-core CPU metrics and server reconciliation. Check out our [Contributing & Module Testing Guide](/docs/getting-started/contributing) to test with live credentials, vibe code fixes with Claude, and submit a PR back!

## Why we use it

Some servers in the fleet live on Linode rather than DigitalOcean. Clockwork detects which is which from SpinupWP's `provider_name` field on each server payload (`DigitalOcean`, `Hetzner`, `Linode`, `Vultr`, `Custom`) and stores the lowercased value on `servers.provider`, or via `clockwork:reconcile-provider` matching a manually-added server's hostname/IP against the account's live instance list.

## What's different from DigitalOcean

Linode's `/v4/linode/instances/{id}/stats` endpoint returns a `cpu` time series, but as a **percentage summed across all vCPUs** (e.g. a 2-vCPU instance fully busy on one core reports ~50%, fully busy on both reports ~100–200%), not a single normalized 0–100 value like DO or Hetzner. `LinodeCloudProvider::metrics()` divides the latest sample by `server->vcpus` and clamps to `[0, 100]` before it reaches the dashboard — comparing a raw Linode `stats` payload against another provider's CPU number directly will be off by a factor of vCPU count. There is **no memory, disk, or load** series — same gap as Hetzner and Azure; those panels stay "no data" for Linode servers.

Plan-slug naming is its own scheme: `g6-standard-*` (Shared CPU), `g6-dedicated-*` (Dedicated CPU), `g6-nanode-*` (Nanode / entry level), `g6-highmem-*` (High Memory), `g6-gpu-*` (Dedicated GPU). `Modules\Linode\LinodeCloudProvider::sizeTier()` matches on `str_contains()` rather than a prefix, since Linode's `type` slugs don't share a fixed-length prefix scheme the way Vultr's do; an unrecognized slug falls back to the raw string.

Linode does have a real Font Awesome brand icon (`fa-brands fa-linode`), unlike Vultr.

## Setup

1. Create a personal access token with read-only "Linodes" scope at `cloud.linode.com/profile/tokens`.
2. Set `CLOCKWORK_LINODE_TOKEN` in `.env` (or `/settings/integrations`).
3. Test at `/settings/diagnostics` — the Linode check hits `GET /v4/account` and reports the authenticated account's email/company.

There's no dedicated `clockwork:linode-test` artisan command (unlike DO/Hetzner/Azure, which predate the diagnostics-check pattern) — the `/settings/diagnostics` page is the only connectivity check, same as WP Engine/Kinsta/Cloudways/Vultr.

## Auth

Bearer token in the `Authorization` header. `Modules\Linode\LinodeClient` reads it via `CredentialResolver` (`linode.token` — database first, `config('clockwork.linode.token')`/`.env` fallback).

## Endpoints we call

Base URL `https://api.linode.com/v4` (override via `CLOCKWORK_LINODE_BASE_URL`).

| Method | Path | Purpose |
|---|---|---|
| GET | `/account` | Auth probe — `/settings/diagnostics` Linode check. |
| GET | `/linode/instances` | List every linode on the account, page-paginated (`page`/`page_size`, following the response's `pages` count — not cursor-based like Vultr). Used for IP↔server matching during `clockwork:reconcile-provider` and the alive/dead check in `clockwork:poll-servers`. |
| GET | `/linode/instances/{id}/stats` | CPU time series. Per-instance, called once per poll for every linked Linode server. |

Like every `CloudProvider` here, metrics are fetched per-server, not fleet-wide — `clockwork:poll-servers` calls `LinodeCloudProvider::metrics()` once per `provider='linode'` row on each tick. Linode's win is that a single `/stats` call returns everything in one shot; DO's equivalent needs up to six separate metric-series requests per droplet (CPU, load, memory ×2, disk ×2) to assemble the same four dashboard fields.

## Files

- `modules/Linode/src/LinodeClient.php` — the HTTP client. Page-paginates `/linode/instances`; `instanceStats()` is a thin per-instance `/stats` wrapper.
- `modules/Linode/src/LinodeMetricsParser.php` — pulls the most recent `[timestamp, value]` pair out of the `cpu` series.
- `modules/Linode/src/LinodeCloudProvider.php` — the `CloudProvider` adapter (size tier, vCPU-normalized CPU metric, alive-id/IP matching).
- `modules/Linode/src/LinodeCheck.php` — the `/settings/diagnostics` connectivity probe.
- `modules/Linode/src/LinodeServiceProvider.php` — registers the client binding, `CloudProvider`, and diagnostics check with the module registry.
- `app/Console/Commands/PollServers.php` / `ReconcileProvider.php` — dispatch to `LinodeCloudProvider` for `provider='linode'` rows via `CloudProviderRegistry`.
- Config: `config/clockwork.php` → `linode` key.

## Scheduled jobs that depend on it

| Cadence | Command |
|---|---|
| every 5 min | `clockwork:poll-servers` — pulls CPU%, evaluates green/yellow/red (CPU-only, per the vCPU-normalization gotcha above). Needs `provider_id` already set, so this has nothing to poll until reconcile-provider has run at least once for a given server. |
| daily 03:45 | `clockwork:reconcile-provider` — the only step that actually fills in `provider_id`/`size_slug`/`vcpus`/`memory_mb`/`disk_gb` for Linode servers. `clockwork:import-spinupwp` (03:30) creates the row tagged `provider=linode` from SpinupWP's `provider_name` field but has no Linode cross-reference of its own (`ImportSpinupWp::upsertServer()` only branches DO/Hetzner) — it leaves `provider_id` null, and reconcile-provider picks up that unlinked row 15 minutes later and matches it against the live `/linode/instances` list by IP. |

## Why we don't use the Linode API for power actions

Same reason as every other cloud provider here. Linode's `POST /linode/instances/{id}/reboot` is a hard reset — risks InnoDB corruption on a live MySQL instance. We use `sudo shutdown -r +1` over SSH so the OS flushes filesystems cleanly first.

## Gotchas

- **CPU is fleet-summed, not normalized.** Divide by `vcpus` before comparing against DO/Hetzner/Azure's already-normalized percentages — `LinodeCloudProvider::metrics()` does this for you on the way into `server_metrics`, but don't re-derive it from a raw `/stats` call elsewhere without repeating that division.
- **No memory / disk / load metrics.** Same three-panel gap as Hetzner and Azure. Use the SSH-side `LiveServerLoad` snapshot on the detail page for those.
- **Page-number pagination, not cursor.** `LinodeClient::instances()` loops on `page`/`pages` from the response body — different shape from Vultr's `meta.links.next` cursor and DO's `links.pages.next` URL.
- **Server match is best-effort by public IPv4**, preferring a non-private/non-reserved address when an instance has more than one `ipv4` entry. Same limitation as every other cloud provider here — a Linode with only a private network won't auto-link.
