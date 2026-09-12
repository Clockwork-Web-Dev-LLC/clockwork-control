---
title: Fleet Integrations Setup
section: Features
order: 5
updated: 2026-09-11
author: Aaron Reimann
tags: [setup, onboarding, first-run, modularization, env, credentials, rate-limits]
tracks: [app/Http/Controllers/SetupController.php, app/Http/Controllers/ServiceApiLimitsController.php, app/Support/EnvCredentialManager.php, app/Support/ServiceRateLimitRegistry.php, resources/views/setup/**]
---

Lives at **`/setup`** (gear menu → Setup). The interactive onboarding and integration configuration dashboard for Clockwork Control. It allows operators to discover, toggle, and configure fleet integrations, customize connection tunables, and save API credentials directly to the root `.env` file without database storage or multi-step wizard friction.

## Why it exists

This app has no self-provisioning: a row in the `users` table *is* the OAuth allowlist (see `architecture/security-model`), so nobody can even log in without one already existing. That means by the time anyone reaches an authenticated page at all, someone has already run migrations and seeded a user — "first run" in the pre-auth sense doesn't really exist here. What this page solves is the next problem: a fresh install, freshly logged into, with an empty dashboard and no obvious next step. It exists to answer "what do I configure to make this do something," guiding the operator to activate their hosting panels, cloud providers, and alerting channels.

## The automatic redirect

`App\Http\Middleware\RedirectToSetupIfFreshInstall` is applied to the dashboard route only (not the whole authenticated route group) and sends you to `/setup` instead when `Server::count() === 0 && Site::count() === 0`. Deliberately not "zero credentials configured" — an operator might legitimately want to browse around with nothing set up yet, and a credential-based check would fight them on every page load by redirecting them away from the very settings page they're trying to use. An empty fleet is the one condition with no plausible false positive on a real, in-use instance — verified in `tests/Feature/SetupRedirectTest.php`.

`/setup` itself never redirects, regardless of fleet state — you can always reach it directly from the gear menu.

## Streamlined Single-Step Onboarding

Unlike traditional multi-step setup wizards that force operators through mandatory secondary screens, `/setup` operates in a streamlined, single-step workflow:
- Toggle on whichever integrations your agency uses.
- Optionally configure credentials or tunables directly in-place via the cog icon on each card.
- Run live connection tests from within the credential modal — connection failures and error diagnostics are reflected directly in the UI.
- Click **"Finish Setup & Go to Dashboard"** to save selections and immediately enter the control panel.

## Service Card Visual States

Each integration card displays one of three clear visual states reflecting both its active status and credential readiness:

| State | Visual Treatment | Toggle State | Meaning |
| :--- | :--- | :--- | :--- |
| **Integrated properly** | **Light green gradient with darker green border** (`.border-2.border-emerald-600.bg-gradient-to-r.from-emerald-50/90...`) | Green track (`bg-emerald-600`) with checkmark knob | The service is enabled and its API credentials or fleet records are configured and ready. |
| **Active but unconfigured** | **Slightly red gradient with solid red border** (`.border-2.border-rose-600.bg-gradient-to-r.from-rose-50/90...`) | Green active toggle track | The service is enabled, but is missing required API keys or tokens. Alerts the operator to click the gear icon. |
| **Inactive / Turned off** | **Clean white card** (`.bg-white.border.border-[var(--color-border-light)]`) | Red inactive track (`bg-rose-600`) with `X` knob | The service is toggled off and disabled in `installed_modules`. |

> [!NOTE]
> Card styling transitions dynamically in real time: toggling a switch or saving/removing an API key in the cog modal immediately updates the card without requiring a page refresh.

## In-Modal API Credentials & Rate Limits (The Cog Icon)

Every service card includes a gear icon that opens the Alpine.js settings modal (`serviceLimitsModal()`):

1. **Direct `.env` Credential Storage**:
   - API keys are pasted directly into the modal and saved immediately to the root `.env` file via `App\Support\EnvCredentialManager`.
   - **Never stored in the database**: Enforces single-source-of-truth configuration and purges legacy DB records.
   - **Runtime Process Reflection**: Updates the *current request's* process memory (`putenv`, `$_ENV`, `$_SERVER`, and Laravel `config`) immediately, and calls `php artisan queue:restart` so the long-running queue daemon picks up the new value on its next job — see [Features → Integrations settings](/docs/features/integrations#root-env-credential-architecture-envcredentialmanager) for what this does and doesn't reach.
   - **Masked Previews & Visibility Toggle**: Sensitive keys are masked (e.g. `••••••••7654`) with interactive eye toggles to inspect plain text.
   - **1-Click "Remove from .env" Button**: Clears the credential from the `.env` file asynchronously and transitions the card state immediately.
2. **Official Vendor Limits & Fleet Impact**:
   - Displays official vendor rate limits (requests per hour/minute), rolling window rules, and response headers (`RateLimit-Limit`, `RateLimit-Remaining`, `Retry-After`).
   - Details telemetry polling consumption based on fleet size.
3. **Connection Tunables**:
   - Direct inputs for HTTP Request Timeout (seconds), Concurrency limit, Inter-Request Delay Pacing (ms), and Automatic Retries with exponential backoff.
   - Instant "Reset to Defaults" button.

## Architectural Pairing & Guidance

- **Fleet Source Prerequisite**: At least one Managed WordPress host (Pressable, WP Engine, Kinsta) or Server Management Panel (SpinupWP, Cloudways, GridPane) must be enabled to discover sites.
- **Hardware Telemetry Pairing**: When a panel provider is active, pairing with a Cloud VPS provider (DigitalOcean, Hetzner, Vultr, Linode, Azure) is highlighted to collect 5-minute CPU, RAM, and droplet telemetry.
- **Category Counts**: Labeled as **"X integrations"** per category (e.g. "5 integrations", "6 integrations").

