---
title: Vultr
section: Integrations
order: 13
updated: 2026-09-05
author: Aaron Reimann
tags: [integrations, vultr, monitoring]
tracks: [modules/Vultr/src/**, app/Console/Commands/PollServers.php, app/Console/Commands/ReconcileProvider.php]
---

The fourth cloud provider Clockwork polls, alongside [DigitalOcean](/docs/integrations/digitalocean), [Hetzner](/docs/integrations/hetzner), and [Azure](/docs/integrations/azure). Same role: read-only, monitoring-only, never used for power actions. Unlike the other three, Vultr's public API has no metrics endpoint at all — this integration is alive/dead-state and IP-matching only.

> [!TIP]
> **Looking for Testers!**
> Have servers on Vultr? Help us test alive/dead checks and IP reconciliation. Check out our [Contributing & Module Testing Guide](/docs/getting-started/contributing) to test with live credentials, vibe code fixes with Claude, and submit a PR back!

## Why we use it

Some servers in the fleet live on Vultr rather than DigitalOcean. Clockwork detects which is which from SpinupWP's `provider_name` field on each server payload (`DigitalOcean`, `Hetzner`, `Linode`, `Vultr`, `Custom`) and stores the lowercased value on `servers.provider`, or via `clockwork:reconcile-provider` matching a manually-added server's hostname/IP against the account's live instance list.

## What's different from DigitalOcean

Vultr's REST API v2 has no equivalent of DO's `/monitoring/metrics/droplet/*` series — no CPU, memory, disk, or load, and no built-in agent that exposes one. `VultrCloudProvider::metrics()` always returns all-null; the green/yellow/red CPU signal on the dashboard never lights up for Vultr servers from this integration. The SSH-collected `LiveServerLoad` snapshot on the server detail page is the only live signal Vultr servers get.

Plan-slug naming is also its own scheme: `vc2-*` (Cloud Compute), `vhf-*` (High Frequency), `vhp-*` (High Performance), `voc-*` (Optimized Cloud / Dedicated vCPU), `vdc-*` (Dedicated Cloud), `vbm-*` (Bare Metal). `Modules\Vultr\VultrCloudProvider::sizeTier()` maps these to the human tier label shown in the server header; an unrecognized prefix falls back to the raw slug, same convention as every other `CloudProvider` adapter.

There's also no dedicated Font Awesome brand icon for Vultr — `iconClass()` reuses the generic `fa-solid fa-server` mark with Vultr's brand blue (`#007BFC`) as the accent color instead.

## Setup

1. Create a read-only API key at `my.vultr.com/settings/#settingsapi`.
2. Set `CLOCKWORK_VULTR_API_KEY` in `.env` (or `/settings/integrations`).
3. Test at `/settings/diagnostics` — the Vultr check hits `GET /account` and reports the authenticated account's name/email.

There's no dedicated `clockwork:vultr-test` artisan command (unlike DO/Hetzner/Azure, which predate the diagnostics-check pattern) — the `/settings/diagnostics` page is the only connectivity check, same as WP Engine/Kinsta/Cloudways.

## Auth

Bearer token in the `Authorization` header. `Modules\Vultr\VultrClient` reads it via `CredentialResolver` (`vultr.api_key` — database first, `config('clockwork.vultr.api_key')`/`.env` fallback).

## Endpoints we call

Base URL `https://api.vultr.com/v2` (override via `CLOCKWORK_VULTR_BASE_URL`).

| Method | Path | Purpose |
|---|---|---|
| GET | `/account` | Auth probe — `/settings/diagnostics` Vultr check. |
| GET | `/instances` | List every instance on the account, cursor-paginated (`meta.links.next`, not offset-based like DO/Hetzner). Used for IP↔server matching during `clockwork:reconcile-provider` and for the alive/dead check in `clockwork:poll-servers`. |

No metrics endpoint exists to call — see "What's different from DigitalOcean" above.

## Files

- `modules/Vultr/src/VultrClient.php` — the HTTP client. Cursor-paginates `/instances` internally so callers always get the full list in one call.
- `modules/Vultr/src/VultrCloudProvider.php` — the `CloudProvider` adapter (size tier, always-null metrics, alive-id/IP matching).
- `modules/Vultr/src/VultrCheck.php` — the `/settings/diagnostics` connectivity probe.
- `modules/Vultr/src/VultrServiceProvider.php` — registers the client binding, `CloudProvider`, and diagnostics check with the module registry.
- `app/Console/Commands/PollServers.php` / `ReconcileProvider.php` — dispatch to `VultrCloudProvider` for `provider='vultr'` rows via `CloudProviderRegistry`.
- Config: `config/clockwork.php` → `vultr` key.

## Scheduled jobs that depend on it

| Cadence | Command |
|---|---|
| every 5 min | `clockwork:poll-servers` — checks alive/dead state for Vultr servers; contributes no CPU metric. |
| daily 03:45 | `clockwork:reconcile-provider` — the only step that actually fills in `provider_id`/`size_slug`/`vcpus`/`memory_mb`/`disk_gb` for Vultr servers. `clockwork:import-spinupwp` (03:30) creates the row tagged `provider=vultr` from SpinupWP's `provider_name` field but has no Vultr cross-reference of its own (`ImportSpinupWp::upsertServer()` only branches DO/Hetzner) — it leaves `provider_id` null, and reconcile-provider picks up that unlinked row 15 minutes later and matches it against the live `/instances` list by IP. |

## Why we don't use the Vultr API for power actions

Same reason as every other cloud provider here. Vultr's `POST /instances/{id}/reboot` is a hard reset — risks InnoDB corruption on a live MySQL instance. We use `sudo shutdown -r +1` over SSH so the OS flushes filesystems cleanly first.

## Gotchas

- **No metrics, ever.** This isn't a temporary gap like Hetzner's missing memory/disk — Vultr's public API structurally has no time-series endpoint. Don't expect the CPU status dot to ever reflect real data for `provider='vultr'` servers from this integration.
- **Cursor pagination, not page-number.** `VultrClient::instances()` follows `meta.links.next` until it comes back empty — different shape from DO's `links.pages.next` URL and Hetzner's `meta.pagination.next_page` integer. Don't copy-paste pagination logic between these three clients without checking the actual response shape.
- **Server match is best-effort by public IPv4** (`main_ip`, plus any `v4[].ip` marked `main_ip`). Same limitation as DO/Hetzner — a Vultr instance with only a private network won't auto-link.
