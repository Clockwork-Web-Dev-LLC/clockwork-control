# Clockwork Renegade & Control Integration Handoff Brief

## 1. Executive Summary

Per the architecture specification in `plans/clockwork-renegade.md`, we have built **Clockwork Renegade**—a standalone, GPL-2.0-or-later WordPress plugin engineered specifically for acceptance into the official **WordPress.org Plugin Directory** (`plugins.svn.wordpress.org/clockwork-renegade/`). 

In parallel, we upgraded **Clockwork Control** (`clockwork-control`) to support a dual-variant plugin fleet:
1. **Clockwork Companion (`companion`)**: The existing, private, MIT-licensed mu-plugin deployed via SSH/command-runner to managed hosting (SpinupWP, Pressable, GridPane).
2. **Clockwork Renegade (`renegade`)**: The public, open-source WordPress.org-listed plugin for standalone and unmanaged sites (WP Engine, Kinsta, custom hosting), updated strictly via WordPress core's native updater.

Both repositories are 100% test-green, fully audited against WordPress.org review gates, formatted via Pint, statically analyzed with PHPStan, and cleanly committed.

---

## 2. Clockwork Renegade Plugin Repository (`~/Projects/clockwork-renegade`)

### A. Repository & Lineage Isolation
- **Path**: `/Users/aaronr/Projects/clockwork-renegade` (Git branch: `main`).
- **Clean Git History**: Fresh git repository with no shared history, subtrees, or commit metadata referencing private Companion.
- **Security Hooks**: Enabled `.githooks` (`gitleaks` pre-commit scanner).
- **License**: GPL-2.0-or-later (`LICENSE` file included; Copyright 2026 Clockwork Web Dev, LLC).
- **Composer**: Minimal zero-runtime-dependency setup (`composer.json`) with PSR-4 autoloading (`ClockworkRenegade\` → `src/`). Dev dependencies only (PHPUnit 11).

### B. WordPress.org Plugin Directory Compliance Audit

| Requirement | Implementation Details | Verification |
|---|---|---|
| **Guideline 6: No Arbitrary Remote Code Execution** | `CodeSnippetRoute.php` (which executed remote PHP via `eval()`) was **completely excluded** from Renegade. No dynamic `eval`, `assert()`, or `create_function()` exists. | Verified by automated `tests/DriftGuardTest.php` asserting 0 instances of `eval()` in codebase. |
| **Guidelines 6 & 7: SaaS Disclosures & Affirmative Consent** | 1. `readme.txt` contains full 3rd-party service disclosures (service name, company, service URL, terms, privacy policy, and exact telemetry payloads).<br>2. Implemented `src/Admin/Pages/ConnectionPage.php`: ManageWP-style pairing screen. Site admin must affirmatively view and copy the base64 Connection Key into Clockwork Control. Zero outbound connections occur before explicit pairing. | Verified by `tests/ConnectionPageTest.php` and `tests/LifecycleTest.php`. |
| **Guideline 8: Core Updater Exclusivity** | All self-hosted update checkers, background auto-installers, and PUC libraries were removed. Releases route exclusively via WordPress.org SVN and core's updater. | Verified by codebase audit; zero update-checker dependencies. |
| **Guideline 9: Clean Uninstallation** | Implemented root `uninstall.php` executing on plugin deletion. Purges all `clockwork_renegade_*` options, transients, and custom database tables (`wp_clockwork_renegade_action_log`, `wp_clockwork_renegade_auth_failures`, `wp_clockwork_renegade_resource_samples`). | Verified by `tests/LifecycleTest.php::testUninstallRemovesAllOptionsAndTables()`. |
| **Core Privacy Policy Integration** | Implemented `src/Support/PrivacyPolicy.php` hooking `wp_add_privacy_policy_content()` to supply suggested policy copy for site owners. | Verified in bootstrap `clockwork-renegade.php`. |
| **Activation Hook & First-Run UX** | `register_activation_hook` sets transient redirecting admin to the Connection screen (`admin.php?page=clockwork-renegade-connection`) on first activation. | Verified by `tests/LifecycleTest.php`. |

### C. Kept REST API Surface
All kept routes ported to `ClockworkRenegade\` namespace, `clockwork_renegade_` table/option prefixes, and `/wp-json/clockwork-renegade/v1/` REST route prefix:
- **Telemetry & Status**: `HealthRoute`, `DetectRoute`, `PluginsRoute`, `ThemesRoute`, `AdminsRoute`, `CronRoute`, `SnapshotRoute`, `ResourceReportRoute`, `CommentsSummaryRoute`, `WordfenceBlocksRoute`, `LockoutsRoute`, `TwoFactorStatusRoute`, `FormSubscriptionsRoute`.
- **Inbound Data Relays**: `BackupsReportRoute`, `TrafficReportRoute`, `ActionLogAppendRoute`, `SecuritySummaryReportRoute`.
- **Core Operations**: `PluginUpdateRoute`, `ThemeUpdateRoute`, `CoreUpdateRoute` (triggering WP core's native upgraders), `PostUpdateVerifyRoute`, `MaintenanceModeRoute`, `CacheFlushRoute`, `CommentsActionRoute`.
- **Security & Identity**: `MalwareScanRoute` (in-plugin file signatures), `BrandingRoute`, `SecretRotateRoute`, `SsoRoute` (one-time magic login link), `TwoFactorMigrateRoute`, `TestContactFormRoute`, `BackupCreateRoute`, `BackupRestoreRoute`.

### D. Packaging & Automation
- **Release Zip Builder**: `bin/build-release-zip.sh` packages clean distribution zip (`dist/clockwork-renegade.zip`, ~328 KB) stripped of dev tools, tests, composer files, and hidden metadata, with SHA-256 sidecar.
- **GitHub Actions**:
  - `.github/workflows/ci.yml`: Matrix tests on PHP 8.1, 8.2, 8.3.
  - `.github/workflows/deploy-svn.yml`: Automated WordPress.org SVN release deployment on `v*` tag push.

### E. Test Results (`clockwork-renegade`)
- **PHPUnit 11.5**: **91 tests, 768 assertions** passing (100% green).

---

## 3. Clockwork Control Integration (`clockwork-control`)

### A. Database Migration & Data Model
- **Migration**: `database/migrations/2026_09_12_233000_add_companion_variant_to_sites_table.php`
  - Adds `companion_variant` column (`string`, nullable, default `'companion'`).
- **Model (`app/Models/Site.php`)**:
  - Added `companion_variant` to `$fillable`.
  - Added helper methods:
    ```php
    public function isRenegade(): bool { return $this->companion_variant === 'renegade'; }
    public function isClassicCompanion(): bool { return $this->companion_variant === 'companion' || $this->companion_variant === null; }
    ```

### B. Dynamic REST Client & HMAC Signing
- **`app/Services/Companion/ClockworkCompanionClient.php`**:
  - Defined `RENEGADE_ROUTE_NAMESPACE = 'clockwork-renegade/v1'`.
  - Added dynamic `routeNamespace()`:
    ```php
    public function routeNamespace(): string
    {
        return ($this->site->companion_variant === 'renegade')
            ? self::RENEGADE_ROUTE_NAMESPACE
            : self::ROUTE_NAMESPACE;
    }
    ```
  - Updated `buildUrl()`, `buildQueryRouteUrl()`, and `signedRequest()` to sign payloads with `$this->routeNamespace()`.
  - HMAC SHA-256 signature payload matches WordPress plugin `HmacVerifier`:
    ```
    METHOD + "\n" + "/wp-json/" + routeNamespace + route + "\n" + timestamp + "\n" + body
    ```

### C. Enrollment & Handshake Negotiation
- **Controller (`app/Http/Controllers/SitesController.php`)**:
  - Connection keys generated by Renegade include `variant: 'renegade'`.
  - Enrollment logic inspects the suggested variant and probes `/wp-json/clockwork-renegade/v1/health`.
  - For manual entry, attempts Renegade/Companion probes with graceful 404 fallback:
    - If `renegade` returns 200, creates site with `companion_variant = 'renegade'`.
    - If `companion` returns 200, creates site with `companion_variant = 'companion'`.
    - If an endpoint returns 401, immediately reports credential failure.
    - If neither responds, provides a clear error explaining neither REST namespace was found.

### D. UI & Views
- **Site Enrollment (`resources/views/sites/create.blade.php`)**:
  - Added 1-click link to the WordPress.org Directory listing for **Clockwork Renegade** alongside private .zip downloads.
  - Updated instructions for copying the Connection Key from `wp-admin → Clockwork`.
- **Site Settings (`resources/views/dashboard/site/tab-settings.blade.php`)**:
  - Displays `Clockwork Renegade` header and `Renegade (WordPress.org)` edition badge when `site->isRenegade()` is true.

### E. Configuration & Documentation
- **Config (`config/clockwork.php`)**: Added `'renegade'` configuration block.
- **Documentation**:
  - Created `resources/docs/features/clockwork-renegade.md` (tracked by `CoverageChecker`).
  - Updated `resources/docs/architecture/companion-plugin.md` and `plans/README.md`.

### F. Test Results (`clockwork-control`)
- **Full Pest Suite**: **2,179 tests, 9,517 assertions** passing (100% green).
- **Enrollment Feature Tests**: `tests/Feature/Sites/EnrollmentVariantDetectionTest.php` (5 tests, 27 assertions passing).
- **HMAC Feature Tests**: `tests/Feature/Companion/CompanionHmacAuthTest.php` (11 tests, 42 assertions passing).
- **Add Standalone Site Tests**: `tests/Feature/Sites/AddStandaloneSiteTest.php` (10 tests, 52 assertions passing).
- **Code Style & Static Analysis**: Pint passed (0 issues), PHPStan passed (0 errors), Gitleaks passed (0 leaks).

---

## 4. Open Items & Next Steps for Grok / Claude Review

1. **WordPress.org Public Disclosures URL**:
   - In `~/Projects/clockwork-renegade/readme.txt`, the external service links currently point to `https://clockworkcontrol.com/terms` and `https://clockworkcontrol.com/privacy`. Confirm these URLs are live and publicly accessible on `clockworkcontrol.com` before submitting to WordPress.org.
2. **WordPress Version Compatibility Headers**:
   - `clockwork-renegade.php` and `readme.txt` are configured with:
     - `Requires at least: 6.4`
     - `Tested up to: 6.7`
     - `Requires PHP: 8.0`
     - `Stable tag: 1.0.0`
   - Adjust if target test matrix expands.
3. **SVN Repository Submission**:
   - The initial submission must be made manually by uploading `dist/clockwork-renegade.zip` to [WordPress.org Plugin Submission](https://wordpress.org/plugins/developers/add/).
   - Once approved and SVN credentials are issued, populate GitHub repository secrets `SVN_USERNAME` and `SVN_PASSWORD` to enable `.github/workflows/deploy-svn.yml`.
