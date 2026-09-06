---
title: Module Directory
section: Features
order: 91
updated: 2026-09-04
author: Aaron Reimann
tags: [modules, directory, ecosystem, settings, integrations]
tracks: [app/Http/Controllers/ModuleDirectoryController.php, modules/Core/src/ModuleDirectoryClient.php]
---

Lives at **`/settings/modules`** (gear menu → API credentials → "Browse Directory" or via Settings). Browse, inspect, and track official and community modules available for Clockwork Control (Control Panel).

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

## Controller & View

* **`ModuleDirectoryController::index()`**: Retrieves feed items, reconciles them with local `InstalledModule` models to compute installation and activation status, and passes category groupings to the view.
* **UI Features**:
  * **Instant Live Search**: Alpine.js client-side search filtering by module name, ID, and description.
  * **Category Navigation**: Filter across Cloud Providers, Hosting, Notifications, Authentication, Performance, Security (ManageWP Suite), and Billing.
  * **Trust Badges**: Visual indicators for `Official`, `Verified`, `Community`, and `Testing`.
  * **Status Pills**: Distinguishes between `Active`, `Installed`, and available modules.
  * **Direct Actions**: Links to credential configuration for installed modules or GitHub repositories for new/community modules.

## Community Modules & Contributions

Developers can publish their own modules to GitHub. By tagging their repository with `clockworkcontrol-module`, community extensions become discoverable. Modules reviewed and merged via pull request to `clockworkcontrol.com-astro` are added to the official feed.
