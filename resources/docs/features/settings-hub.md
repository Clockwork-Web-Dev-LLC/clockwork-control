---
title: Settings & Operations Hub
section: Features
order: 90
updated: 2026-09-10
author: Aaron Reimann
tags: [settings, operations, fleet, hub, navigation]
tracks: [app/Http/Controllers/SettingsController.php, resources/views/settings/index.blade.php, resources/views/settings/_tabs.blade.php]
---

Lives at **`/settings`** (gear icon → "Settings Hub", or clicking any settings tab). Serves as the central command center for fleet configuration, API integrations, maintenance runbooks, and system administration.

Prior to the unified hub, settings consisted of more than 24 independent pages in a flat navigation dropdown. The Settings Hub organizes all configuration into five structured operational pillars, backed by real-time client-side search, a desktop mega-menu, and cohesive sub-navigation tabs.

## The Five Operational Pillars

The hub categorizes settings and operational tools into five functional areas:

### 1. Overview
The launchpad and discovery index for the entire administration suite:
* **Settings Overview** (`/settings`) — Quick metrics, interactive search across all tools, and direct category jump cards.

### 2. Agency Branding
Unified agency white-label styling and client-facing branding:
* **Agency Branding & White Labeling** (`/settings/companion`) — Dedicated single-tool pillar housing the Master Agency Brand Palette (primary, accent, surface, text), Companion (wp-admin) header chrome & branding, Client Reports styling, and Plugin Notification Email templates. When viewing this pillar, single-tool secondary ribbon noise is automatically omitted. See [Companion & White Label](/docs/features/companion-branding).

### 3. Fleet Policies
Policies, fleet-wide plugins, and automated maintenance cadences:
* **WordPress Plugins** (`/settings/wordpress-plugins`) — Fleet-wide plugin version inventory, adoption metrics, and update tracking.
* **Ingest Pipeline** (`/settings/ingest`) — LLAR and Wordfence threat log ingestion frequency and IP ban auto-approval policies.
* **Security Scans** (`/settings/security-scans`) — Daily vulnerability, checksum, and malware scan cadences.
* **Backup Relay** (`/settings/backup-relay`) — Offsite S3/Glacier backup relay configuration and status monitoring.
*(Note: **Tag Management** (`/settings/tags`) is also accessible directly from the main Dashboard header and inline filter pills on `/` for rapid access).*

### 4. Integrations & Alerts
Outbound API credentials, ecosystem modules, and alerting channels:
* **API Credentials** (`/settings/integrations`) — Store and validate cloud provider (DigitalOcean, Hetzner, Azure, Vultr, Linode) and hosting provider API tokens (encrypted at rest, never echoed back).
* **Module Directory** (`/settings/modules`) — Browse bundled and community extensions with trust tiers (`official`, `verified`, `community`).
* **Notifications (SMS / Twilio)** (`/settings/notifications`) — On-call emergency dispatch lists, off-hours quiet windows, and phone number verification.
* **Slack Alerts** (`/settings/slack`) — Webhook integration and granular per-event notification toggles.
* **Mattermost Alerts** (`/settings/mattermost`) — Self-hosted chatops integration and webhook channels.
* **Bill.com Sync** (`/settings/bill-com`) — Automated recurring invoice synchronization and care plan reconciliation.

### 5. System & Workspace
Application governance, health diagnostics, and platform maintenance:
* **User Management** (`/settings/users`) — Operator accounts, authentication allowlists, and role permissions.
* **Core Updates** (`/settings/updates`) — Clockwork Control self-updater and Companion mu-plugin fleet deployment breakdown.
* **Database Maintenance** (`/settings/maintenance`) — Database table statistics, cache clearing, and on-demand encrypted database exports.
* **Diagnostics & Health** (`/settings/diagnostics`) — Live health probes across redis, database, scheduler, queues, and outbound APIs.
* **Threat Intelligence** (`/settings/weird-stats`) — Pre-warmed analytics on blocked attacks, top attacker ASNs, and botnets.
* **Setup Checklist** (`/setup`) — First-run installation wizard and initial provider onboarding checklist.
* **Documentation** (`/docs`) — In-app team handbook, architecture docs, runbooks, and API catalogs.

## Live Search & Navigation

* **Instant Client-Side Filtering**: Powered by Alpine.js (`x-model="search"`). Filters all tool cards instantly by title, description, or keyword (e.g. typing `"slack"`, `"backup"`, `"palette"`, or `"token"` highlights matching cards in real time).
* **Settings Sub-Tabs (`settings._tabs.blade.php`)**: A persistent navigation block rendered above `<x-page-header>` on every settings view — Tier 1 displays the five pillar tabs (`Overview`, `Agency Branding`, `Fleet Policies`, `Integrations & Alerts`, `System & Workspace`); Tier 2 displays a contextual tools ribbon when the active pillar contains multiple tools, omitting ribbon clutter for single-tool pillars like Agency Branding.
* **Mega-Menu Popover**: Desktop header gear icon opens a categorized popover menu matching the hub categories, providing single-click direct access to any destination.
* **Mobile Slide-Over Drawer**: Responsive navigation drawer containing quick access links to settings pillars, theme switching, and sign-out actions.
