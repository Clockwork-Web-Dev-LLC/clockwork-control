---
title: Dashboard
section: Features
order: 10
updated: 2026-09-07
author: Aaron Reimann
tags: [dashboard, fleet, monitoring]
tracks: [app/Http/Controllers/DashboardController.php, resources/views/dashboard/**]
---

The home page (`/`) is the fleet view — of servers. One card per server, sorted with anything-needing-attention on top. If the dashboard is green and the Issues page is empty, you can close the tab.

This page is server-centric, so **Pressable sites don't appear here at all** — Pressable has no server concept for a card to represent. For a fleet view that covers both hosting providers, see [Features → Sites (fleet view)](/docs/features/sites-fleet-view).

## What you see

Each server gets a card with:

- A health pill — **green** (fine), **yellow** (needs attention), **red** (something's wrong now), **gray** (ignored or unreachable).
- A 1px **status-tinted card border** matching the pill — yellow for Watch, red for Alert, gray for Unknown. Healthy cards keep the default neutral border so the eye is drawn only to the cards that need attention.
- Three sparklines and a numeric readout: CPU %, memory %, disk %.
- A site count and the top sites by current PHP-FPM share.
- Inline status pills for SSL state, Cloudflare state, and recent ban activity.
- Gray tag pills for whatever tags the server carries (color comes from the tag itself, rendered low-contrast so it doesn't compete with the health pill). A server tagged **staging** additionally gets a "Not monitored" pill — see below.
- Per-server action buttons: View detail, Test SSH, Provision fail2ban (when not yet provisioned), Toggle ignore.

Sort order is "worst first" — red cards float to the top, then yellow, then green. Within each color tier, more-recent transitions sort higher.

## Tag filter

A chip strip above the grid lists every tag that has at least one server, each showing the server count and rendered in the tag's own color (`Tag::color`). Click a chip to filter the grid to `?tag=<slug>` — `DashboardController::index()` resolves the slug via `Tag::where('slug', ...)->first()` and constrains the server query with `whereHas('tags', ...)`. An unknown or empty slug is silently ignored (falls back to "all servers"), so a stale bookmark never 404s. Click the "All" chip (or drop the query string) to clear the filter. Tags themselves are managed on the Settings → Tags page and assigned per-server from the server detail page — see [Features → Server updates + reboots → Tags](/docs/features/server-updates-and-reboots) for how tagging (in particular the **staging** tag) is used.

A server tagged **staging** shows a gray italic "Not monitored" pill next to its tag chip, with a tooltip explaining why: staging servers are excluded from uptime probes, security scans, plugin checks, fail2ban, and the update/reboot pipeline via `Server::scopeMonitored()`, but they're deliberately still shown on the dashboard (and countable via `is_ignored`) so operators know they exist.

## Dynamic per-site navigation

On per-site views (`/sites/{id}`), navigation tabs respect both site tier and module enablement:
- **Performance**: visible only for care-plan enabled sites.
- **Updates**: visible only when the site's host supports remote WP updates.
- **Forms**: visible only when the site is on a care plan AND the `contact-forms` module is enabled (`ModuleStateResolver::isEnabled('contact-forms')`). When disabled via `/settings/modules`, the Forms tab is omitted cleanly.

## How a server becomes red or yellow

CPU drives most of it. The thresholds are tunable in `.env` (`CLOCKWORK_CPU_RED_THRESHOLD` / `_YELLOW_THRESHOLD`, defaults 90 / 70). Memory and disk add yellow flags above their own thresholds — when the cloud provider exposes those series. The exact rules live in `App\Services\Monitoring\CpuStatusClassifier` — provider-agnostic, applied the same way regardless of which cloud a server is on.

Polling runs every 5 minutes via whichever cloud-provider API owns each server. DigitalOcean droplets get the full suite (CPU, memory, disk, load); Azure VMs get CPU and memory (disk requires the Azure Monitor Agent, not yet wired up); Hetzner Cloud servers get CPU only and the secondary panels show "no data." See [Integrations → DigitalOcean](/docs/integrations/digitalocean), [Integrations → Azure](/docs/integrations/azure), and [Integrations → Hetzner Cloud](/docs/integrations/hetzner) for what each reads, and [Architecture → System overview](/docs/architecture/system-overview) for how it gets surfaced.

## Sections

The dashboard is divided:

1. **Active servers** — sorted by health, worst on top.
2. **Ignored servers** — `is_ignored=true`, kept visible (muted) so you remember they exist.

A server flips to ignored either manually (the Toggle ignore button) or auto-flagged on first import via `CLOCKWORK_AUTO_IGNORE_PATTERNS` (empty by default — comma-separated substrings, case-insensitive; set your own per-operator). Auto-flag only sets the bit on **first creation** — manual changes survive subsequent imports.

## Search

Press `/` from anywhere on the dashboard (or any page) to focus the site search at the top right. Returns matching sites as you type. Endpoint: `GET /search/sites?q=`.

## What's NOT on the dashboard

- Detailed metric charts — go to the per-server page (`/servers/{id}/stats`).
- The review queue — that's `/bans/queue`.
- Site-level health — sites surface on the per-server page or via Search.
- Anything historical beyond the sparklines — see `/maintenance-history` for the long view.

## Issues page additions

Several feature areas surface on the `/issues` page rather than the main dashboard:

- **Patches Available** rows now include a **Reboot now** button. Clicking it triggers the same confirm-then-POST reboot flow as the server detail page without needing to scroll down to the Reboot Required section. Useful when `reboot_required` hasn't flipped on the row yet but you know an apt upgrade just ran.
- **Orphaned sites** (sites with no SpinupWP record and not archived) now have a **Remove** button with an "Are you sure" confirmation — archives the site row rather than hard-deleting it.
- **SSH credentials** — servers missing SSH credentials surface on the Issues page. Each row has an inline action: **Test SSH** (AJAX, fires `POST /servers/{id}/test`, shows pass/fail inline without a page reload) if a password is already stored, or **Add password** (links to the credentials edit page) if none is on record yet.
- **Ignored issues workflow** — operators can suppress a specific alert (e.g. an intentional `noindex` flagged by SEO Indexability) by clicking **Ignore** and optionally recording a reason in a modal. The card moves to an **Ignored** tab with a one-click **Resume monitoring** action, and the suppressed site is excluded from `IssueCounter`'s total so it stops inflating the header nav badge. See [Architecture → Data model](/docs/architecture/data-model) for the `ignored_issues` table this is backed by.

## Per-site CPU collection toggle

The `/capacity` leaderboard section has a **Pause collection / Resume collection** button per site. When paused, `clockwork:pull-site-metrics` skips that site's Companion sampler polling. Historical rows are kept, so the 7-day leaderboard stays visible while paused. A yellow "Collection paused" banner makes the state obvious on the site's capacity row.

Use this once you've identified the hot-CPU offenders and no longer need the overhead of per-request DB writes that Companion's sampler produces.

## When the dashboard lies

A few cases where the dashboard can mislead:

- **Scheduler not running** → cards stay green even as data goes stale. The `last_polled_at` timestamp on the card tells you the truth. See [Runbooks → Scheduler stuck](/docs/runbooks/scheduler-stuck).
- **Ignored server is actually broken** — by design, ignored servers don't count in headline status. Don't ignore a box you actually need to monitor.
- **CF cache masks origin death** — a CF-cached homepage can serve 200 even when the origin has imploded. The uptime probe sees up, but the real site is down. Pair the dashboard with `/monitoring`.
- **Provider-deleted server still showing green** — if a server is removed at the provider (DO/Hetzner) without being decommissioned in Clockwork, `PollServers` marks it `status=unknown` on the next cycle. Cards stuck green with a stale `last_polled_at` are a sign the scheduler is down; cards showing "unknown" are a sign the server is gone at the provider.
