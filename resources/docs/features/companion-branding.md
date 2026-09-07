---
title: Companion & White Label
section: Features
order: 93
updated: 2026-09-07
author: Aaron Reimann
tags: [companion, branding, white-label, agency, wordpress]
tracks: [app/Http/Controllers/CompanionSettingsController.php, app/Services/Companion/CompanionBrandingManager.php, app/Jobs/PushCompanionBrandingJob.php, app/Console/Commands/PushCompanionBranding.php, resources/views/settings/companion.blade.php]
---

Lives at **`/settings/companion`** (gear menu → Companion (White label)). Lets an agency replace Clockwork's own branding — plugin name/description, author/company, admin menu label and icon, support email, dashboard logo, and admin footer text — with their own across every Companion-equipped WordPress site in the fleet.

## How it's stored and pushed

- **`App\Services\Companion\CompanionBrandingManager`** persists the configuration to `App\Support\Settings` (key prefix `companion.branding.*`), merged with sane Clockwork defaults (`DEFAULT_COMPANY_NAME`, `DEFAULT_PLUGIN_NAME`, etc.) whenever a field is unset.
- Saving triggers **`ClockworkCompanionClient::pushBranding()`**, an HMAC-signed REST call to each site's `POST /wp-json/clockwork/v1/branding` — the same signed-request mechanism used by every other Companion write endpoint. The remote plugin stores the payload in `wp_options['clockwork_companion_branding']` and filters its own plugin header, admin menu, and support links from it on every page load — no outbound requests from the WordPress side.
- **Push timing**:
  - Checking "Push to all connected sites upon saving" (default on) when saving the form dispatches `PushCompanionBrandingJob` (queued, fleet-wide).
  - The dedicated **"Sync Fleet Now"** button in the page header runs `CompanionBrandingManager::syncFleet()` synchronously and reports a `successful`/`failed` count per site.
  - `php artisan clockwork:push-companion-branding [--site=<id-or-domain>]` does the same from the CLI, scoped to one site or the whole fleet.
  - **Every Companion install or update** (`CompanionInstaller`/`PressableCompanionInstaller::installOrUpdate()`) also pushes the current branding to that one site inline, best-effort — so a freshly-installed or re-installed Companion never briefly shows default Clockwork branding before the next fleet sync.
- Only sites with `companion_installed = true` and a non-empty `companion_secret` are eligible; everything else is silently skipped (not an error) since there's no signed channel to reach them yet.

## Logo upload

- `POST /settings/companion/logo` accepts `png`, `jpg`, `jpeg`, `svg`, or `webp` up to 2MB, stored on the `public` disk under `branding/` and referenced by its public URL in `logo_url`. Re-uploading or resetting does not delete the previous file from storage — a known minor cleanup gap, not a functional issue.

## Live preview

The right-hand column on `/settings/companion` is an Alpine.js mockup of the WordPress sidebar menu, plugins list row, and Companion dashboard header, updating live as the form fields change — nothing here calls out to a real site; it's local-only, for visualizing the effect before saving.

## Routes

- `GET /settings/companion` (`settings.companion.index`) — the settings + preview page.
- `PATCH /settings/companion` (`settings.companion.update`) — save branding, optionally queueing a fleet push.
- `POST /settings/companion/logo` (`settings.companion.logo`) — upload a brand logo.
- `POST /settings/companion/sync` (`settings.companion.sync`) — push current branding to the whole fleet now.
- `POST /settings/companion/reset` (`settings.companion.reset`) — revert every field back to Clockwork Control defaults.
