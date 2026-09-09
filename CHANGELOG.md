# Changelog

All notable changes to Clockwork Control will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.5.4] - 2026-09-09

### Added
- **Admin vs. Operator role authorization**: Introduced `users.role` (`admin`, `operator`). Operators can run the fleet (servers, sites, SSH, Companion, fail2ban), while allowlist management, panel self-updates, database backup downloads, and Code Snippets mutations/execution are strictly restricted to administrators.
- **SSH metrics for Vultr servers**: Added single-shot SSH metrics collection (`vmstat`, `free`, `df`, `/proc/loadavg`) to `VultrCloudProvider::metrics()`. Vultr servers now report real-time health, live CPU, memory, and disk stats, and 24h sparklines on the fleet dashboard.
- **Configurable SSRF private host bypass**: Added `CLOCKWORK_ALLOW_PRIVATE_HOSTS` (`clockwork.security.allow_private_hosts`) to allow intranet, homelab, or local staging instances to probe private IP ranges.
- **SpinupWP sync button on empty server views**: When viewing a server with no sites mapped to it, an inline "Sync sites from SpinupWP" button now surfaces directly inside the empty state.

### Changed
- **Hourly SpinupWP inventory sync**: Increased SpinupWP import schedule from once daily (`03:30`) to hourly (`hourlyAt(30)`), followed by `clockwork:find-orphan-sites` at `:35`. New servers, newly provisioned sites, and site moves between servers are now automatically detected throughout the day rather than waiting up to 24 hours.
- **Immediate session invalidation on revoke and password change**: `User::invalidateSessions()` cycles `remember_token` and deletes database session records. `EnsureUserIsActive` middleware terminates revoked users on their next web request.
- **OAuth security hardening**: Google OAuth now enforces the `hd` (hosted domain) claim on callback. Microsoft Entra ID now requires a pinned tenant ID in production (rejecting `common`/`organizations`/`consumers`). Added `throttle:10,1` on OAuth redirect/callback routes.
- **Dev-login loopback protection**: `/dev-login` rejects reverse-proxy forwarded headers (`X-Forwarded-For`, `X-Forwarded-Host`) and verifies direct connection IPs.

### Fixed
- **GridPane `wp-config.php` path resolution**: Probes both the docroot and parent directory, resolving DB credentials extraction for GridPane sites storing `wp-config.php` above `htdocs/`. (Contributed by [@karenalenore](https://github.com/karenalenore) in [#3](https://github.com/Clockwork-Web-Dev-LLC/clockwork-control/pull/3)).
- **Installer exception disclosure**: Database connection and migration errors now log full exception details internally while displaying sanitized error messages in the wizard.
- **Companion branding logo upload**: Blocked SVG uploads to prevent stored script execution on the public disk.
- **Last-admin self-demotion guard**: Prevented administrators from accidentally demoting their own account or the sole active administrator to operator.

## [1.5.3] - 2026-09-08

### Added
- **OAuth 2.0 redirect URI helper**: Added Authorized Redirect URI display box with 1-click clipboard copy (`/auth/{provider}/callback`) to Google, GitHub, and Microsoft integration modals and settings pages.
- **Context-aware integration settings & navigation**: Settings buttons on `/settings/integrations` and modal headers on `/setup` now display tailored labels and icons based on real service type (`API Limits & Docs`, `OAuth Setup & Keys`, `Webhook Settings`).

### Changed
- **Audit module rate limits and integration types**: Classified all 24 registered services into real architectural types (APIs, webhooks, OAuth SSO). Stripped fake vendor rate limits (60–100 req/min), fake quota reset headers (`X-RateLimit-Limit`), and fleet polling projections from outbound webhooks (`slack`, `mattermost`, `client_slack`) and OAuth SSO providers (`auth_google`, `auth_github`, `auth_microsoft`).
- **Contextual credentials presentation**: Modals and settings cards for public scanner services without credentials (such as Sucuri SiteCheck) now display "Service Access & Authentication — Zero credentials required • Public access" instead of a confusing empty `.env` credentials box.

### Fixed
- **Contact Form Testing integration modal**: Removed `contact-forms` from `ServiceRateLimitRegistry` so this first-party synthetic test runner no longer renders an erroneous gear button, empty API credentials card, or operator pacing sliders on `/setup`.
- **Setup checklist Backup Relay auto-detection**: Fixed Backup Relay setup checklist card to auto-detect S3 bucket configuration and sites in use on `/setup`.
- **Module navigation bar and resolver memoization**: Fixed disabled module items appearing in the global navigation bar and resolved premature `ModuleStateResolver` memoization issues.

## [1.5.2] - 2026-09-08

### Added
- **Vultr cloud instance discovery & sync**: Added real-time discovery of cloud VPS instances via Vultr API v2 on `/settings/integrations/vultr/limits` and Setup Checklist modal. Displays plan, specs (vCPUs, RAM, disk, region, tags), and real-time IP linking to local servers. Includes hosting architecture guide and one-click actions: "Sync from SpinupWP/GridPane", "Import as Standalone Server", and "Reconcile Hardware Specs".

### Changed
- **Promoted GridPane and Vultr to Verified in Production**: Marked both modules as `STATUS_VERIFIED` in their service providers, removing the yellow beaker icon and "Looking for testers" banner across the control panel and website.

### Fixed
- Fixed `clockwork:import-gridpane` silently failing to pull a complete fleet on accounts with many paginated pages of sites/servers: the pagination loop slept a hardcoded 150ms between pages regardless of the operator's configured `services.gridpane.delay_ms`, well above GridPane's documented 1-2 requests/second limit, and a single page failing after retries discarded every page already fetched (the whole import aborted with nothing saved). Paging now honors the configured delay via the same per-request gate used elsewhere, and a page that ultimately fails after already accumulating results now returns what was fetched with a console warning instead of throwing everything away — re-running the (idempotent) import picks up the rest. (Contributed by [@karenalenore](https://github.com/karenalenore) in [#2](https://github.com/Clockwork-Web-Dev-LLC/clockwork-control/pull/2))
- Confirmed against a real fleet that GridPane throttles considerably tighter than its own docs claim: even a 600ms delay with 2 retries still hit `429 Beep, Beep, you're going too fast...` mid-pagination. Default delay bumped to 1500ms and default retries to 3 (both still operator-overridable via `services.gridpane.delay_ms`/`retry_attempts`). (Contributed by [@karenalenore](https://github.com/karenalenore) in [#2](https://github.com/Clockwork-Web-Dev-LLC/clockwork-control/pull/2))
- Root-caused the remaining truncation: GridPane enforces a separate, tighter per-endpoint rate budget (`X-RateLimit-Endpoint-Limit`) on top of its account-wide one — a `/site` listing page costs 2 units against a budget of 12, so only ~6 pages clear before a 429 regardless of delay/retry tuning. On a 429 it reports the real cooldown via `Retry-After-Endpoint` (observed ~27s) and `X-RateLimit-Endpoint-Reset`, not the generic `Retry-After` header our backoff was checking (which GridPane never sends) — so every retry fell through to a 5s fallback that wasn't remotely long enough and kept retrying into the same still-exhausted window. Backoff now reads the correct header (falling back to the reset timestamp, then a 5s last resort). Verified against the real fleet: a full import now completes in one run with no partial warning (132 sites, up from the 30 the old backoff was stuck at). (Contributed by [@karenalenore](https://github.com/karenalenore) in [#2](https://github.com/Clockwork-Web-Dev-LLC/clockwork-control/pull/2))

## [1.5.1] - 2026-09-08

### Fixed
- Restored a "Revoked" count tile to the `/settings/users` roll-up stats — it was swapped out for a "Password Ready" tile when local email/password auth shipped in v1.2.3, leaving revoked status visible only per-row. Added back as a 5th tile alongside the others rather than displacing anything.

## [1.5.0] - 2026-09-08

### Added
- **Backup relay offsite archive browsing**: `/settings/backup-relay` site rows are now expandable, showing the actual S3 Glacier snapshots for that site (type, size, archived-at, direct download link) instead of just a last-run timestamp. Backed by a new `BackupArchiveEnumerator` service and two new routes (`settings.backup-relay.archives`, `settings.backup-relay.download`).
- Both Companion backup-report push commands (`clockwork:pressable-backups-report`, `clockwork:push-companion-backups`) now attach real presigned S3 download links to the offsite-archive block on a site's client-facing wp-admin backups page, when no external-agent manifest entry already covers it.

### Fixed
- Offsite archive listings were showing every S3 object as **0 B** with no timestamp, and occasionally failing with `AccessDenied`: the code was calling `$disk->size()`/`$disk->lastModified()` per object (each a `HeadObject` call needing `s3:GetObject`, which the monitoring IAM user doesn't have). Switched to reading size/timestamp directly off the `ListObjectsV2` listing already fetched for enumeration, which needs only `s3:ListBucket`.
- Offsite archive links pushed to a client's Companion wp-admin page could silently fall back to an operator-only Clockwork Control login route if the disk wasn't S3-backed, handing the client a dead end. That enrichment is now skipped entirely (rather than emitting a broken link) when a real presigned S3 URL can't be minted.
- `offsite_archive.download_expires_at` in the Companion payload was hardcoded to `null` in this new fallback path — now reflects the real ~24h presigned-URL expiry.
- Cleared all 32 pages the docs staleness checker had flagged, correcting content that had drifted out of date (the White Labeling hub, the settings-nav reorder, local email/password auth, the new backup relay archive browsing, and several smaller undocumented additions found along the way: the visual fleet grid + automated site screenshots, the "provider missing" removal banner, and telemetry now being on by default) and re-verifying the rest before bumping their `updated:` date.

## [1.4.0] - 2026-09-08

### Added
- **White Labeling hub** (`/settings/companion`, renamed from "Companion" in navigation and page headers): consolidates all client-facing branding into one 3-tab hub — WordPress Companion mu-plugin branding (agency identity, plugin metadata, wp-admin menu, logo, live preview), Client Reports branding (brand color, accent strip color, 5 palette presets, custom SLA/footer text, live report preview), and Plugin Notification Email branding (header/accent colors, badge text, custom care-plan note, logo toggle, test-email dispatch, live email preview).
- **Persistent two-tier settings navigation**: the 5-pillar top-level menu (Overview, Fleet & Branding, Integrations & Alerts, Operations & Tools, System & Workspace) now stays visible across every settings and operations page — previously it disappeared the moment you drilled into a specific tool. A contextual "tools" ribbon renders below it for the active pillar.
- **7-day fleet uptime figure** on `/monitoring`, alongside the existing 30-day average.

### Changed
- Removed Midnight theme mode in favor of a 4-option grid (Light, Dark, High Contrast, Auto); existing Midnight cookies/preferences fall back to Dark automatically.
- Fixed dark-mode bleed: an explicit Light selection could still pick up `dark:` Tailwind classes from the OS's `prefers-color-scheme`, now scoped strictly to the app's own theme state.
- Settings navigation now sits above each page's header instead of below it, removing layout jump as header height varies between pages; the contextual tools ribbon is now a contained, elevated pill bar matching the rest of the settings hub's styling.
- Module directory and integrations pages decluttered — capability badges moved from the directory cards to each integration's Configure page.

### Fixed
- Reports brand palette colors (primary/accent) were saved by the new White Labeling settings but never rendered anywhere in the generated report — fixed, with a regression test.
- `/capacity`, `/maintenance-history`, `/operations/server-updates`, and the bulk SSH credentials page were missing the settings navigation entirely, despite already being wired into it as "Operations & Tools" destinations.
- CI was failing on two installer-wizard tests that require a real MySQL server, which the CI runner doesn't provide — excluded from CI (they still run normally against a local MySQL server), matching the existing pattern for the one test that needs live Twilio API access.

## [1.3.1] - 2026-09-08

### Fixed
- **Orphan sites falsely flagged for GridPane, Cloudways, and other non-SpinupWP-managed sites.** Orphan detection (`/issues`, the fleet issue-count badge, and `clockwork:find-orphan-sites`) queried `spinupwp_id IS NULL` without scoping to SpinupWP-managed sites, so every site on a different provider — which naturally never has a `spinupwp_id` — was flagged as a SpinupWP site whose linkage was lost. Orphan detection is now scoped strictly to `hosting_provider = spinupwp`, and gracefully skips the SpinupWP API lookup entirely (instead of crashing) when no SpinupWP token is configured.
- **"Recheck SSL" crashed with a 422 on any non-SpinupWP site.** The button unconditionally called the SpinupWP-only cert refresher; it now uses a live TLS probe (the same one the scheduled SSL checker already uses) for hosting providers with no per-site cert API.
- **Companion "Push update" showed a confusing "site has no SpinupWP id" error on non-SpinupWP sites.** The backups leg now reports a clean skip instead of an error for hosts that don't use SpinupWP backup reporting.
- **Daily apt-update polling silently skipped every non-SpinupWP server for up to a week.** `upgrade_required` is only ever set by the SpinupWP import mirror, so GridPane/Hetzner/custom-VPS servers never tripped the daily poll and were only checked by the weekly full-fleet sweep. The daily run now always includes non-SpinupWP-managed servers too.
- **Adding a server manually always attempted a SpinupWP refresh**, even on fleets with no SpinupWP account, wasting a request and showing a misleading flash message. It now only runs when SpinupWP is actually configured, and still polls the new server immediately either way so its status classifies without delay.
- **WordPress core-checksum verification and plugin detection guessed the wrong on-disk path on non-SpinupWP servers.** Both fell back to SpinupWP's `/sites/{domain}/files` convention whenever `wp_path` wasn't recorded. Path resolution is now provider-aware (SpinupWP, GridPane conventions) and skips cleanly — rather than guessing wrong — for a provider with no known layout.
- **`hosting_provider` silently defaulted to `spinupwp`** for any site created without specifying it, which is exactly what fed the orphan false-positive bug above. Every site-creation path now sets it explicitly, and the column no longer has a default — a future path that forgets it fails loudly instead of silently mislabeling the site.
- Updated empty-state and description copy across the dashboard, issues page, WordPress plugins settings, and server Updates tab that assumed SpinupWP was the only hosting provider.

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
