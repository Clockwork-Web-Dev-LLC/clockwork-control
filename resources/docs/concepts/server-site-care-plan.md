---
title: Server, Site, Care plan, Hosting tier
section: Concepts
order: 10
updated: 2026-09-02
author: Aaron Reimann
tags: [concepts, mental-model, care-plan, hosting, pressable, wpengine, kinsta, cloudways]
---

Four words show up everywhere in Clockwork. If you don't have these straight in your head, half the UI is going to feel ambiguous. Read this once and they'll click.

## Server

A **server** is one DigitalOcean droplet, Hetzner Cloud server, Azure VM, or Cloudways-provisioned server running WordPress sites (or, occasionally, a hand-rolled Ubuntu host). Which cloud it lives on is tracked independently via `servers.provider` and has nothing to do with which hosting provider manages the WordPress sites on it — see "Two independent axes" below. We pull metrics from it every 5 minutes via the matching cloud API (`CloudProvider` contract — see [Architecture → System overview](/docs/architecture/system-overview)) and, for SpinupWP servers, SSH into it for everything else — banning IPs, running `wp-cli`, tailing logs (Cloudways servers get metrics the same way but are reached over SSH through the hosting-provider layer, not this app's own SSH client — see [Hosting provider](#hosting-provider)).

In the UI, servers show up as cards on the dashboard sorted by health. Green = fine, yellow = needs attention, red = something's wrong right now.

**Only SpinupWP and Cloudways sites have a server.** Pressable, WP Engine, and Kinsta have no server concept at all — every operation on those three is addressed by a per-site or per-install/environment identifier alone, no droplet row to point at. See [Hosting provider](#hosting-provider) below.

## Site

A **site** is one WordPress install. On SpinupWP and Cloudways, that install lives on a server — most servers run multiple sites, and each site has its own domain, database, nginx vhost, and SSL cert. On Pressable, WP Engine, and Kinsta, there's no server to point at; the site is still all those same things, just reached through each provider's own API/SSH layer instead of this app's SSH client.

Sites are what clients care about. Servers (where they exist) are the substrate.

## Hosting provider

`sites.hosting_provider` is one of `spinupwp`, `pressable`, `wpengine`, `kinsta`, or `cloudways`. This is the one thing that changes *how* Clockwork reaches a site, not *what* Clockwork monitors — uptime, SSL, security scans, and backups/traffic all still show up across providers, just sourced (or, for the ones without a native equivalent, gated off) differently per the `HostingProvider` contract's `supports()` capability matrix. `Site::isPressable()` / `isSpinupWp()` / `isWpEngine()` / `isKinsta()` / `isCloudways()` are the identity helpers app code branches on for genuinely provider-specific quirks; `Site::hostMonitored()` is the scope every fleet-wide monitoring command uses in place of the old `whereHas('server', fn ($q) => $q->monitored())` — it OR's a monitored-server check with membership in `Site::HOSTING_PROVIDERS_WITHOUT_SERVER` (Pressable, WP Engine, Kinsta), since those sites have no `server` row to hang the old scope off of.

Concretely:

- **SpinupWP sites**: `server_id` set, `spinupwp_id` set.
- **Cloudways sites**: `server_id` set (a real, Cloudways-provisioned server row), `cloudways_app_id` set.
- **Pressable sites**: `server_id` null, `pressable_site_id` set.
- **WP Engine sites**: `server_id` null, `wpengine_install_name` set.
- **Kinsta sites**: `server_id` null, `kinsta_environment_id` set.

A handful of features are genuinely SSH/server-row-only and have no path for the three server-less providers — the per-site Traffic tab, the Bans tab, LLAR install, and the cert "Recheck now" button are hidden rather than shown broken for Pressable/WP Engine/Kinsta sites (all three declare `HostingProvider::CAP_SSH === false`, tied specifically to `server_id` being populated — see the contract's own docblock — even though WP Engine and Kinsta both have real per-site/per-environment SSH under the hood; it's just never routed through a tracked `Server` row). Full breakdown in [Integrations → Pressable](/docs/integrations/pressable), [WP Engine](/docs/integrations/wp-engine), [Kinsta](/docs/integrations/kinsta), and [Cloudways](/docs/integrations/cloudways).

### Two independent axes: hosting provider and cloud provider

It's easy to conflate these because SpinupWP and "the server" are so tightly linked day-to-day, but they're answering two different questions and are tracked on two different tables:

- **`sites.hosting_provider`** (`CloudProvider`'s sibling contract, `HostingProvider`) — *who manages this WordPress install*: SpinupWP, Pressable, WP Engine, Kinsta, or Cloudways.
- **`servers.provider`** (`CloudProvider` contract) — *whose virtual machine is the underlying server running on*, when there is one at all: DigitalOcean, Hetzner, Azure, or Cloudways.

| Hosting provider | Needs a `CloudProvider`-tracked Server row? | Valid cloud providers |
|---|---|---|
| SpinupWP | **Yes** — every SpinupWP site's `server_id` is a real, non-null foreign key | DigitalOcean, Hetzner, or Azure — SpinupWP itself doesn't care which; it's just Ubuntu + nginx to SpinupWP either way |
| Cloudways | **Yes** — every Cloudways site's `server_id` is a real, non-null foreign key | Cloudways only — Cloudways provisions its own servers on top of DO/AWS/GCP/Vultr/Linode per the customer's choice, but this app never holds credentials for whichever cloud that turns out to be, so metrics/alive-state come from **Cloudways' own API**, tracked under `servers.provider = 'cloudways'` rather than the underlying cloud's real identity |
| Pressable | **No** — `server_id` is always null | N/A — Pressable manages its own infrastructure end-to-end behind its API, so there's nothing for a `CloudProvider` adapter to track |
| WP Engine | **No** — `server_id` is always null | N/A — same reasoning as Pressable |
| Kinsta | **No** — `server_id` is always null | N/A — same reasoning as Pressable |

In other words: **"SpinupWP on Hetzner" and "SpinupWP on DigitalOcean" are both completely normal** — they're the same hosting-provider software running on different clouds, and Clockwork monitors the WordPress layer (via the `HostingProvider` contract) and the VM layer (via the matching `CloudProvider` contract) independently. **"Pressable on DigitalOcean" isn't a real combination**, and neither is "WP Engine on Hetzner" or "Kinsta on Azure" — none of the three server-less hosting providers is ever paired with a `CloudProvider` module, because those sites have no VM in this app's model at all. Cloudways sits in its own category: it's the only hosting provider that's *also* a cloud provider — `CloudwaysServiceProvider` is the one module in this codebase implementing both contracts from a single class pair, and a Cloudways site's server row always has `servers.provider = 'cloudways'`, never `digitalocean`/`hetzner`/`azure`.

**Grouping, informally:** SpinupWP and Cloudways behave alike day-to-day — both give you a real server row, SSH-shaped access, and droplet-style metrics (this is why Cloudways was built as a `HostingProvider` + `CloudProvider` pair rather than just a `HostingProvider`). Pressable, WP Engine, and Kinsta behave alike in the other direction — no server row, no `CAP_SSH`, everything addressed by a site/install/environment id straight through each one's own API.

## Hosting tier

If we host a site, that site is on the **hosting tier** by default. Every Companion-installed site is on the hosting tier — there's no "host the site without monitoring" mode. Hosting includes:

- Off-site backups — 30-day retention for SpinupWP sites (DigitalOcean Spaces). **Pressable sites have no retention policy to apply**: Pressable's own `/backups` API has no pagination and returns whatever window it returns (observed ~36-38 hours), so Companion shows Pressable sites "all available history" rather than a day-count promise. **WP Engine, Kinsta, and Cloudways backup history is not yet wired into Companion or any report command** — each client (`WPEngineClient::backups()`, `KinstaClient::backups()`, `CloudwaysClient::takeBackup()`/`restoreBackup()`) can reach the provider's native backup API, but unlike Pressable there's no `*BackupsReport` command pushing that data anywhere yet. Don't assume backup visibility parity with Pressable/SpinupWP for these three until that's built.
- Uptime monitoring (HTTP probes, Mattermost alerts on downtime)
- Daily blacklist scans (Spamhaus + URLHaus + Google Safe Browsing)
- The Companion plugin if installed, with Activity / Uptime / Backups visibility for the client

You don't have to do anything to put a new site on the hosting tier — it's the default.

## Care plan

A **care plan** is the premium tier on top of hosting. Care-plan sites get everything hosting gets, plus:

- 90-day backup retention for SpinupWP sites (instead of 30) — no equivalent bump for Pressable, WP Engine, Kinsta, or Cloudways, since retention for all four is each provider's own, not ours, to extend (and isn't surfaced to Companion for the three newest providers at all yet — see [Hosting tier](#hosting-tier))
- Weekly Sucuri SiteCheck malware/blacklist remote scan
- Daily WordPress core file integrity check via `wp-cli verify-checksums`
- Nightly Lighthouse performance scan (GTmetrix, pinned test location)
- Managed plugin / theme / core updates
- Companion's Performance and Security admin pages light up with daily data

The care plan flag is a per-site boolean: `sites.care_plan_enabled`. It's set automatically by the daily Bill.com sync (any customer with a recent invoice line item naming "care plan" gets the flag), or manually via the per-site Settings tab when a client pays out-of-band.

## How these fit together

```
DigitalOcean droplet (SpinupWP)     Cloudways server (provider=cloudways)   Pressable / WP Engine / Kinsta
   └── Server (one)                       └── Server (one)                  (no server concept, any of the three)
         ├── Site A (hosting tier)              └── Site F (hosting tier)      ├── Site D (Pressable, hosting tier)
         ├── Site B (hosting tier + care plan)                                 ├── Site G (WP Engine, care plan)
         └── Site C (hosting tier)                                            └── Site E (Kinsta, hosting tier + care plan)
```

Every SpinupWP or Cloudways site lives on exactly one server, regardless of which cloud that server is on (or, for Cloudways, regardless of which cloud Cloudways itself provisioned it on); every Pressable/WP Engine/Kinsta site lives on no server at all. Every site, either way, is on the hosting tier. Some sites have the care plan flag flipped on top — hosting provider, cloud provider, and care plan are three independent axes.

## When the words don't quite fit

A few edge cases worth knowing:

- **Ignored servers** — `is_ignored = true` on a server means we don't poll it, don't show it in headline counts, don't run plugin inventory on it. Useful for hand-managed boxes or hosts behind firewalls we can't reach. It still shows on the dashboard in a muted section so you remember it exists.
- **Archived sites** — sites that we used to host but don't anymore. Hidden by a global Eloquent scope so they don't pollute dashboards. Bypass the scope when you need to look one up: `Site::withoutGlobalScopes()->where(...)`.
- **Care plan override** — if `care_plan_override IS NOT NULL`, the daily Bill.com sync stops touching `care_plan_enabled` for that site. Useful when a customer pays out-of-band or when Bill.com's data is wrong. Clear the override (the "Let Bill.com decide" button on the site Settings tab) to re-engage automatic sync.
