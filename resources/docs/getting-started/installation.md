---
title: Installation & Setup
section: Getting Started
order: 15
updated: 2026-09-07
author: Aaron Reimann
tags: [getting-started, install, setup, wizard, self-hosted]
tracks: [app/Installer/InstallerEnvWriter.php, app/Http/Controllers/InstallerController.php, routes/install.php, app/Http/Middleware/EnforceInstallerGate.php]
---

Clockwork Control provides a web-based installation wizard (`/install`) that streamlines provisioning a self-hosted instance.

## Quickstart Installation

### 1. Clone & Install Dependencies

```bash
git clone git@github.com:Clockwork-Web-Dev-LLC/clockwork-control.git
cd clockwork-control
composer install
npm install && npm run build
```

`.env.example` ships with a valid fallback `APP_KEY` and temporary file/sync session drivers. This allows the Laravel runtime to boot HTTP and CSRF sessions immediately without manual edits.

### 2. Start the Application

```bash
php artisan serve
```

### 3. Open the Web Installer

Navigate to `http://localhost:8000` (or your configured server domain). Because `storage/installed` does not exist yet, you will be automatically redirected to the installer wizard at `/install`.

---

## The 8-Step Installation Wizard

1. **Welcome & Requirements Check**: Confirms PHP >= 8.2, required extensions (`pdo_mysql`, `openssl`, `mbstring`, `fileinfo`, `curl`), and write permissions on `storage/` and `bootstrap/cache/`.
2. **Database Connection**: Configure MySQL / MariaDB host, port, database, and credentials. Includes a live connection test button.
3. **Application Identity**: Set your panel's title, public URL, and timezone.
4. **Outbound Mail (Optional)**: Configure SMTP for alerts and notifications, or skip to default to the log driver.
5. **Google OAuth (Required)**: Enter your Google Client ID, Client Secret, and optional Google Workspace hosted domain.
6. **Administrator Account**: Provision your first admin email and name. This email will be granted allowlist access upon completion.
7. **Hosting Provider Quick-Connect (Optional)**: Select your primary infrastructure (SpinupWP, Forge, RunCloud, Pressable, WP Engine, Kinsta, or Custom VPS).
8. **Review & Confirm**: Review all settings with masked secrets. Clicking **Install & Complete Setup** will:
   - Generate a fresh cryptographically secure `APP_KEY`
   - Atomically write all settings to `.env` with `0600` permissions
   - Run database migrations (`php artisan migrate --force`)
   - Provision your admin operator on the allowlist
   - Seal the installer by creating `storage/installed`
   - Redirect to the celebration screen

---

## Disaster Recovery & Reopening the Installer

Once installed, the `EnforceInstallerGate` middleware returns a hard **404** for any URL under `/install/*`.

If you ever need to deliberately reopen the installer (for example, in disaster recovery or migration):

```bash
php artisan clockwork:installer:reopen
```

In automated scripts, pass `--force`:

```bash
php artisan clockwork:installer:reopen --force
```

This removes `storage/installed` and re-exposes the wizard until completed again.
