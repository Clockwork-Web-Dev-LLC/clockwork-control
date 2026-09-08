# Changelog

All notable changes to Clockwork Control will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.3.0] - 2026-09-08

### Added
- **ManageWP-Style Site Command Center Overview**: Reimagined the single-site Overview tab into a comprehensive 3-column command center widget grid displaying Updates, Uptime, Performance, Backups, Traffic Analytics, Notes, Security & Integrity (with SSL health), SEO Health, and Form Activity.
- **Draggable & Reorderable Dashboard Cards**: Operators can customize their Command Center layout on a per-site basis via intuitive HTML5 drag-and-drop with optimistic updates, persistence to database, DOM rollback on error, and a one-click reset to default layout.
- **Visual Fleet Grid View & Automated Screenshots**: Added a toggleable card grid view to the fleet-wide `/sites` directory featuring 16:10 website preview thumbnails, health accent strips, status badges, and client-side live search. Powered by an automated background screenshot capture engine using Automattic mShots, caching to public storage on site creation and scheduled daily.
- **Compact Site Settings Redesign**: Overhauled `/sites/{site}?tab=settings` from stacked, full-width forms into clean 3-column card modules matching the Command Center aesthetic.
- **Per-Site Notes**: Added a dedicated scratchpad/notes module per site for operator documentation, internal credentials references, and staging notes.
- **Client Reports Templates & Scheduling**: Pre-built client report templates, schedule frequency configuration, and automated report generation pipeline.

### Fixed
- **Uptime calculation**: `computeUptimePercentage()` delegates to canonical `UptimeStatsCalculator` to accurately track downtime intervals across paired events and active outages instead of defaulting to a flat 300s.
- **Updates widget fallbacks**: Displays accurate plugin and theme update counts from Companion snapshots when available, and gracefully falls back to hosting provider update flags (`wp_plugin_updates`, `wp_theme_updates`) when Companion is not snapshotted.
- **Layout persistence error handling**: Catches non-2xx responses (including 419 CSRF expiry and validation errors), rolls back DOM card order, and surfaces a clear error toast.
- **Automattic mShots placeholder filtering**: Detects and rejects mShots "still generating" placeholder images and redirects to prevent caching blank/placeholder previews.
- **Concurrent screenshot captures**: Scheduled screenshot capture command now dispatches background queue jobs across workers rather than executing blocking sequential HTTP requests.

## [1.2.3] - 2026-09-07

### Added
- **Local email/password authentication**, always available alongside the existing modular OAuth providers (Google, GitHub, Microsoft). Google OAuth was previously a mandatory blocker in the web installer — an operator without a Google Cloud Console project, OAuth Consent Screen, and redirect URI already configured couldn't get past Step 5, and `/login` had no fallback at all if no OAuth provider was configured, leaving them completely locked out of the app they'd just installed. The installer's Google step now has a "Skip for now" option, and the admin-account step only requires a password when Google was actually skipped; `/login` always shows the local sign-in form, with OAuth buttons appended below a divider only when at least one provider is actually configured.
- `php artisan clockwork:set-password <email>` — CLI password recovery/reset (masked prompt or `--password=`), and `clockwork:add-user` gains a matching `--password=` option.
- `/settings/users` can set an initial password when adding a teammate, and reset any existing operator's password via a modal.

## [1.2.2] - 2026-09-07

### Fixed
- **Sites page and dashboard no longer show hosting panels you don't use.** The `/sites` provider tabs, filter, and counts, and the main dashboard's fleet-wide refresh button, hardcoded SpinupWP/Pressable as the only possible options — an operator running e.g. GridPane + Vultr saw tabs and refresh actions for panels they'd never enabled. Both now derive from whichever hosting-provider modules are actually enabled, and work with any of the 6 hosting-provider modules (SpinupWP, Pressable, WP Engine, Kinsta, Cloudways, GridPane) without hardcoding a specific set.
- Fixed the server detail page always showing "Refresh from SpinupWP" and posting to the SpinupWP import even on a GridPane-managed server; it now shows whichever panel actually owns that server's fleet inventory.
- Fixed SSH test failures (single-server, bulk, and paste-and-import credential updates) always flashing with success/green styling regardless of outcome — failures now flash as an error.
- Fixed a stale `CLOCKWORK_VERSION` left in a real `.env` file silently overriding the actual installed version shown on `/settings/updates`, even after a successful update. The installed version now always comes from the code itself, never from `.env`.
- Fixed self-update failing under `php artisan serve` (which strips `HOME`/`COMPOSER_HOME` from its subprocesses), leaving `composer install` with no home directory to write its cache/config to.
- Fixed a pre-existing test (`SetupControllerTest`) that asserted against a string that never appears on the page, silently masking a real gap in Step 2's service-enablement filtering.

## [1.2.1] - 2026-09-07

### Fixed
- Fixed a redirect loop trapping fresh installs at `/setup`: completing setup only ever saved credentials, never actually imported a fleet, so an operator with zero servers/sites would get bounced straight back to `/setup` by the dashboard's fresh-install gate the instant setup finished. Setup now runs the relevant fleet-source import (SpinupWP, Pressable, GridPane) automatically before redirecting, and falls back to the manual "Add a server" page instead of the gate if the fleet is still empty afterward.

## [1.2.0] - 2026-09-07

### Added
- **Comment Moderation module** (`modules/CommentModeration`): browse, filter, and moderate WordPress comments (approve, hold, spam, trash, delete) via the Companion plugin, plus a weekly scheduled bulk cleanup of old spam/trash.
- **Code Snippets module** (`modules/CodeSnippets`): sandboxed PHP execution workbench with preset and custom snippets, runnable across one or more sites at once.
- **Site Maintenance module** (`modules/SiteMaintenance`): toggle WordPress maintenance mode on/off without SSH access, with a custom headline and message.
- **Client Management module** (`modules/ClientManagement`): a client directory with per-client site assignment, feeding the existing Client Reports module.
- **Client Reports module** (`modules/ClientReports`): automated executive client reporting — updates, uptime, security, backups, performance, and forms into white-labeled reports.
- **Settings Hub** (`/settings`): a centralized 4-quadrant operations overview (Configuration, Integrations, Operations, System) with live tool search, plus a unified secondary tab navigation across settings pages.
- **Ignore/suppress SEO indexability alerts** (`/issues`): operators can mark a known-intentional noindex (an internal intranet, a volunteer portal, etc.) as reviewed instead of it permanently sitting in the active issues list, with an Active/Ignored tab toggle and a reason-capturing modal.
- Real-time search filtering on the Module Directory page.
- **Remove-server action surfaced automatically**: when a poll confirms a server's provider_id no longer exists at its cloud provider (DigitalOcean, Hetzner, etc.), the server's detail page now shows a banner with a one-click "Remove from Clockwork" action (still typed-name-confirmed and cascade-deleting), regardless of which tab is open. Previously this required knowing the Settings tab had a delete button at all. Self-clears if the next poll succeeds, so a transient API hiccup never falsely flags a live server.

### Changed
- **Anonymous usage telemetry is now on by default** (previously opt-in/off by default), still a one-click opt-out in Settings or via `CLOCKWORK_TELEMETRY_ENABLED=false`. The payload now sends exact site and server counts (previously bucketed only — buckets are retained alongside the exact counts for backwards compatibility) plus a per-module breakdown of servers and sites. Never domains, IPs, emails, or database contents.
- Maintenance page redesigned with roll-up stat tiles, a per-module server/site breakdown table, and a copyable JSON payload inspector; database size now displays in GB above 1,024 MB.
- Card background lightened from pure white to `#f9f9f9` in light mode (dark mode unaffected).
- Removed the "Expand / Collapse all" bulk toggle from the docs sidebar.
- Client Reports navigation icon standardized to `fa-solid fa-file-lines`.
- **Main navigation decluttered**: Code Snippets and Clients moved from top-level nav pills into the gear/Settings menu (both now contribute via the same module-nav-item mechanism Client Reports already used).

### Fixed
- Increased the PHP execution timeout for the security-scan endpoint.
- Restored six `ClockworkCompanionClient` methods (`comments()`, `moderateComments()`, `cleanupComments()`, `maintenanceMode()`, `setMaintenanceMode()`, `executeCodeSnippet()`) that were dropped during the modularization refactor above, which had left Comment Moderation, Code Snippets, and Site Maintenance non-functional.
- Fixed missing Composer autoload registration for the four new modules — their classes could not be loaded outside of static analysis.
- Fixed a route-name collision at `/settings/modules` that made the pre-existing Module Directory page unreachable; module enable/disable now correctly surfaces all bundled modules at `/setup/modules`.

## [1.1.0] - 2026-09-05

### Added
- **Web-based installer (`/install`)**: guided setup wizard covering the pre-auth bootstrap gap — live database connection test, `APP_KEY`/app-identity generation, mail and Google OAuth setup, first-admin-user provisioning, and optional hosting-provider quick-connect — replacing manual CLI-only setup steps.
- **Theming system**: Light/Dark/Midnight/High Contrast color schemes via a `[data-theme]` attribute and CSS custom-property overrides, persisted per-user with a FOUC-prevention script, plus ECharts chart theming.
- **Backup relay, generalized**: new `HostingProvider::CAP_BACKUP_RELAY` capability + adapter contract so backup archival is no longer Pressable-only; a default in-repo queued-job archival mode (`modules/BackupRelay`) needs no external infrastructure, with a schema-versioned bridge keeping the existing external-agent droplet working unmodified.
- **Release process**: `CHANGELOG.md`, `RELEASING.md`, and a tag-triggered GitHub Actions release workflow, wiring into the previously-unused self-update system (`SystemUpdateService` / `/settings/updates`).
- **Limit Login Attempts Reloaded (LLAR) Module (`modules/Llar`)**: Extracted LLAR into a dedicated security module with automated wp-cli provisioning, lockout log ingestion, and runtime module state gating.
- **Companion White-Labeling & Branding (`/settings/companion`)**: Custom agency plugin name, description, author name, URL, and brand logo upload (`/storage/companion/logo.png`).
- **Companion Live Interactive Preview (`/settings/companion/preview`)**: Full-screen modal and dual-tab preview simulating WordPress wp-admin plugin cards and backup admin screens in real-time.
- **Conditional Traffic Report Gating**: Companion automatically hides traffic metrics when SSH or traffic stats are unavailable on hosting providers like SpinupWP.
- **Self-Update Center (`/settings/updates`)**: Operator-triggered updater pipeline with preflight checks (`git status`), `git pull`, migrations, and cache clearing.
- **Provider API Tunables**: Configurable base URLs, timeouts, and view-only flags across cloud providers and hosting integrations.
- **Instant Live Search**: Real-time filtering across sites, fail2ban review queues, active bans, and contact forms.

### Changed
- **Fleet WordPress Plugins Redesign (`/settings/wordpress-plugins`)**:
  - Removed outdated Core, Themes, and Plugins update columns and metric tiles to focus strictly on agent deployment.
  - Elevated the Clockwork Companion plugin with prominent status badges (`Installed vX.Y.Z` vs `Missing`) and dedicated install CTAs.
  - Added quick filter tabs (`All Sites`, `Missing Companion`, `Companion Installed`, `Missing LLAR`).
  - Added real-time domain and server search filter.
  - Scoped query to `Site::hostMonitored()` to audit all monitored fleet sites including managed hosts.
- **Main Navigation Menu**: Removed Docs pill from top navigation bar; documentation remains accessible in the Settings (gear) menu under "Help & Community" and in the footer.
- **Weird Stats page**: removed the hour-of-day attack histogram panel.
- **Docs viewer**: sidebar, search, and rendering improvements (`DocsController`/`DocsManifest`/`MarkdownRenderer`).

### Fixed
- Staging-tagged servers can now queue OS-level system updates — previously blocked entirely, sitting "queued" forever since the drainer never processed staging-tagged servers.
- Fixed a test-suite-polluting `putenv()` leak in the installer's env writer (`APP_ENV` wasn't on the test-protection allowlist) that broke CSRF handling for unrelated tests when run in the same process.
- Fixed the backup-relay default mode (`external_agent`) contradicting its own documentation, which already claimed `in_repo` was default.
- Fixed missing server-side re-verification on `/install/unlock` (could permanently seal the installer against an unconfigured app), an exception-message leak on `/install/*` routes, and a silently-swallowed migration failure during install.
- Fixed broken CSS variable references (`--color-primary-300/-50/-950`, none defined) and leftover non-theme-aware `dark:` classes in the WordPress plugins view.
- Fixed double-escaped ampersand in Companion settings header (`&amp;` -> `&`).
- Fixed PHPStan type assertions in `SitesController::pushCompanionData`.
- Fixed code style formatting across provider clients.

### Security
- Documented physical/host security posture (desktop vs. laptop, public-IP exposure) and cross-provider SSH credential scope in the security model — see [Architecture → Security model](resources/docs/architecture/security-model.md).

## [1.0.0] - Baseline (never formally tagged)

This is the original feature set the app shipped with before formal release tracking (this
CHANGELOG, tagged GitHub Releases) began. `CLOCKWORK_VERSION`/`config/clockwork.php` have defaulted
to `1.0.0` since the fresh-history migration, but no `v1.0.0` git tag or GitHub Release was ever
actually cut. Per [RELEASING.md](./RELEASING.md), the first real tagged release should be `1.1.0`
(or later) once the `Unreleased` batch above is ready to ship — not `1.0.0`, since that would claim
a release happened on a date it didn't.

### Added
- Initial self-hosted release of Clockwork Control.
- Fleet health monitoring, uptime tracking, and SSL certificate management.
- Multi-provider integrations: DigitalOcean, Hetzner, Vultr, Linode, Azure, SpinupWP, Pressable, Cloudways, GridPane, WPEngine, Kinsta.
- Security scan suite: Sucuri SiteCheck and core checksum verification.
- Contact form synthetic deliverability monitoring and alerting.
- Notifications dispatch to Slack, Mattermost, and Twilio SMS.
