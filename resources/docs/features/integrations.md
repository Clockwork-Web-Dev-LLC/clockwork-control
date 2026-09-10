---
title: Integrations settings
section: Features
order: 89
updated: 2026-09-09
author: Aaron Reimann
tags: [integrations, credentials, settings, modularization, rate-limits, env]
tracks: [app/Http/Controllers/IntegrationCredentialsController.php, app/Http/Controllers/ServiceApiLimitsController.php, app/Support/EnvCredentialManager.php, app/Support/ServiceRateLimitRegistry.php, app/Support/CredentialResolver.php, app/Models/IntegrationCredential.php, modules/*/src/*ServiceProvider.php]
---

Lives at **`/settings/integrations`** (gear menu → API credentials, or via the centralized [Settings Hub](/docs/features/settings-hub) at `/settings`, under Integrations & Alerts). Provides credential management and connection monitoring for all supported cloud VPS providers, hosting platforms, and external service APIs. A "Browse Directory" button links directly to the [Module Directory](/docs/features/module-directory) at `/settings/modules`.

## Root `.env` Credential Architecture: `EnvCredentialManager`

To eliminate unnecessary database credential storage and enforce the root `.env` file as the single source of truth:
- **`App\Support\EnvCredentialManager`** maintains canonical environment variable mappings for all 25 fleet services (e.g. `CLOCKWORK_DIGITALOCEAN_TOKEN`, `CLOCKWORK_HETZNER_TOKEN`, `CLOCKWORK_SPINUPWP_TOKEN`, `CLOCKWORK_AZURE_*`, `GOOGLE_*`, `TWILIO_*`, etc.).
- Saving credentials from either the `/setup` cog modal or the dedicated limits page at `/settings/integrations/{service}/limits` writes atomically to the root `.env` file via regex.
- **Process Memory Reflection**: Calls `putenv()`, updates `$_ENV` and `$_SERVER`, and mutates Laravel `config([$path => $value])` — this only affects the *current* request's own PHP process, so a save is immediately visible on the confirmation response and any code that runs later in that same request. It does **not** reach other already-running processes.
- **Queue restart, not a full server reboot**: `ServiceApiLimitsController` calls `php artisan queue:restart` after any credential change, so the long-running `queue:work` daemon (`com.clockwork.queue`) picks up the new value on its very next job — launchd's `KeepAlive` respawns it automatically, no manual restart needed. Scheduled/cron-driven fleet polling doesn't need this at all: `schedule:run` forks a fresh process every minute and reads `.env` fresh every time. The one thing that's genuinely NOT instant is `php artisan serve`/php-fpm itself — those are long-running processes that loaded `.env` once at boot, so a credential saved here won't be visible to a *different* concurrent request until that process restarts (rare in practice, since this app is meant to run under Herd/php-fpm which recycles workers regularly, not the long-lived dev server).
- **Single Source of Truth**: When saved to `.env`, any legacy rows in `integration_credentials` are automatically purged from the database.
- **1-Click Removal**: Operators can remove any credential from `.env` using the dedicated "Remove from .env" button (`POST /settings/integrations/{service}/credentials/{field}/remove`) — this also triggers the same queue restart.

## Rate Limits, Telemetry Impact & Tunables: `ServiceRateLimitRegistry`

Each provider service now has a dedicated rate limits and connection configuration page at **`/settings/integrations/{service}/limits`** (`ServiceApiLimitsController`):

- **Official Vendor Rate Limits**: Documented hourly/per-minute request limits, burst rules, and monitored response headers (`RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset`, `Retry-After`).
- **Fleet Polling Telemetry Costs**: Projective breakdown of background polling impact across Droplet/VM counts (e.g., 25 droplets = 1,500 req/hr; 80+ droplets requires inter-request delay pacing).
- **Operator Connection Tunables**:
  - `timeout`: HTTP request timeout (1–300s).
  - `concurrency`: Maximum simultaneous HTTP connections (1–10).
  - `delay_ms`: Inter-request delay pacing (ms) via `usleep` to smooth background polling bursts.
  - `retry_attempts`: Automatic retries on HTTP 429 / 503 with exponential backoff.
- Runtime clients (e.g. `DigitalOceanClient`) dynamically read these operator overrides from `App\Support\Settings`.

## Descriptions & Capability Badges

Each integration card on `/settings/integrations` shows a one-line description and a row of feature-capability badges (e.g. "Offsite S3 Backups", "WAF Analytics", "Automated Provisioning"). For the built-in services (`do_spaces`, `cloudflare`, `security_scans`, `ssh`) these come from static `description`/`capabilities` entries on `IntegrationCredentialsController::INTEGRATIONS`; for module-provided integrations, `IntegrationCredentialsController::index()` fetches the live feed via `ModuleDirectoryClient::fetch()` and prefers the feed's `description`/`capabilities` when present, falling back to the static metadata (or the module manifest's own description) otherwise. A feed fetch failure is swallowed silently — the page just falls back to static metadata rather than erroring. These badges used to live on the [Module Directory](/docs/features/module-directory) cards; they moved here to declutter that page.

## Legacy DB Fallback: `CredentialResolver`

For backwards compatibility with database-encrypted credentials:
- Every credential-holding client (`AzureClient`, `PressableClient`, `CloudflareClient`, `TwilioClient`, …) is bound in its service provider with named constructor args resolved through `CredentialResolver::get('provider.field', $default)`.
- `CredentialResolver::source('provider.field')` checks for any database records first, then falls back to `config()` and the `.env` value.

## Routes

- `GET /settings/integrations` (`settings.integrations.index`) — overview of all registered integration credentials.
- `PATCH /settings/integrations` (`settings.integrations.update`) — bulk update credentials.
- `POST /settings/integrations/{integration}/test` (`settings.integrations.test`) — runs diagnostic checks on demand.
- `GET /settings/integrations/{service}/limits` (`settings.integrations.limits`) — dedicated limits, telemetry docs, tunables, and `.env` credentials.
- `PATCH /settings/integrations/{service}/limits` (`settings.integrations.limits.update`) — save connection tunables and `.env` credentials.
- `POST /settings/integrations/{service}/limits/reset` (`settings.integrations.limits.reset`) — reset tunables to vendor defaults.
- `POST /settings/integrations/{service}/credentials/{field}/remove` (`settings.integrations.credentials.remove`) — remove a credential from `.env`.

