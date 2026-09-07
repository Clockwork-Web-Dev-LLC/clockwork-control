---
title: Module Directory
section: Features
order: 91
updated: 2026-09-07
author: Aaron Reimann
tags: [modules, directory, ecosystem, settings, integrations]
tracks: [app/Http/Controllers/ModuleDirectoryController.php, modules/Core/src/ModuleDirectoryClient.php]
---

Lives at **`/settings/modules`** (gear menu → API credentials → "Browse Directory", or via Settings Hub → Integrations & Alerts). Browse, inspect, and track official and community modules available for Clockwork Control (Control Panel).

The directory is backed by the public metadata feed at `https://clockworkcontrol.com/api/modules.json`, hosted and statically served by `clockworkcontrol.com`. It provides real-time visibility into new integrations, trust tiers, capabilities, and update statuses without requiring an application upgrade.

## The Public Feed (`https://clockworkcontrol.com/api/modules.json`)

The feed publishes a typed, JSON-formatted schema (`schema_version: "1.0"`) with CORS enabled (`Access-Control-Allow-Origin: *`). Each entry includes:

* `id` — Module identifier (e.g. `hetzner`, `pressable`, `gtmetrix`, `auth_github`)
* `name` — Human-friendly display name
* `category` — Functional category (`cloud_provider`, `hosting`, `notification`, `authentication`, `performance`, `security`, `billing`)
* `description` — Summary of what the integration does
* `trust_tier` — Verification badge (`official`, `verified`, `community`, `testing`)
* `capabilities` — Integration hooks (`server_monitoring`, `hosting_sync`, `oauth`, etc.)
* `repository_url` — Link to source code repository on GitHub
* `author` & `version` — Package maintainer and release version

## In-App Client: `ModuleDirectoryClient`

The application interacts with the feed through `Modules\Core\ModuleDirectoryClient`:

1. **6-Hour Caching**: Remote feed responses are cached in Laravel cache for 21,600 seconds (6 hours) under `clockwork.module_directory.feed`.
2. **Network Resilience & Stale Fallback**: If an upstream network timeout or HTTP error occurs, the client catches the exception, logs a warning, and returns the stale cached payload if available.
3. **Offline Fallback**: If no cached data exists and the network is unavailable, the client seamlessly falls back to the local `ModuleCatalog::bundled()` (`modules/Core/src/ModuleCatalog.php`) so the directory page never fails or renders blank.
4. **On-Demand Cache Refresh**: Clicking "Check for Updates" triggers `POST /settings/modules/refresh`, which flushes the cache and fetches fresh metadata from the upstream feed.

## Controller & View Architecture

* **`ModuleDirectoryController::index()`**: Retrieves feed items, reconciles them with local `InstalledModule` models to compute installation and activation status, and passes category groupings to the view.
* **UI Features**:
  * **Header Search Bar**: Prominently located in the top-right header actions slot (`x-model="searchQuery"`), leaving the category tabs bar 100% of horizontal space to scroll cleanly without collisions.
  * **Settings Sub-Tabs**: Integrated with `settings._tabs.blade.php`, highlighting the *Integrations & Alerts* tab and linking back to the unified Settings Hub.
  * **Full-Width Category Navigation**: Filter across Cloud Providers, Hosting, Notifications, Authentication, Performance, Security (ManageWP Suite), and Billing.
  * **Trust Badges**: Visual indicators for `Official`, `Verified`, `Community`, and `Testing`.
  * **Status Pills**: Distinguishes between `Active`, `Installed`, and available modules.
  * **Direct Actions**: Links to credential configuration for installed modules or GitHub repositories for new/community modules.

## Community Modules & Submission Workflow

Developers can build and publish their own modules to GitHub. Community intake follows the architecture outlined in [`plans/module-submission-workflow.md`](plans/module-submission-workflow.md):
1. **Repository Standards**: Packages implement `Modules\Core\ModuleServiceProvider` and tag their public GitHub repository with `clockworkcontrol-module`.
2. **Intake & Verification**: Authors submit via GitHub Issue template (`submit_module.yml`) or the marketing site.
3. **Feed Schema Validation**: Submitted packages undergo automated JSON schema testing before inclusion in the upstream directory feed.
