---
title: Overview
section: Integrations
order: 1
updated: 2026-09-04
author: Aaron Reimann
tags: [integrations, overview]
---

Every external service Clockwork talks to. Each gets its own page with auth, endpoints, files, scheduled jobs, and how to test connectivity.

> [!TIP]
> **Help Us Test Integrations!**
> Clockwork is open source and designed to support many cloud and hosting providers. While we actively run production workloads on SpinupWP, DigitalOcean, Hetzner, Cloudflare, Slack, and Mattermost, we have written modules for **GridPane**, **WP Engine**, **Kinsta**, **Cloudways**, **Azure**, **Linode**, **Vultr**, **Twilio**, and **Pressable**.
> 
> The code is written and unit-tested, but **we need agencies to test these integrations against live accounts!** See our [Contributing & Module Testing Guide](/docs/getting-started/contributing) for how to test with your credentials, vibe code fixes with Claude, and submit a pull request back to the project.

## Real-world testing status matrix

| Service | Type | Status | Real-world state | Page |
|---|---|---|---|---|
| **SpinupWP** | Hosting | 🟢 Verified in Production | Daily active use for server inventory, site events, backups. | [spinupwp](/docs/integrations/spinupwp) |
| **DigitalOcean** | Cloud IaaS | 🟢 Verified in Production | Daily active use polling droplet metrics every 5 minutes. | [digitalocean](/docs/integrations/digitalocean) |
| **Hetzner Cloud** | Cloud IaaS | 🟢 Verified in Production | Daily active use polling server CPU metrics every 5 minutes. | [hetzner](/docs/integrations/hetzner) |
| **Cloudflare** | DNS & WAF | 🟢 Verified in Production | Daily DNS/WAF diagnostics and migration cutover. | [cloudflare](/docs/integrations/cloudflare) |
| **SSH + fail2ban** | Host access | 🟢 Verified in Production | Sudo command execution and security ban enforcement. | [ssh-and-fail2ban](/docs/integrations/ssh-and-fail2ban) |
| **Slack** | Alerts | 🟢 Verified in Production | Ops alerts & per-site client channels. | [slack](/docs/integrations/slack) |
| **Mattermost** | Alerts | 🟢 Verified in Production | Daytime ops real-time chat alerts. | [mattermost](/docs/integrations/mattermost) |
| **Mailgun** | Email | 🟢 Verified in Production | Critical alert email fallbacks and test summaries. | [mailgun](/docs/integrations/mailgun) |
| **Bill.com** | Billing | 🟢 Verified in Production | Active customer billing sync and care plan toggles. | [bill-com](/docs/integrations/bill-com) |
| **Sucuri / Google / Spamhaus** | Security | 🟢 Verified in Production | Remote malware scans, Google Safe Browsing, DNS blacklists. | [sucuri-sitecheck](/docs/integrations/sucuri-sitecheck) |
| **GTmetrix** | Performance | 🟢 Verified in Production | Nightly Lighthouse performance checks for care plan sites. | [gtmetrix](/docs/integrations/gtmetrix) |
| **Pressable** | Hosting | 🟢 Verified in Production | Managed WordPress hosting, site imports, backup history sync, async WP-CLI commands. | [pressable](/docs/integrations/pressable) |
| **Twilio (SMS)** | Notifications | 🟢 Verified in Production | On-call SMS paging for care-plan site downtime with storm circuit-breaker. | [twilio](/docs/integrations/twilio) |
| **GridPane** | Hosting | 🧪 Looking for Testers | Written for REST API v1, server & site import, SSH WP-CLI, and backup schedule inspection. | [gridpane](/docs/integrations/gridpane) |
| **GitHub** | Auth | 🧪 Looking for Testers | OAuth 2.0 login integration. Looking for agencies with GitHub accounts to test. | [google-oauth](/docs/integrations/google-oauth) |
| **Microsoft** | Auth | 🧪 Looking for Testers | Microsoft Entra ID / Microsoft 365 OAuth login integration. Looking for testers. | [google-oauth](/docs/integrations/google-oauth) |
| **WP Engine** | Hosting | 🧪 Looking for Testers | Written against published API docs. Needs live account testing for backup envelopes and install SSH. | [wp-engine](/docs/integrations/wp-engine) |
| **Kinsta** | Hosting | 🧪 Looking for Testers | Written against API docs. Needs live verification of environment SSH connection info lookup. | [kinsta](/docs/integrations/kinsta) |
| **Cloudways** | Hybrid | 🧪 Looking for Testers | Written for API v2. Needs live verification of monitoring metrics and app-isolated SSH vs sudo. | [cloudways](/docs/integrations/cloudways) |
| **Azure** | Cloud IaaS | 🧪 Looking for Testers | Written for Azure VM metrics polling and token management. | [azure](/docs/integrations/azure) |
| **Linode (Akamai)** | Cloud IaaS | 🧪 Looking for Testers | Written for Linode API v4 multi-core normalized CPU telemetry. | [linode](/docs/integrations/linode) |
| **Vultr** | Cloud IaaS | 🧪 Looking for Testers | Written for Vultr API v2 alive/dead checks and instance matching. | [vultr](/docs/integrations/vultr) |

| Service | What it does | Page |
|---|---|---|
| Google OAuth | Only login provider for the app. Allowlist gating in the `users` table. | [google-oauth](/docs/integrations/google-oauth) |

## Cloud / server providers

The IaaS layer — the `CloudProvider` contract, keyed on `servers.provider`. These track the *virtual machine*, independent of which hosting provider manages the WordPress sites running on it.

| Service | What it does | Page |
|---|---|---|
| DigitalOcean | Pull droplet metrics every 5 min — what turns a server "red." | [digitalocean](/docs/integrations/digitalocean) |
| Hetzner Cloud | Same role as DO for servers tagged `provider=hetzner`. CPU only — Hetzner exposes no memory/load metrics. | [hetzner](/docs/integrations/hetzner) |
| Azure | Same role for servers tagged `provider=azure` — VM metrics polling + reconciliation. | [azure](/docs/integrations/azure) |
| Vultr | Same role for servers tagged `provider=vultr`. No metrics API — alive/dead + IP matching only. | [vultr](/docs/integrations/vultr) |
| Linode (Akamai) | Same role for servers tagged `provider=linode`. CPU only, normalized by vCPU count — no memory/disk/load. | [linode](/docs/integrations/linode) |
| Cloudways | Metrics/alive-state for servers tagged `provider=cloudways`, sourced from Cloudways' own API rather than whichever cloud it actually provisioned on (DO/AWS/GCP/Vultr/Linode) — this app never holds credentials for that underlying cloud. Also a `HostingProvider` (see below) — the only module implementing both contracts. | [cloudways](/docs/integrations/cloudways) |
| DigitalOcean Spaces | Enumerate SpinupWP backup objects (REST API doesn't expose history). | [digitalocean-spaces](/docs/integrations/digitalocean-spaces) |

## WordPress hosting providers

The `HostingProvider` contract, keyed on `sites.hosting_provider` — *who manages the WordPress install itself*. SpinupWP, Cloudways, and GridPane sites always sit on top of a `CloudProvider`-tracked server row (SpinupWP: DigitalOcean, Hetzner, or Azure, its choice; Cloudways: its own `cloudways` entry; GridPane: cloud VPS with direct SSH access); Pressable, WP Engine, and Kinsta sites have no server/cloud-provider layer at all. Full explanation: [Concepts → Server, Site, Care plan, Hosting tier](/docs/concepts/server-site-care-plan#two-independent-axes-hosting-provider-and-cloud-provider).

| Service | What it does | Page |
|---|---|---|
| SpinupWP | Inventory bootstrap — servers, sites, backup config, site events. | [spinupwp](/docs/integrations/spinupwp) |
| Pressable | No server concept — site import, backups/traffic history, async command execution in place of SSH. | [pressable](/docs/integrations/pressable) |
| WP Engine | No server concept — real per-install SSH, but never through a tracked server row. Built against WP Engine's published API docs, not yet exercised against a live account — see its page for what's confirmed vs. guessed. | [wp-engine](/docs/integrations/wp-engine) |
| Kinsta | No server concept — real per-environment SSH, same caveat as WP Engine (unverified against a live account, including a genuinely-guessed SSH-connection-info endpoint). | [kinsta](/docs/integrations/kinsta) |
| Cloudways | Has a real server row (see Cloud/server providers above) — the only hosting provider that's also a cloud provider. Built against Cloudways' API (v1 has reached end-of-life; targets v2). | [cloudways](/docs/integrations/cloudways) |
| GridPane | Dedicated server management panel on cloud VPS with real server rows, direct SSH access, site discovery, and backup schedule inspection. | [gridpane](/docs/integrations/gridpane) |

## Networking & access

| Service | What it does | Page |
|---|---|---|
| Cloudflare | DNS + WAF diagnostics. Plus DNS write during migration cutover (separate token). | [cloudflare](/docs/integrations/cloudflare) |
| SSH + fail2ban | The substrate. Every server is reached as a non-root sudo user; bans go through fail2ban. | [ssh-and-fail2ban](/docs/integrations/ssh-and-fail2ban) |

## Billing

| Service | What it does | Page |
|---|---|---|
| Bill.com | Read-only sync. Auto-link sites to customers and auto-flip `care_plan_enabled`. | [bill-com](/docs/integrations/bill-com) |

## Notifications

| Service | What it does | Page |
|---|---|---|
| Mattermost | Real-time alerts on uptime transitions, IP blocks, SSL state changes. | [mattermost](/docs/integrations/mattermost) |
| Slack | Second ops-facing chat channel (same events as Mattermost), plus a separate per-site client-facing channel. | [slack](/docs/integrations/slack) |
| Mailgun | Contact-form failure / recovery / monthly-summary emails. | [mailgun](/docs/integrations/mailgun) |

## Security scans

| Service | What it does | Page |
|---|---|---|
| Sucuri SiteCheck | Free remote malware scan (same engine ManageWP resold). | [sucuri-sitecheck](/docs/integrations/sucuri-sitecheck) |
| Google Safe Browsing | Domain malware/phishing classification — drives Chrome's red warning. | [safe-browsing](/docs/integrations/safe-browsing) |
| URLhaus + Spamhaus DBL | Two domain-blacklist sources. Spamhaus is free DNS; URLhaus needs a free account. | [urlhaus-spamhaus](/docs/integrations/urlhaus-spamhaus) |

## Performance

| Service | What it does | Page |
|---|---|---|
| GTmetrix | Nightly Lighthouse score per care-plan site from a pinned test location. Primary engine. | [gtmetrix](/docs/integrations/gtmetrix) |
| Google PageSpeed Insights | Fallback engine — runs only when GTmetrix errors. | [pagespeed-insights](/docs/integrations/pagespeed-insights) |

## Bot management

| Service | What it does | Page |
|---|---|---|
| Arcjet well-known-bots | Curated bot allowlist. Synced daily into `allowed_bots`. | [arcjet-bots](/docs/integrations/arcjet-bots) |

## AI

| Service | What it does | Page |
|---|---|---|
| LM Studio (local) | Local LLM for nginx-log threat analysis. Loopback only — no data leaves the box. | [lm-studio](/docs/integrations/lm-studio) |
