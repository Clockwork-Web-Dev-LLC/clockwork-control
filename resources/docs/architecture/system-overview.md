---
title: System overview
section: Architecture
order: 10
updated: 2026-09-03
author: Aaron Reimann
tags: [architecture, overview, stack, pressable]
---

A high-level map of how the app is built and where data flows. If you're new to the codebase, read this once and the other architecture pages will slot into place.

## What it is

Clockwork is a Laravel 13 web app on PHP 8.4. It runs locally on the agency's home network — laptop today, Mac Studio later — against a fleet of ~50 SpinupWP-managed servers (DigitalOcean, Hetzner Cloud, and Azure VMs) that host ~150 WordPress sites, plus ~90 more sites hosted on Pressable with no server layer at all. There is no public URL and no client login. Every account on Clockwork is an agency account.

The app sits **next to** SpinupWP and Pressable, not on top of either. They run the actual hosting; Clockwork watches it, reaches into it when it needs to (SSH for SpinupWP, an API + async-command transport for Pressable), and surfaces what needs human attention. See [Concepts → Server, Site, Care plan, Hosting tier](/docs/concepts/server-site-care-plan) for how the two hosting models differ under one `Site` row.

## The stack

| Layer | Choice |
|---|---|
| Language | PHP 8.4 |
| Framework | Laravel 13 (server-rendered Blade) |
| Database | MySQL 9.6 (SQLite supported for dev quirks) |
| Frontend | Tailwind 4, Alpine.js 3, ECharts 6, Font Awesome 7 |
| Build | Vite 8 + `laravel/vite-plugin` |
| SSH library | `phpseclib/phpseclib` v3 |
| S3 client | `league/flysystem-aws-s3-v3` |
| Auth | `laravel/socialite` (Google) |
| Queue / cache / sessions | Database driver (no Redis required for v1) |
| Local LLM | LM Studio over loopback OpenAI-compatible API |

We deliberately stayed traditional MVC. There's no SPA, no JS framework, no GraphQL. Forms post, controllers render Blade. Alpine handles the bits that need to be reactive in the browser. ECharts handles the data viz.

## How data flows

The app pulls everything; nothing pushes into it (the home LAN isn't reachable from outside).

```
SpinupWP ─── inventory ─────────┐
Pressable ─── inventory + async commands ┤
DigitalOcean + Hetzner + Azure ─ metrics ┤
DO Spaces ─── backup history ────┤
Cloudflare ─── DNS + WAF rules ──┤
Bill.com ─── customers + invoices ┤
WP sites (Companion) ─── snapshots ┤
WP sites (SSH+SQL fallback) ─────┤      ┌─────────────┐
nginx logs (SSH tail) ───────────┼─────►│ Clockwork   │──► Mattermost / Slack alerts
LLAR / Wordfence (DB pull) ──────┤      │  Laravel    │──► Mailgun (forms)
External blacklists ─────────────┤      │  + MySQL    │──► fail2ban over SSH
GTmetrix / PSI / Sucuri / Safe Browsing ─┤      └──────┬──────┘   ──► (admin web UI)
                                                        │
                                                        └──► S3 (targets.json, read by
                                                             standalone backup-relay droplet)
                                                        ◄──  S3 (last-report.json, pulled back)
```

Outbound side effects are: Mattermost/Slack messages, Mailgun emails, fail2ban bans pushed over SSH, Cloudflare WAF/rate-limit rule writes, and — Pressable-specific — arbitrary bash/wp-cli commands run on a client's site via Pressable's async command API (see [Architecture → Security model](/docs/architecture/security-model)'s note on treating that credential like an SSH key, not a scoped read token). That's the full list — note the S3 leg above is push/pull only, same as everything else; the backup-relay droplet never connects to Clockwork directly, since the home LAN isn't reachable from outside (see [Features → Backup relay](/docs/features/backup-relay)).

## The big patterns

A few decisions are load-bearing and worth knowing up front. The dedicated pages cover each one in detail; this is the orientation list.

- **SSH is the substrate — for SpinupWP.** Every SpinupWP server is reached as a non-root sudo user (default `clockwork-deploy`) over SSH. We do not use the cloud-provider APIs for anything but metrics. Pressable has no SSH at all; its substrate is a REST API plus an async command-execution endpoint that Clockwork wraps into something synchronous. See [Integrations → SSH + fail2ban](/docs/integrations/ssh-and-fail2ban) and [Integrations → Pressable](/docs/integrations/pressable).
- **Blocking is fail2ban-mediated — for SpinupWP.** A `clockwork` jail is provisioned once per server. Bans go via `sudo fail2ban-client set clockwork banip <ip>`. fail2ban handles iptables, persistence, and TTL. Pressable has no inbound-blocking equivalent at all — structurally, not just "not built yet." See [Architecture → Ingest pipeline](/docs/architecture/ingest-pipeline).
- **Ingest is human-in-the-loop by default — for SpinupWP.** LLAR + Wordfence + nginx logs feed a `review_queue`; an operator clicks Approve. The "auto-approve repeats" toggle short-circuits step 2 for IPs sighted 2+ times. None of this reaches Pressable sites (no logs to tail, no direct DB to pull from). See [Features → Review queue](/docs/features/review-queue).
- **Companion mu-plugin coexists with SSH+SQL (and, for Pressable, the async command transport).** Where Companion is installed, we prefer signed REST calls. Where it isn't, the SSH+SQL fallback works for SpinupWP sites; Pressable sites without Companion have no fallback at all for anything that needs command execution (checksum verification, malware scan). The `companion_capabilities` column gates which path runs per feature. See [Architecture → Companion plugin](/docs/architecture/companion-plugin).
- **Auth = Google OAuth + a database allowlist.** The `users` table IS the allowlist. No auto-provisioning. See [Architecture → Security model](/docs/architecture/security-model).
- **The scheduler is load-bearing.** A one-line crontab entry (`* * * * * ... php artisan schedule:run`) drives drainers (bans, updates) and ingest — deliberately *not* a long-running `schedule:work` daemon (which can hold a stale cached PHP binary path across package upgrades). If the scheduler stops, the UI keeps rendering but everything drifts. `clockwork:scheduler-heartbeat` writes a timestamp every minute; the web UI flags it after 5 minutes of silence. See [Runbooks → Scheduler stuck](/docs/runbooks/scheduler-stuck) for architectural details and [Reference → Scheduled jobs](/docs/reference/scheduled-jobs) for what runs when.

## Surfaces

Five top-level surfaces in the UI:

| Surface | URL | Job |
|---|---|---|
| Dashboard | `/` | Server health, sorted by attention. SpinupWP-only — Pressable sites have no server to show here. |
| Sites | `/sites` | Fleet-wide site list across both hosting providers — the one place Pressable sites show up alongside SpinupWP's. |
| Issues | `/issues` | Consolidated "what needs human eyes." |
| Bans | `/bans` | Queue + active + history. SpinupWP-only. |
| Capacity | `/capacity` | Shared-server pressure + over-quota visits. SpinupWP-only (no server tier concept for Pressable). |
| Monitoring | `/monitoring` | Fleet-wide uptime view. Both providers. |
| Updates | `/updates` | Fleet-wide plugin/theme/core/translation updates. Both providers (reads Companion's snapshot either way). |

Plus per-server (`/servers/{id}`) and per-site (`/sites/{id}`) detail pages, with sub-tabs for their respective sub-features.

## Two things this app is NOT

1. **Not a SaaS product.** No public URL. No multi-tenant model. The DB holds ~150 sets of SSH + WP database credentials for the SpinupWP fleet, plus one account-level Pressable API credential that can reach every Pressable site (no per-site scoping exists on Pressable's side) — encrypted at rest with `APP_KEY`-derived AES-256-GCM, but still the highest-value target on the host. Keep it on the LAN.
2. **Not a SpinupWP (or Pressable) replacement.** They provision and run the actual hosting. Clockwork watches it. `Modules\SpinupWp\SpinupWpClient` is read-only — we never write to SpinupWP; the Pressable command-execution transport is the one place we *do* actively act on a client's site (Companion install, checksum verification), by design.

## Where to read next

- [Data model](/docs/architecture/data-model) — every table and how it relates.
- [Ingest pipeline](/docs/architecture/ingest-pipeline) — nginx + LLAR + Wordfence → review queue → fail2ban.
- [Security model](/docs/architecture/security-model) — OAuth, allowlist, encrypted columns, dual CF tokens, HMAC-signed Companion.
- [Companion plugin](/docs/architecture/companion-plugin) — what Companion is and why we built it.
- [Integrations → Pressable](/docs/integrations/pressable) — the second hosting provider, and everywhere it changes the picture above.
- [Concepts → Server, Site, Care plan, Hosting tier](/docs/concepts/server-site-care-plan) — the mental model for how one `Site` row means two very different things depending on `hosting_provider`.
