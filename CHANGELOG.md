# Changelog

All notable changes to Clockwork Control will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
