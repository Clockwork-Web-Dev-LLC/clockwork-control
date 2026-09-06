---
title: DigitalOcean
section: Integrations
order: 10
updated: 2026-09-06
author: Aaron Reimann
tags: [integrations, digitalocean, monitoring]
tracks: [modules/DigitalOcean/src/**, app/Services/DigitalOcean/SpacesClient.php, app/Services/Monitoring/CpuStatusClassifier.php, app/Console/Commands/PollServers.php, app/Console/Commands/DigitalOceanTest.php]
---

We use DigitalOcean only for **monitoring metrics** — never for provisioning, never for power actions. CPU/memory/disk/load come from the DO API every 5 minutes; that's what turns a server "red" on the dashboard. This module is **verified** and in active production use across droplets.

DO is one of three cloud providers Clockwork polls. The sister pages are [Hetzner Cloud](/docs/integrations/hetzner) and [Azure](/docs/integrations/azure) — which servers go through which API is decided per-row from `servers.provider`, populated during the SpinupWP import (or by `clockwork:reconcile-provider` for manually-added servers).

## Why we use it

DigitalOcean exposes per-droplet time series for CPU jiffies, load average, memory (free / total / available), and filesystem (free / size). Those four series + a 15-minute jiffy delta give us the green/yellow/red signal on the dashboard. Without DO metrics, the only data we'd have on a server is whatever its sites send up over SSH — way too coarse to catch a server before it impacts a client.

## Setup

1. Create a Personal Access Token at `cloud.digitalocean.com/account/api/tokens`. **Read-only** scope is enough.
2. Set `CLOCKWORK_DIGITALOCEAN_TOKEN` in `.env`.
3. Test:

```bash
php artisan clockwork:digitalocean-test
```

Lists the account email + first page of droplets. If the token is wrong you get a 401.

## Auth

Bearer token in the `Authorization` header. `Modules\DigitalOcean\DigitalOceanClient` reads it via `CredentialResolver` (`digitalocean.token` — database first, `config('clockwork.digitalocean.token')`/`.env` fallback).

## Endpoints we call

Base URL `https://api.digitalocean.com/v2` (override via `CLOCKWORK_DIGITALOCEAN_BASE_URL`).

| Method | Path | Purpose |
|---|---|---|
| GET | `/account` | Sanity-check the token. |
| GET | `/droplets` | List droplets, 200 per page, paginated. Used during inventory import to match `provider_id` by public IPv4 for DO-provider servers. |
| GET | `/monitoring/metrics/droplet/cpu` | Per-droplet CPU jiffy series. |
| GET | `/monitoring/metrics/droplet/load_1` | 1-minute load. |
| GET | `/monitoring/metrics/droplet/memory_{free,total,available}` | Memory. **We use `available`, not `free`** — `MemAvailable` is what visiting clients actually have access to (`memory_free` looks 90%+ used because the kernel reclaimably caches). |
| GET | `/monitoring/metrics/droplet/filesystem_{free,size}` | Disk. |

The metric endpoints take a 15-minute window (override via `CLOCKWORK_METRICS_WINDOW_MINUTES`).

## Files

- `modules/DigitalOcean/src/DigitalOceanClient.php` — the HTTP client.
- `modules/DigitalOcean/src/DigitalOceanMetricsParser.php` — parses DO's raw metrics payloads into `cpu_pct`/`memory_pct`/`disk_pct`/`load_1`.
- `modules/DigitalOcean/src/DigitalOceanCloudProvider.php` — the `CloudProvider` adapter.
- `app/Services/Monitoring/CpuStatusClassifier.php` — turns a CPU percentage into the `green|yellow|red|unknown` status on `servers.status`. Provider-agnostic (used for DO, Hetzner, and Azure alike) — not part of the DigitalOcean module, even though it originated there.
- `app/Console/Commands/PollServers.php` — the scheduled command.
- `app/Console/Commands/DigitalOceanTest.php` — connectivity check.
- Config: `config/clockwork.php` → `digitalocean` key.

## Scheduled jobs that depend on it

| Cadence | Command |
|---|---|
| every 5 min | `clockwork:poll-servers` — pulls metrics, writes `server_metrics`, evaluates green/yellow/red. |

## Why we don't use the DO API for power actions

DO's reboot endpoint is a hard hypervisor reboot — can corrupt InnoDB. We use `sudo shutdown -r +1` over SSH instead so the OS flushes filesystems cleanly. The `+1` (one minute) gives the SSH command time to return. SpinupWP's API has no reboot endpoint at all.

## Gotchas

- **`memory_free` ≠ available.** Use `memory_available`. We had this bug for a while and it polluted 24h averages.
- **Droplet match is best-effort by public IPv4.** Some servers (Azure, hand-rolled) won't have a `provider_id` — the column is nullable.
- **Metrics windows >24h get noisy.** Shorter windows are sharper signal.
