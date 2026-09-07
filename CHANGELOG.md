# Changelog

All notable changes to Clockwork Control will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
