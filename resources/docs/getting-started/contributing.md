---
title: Contributing & Module Testing
section: Getting Started
order: 15
updated: 2026-09-06
author: Aaron Reimann
tags: [contributing, modules, open-source, vibe-coding, claude]
tracks: [CONTRIBUTING.md]
---

Clockwork is an open-source fleet monitoring brain built by an agency, for agencies. It watches server health, WordPress sites, uptime, SSL, security, and performance across diverse hosting stacks.

To make Clockwork universal, we designed a modular architecture (`modules/`) and wrote integration code for virtually every major WordPress hosting platform and cloud provider. However, **one agency cannot possibly have active production accounts on every single hosting service.**

That's where you come in.

```
┌─────────────────┐     ┌───────────────────┐     ┌───────────────────────┐     ┌──────────────────┐
│ 1. Get Module   │ ──> │ 2. Test Live APIs │ ──> │ 3. Vibe Code w/Claude │ ──> │ 4. Open a PR     │
│ Download/enable │     │ Use your agency's │     │ Paste errors/payloads │     │ Share fixes with │
│ for your stack  │     │ real credentials  │     │ & let AI patch code   │     │ the community    │
└─────────────────┘     └───────────────────┘     └───────────────────────┘     └──────────────────┘
```

---

## The Big Idea: We Built It for Our Stack. We Gave You a Huge Head Start to Finish Yours.

We run Clockwork Control Panel in production for our own agency’s infrastructure (including SpinupWP, DigitalOcean, Hetzner, Pressable, and Cloudflare). For the platforms we don't use every day—like **WP Engine**, **Kinsta**, **Cloudways**, **Azure**, **Linode**, and **Vultr**—we did the heavy lifting: clean provider contracts, typed clients, diagnostic health checks, and credential resolvers are already 80% written.

What these modules need now is **real-world testing** against live accounts:
- Does the provider's API payload have an unexpected envelope?
- Does their SSH gateway have specific cipher or sudo constraints?
- Is there a rate limit or pagination quirk?

**You do not need to be a developer to finish them.** As long as you have an active account on one of these platforms and know how to prompt Claude (or Cursor / Antigravity / ChatGPT), you have everything you need:
1. Try the integration with your agency's credentials.
2. If an API call fails or an endpoint shape differs from docs, copy the raw error trace or JSON response.
3. Feed it to **Claude**: *"Here's the real payload; patch the response parser and make Pest tests pass."*
4. Run `./vendor/bin/pest` and open a Pull Request.

By spending 15 minutes testing your agency's provider and letting Claude generate the fix, you help yourself and every other agency using that platform.

---

## Modules Looking for Testers

Check the badges on [`/settings/integrations`](/settings/integrations) or the full matrix in [Integrations → Overview](/docs/integrations/overview):

| Integration | Type | What needs live verification | Doc Link |
|---|---|---|---|
| **GitHub** | Auth | Live login callback verification with personal and organization GitHub accounts | [Google & OAuth](/docs/integrations/google-oauth) |
| **Microsoft** | Auth | Microsoft Entra ID / Microsoft 365 OAuth tenant authentication and user info parsing | [Google & OAuth](/docs/integrations/google-oauth) |
| **WP Engine** | Hosting | Backup response envelope, domain SSL discovery, per-install SSH gateway | [WP Engine](/docs/integrations/wp-engine) |
| **Kinsta** | Hosting | Environment SSH host/port lookup (`sshConnectionInfo`), on-demand SSH password generation | [Kinsta](/docs/integrations/kinsta) |
| **Cloudways** | Hybrid | Cloudways API v2 monitoring payload shapes, app-isolated SSH vs cross-site sudo | [Cloudways](/docs/integrations/cloudways) |
| **Azure** | Cloud | VM metrics polling, tenant authentication refresh | [Azure](/docs/integrations/azure) |
| **Linode (Akamai)** | Cloud | Multi-core vCPU normalized stats, instance reconciliation | [Linode](/docs/integrations/linode) |
| **Vultr** | Cloud | Instance reconciliation, plan size tier mapping | [Vultr](/docs/integrations/vultr) |

---

## Step-by-Step Contribution Guide

### Step 1: Download & Enable the Module
Every module lives under `modules/{Name}/` as a standalone Composer package symlinked into the application:
- If you're running the Clockwork repository directly, all bundled modules are already present in `modules/`.
- Verify your provider's service provider is enabled in `bootstrap/providers.php`.

### Step 2: Configure Live Credentials (In `.env` Only!)

> [!IMPORTANT]
> **API Key & AI Safety Rule:**
> - **Always manually paste your live API keys directly into `.env` yourself.**
> - **NEVER feed real API keys, bearer tokens, SSH private keys, or passwords to Claude, Cursor, ChatGPT, Antigravity, or any AI.**
> - AI coding assistants *do not need your secrets* to fix response envelope parsers or API payload models.
> - When prompting AI with sample JSON responses or error logs, **always sanitize them first** by replacing real tokens with dummy values (e.g. `your-api-token-here` or `Bearer <REDACTED>`).

Go to **Settings → API credentials** (`/settings/integrations`) in the web UI, or add credentials directly to your local `.env` file (recommended so secrets never enter MySQL or database dumps).
For example, for WP Engine:
```env
CLOCKWORK_WPENGINE_API_USER_ID=your-api-user-id
CLOCKWORK_WPENGINE_API_PASSWORD=your-api-password
CLOCKWORK_WPENGINE_SSH_PRIVATE_KEY="-----BEGIN OPENSSH PRIVATE KEY-----\n..."
```

### Step 3: Run Live Probes
1. Visit `/settings/diagnostics` and click **Run checks**.
2. Or run the test button under **Settings → API credentials**.
3. Trigger scheduled commands manually to observe output:
   ```bash
   php artisan clockwork:poll-servers    # for cloud providers
   php artisan clockwork:import-pressable # for hosting providers
   ```

### Step 4: Vibe Code Fixes with Claude
If an endpoint returns an unexpected HTTP code or response structure, don't stress. Just copy the error trace or raw JSON output (**ensuring any authorization headers or secret tokens are replaced with dummy values**) and prompt Claude:

> **Example Prompt for Claude:**
> 
> *"I am testing the WP Engine module in Clockwork Control Panel. When calling `GET /installs`, my live WP Engine API account returned this JSON payload (sanitized with dummy IDs):*
> 
> *```json*
> *{ ...paste sanitized json response here... }*
> *```*
> 
> *The current parser in `modules/WPEngine/src/WPEngineClient.php` threw a `KeyNotFoundException` on `results`. Please update `WPEngineClient.php` and `WPEngineHostingProvider.php` to handle this shape cleanly, preserve fallback compatibility, update the matching mock fixture in `tests/`, and keep all Pest tests passing."*

Claude will adjust the parser, update the contracts, and write or update test fixtures!

### Step 5: Verify Quality
Before opening your pull request, run the standard quality trifecta:

```bash
./vendor/bin/pint       # Automatic code styling (opinionated Laravel Pint)
composer phpstan        # Static analysis (Larastan level 5 — must be clean)
./vendor/bin/pest       # In-memory test suite
```

All three must pass cleanly. Pint will automatically reformat your code to match the project conventions.

### Step 6: Submit a Pull Request
Push your branch to your fork and submit a PR to `Clockwork-Web-Dev-LLC/clockwork-control`:
- Mention the module you tested.
- Note which provider endpoints you verified with real credentials.
- If you made changes, briefly describe what the live API returned vs. what the original code assumed.

We review and merge PRs quickly!

---

## Building 3rd-Party & Community Modules

Clockwork Control is designed from the core to be extended. If your agency runs a hosting platform, cloud hypervisor, or alerting system that isn't bundled in the core repository, **we want you to build a module for it—and we will list it as an official 3rd-party module.**

### Why Build a Module for Your Use Case?
- **Custom Hosting Stacks**: RunCloud, GridPane, Enhance, Plesk, cPanel, ServerPilot, or AWS Lightsail.
- **Alternative Cloud Hypervisors**: Scaleway, OVHcloud, Google Cloud Compute Engine, or custom Proxmox/VMware clusters.
- **Custom Alert Channels**: PagerDuty, Opsgenie, Telegram, Discord, Microsoft Teams, or custom SMS carriers (MessageBird, AWS SNS, Telnyx).
- **Internal Agency Probes**: Custom client SLA trackers, custom billing sync engines, or internal staging environment scanners.

You don't need to wait for the Clockwork Control maintainers to add support. You can build, run, and distribute your own module immediately.

---

### Extension Architecture: Zero Core Edits

Clockwork Control's modular system isolates integrations completely:

1. **`ModuleServiceProvider`**: Every module extends `Modules\Core\ModuleServiceProvider`.
2. **`ModuleManifest`**: Declares module ID, title, description, and credential fields.
   - Any credential fields defined here (`token`, `api_key`, `secret`, etc.) **automatically render in the Settings → API Credentials UI** (`/settings/integrations`).
   - Clockwork Control encrypts the keys in the database and provides them to your service provider at runtime. You do not need to create database migrations or edit any core Blade templates!
3. **Contracts**: Your service provider can optionally implement and return any of:
   - `cloudProvider(): ?CloudProvider` — Telemetry (CPU/RAM/load), server reconciliation by IP, and size tier labels.
   - `hostingProvider(): ?HostingProvider` — WordPress site discovery, domain mapping, backup history, and SSH/WP-CLI execution.
   - `smsNotifier(): ?SmsNotifier` — Custom SMS dispatcher for on-call alerting.
   - `diagnosticCheck(): ?DiagnosticCheck` — Automated health probes integrated into `/diagnostics`.
   - `scheduledTasks(Schedule $schedule): void` — Background sync or metric-polling jobs registered with Laravel's scheduler.

---

### Step-by-Step: Creating a Module

#### 1. Scaffold the Module Directory
Create a folder under `modules/{ModuleName}/`:
```
modules/RunCloud/
├── composer.json
└── src/
    ├── RunCloudClient.php
    ├── RunCloudHostingProvider.php
    ├── RunCloudCheck.php
    └── RunCloudServiceProvider.php
```

In `modules/RunCloud/composer.json`:
```json
{
    "name": "clockwork/runcloud",
    "description": "RunCloud hosting provider integration for Clockwork Control Panel",
    "type": "library",
    "require": {
        "php": "^8.4",
        "clockwork/core": "@dev"
    },
    "autoload": {
        "psr-4": {
            "Modules\\RunCloud\\": "src/"
        }
    }
}
```

#### 2. Define the Service Provider & Manifest
In `modules/RunCloud/src/RunCloudServiceProvider.php`:
```php
<?php

namespace Modules\RunCloud;

use Modules\Core\ModuleManifest;
use Modules\Core\ModuleServiceProvider;
use Modules\Core\Contracts\HostingProvider;

class RunCloudServiceProvider extends ModuleServiceProvider
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'runcloud',
            name: 'RunCloud',
            description: 'Sync servers, manage WordPress installs, and run remote commands via the RunCloud API v1.',
            credentialFields: [
                'api_key' => ['label' => 'API Key', 'secret' => true],
                'api_secret' => ['label' => 'API Secret', 'secret' => true],
            ],
            status: ModuleManifest::STATUS_VERIFIED,
            statusNote: 'Built and verified for RunCloud production accounts.',
        );
    }

    public function hostingProvider(): ?HostingProvider
    {
        return $this->app->make(RunCloudHostingProvider::class);
    }
}
```

#### 3. Register the Provider
Add the service provider class to `bootstrap/providers.php`:
```php
return [
    App\Providers\AppServiceProvider::class,
    Modules\Core\CoreServiceProvider::class,
    // ...
    Modules\RunCloud\RunCloudServiceProvider::class,
];
```

That's it! As soon as registered:
- RunCloud appears in the setup checklist and on `/settings/integrations`.
- API keys entered in the browser are securely encrypted and resolved at runtime.
- The `RunCloudHostingProvider` is called automatically whenever Clockwork Control interacts with sites hosted on RunCloud.

---

### Vibe-Coding Your Module with Claude

You don't need to write every endpoint wrapper from scratch. You can paste our contract interfaces and your provider's API docs into Claude:

> *"I am building a 3rd-party module for Clockwork Control Panel to integrate with [Provider Name].*
>
> *Here is the Clockwork Control contract interface:*
> *[Paste `modules/Core/src/Contracts/HostingProvider.php` or `CloudProvider.php`]*
>
> *Here is the base `ModuleServiceProvider` and `ModuleManifest`:*
> *[Paste `modules/Core/src/ModuleServiceProvider.php`]*
>
> *Here is the [Provider Name] REST API documentation for [Endpoints].*
>
> *Please generate:*
> *1. `{Provider}Client.php` wrapping HTTP calls with Laravel HTTP client.*
> *2. `{Provider}HostingProvider.php` implementing the contract.*
> *3. `{Provider}ServiceProvider.php` registering the module manifest.*
> *4. Pest test cases with JSON response fixtures."*

---

### Architectural Note: Why the Installer Isn't a Module

The Web-Based Installer (`/install`, living at `app/Installer/`, `app/Http/Controllers/InstallerController.php`, and `routes/install.php`) is built directly into the application core rather than modeled as a package under `modules/Installer/`.

Every module in `modules/{Name}/` extends `Modules\Core\ModuleServiceProvider`, booting against an active database connection and relying on `AppSetting` storage. The installer's entire purpose is to run *before* the database, encryption keys, or authentication tables are configured. Trying to shoehorn pre-flight installation into a module creates circular dependencies against an unconfigured environment.

Additionally, the installer is permanent and mandatory for self-hosted instances rather than an optional or swappable third-party integration. The architectural test suite enforces that `App\Installer` classes never import `Modules\*`.

---

### Getting Listed in the Module Directory (`/api/modules.json`)

We want to highlight and promote community modules so other agencies can benefit from your work! Clockwork Control powers an automated ecosystem directory accessible both on the web and inside every self-hosted installation at `/settings/modules`.

The directory feed is published as a public JSON API at:
```
https://clockworkcontrol.com/api/modules.json
```

Inside the Clockwork Control web app, the `ModuleDirectoryClient` polls this feed (cached for 6 hours) to render available official and community modules, allowing operators to discover extensions, verify trust tiers, and access GitHub repositories.

Once your module is functional in a public Git repository:

1. **Tag with Topic**: Add the topic `clockworkcontrol-module` to your GitHub repository.
2. **Submit a Pull Request or Issue**: Open an issue or PR against `Clockwork-Web-Dev-LLC/clockwork-control` titled `[3rd-Party Module] Your Module Name`.
3. **Provide Module Information**:
   - **Module ID**: (e.g. `runcloud`)
   - **Module Name**: (e.g. `RunCloud Hosting`)
   - **Category**: `cloud_vps`, `managed_hosts`, `control_panels`, `notifications`, `authentication`, `performance`, or `billing`
   - **Repository URL**: GitHub link
   - **Supported Contracts**: `HostingProvider`, `CloudProvider`, `AuthProvider`, `SmsNotifier`, or `DiagnosticCheck`
   - **Author / Agency**: Your agency name and website link
   - **License**: Open source license (MIT, Apache 2.0, etc.)
4. **We Feature It**:
   - We audit and index your module into the live `https://clockworkcontrol.com/api/modules.json` feed.
   - It immediately shows up in the in-app **Module Directory** (`/settings/modules`) across all running Clockwork Control installations worldwide.
   - We feature it on [`clockworkcontrol.com/modules`](https://clockworkcontrol.com/modules) with full credit and backlinks to your agency.

If you have questions while designing a module or need an interface expanded, reach out in GitHub Discussions or open an issue!
