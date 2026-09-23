# Gatekeeper (Native Login Lockouts)

## Overview

**Gatekeeper** is Clockwork's native WordPress login lockout and brute-force throttling engine built directly into **Clockwork Companion** and **Clockwork Renegade**. It replaces third-party plugins like Limit Login Attempts Reloaded (LLAR) with a silent, headless architecture that eliminates client-facing attack counters, dashboard widgets, and upgrade upsells.

Gatekeeper integrates directly with **Clockwork Control** and the **Unlock Hub** while extending login lockout visibility to managed hosting environments like **Pressable** for the first time.

> [!NOTE]
> Gatekeeper provides application-layer brute-force mitigation in PHP. It **does not replace** Nginx `limit_req` or `fail2ban` host firewalls on SpinupWP VPS servers; both layers work in concert.

---

## Architectural Highlights

### 1. Silent & Client-Friendly
- **No wp-admin dashboard widgets**: Clients never see panic-inducing "attacks blocked this month" graphs.
- **No upsells**: Zero commercial banners, cloud syncing upsells, or CAPTCHA promos.
- **Enterprise white-labeled 429 page**: HTML login attempts from locked IPs receive a branded, clean HTTP 429 response card with a 1-click **Copy IP** badge, duration backoff notification, and optional IT Helpdesk support contact details or Unlock Hub link.

### 2. High-Performance Authentication Interceptor
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
| `show_ip` | `true` | Displays visitor IP with 1-click clipboard copy badge |
| `show_unlock_link` | `true` | Shows Unlock Hub link when an agency unlock URL is set |

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
