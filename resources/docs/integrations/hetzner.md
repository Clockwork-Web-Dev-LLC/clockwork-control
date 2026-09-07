---
title: Hetzner Cloud
section: Integrations
order: 11
updated: 2026-09-07
author: Aaron Reimann
tags: [integrations, hetzner, monitoring]
tracks: [modules/Hetzner/src/**, app/Console/Commands/PollServers.php, app/Console/Commands/HetznerTest.php]
---

The second cloud provider Clockwork pulls metrics from, alongside DigitalOcean and [Azure](/docs/integrations/azure). Same role: read-only, monitoring-only, never used for power actions.


## Why we use it

Some servers in the fleet live on Hetzner Cloud rather than DigitalOcean. Clockwork detects which is which from SpinupWP's `provider_name` field on each server payload (`DigitalOcean`, `Hetzner`, `Linode`, `Vultr`, `Custom`) and stores the lowercased value on `servers.provider`. `clockwork:poll-servers` then branches on that column to call the matching cloud's metrics API.

## What's different from DigitalOcean

Hetzner's metrics surface is narrower. They only expose three series — CPU (as a ready-made percentage 0–100), disk IO rate, and network throughput. There is **no equivalent** of DO's `memory_available`, `filesystem_free`, or `load_1`. Until Hetzner ships those (or we layer in an SSH-collected metrics path) the secondary panels on the server detail page show "no data" for Hetzner servers — CPU still gates the green/yellow/red signal.

The size-slug naming also differs: `cx*` (Shared AMD), `cpx*` (Shared AMD EPYC), `ccx*` (Dedicated vCPU), `cax*` (Shared ARM Ampere). Each provider's `CloudProvider` adapter maps its own slugs to a human tier label for the server header — `Modules\Hetzner\HetznerCloudProvider::sizeTier()` for Hetzner.

## Setup

1. Create an API token at `console.hetzner.cloud → project → Security → API Tokens`. **Read-only** scope is enough.
2. Set `CLOCKWORK_HETZNER_TOKEN` in `.env`.
3. Test:

```bash
php artisan clockwork:hetzner-test
```

Lists the visible locations (auth probe — Hetzner has no `/account` endpoint, so we use `/locations` instead) and the first 10 servers in the project.

> **Per-project tokens.** Hetzner tokens are scoped to a single project. If you have multiple Hetzner projects the importer only sees servers in the one whose token is configured.

## Auth

Bearer token in the `Authorization` header. `Modules\Hetzner\HetznerClient` reads it via `CredentialResolver` (`hetzner.token` — database first, `config('clockwork.hetzner.token')`/`.env` fallback).

## Endpoints we call

Base URL `https://api.hetzner.cloud/v1` (override via `CLOCKWORK_HETZNER_BASE_URL`).

| Method | Path | Purpose |
|---|---|---|
| GET | `/locations` | Auth probe — used by `clockwork:hetzner-test` and the inventory bootstrap. |
| GET | `/servers` | List servers, paginated. Used during inventory import to match `provider_id` by public IPv4. Each row carries `server_type.{name,cores,memory,disk}` and `public_net.ipv4.ip`. |
| GET | `/servers/{id}/metrics?type=cpu` | Per-server CPU% time series. |
| GET | `/servers/{id}/metrics?type=disk` | Disk IO rate (not capacity %). |
| GET | `/servers/{id}/metrics?type=network` | Network throughput. |

The metric endpoints use a 15-minute window (override via `CLOCKWORK_METRICS_WINDOW_MINUTES`). Hetzner expects RFC-3339 timestamps; `HetznerClient::serverMetrics` accepts Unix seconds and converts on the way out so callers match the DO client signature.

The metrics payload shape is `metrics.time_series.<key>.values = [[unix_ts, "stringified_number"], …]` — already a percentage for CPU, no jiffy delta math needed. `HetznerMetricsParser::latestCpuPercent` pulls the most recent sample.

## Files

- `modules/Hetzner/src/HetznerClient.php` — the HTTP client.
- `modules/Hetzner/src/HetznerMetricsParser.php` — selector for the latest sample per series.
- `modules/Hetzner/src/HetznerCloudProvider.php` — the `CloudProvider` adapter (size tier, metrics, alive-id/IP matching).
- `modules/Hetzner/src/HetznerServiceProvider.php` — registers the client binding, `CloudProvider`, and diagnostics check with the module registry.
- `modules/Hetzner/src/HetznerCloudProvider.php` — Hetzner's `CloudProvider` adapter, including `sizeTier()`.
- `app/Console/Commands/PollServers.php` — branches on `Server::provider` and calls the matching client.
- `app/Console/Commands/HetznerTest.php` — connectivity check.
- Config: `config/clockwork.php` → `hetzner` key.

## Scheduled jobs that depend on it

| Cadence | Command |
|---|---|
| every 5 min | `clockwork:poll-servers` — pulls CPU%, evaluates green/yellow/red. Same job as DO; one tick per server, branched by provider. |
| daily 03:30 | `clockwork:import-spinupwp` — cross-references SpinupWP servers tagged `provider_name=Hetzner` against `/servers`, populates `provider_id`, `size_slug`, `vcpus`, `memory_mb`, `disk_gb`. |

## Why we don't use the Hetzner API for power actions

Same reason as DO. Hetzner's `POST /servers/{id}/actions/reboot` is a hard reset — risks InnoDB corruption. We use `sudo shutdown -r +1` over SSH so the OS flushes filesystems cleanly.

## Gotchas

- **No memory / disk-free / load metrics.** Three of the four DO panels are empty on Hetzner servers. Don't read that as "the server is fine" — read it as "Hetzner doesn't tell us." Use the SSH-side `LiveServerLoad` snapshot on the detail page for those.
- **Server match is best-effort by public IPv4.** Same as DO. A Hetzner server with no public IPv4 (private network only) won't be auto-linked — `provider_id` stays null and that server simply doesn't appear in the metrics poll.
- **Tokens are per-project.** Multi-project Hetzner accounts need one token per project; v1 only supports one.
- **Stringified numeric values.** Hetzner returns metric values as strings, not floats. `HetznerMetricsParser` casts on read — don't compare raw API responses with `===`.
