---
title: Settings & Operations Hub
section: Features
order: 90
updated: 2026-09-08
author: Aaron Reimann
tags: [settings, operations, fleet, hub, navigation]
tracks: [app/Http/Controllers/SettingsController.php, resources/views/settings/index.blade.php, resources/views/settings/_tabs.blade.php]
---

Lives at **`/settings`** (gear icon → "Settings Hub", or clicking any settings tab). Serves as the central command center for fleet configuration, API integrations, maintenance runbooks, and system administration.

Prior to the unified hub, settings consisted of more than 24 independent pages in a flat navigation dropdown. The Settings Hub organizes all configuration into four structured operational pillars, backed by real-time client-side search, a four-column desktop mega-menu, and cohesive sub-navigation tabs.

## The Four Operational Pillars

The hub categorizes settings and operational tools into four functional quadrants:

### 1. Fleet & Branding
Policies and client-facing customizations applied across monitored sites and client environments:
* **White Labeling** (`/settings/companion`, renamed from "Companion") — 3-tab hub: Companion (wp-admin) branding (logo, plugin name/menu, support links), Client Reports branding (colors, footer text), and Plugin Notification Email branding (header/accent colors, badge text, test-send). See [Companion & White Label](/docs/features/companion-branding).
* **Tag Management** (`/settings/tags`) — Manage server and site tagging taxonomy for grouped actions and batch scheduling.
* **WordPress Plugins** (`/settings/wordpress-plugins`) — Fleet-wide plugin version inventory, adoption metrics, and update tracking.
* **Ingest Pipeline** (`/settings/ingest`) — LLAR and Wordfence threat log ingestion frequency and IP ban auto-approval policies.
* **Security Scans** (`/settings/security-scans`) — Daily vulnerability, checksum, and malware scan cadences.
* **Backup Relay** (`/settings/backup-relay`) — Offsite S3/Glacier backup relay configuration and status monitoring.

### 2. Integrations & Alerts
Outbound API credentials, ecosystem modules, and alerting channels:
* **API Credentials** (`/settings/integrations`) — Store and validate cloud provider (DigitalOcean, Hetzner, Azure, Vultr, Linode) and hosting provider API tokens (encrypted at rest, never echoed back).
* **Module Directory** (`/settings/modules`) — Browse bundled and community extensions with trust tiers (`official`, `verified`, `community`).
* **Notifications (SMS / Twilio)** (`/settings/notifications`) — On-call emergency dispatch lists, off-hours quiet windows, and phone number verification.
* **Slack Alerts** (`/settings/slack`) — Webhook integration and granular per-event notification toggles.
* **Mattermost Alerts** (`/settings/mattermost`) — Self-hosted chatops integration and webhook channels.
* **Bill.com Sync** (`/settings/bill-com`) — Automated recurring invoice synchronization and care plan reconciliation.

### 3. Operations & Tools
Day-to-day fleet maintenance, capacity planning, and threat intelligence:
* **Fleet Capacity & Limits** (`/capacity`) — Server density tracking, memory/CPU quotas, and traffic pressure alerts.
* **Server OS Updates** (`/operations/server-updates`) — Linux package patching (apt/yum) and queued server reboots.
* **Maintenance History** (`/maintenance-history`) — Chronological audit trail of plugin updates, core updates, and automated rollbacks.
* **Server SSH Credentials** (`/servers/credentials`) — Centralized SSH keypair distribution and sudo operator credentials.
* **Threat Intelligence** (`/settings/weird-stats`) — Pre-warmed analytics on blocked attacks, top attacker ASNs, and botnets.

### 4. System & Workspace
Application governance, health diagnostics, and platform maintenance:
* **User Management** (`/settings/users`) — Operator accounts, authentication allowlists, and role permissions.
* **Core Updates** (`/settings/updates`) — Clockwork Control self-updater and Companion mu-plugin fleet deployment breakdown.
* **Database Maintenance** (`/settings/maintenance`) — Database table statistics, cache clearing, and on-demand encrypted database exports.
* **Diagnostics & Health** (`/settings/diagnostics`) — Live health probes across redis, database, scheduler, queues, and outbound APIs.
* **Setup Checklist** (`/setup`) — First-run installation wizard and initial provider onboarding checklist.
* **Documentation** (`/docs`) — In-app team handbook, architecture docs, runbooks, and API catalogs.

## Live Search & Navigation

* **Instant Client-Side Filtering**: Powered by Alpine.js (`x-model="search"`). Filters all 24+ tool links instantly by title, description, or keyword (e.g. typing `"slack"`, `"backup"`, or `"token"` highlights matching cards in real time).
* **Settings Sub-Tabs (`settings._tabs.blade.php`)**: A persistent two-tier navigation block rendered above `<x-page-header>` on every settings/operations view — Tier 1 is the five pillar tabs (`Overview`, `Fleet & Branding`, `Integrations & Alerts`, `Operations & Tools`, `System & Workspace`); Tier 2 is a contextual "tools" ribbon (a contained, rounded pill container with an elevated active-state pill) listing the pages within whichever pillar is currently active, so operators keep both their place in the hierarchy and one-click access to sibling tools as they drill in. It's included on all settings pages plus four Operations & Tools pages that live outside `resources/views/settings/`: `/capacity` (and its settings sub-page), `/maintenance-history`, `/operations/server-updates`, and the bulk SSH credentials page (`/servers/credentials`) — all four were missing the nav until a follow-up fix added it.
* **Mega-Menu Popover**: Desktop header gear icon opens a categorized 4-column menu matching the hub categories, providing single-click direct access to any destination without navigating through the hub.
* **Mobile Slide-Over Drawer**: Responsive navigation drawer containing quick access links to all four settings quadrants, theme switching, and sign-out actions.
