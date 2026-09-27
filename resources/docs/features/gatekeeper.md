---
title: Gatekeeper (Native Login Protection)
section: Features
order: 22
updated: 2026-09-27
author: Aaron Reimann
tags: [gatekeeper, brute-force, security, login-protection, companion, fail2ban, pressable, modules]
tracks: [modules/Gatekeeper/**, app/Services/Gatekeeper/**, app/Console/Commands/PushGatekeeperSettings.php, app/Console/Commands/GatekeeperRollout.php, app/Console/Commands/PullLlarLockouts.php, app/Http/Controllers/GatekeeperSettingsController.php, resources/views/settings/gatekeeper.blade.php, resources/views/dashboard/site/tab-settings.blade.php]
---

# Gatekeeper (Native Login Protection)

## Overview & Why We Moved Away From LLAR

**Gatekeeper** is Clockwork's native WordPress login lockout and brute-force throttling engine built directly into **Clockwork Companion** (v1.39.1+) and **Clockwork Renegade**. It completely replaces third-party plugins like Limit Login Attempts Reloaded (LLAR) with a silent, headless architecture that protects our clients without compromising their wp-admin experience.

### The Problem With LLAR (Ads, Upsells & Scare Tactics)
For years, Limit Login Attempts Reloaded served as our application-layer login throttler, but upstream changes made it increasingly unsuitable for an agency care plan:
- **Intrusive Upsells & Ads:** LLAR constantly promotes paid cloud upgrades, Micro Cloud sync, premium CAPTCHA integrations, and third-party partner ads directly in the WordPress dashboard.
- **Client Panic & "Scare Tactics":** LLAR displays prominent dashboard widgets and admin notices filled with alarming graphs showing thousands of "failed attack attempts." Non-technical clients regularly see these numbers and panic, assuming their website is currently being hacked or compromised when it's simply normal background bot noise.
- **Support Burden:** These scare tactics generate unnecessary client support tickets and anxiety that agency staff must spend time de-escalating.
- **Pressable Blind Spots:** LLAR direct-DB polling requires SSH and MySQL credentials. On managed platforms like Pressable, LLAR ran as an isolated black box with no visibility in Clockwork Control's review queue.

Gatekeeper solves all of this: it is **100% silent, headless, and agency white-labeled**. It has zero ads, zero commercial upsells, and zero client-facing panic widgets.

## Modular Architecture: Optional, Not Forced

Gatekeeper is implemented as a first-class modular package in `modules/Gatekeeper/`. It is **optional, not forced**:

- **Module Directory Control (`/settings/modules`)**: Agency owners can enable or disable the Gatekeeper module. When disabled, Gatekeeper settings tabs, fleet push commands, and rollout tasks cleanly stand down without impacting other features.
- **Opt-In by Default**: Fleet-wide default `enabled` state is `false`. Gatekeeper is never forced fleet-wide unless an agency explicitly chooses to turn on the fleet default at `/settings/gatekeeper`.
- **Per-Site Granular Overrides**: Each site can independently inherit the fleet setting, force enable Gatekeeper, or force disable Gatekeeper.
- **Third-Party SSO Compatibility**: Sites that use custom enterprise SSO (e.g. miniOrange SAML/OAuth, Google Workspace SAML, or Cloudflare Access) can safely disable Gatekeeper locally via `gatekeeper_settings.enabled = false` or the wp-config rescue constant without interfering with authentication flows.

---

## Technical Capabilities

### 1. High-Performance Authentication Interceptor
- Hooks WordPress `authenticate` at **priority 5** (before WordPress's default `wp_authenticate_username_password` at priority 20).
- Locked IPs exit immediately with HTTP 429 (or XML-RPC fault / JSON 429) **before** expensive bcrypt password hashing runs, protecting PHP and CPU resources during attacks.
- Exempts internal HMAC Clockwork REST routes (`/wp-json/clockwork/` and `/wp-json/clockwork-renegade/`) so Control and the Unlock Hub are never locked out.
- Local loopback, RFC1918 private IPs, and link-local ranges are bypassed locally.

### 3. Pressable Visibility
- Previously, `clockwork:pull-llar-lockouts` required an SSH host and direct MySQL database credentials, leaving Pressable sites blind to the Control review queue.
- Gatekeeper serves active lockouts via signed HMAC REST (`GET /wp-json/clockwork/v1/lockouts`), enabling Control to ingest and monitor lockouts from Pressable sites without SSH or database passwords.

### 4. Cache & Database Dual-Storage
- **Persistent Object Cache (`wp_using_ext_object_cache()`)**: Hot attempt counters use atomic cache increments (`gatekeeper_attempts:{ip}`). Stage 1 lockout verification runs cache-only (`gatekeeper_locked:{ip}`) without hitting the MySQL database.
- **No Object Cache**: Attempts and windows are recorded directly on the `{$wpdb->prefix}clockwork_lockouts` table row with atomic increment queries.
- Failed attempts against an already-locked IP do not increment `consecutive_lockouts` (REST/XML-RPC floods cannot skip the 20-minute window straight to 24 hours).
- Attempt counters use a fixed window TTL; the first cache write always sets expiry so Redis `INCR` cannot create a key that never expires.
- `unlock_at` is stored and compared as UTC (`gmdate` / PHP-bound UTC datetimes), not MySQL `NOW()`.
- Stale rows with no consecutive history are pruned hourly (and lazily on writes) after 48 hours.

---

## Policy Defaults & Progressive Backoff

| Key | Default Value | Description |
| :--- | :--- | :--- |
| `enabled` | `false` (Default Off) | Requires Control or site toggle to enable enforcement |
| `threshold` | `4` attempts | Failed attempts allowed before triggering a lockout |
| `window_seconds` | `1200`s (20 minutes) | Window during which failures accumulate |
| `lockout_seconds` | `1200`s (20 minutes) | Duration of standard lockout |
| `consecutive_lockouts_for_extended` | `4` lockouts | Consecutive lockouts triggering extended ban |
| `extended_lockout_seconds` | `86400`s (24 hours) | Duration of extended ban |
| `headline` | `'Too many failed login attempts'` | Top heading displayed on the 429 lockout card |
| `body` | `'Please wait {duration} before trying again.'` | Custom explanation text. Supports `{duration}` (e.g. "20 minutes") and `{ip}` placeholders |
| `support_label` | `'IT Helpdesk'` | Label for support contact button/link |
| `support_email` | `''` (Optional) | Support email address (renders `mailto:` action button) |
| `support_url` | `''` (Optional) | Custom helpdesk or ticketing URL (e.g. `https://helpdesk.agency.com`) |
| `show_ip` | `true` | Displays visitor IP with 1-click clipboard copy badge |
| `show_unlock_link` | `true` | Shows Unlock Hub link when an agency unlock URL is set |
| `unlock_url` | `'https://clockworkwd.com/wp-admin/admin.php?page=clockwork-unlock'` | Agency unlock console URL |


### Emergency Rescue Hatch
In the event of an unexpected site lockout, add the following constant to `wp-config.php`:
```php
define('CLOCKWORK_GATEKEEPER_DISABLE', true);
```
This immediately disables Gatekeeper gate checks across all login pathways without modifying database options or deactivating Companion.

---

## Fleet & Site Management in Control

1. **Fleet Policies (`/settings/gatekeeper`)**:
   - Configure global fleet defaults for thresholds, durations, headline/body copy, and support contact details.
   - Automatically merges Cloudflare edge proxy ranges and fleet server public IPs into `ignore_cidrs` and `ignore_ips`.
   - On-demand sync via **Push to Fleet** button or automated nightly catch-up via `clockwork:push-gatekeeper-settings` at 06:40.

2. **Per-Site Settings Tab (`/sites/{site}/settings`)**:
   - Individual site card allows overriding the policy state (Inherit, Force Enable, Force Disable), custom failure thresholds, and site-specific support copy (e.g. for dedicated client helpdesks).
   - Empty fields automatically inherit fleet defaults.
   - On-demand **Push to site** action pushes effective policy settings to WordPress over HMAC REST immediately.

3. **Unlock Hub Integration**:
   - `DELETE /wp-json/clockwork/v1/lockouts` unlocks IPs from both native `clockwork_lockouts` and legacy LLAR options/tables simultaneously, ensuring zero disruption to existing unlock workflows.

---

## Migration from LLAR to Gatekeeper (Complete)

`clockwork:gatekeeper-rollout` drove the cutover across the fleet. It targets sites with no Companion and Companion sites on LLAR (`--scope=all|no-companion|llar`), executing a four-step gated sequence:

1. **Companion present and capable**: install or upgrade Companion if missing or outdated, then verify `gatekeeper` capability via live `/health` probe.
2. **Enable Gatekeeper**: persist `gatekeeper_settings.enabled = true` as a **per-site override** and push to WordPress. The override is critical: `clockwork:push-gatekeeper-settings` rebuilds every payload from fleet default + overrides at 06:40, so a push without the override would be reverted the next morning. The push also trips Companion's internal `maybeDeactivateLegacyLlar()`.
3. **Remove LLAR**: deactivate (network-wide first, then single-site) and delete over Companion REST. `CompanionProtectedPlugins::guardDestructive()` exempts LLAR only when `Site::gatekeeperEnabled()` is true, ensuring LLAR is never removed unless Gatekeeper is confirmed enforcing.
4. **Bookkeeping**: flip `llar_enabled = false` and record an `llar_retired` action log entry.

**Fleet Rollout Outcome**: 88 out of 88 targeted sites were cleanly migrated with zero deactivation or push failures. LLAR has been retired across active sites in the fleet. Single-site rollouts can be executed anytime using `--site=<id|domain>`.

### What "protected" means

`Site::gatekeeperEnabled()` / the `gatekeeperProtected()` scope is the login-protection test alongside `llar_enabled` (legacy) and `wordfence_enabled`: Companion installed, `gatekeeper` capability advertised, and either a per-site `enabled: true` override or the fleet default. Weird Stats' coverage matrix, "Unprotected sites", the WordPress Plugins page tiles and filters, and `clockwork:install-llar` all use it — so a Gatekeeper site with no LLAR reads as protected, not as a gap to re-fill.

## fail2ban & Review Queue Integration

Gatekeeper integrates directly into Clockwork Control's threat pipeline:
- **Pull Ingest (`clockwork:pull-lockouts` / `clockwork:pull-llar-lockouts`)**: Polling Companion's signed HMAC REST endpoint `GET /wp-json/clockwork/v1/lockouts` pulls all active Gatekeeper lockouts.
- **SpinupWP VPS Servers**: If `auto_ban_llar` is enabled on the server record, pulled lockouts immediately trigger `fail2ban-client set clockwork banip <ip>` over SSH and create a `BlockedIp` record (`decided_by: llar-auto` or `gatekeeper-auto`). If auto-ban is disabled, lockouts are placed into the **Review Queue** (`/bans/queue`) for human review.
- **Pressable Managed Hosting**: Because Pressable is serverless and has no host SSH/fail2ban jail, Gatekeeper acts as the primary defense line by enforcing HTTP 429 application blocks directly, while streaming lockout telemetry into Control for fleet-wide repeat offender tracking.

