---
title: Inactive sites
section: Features
order: 32
author: Aaron Reimann
updated: 2026-09-06
tags: [sites, issues, alerting, care-plan]
tracks: [app/Models/Site.php, app/Support/IssueCounter.php, app/Http/Controllers/IssuesController.php, app/Http/Controllers/SitesController.php, app/Services/Chat/ChatNotifierDispatcher.php, database/migrations/*add_is_inactive_to_sites*]
---

`sites.is_inactive` — a fleet-wide "stop nagging me about this one" flag, distinct from both Archive and the per-site uptime-ignore toggle.

## Why this exists

A client migrates away but asks to keep their old site reachable a while longer. The site is still technically live — still worth seeing in the fleet, still worth knowing if it goes down or gets hacked — but nobody's going to renew its SSL cert on schedule, update its plugins, or migrate its 2FA setup. The routine "needs eyes" surfaces (Issues page, nav badge, Mattermost/Slack) don't know the difference between "actively maintained site with a real problem" and "site we've deliberately stopped maintaining," so they keep flagging it the same way. `is_inactive` is the switch that tells them apart.

## How it's different from the other two "quiet down" mechanisms

| | Visible in Sites list / search? | What's suppressed |
|---|---|---|
| **Archive** (`archived_at`) | No — hidden from every listing entirely | Everything, because the row is effectively gone from the live app |
| **Uptime ignore** (`uptime_ignored_at`) | Yes | Only uptime alerts/Issues entries for that one site — probe keeps running |
| **Inactive** (`is_inactive`) | Yes | Every routine-maintenance signal at once (see below) — not just one |

Use Archive when a site is truly gone (decommissioned, DNS pointed elsewhere, no reason to ever look at it again). Use Inactive when it's still around and still worth glancing at, just not worth the nagging.

## What's suppressed vs. what still fires

**Suppressed** (routine maintenance — things that can wait indefinitely for a site nobody's actively working on):

- SSL renewal / expiry (`IssueCounter`, `IssuesController::index()`'s SSL section)
- Plugin updates outdated
- 2FA-at-risk (WFLS migration)
- Contact-form / Companion-missing checks
- Cloudflare DNS-only misconfiguration
- Traffic-capacity ("over quota") flags
- The matching Mattermost/Slack events: `ssl_state_changed`, `llar_installed`, `contact_form_failed`/`recovered`, `companion_unreachable`/`reachable`, `plugin_update_failed`

**NOT suppressed** (active incidents — still Clockwork's problem regardless of the client relationship, since the site is still live infrastructure):

- Malware findings (Sucuri SiteCheck, Companion in-WP probe, core-checksum tampering)
- Uptime down/up (use the separate `uptime_ignored_at` toggle — see [Features → Uptime monitoring](/docs/features/uptime-monitoring) — if you also want quiet on a known-down inactive site)
- Blocked IPs (`ipBlocked`)
- Orphaned-site detection

The gate for the Mattermost/Slack half lives in `ChatNotifierDispatcher::dispatchForSite()` — a single choke point, so any *future* routine-maintenance event type just needs to route through it to inherit this behavior for free. Active-incident methods call `dispatch()` directly instead, bypassing the check on purpose.

## Toggling it

Site → Settings tab → "Site status" card. Optional free-text reason, shown as a tooltip everywhere the Inactive badge appears (site header, Sites list row). `ActionLog::TYPE_SITE_DEACTIVATED` / `TYPE_SITE_REACTIVATED` record the transition, same as every other settings toggle in this app.
