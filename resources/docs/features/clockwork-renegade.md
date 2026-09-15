---
title: Clockwork Renegade (WordPress.org Plugin)
section: Features
order: 94
updated: 2026-09-14
author: Aaron Reimann
tags: [renegade, companion, wordpress, wporg, plugins, enrollment, gpl]
tracks: [app/Models/Site.php, app/Services/Companion/ClockworkCompanionClient.php, app/Http/Controllers/SitesController.php, resources/views/dashboard/site/header.blade.php, resources/views/dashboard/sites.blade.php, tests/Feature/Sites/EnrollmentVariantDetectionTest.php, tests/Feature/SiteOverviewDashboardTest.php, tests/Feature/Models/SiteRelationshipsAndCastsTest.php]
---

**Clockwork Renegade** is the official open-source, GPL-2.0-or-later edition of the Clockwork Companion plugin designed for distribution on the official [WordPress.org Plugin Directory](https://wordpress.org/plugins/).

While private Clockwork Companion is distributed via SSH tarball push or direct zip downloads for managed infrastructure, Clockwork Renegade provides WordPress site owners with a zero-friction, standard 1-click install directly from the WordPress plugin search directory.

---

## Key Differences: Renegade vs Companion

| Feature | Private Companion (`companion`) | Clockwork Renegade (`renegade`) |
|---|---|---|
| **Distribution** | Private zip / SSH mu-plugin push (`~/Projects/clockwork-companion`) | Official WordPress.org Plugin Directory (`~/Projects/clockwork-renegade`) |
| **License** | Proprietary / Internal | GPL-2.0-or-later |
| **REST Namespace** | `/wp-json/clockwork/v1/` | `/wp-json/clockwork-renegade/v1/` |
| **Route Prefix & Nonce** | `clockwork_` | `clockwork_renegade_` |
| **Arbitrary Remote Code (`eval`)** | Supported (`CodeSnippetRoute.php` for internal rescue operations) | **Completely Removed** (Strict WordPress.org Guideline compliance; zero `eval()` on remote payloads) |
| **Plugin Updates** | Self-hosted release tarballs / SSH push | WordPress Core official updater via WordPress.org SVN repository |
| **Pairing UI** | Tools → Clockwork Control (mu-plugin) | Clockwork → Connection (`admin.php?page=clockwork-connection`) |

---

## WordPress.org Guideline Compliance

Clockwork Renegade adheres to all WordPress.org plugin review requirements:

1. **Zero Remote Execution (Guideline 6 & Security Rules)**:
   - The private Companion `CodeSnippetRoute` (which allows executing arbitrary PHP code via remote REST call) is completely omitted from Clockwork Renegade.
   - Guarded by automated `DriftGuardTest` asserting that no `eval()` calls exist anywhere in the codebase.
2. **Affirmative Consent & 3rd-Party Service Disclosures (Guideline 6 & 7)**:
   - Full service terms, privacy policy links, and data handling explanations are documented in both `readme.txt` and `src/Admin/Pages/ConnectionPage.php`.
   - The plugin does not connect to Clockwork Control until the site administrator explicitly copies the Connection Key and connects their dashboard.
3. **Core Updater Exclusivity (Guideline 8)**:
   - Contains no external update checkers, background auto-installers, or remote tarball pulls. Updates are delivered exclusively via the official WordPress.org updater.
4. **Clean Uninstallation (Guideline 9)**:
   - Includes a comprehensive `uninstall.php` script that deletes all `clockwork_renegade_*` options, transients, and any custom action log or security sample tables upon deletion.
5. **Core Privacy Policy Guide**:
   - Integrates with WordPress core's `wp_add_privacy_policy_content()` to provide suggested privacy policy text for site administrators.

---

## 256-Bit Cryptographic Connection Key & Pairing

Communication between Clockwork Control and a WordPress site requires explicit administrator enrollment via a **256-bit cryptographic Connection Key**:

- **Entropy & Generation**: Generated via PHP's cryptographically secure pseudo-random number generator (`random_bytes(32)`), producing a 256-bit (32-byte) secret encoded as a 64-character hex string.
- **Connection Key Format**: A base64-encoded JSON envelope containing the site URL, the 256-bit shared secret, and the variant tag:
  ```json
  {
    "url": "https://client-site.com",
    "secret": "d4f3a8b2... (256-bit hex secret)",
    "variant": "renegade"
  }
  ```
- **Admin Location**: Displayed in WordPress under **Clockwork → Connection** (`admin.php?page=clockwork-connection`), featuring a 1-click clipboard copy button, connection status badge (`Active & Monitored` vs `Unlinked`), and manual credential reveals.
- **Affirmative Consent (WordPress.org Guideline 7)**: Zero network calls, background pings, or data transmissions occur upon plugin activation. Communication begins solely when an authorized site administrator copies the Connection Key and submits it in Clockwork Control's **Sites → + Add Site** modal.
- **Optional Constant Pinning**: For hardened production sites, operators can define `CLOCKWORK_RENEGADE_SECRET` in `wp-config.php`, isolating the 256-bit secret from `wp_options` and database backups.

---

## Clockwork Control Integration

Clockwork Control seamlessly supports both Companion variants through dynamic route negotiation and configuration.

### Data Model

The `sites` table includes a `companion_variant` column:
- Default: `'companion'`
- Renegade: `'renegade'`
- Model helpers on `App\Models\Site`:
  - `isRenegade(): bool`
  - `isClassicCompanion(): bool`
  - `pluginOnlyHostLabel(): string` — **Renegade Only** or **Companion Only**, used as the host pill on `/sites` and the site header for custom (no-server) sites.

### Dynamic REST Client & HMAC Signing

`App\Services\Companion\ClockworkCompanionClient` automatically computes URLs and HMAC signatures based on the site's `companion_variant`:
- Uses `ClockworkCompanionClient::routeNamespace()` (`clockwork-renegade/v1` vs `clockwork/v1`).
- HMAC-SHA256 signatures are computed using the matching route URI string:
  ```
  METHOD + "\n" + "/wp-json/" + routeNamespace + route + "\n" + timestamp + "\n" + body
  ```
- Transparent retry mechanics re-sign with fresh timestamps to prevent replay detection failures.

### Dual-Variant Enrollment Handshake

When an operator enrolls a new site under **Sites → + Add Site** (`SitesController::store()`):
1. **Connection Key Decoding**: Decodes the base64 connection payload containing `url`, `secret`, and optional `variant: 'renegade'`.
2. **Variant Probing**:
   - If the connection key explicitly specifies `variant: 'renegade'`, Control probes `/wp-json/clockwork-renegade/v1/health` first.
   - If manual credentials or standard keys are provided, Control probes with automatic fallback across `['companion', 'renegade']`.
3. **Automatic Variant Assignment**:
   - If the Renegade endpoint returns 200 OK, the site is created with `companion_variant = 'renegade'`.
   - If Classic Companion returns 200 OK, `companion_variant = 'companion'`.
   - If neither endpoint responds, the operator receives an informative HTTP 404 message indicating neither variant was found.

### Download Hub

Operators can download the latest release `.zip` packages for both editions directly from Clockwork Control at **[Plugin Downloads](/downloads)** (`route('downloads.index')`). The hub is directly linked from:
- **Sites List** (`/sites` header button)
- **Add Site** (`/sites/create` connection banner)
- **Global Settings Hub** (`/settings` Fleet Policies section)

