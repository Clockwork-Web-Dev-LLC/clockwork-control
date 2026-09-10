---
title: Companion & White Label
section: Features
order: 93
updated: 2026-09-10
author: Aaron Reimann
tags: [companion, branding, white-label, agency, wordpress]
tracks: [app/Http/Controllers/CompanionSettingsController.php, app/Services/Companion/CompanionBrandingManager.php, app/Jobs/PushCompanionBrandingJob.php, app/Console/Commands/PushCompanionBranding.php, resources/views/settings/companion.blade.php, resources/views/settings/companion-preview.blade.php, resources/views/components/color-picker.blade.php]
---

Lives at **`/settings/companion`** (gear menu → White Labeling — renamed from "Companion"). A centralized hub covering every client-facing surface an agency wants rebranded:

1. **Master Agency Brand Palette** — global primary and accent colors that can be defined once and cascaded across all surfaces with a single click.
2. **Companion (wp-admin)** — plugin name/description, author/company, admin menu label and icon, support email, dashboard logo, brand colors, and admin footer text, pushed into every Companion-equipped WordPress site.
3. **Client Reports** — brand colors and footer text for the Client Reports module's PDF/web output.
4. **Plugin Notification Email** — header/accent colors, sender name, reply-to, and badge text for the CVE/vulnerability alert emails Companion triggers, plus a test-send action.

## Master Agency Brand Palette

A quick-action palette bar anchored at the top of the hub allows operators to set agency-wide Primary (`#2D2062`) and Accent (`#7EFF83`) colors:
- Operators can adjust colors and click **Save Master** (`PATCH /settings/companion` with `tab: 'master'`) to persist the master palette in `master.branding.*`.
- Clicking **Apply to All 3 Hubs** immediately syncs the input fields and live previews in the current browser session without saving.
- Clicking **Save & Cascade All** (`PATCH /settings/companion` with `tab: 'master', apply_to_all: 1`) persists the master palette and propagates the primary and accent colors across:
  - Companion (wp-admin): `companion.branding.primary_color` & `companion.branding.accent_color`
  - Client Reports: `reports.branding.primary_color` & `reports.branding.accent_color`
  - Plugin Notification Email: `email.branding.header_bg` & `email.branding.accent_color`
- Each individual hub still retains its own color picker inputs for fine-tuning overrides when desired.

## Tabs

A client-side segmented control (`activeTab` Alpine state) switches between the three panels; `?tab=companion|reports|email` on the index route (and on every redirect after saving/resetting a given tab) makes sure a link or page reload lands back on the right one.

- **Companion (wp-admin)** is the original branding surface; configuring company identity, custom colors (with companion header two-tone contrast), logo, and admin menu preferences.
- **Client Reports** writes to `reports.branding.*` settings keys, falling back to tab 1's shared agency identity (company name, logo, support email/URL) for any field left blank. Colors default to Clockwork's own purple/green (`#2D2062` / `#7EFF83`) until customized. This tab is only meaningfully exercised when the `client_reports` module is enabled (`ModuleStateResolver::isEnabled('client_reports')`) — its tab button shows a small "Disabled" badge instead of the enabled/disabled status dot when the module is off, though the form itself doesn't block saving.
- **Plugin Notification Email** writes to `email.branding.*` settings keys with the same shared-defaults fallback, plus a **"Send test email"** action that fires a real `SiteVulnerabilityReportMail` — populated with two fabricated demo plugin vulnerabilities, not live site data — to an operator-supplied address, so the styling can be checked in an actual inbox before a client ever sees it.

## How it's stored and pushed

- **`App\Services\Companion\CompanionBrandingManager`** persists tab 1's configuration to `App\Support\Settings` (key prefix `companion.branding.*`), merged with sane Clockwork defaults (`DEFAULT_COMPANY_NAME`, `DEFAULT_PLUGIN_NAME`, etc.) whenever a field is unset. Tabs 2 and 3 have their own prefixes (`reports.branding.*`, `email.branding.*`) via `getReportsBranding()`/`saveReportsBranding()`/`resetReports()` and `getEmailBranding()`/`saveEmailBranding()`/`resetEmail()`. Master palette is stored in `master.branding.*`.
- Saving tab 1 triggers **`ClockworkCompanionClient::pushBranding()`**, an HMAC-signed REST call to each site's `POST /wp-json/clockwork/v1/branding` — the same signed-request mechanism used by every other Companion write endpoint. The remote plugin stores the payload in `wp_options['clockwork_companion_branding']` and filters its own plugin header, admin menu, and support links from it on every page load — no outbound requests from the WordPress side.
- **Only tab 1 (Companion/wp-admin) is pushed to the fleet.** Client Reports and Plugin Notification Email branding render entirely inside Clockwork Control (report output, outbound emails) and never reach the remote plugin — there's no "sync fleet" concept for them. They take effect the next time a Client Report is generated or a vulnerability email is sent.
- **Push timing** (tab 1 only):
  - Checking "Push to all connected sites upon saving" (default on) when saving the form dispatches `PushCompanionBrandingJob` (queued, fleet-wide).
  - The dedicated **"Sync Fleet Now"** button in the page header launches `clockwork:push-companion-branding` in the background (`BackgroundArtisan`) and redirects immediately.
  - `php artisan clockwork:push-companion-branding [--site=<id-or-domain>]` does the same from the CLI, scoped to one site or the whole fleet.
  - **Every Companion install or update** (`CompanionInstaller`/`PressableCompanionInstaller::installOrUpdate()`) also pushes the current branding to that one site inline, best-effort — so a freshly-installed or re-installed Companion never briefly shows default Clockwork branding before the next fleet sync.
- Only sites with `companion_installed = true` and a non-empty `companion_secret` are eligible; everything else is silently skipped (not an error) since there's no signed channel to reach them yet.

## Vulnerability emails use the Plugin Notification Email tab

`SiteVulnerabilityReportMail` and its Blade view (`emails/site-vulnerability-report.blade.php`) pull header background, accent color, badge text, sender name, and reply-to from `CompanionBrandingManager::getEmailBranding()` instead of hardcoded styles — so the CVE alert emails a client receives carry the agency's own branding once this tab is configured (or its shared-default fallbacks otherwise).

## Client Reports branding

`ClientReportCompiler` and the report view read `CompanionBrandingManager::getReportsBranding()` for primary/accent colors and footer text on generated Client Reports.

## Logo upload & storage management

- `POST /settings/companion/logo` accepts `png`, `jpg`, `jpeg`, or `webp` up to 2MB (SVG files are intentionally rejected to mitigate stored script injection risks). Stored on the `public` disk under `branding/` and referenced by its public URL in `logo_url`.
- Uploading a replacement logo, entering an external logo URL, or resetting branding cleanly deletes previously uploaded logo files from disk storage.
- The uploaded logo is shared across all three tabs — Client Reports and email branding fall back to it when they don't specify their own.

## Live preview

The right-hand column of the Companion (wp-admin) tab is an Alpine.js mockup of the WordPress sidebar menu, plugins list row, and Companion dashboard header, updating live as the form fields change — nothing here calls out to a real site; it's local-only, for visualizing the effect before saving. A dedicated preview route (`/settings/companion/preview`) also renders the full isolated wp-admin chrome mockup in a separate window/tab.

## Routes

- `GET /settings/companion` (`settings.companion.index`) — the settings + preview page; accepts `?tab=companion|reports|email`.
- `GET /settings/companion/preview` (`settings.companion.preview`) — isolated full-page endpoint rendering the live WordPress admin chrome preview.
- `PATCH|POST /settings/companion` (`settings.companion.update`) — save branding for whichever tab was submitted (`tab=companion|reports|email|master`), optionally cascading colors or queuing a fleet push.
- `POST /settings/companion/logo` (`settings.companion.logo`) — upload a brand logo (`png, jpg, jpeg, webp` up to 2MB).
- `POST /settings/companion/sync` (`settings.companion.sync`) — push current Companion (wp-admin) branding to the whole fleet now.
- `POST /settings/companion/reset` (`settings.companion.reset`) — revert Companion (wp-admin) tab fields back to Clockwork Control defaults and purge stored logo.
- `POST /settings/companion/reset-reports` (`settings.companion.reset-reports`) — revert Client Reports branding to shared defaults.
- `POST /settings/companion/reset-email` (`settings.companion.reset-email`) — revert Plugin Notification Email branding to defaults.
- `POST /settings/companion/test-email` (`settings.companion.test-email`) — send a real test vulnerability-alert email (fabricated demo CVE data) to an operator-supplied address, styled with the current Plugin Notification Email branding.
