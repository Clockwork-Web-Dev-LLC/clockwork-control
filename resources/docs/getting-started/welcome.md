---
title: Welcome to Clockwork Control
section: Getting Started
order: 10
updated: 2026-09-04
author: Aaron Reimann
tags: [overview, onboarding, control-panel]
---

**Clockwork Control** is the self-hosted fleet control panel and monitoring brain for our agency's WordPress fleet. It watches every server we host, every site we run, and every signal that tells us something is about to break — so we get a heads-up before clients do.

## What it does

A few things, all at once:

- **Watches the servers** — every 5 minutes Clockwork Control pulls metrics from each server's cloud provider — DigitalOcean droplets give CPU, memory, disk, and load; Azure VMs give CPU and memory; Hetzner Cloud servers give CPU only — and tags any server that's running hot.
- **Watches the sites** — every monitored site gets an HTTP probe a few times an hour. If a site stops responding for two probes in a row, we get a Mattermost alert before the client emails us.
- **Watches for trouble** — nginx logs are tailed, Wordfence and Limit Login Attempts data is ingested, and IPs that misbehave land in a review queue. Repeated abusers get banned via fail2ban over SSH.
- **Runs the care-plan checks** — nightly Lighthouse performance scans (GTmetrix), weekly Sucuri SiteCheck, daily WordPress core file integrity, daily blacklist checks against Spamhaus / URLHaus / Google Safe Browsing.
- **Talks to clients via the Companion plugin** — every Companion-equipped site has a Tools → Clockwork section in wp-admin showing the same data we see, formatted for them.

## What it isn't

Clockwork Control is not a SaaS product. It runs on the operator's own hardware (a laptop today, a dedicated machine later) on their home network. There's no public URL, no client login here. Every account on Clockwork Control is an agency account.

It's also not a replacement for your hosting providers or server control panels — it sits side-by-side with them. Whether your fleet is on SpinupWP, Cloudways, Pressable, WP Engine, Kinsta, or custom cloud servers, those platforms provision and run the infrastructure; Clockwork Control watches over them, catches issues early, and serves as your agency's unified monitoring brain.

## Where to start

If you're new to the team:

1. Read **Concepts → Server, Site, Care plan, Hosting tier** so the vocabulary on every other page makes sense.
2. Skim **Features → Servers & health** and **Features → Uptime monitoring** — those two cover 80% of what you'll touch day-to-day.
3. Bookmark **Runbooks → A site is down — what now?** for when the alert hits.

If you've been around a while and you're just looking something up — sidebar, ctrl-F. The whole catalog is on this page's left.

## How these docs work

Markdown files in `resources/docs/`. Edit, save, refresh. The sidebar rebuilds itself from the filesystem — drop in a new `.md` file and it shows up. See `_conventions.md` in the docs root for the frontmatter shape.

When you ship a feature, add or update the matching page under `Features`. Bump the `updated:` date at the top. The byline is whoever's editing — change it from mine to yours when you take over a page.
