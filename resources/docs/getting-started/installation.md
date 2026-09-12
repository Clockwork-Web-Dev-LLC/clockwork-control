---
title: Installation & Setup
section: Getting Started
order: 15
updated: 2026-09-11
author: Aaron Reimann
tags: [getting-started, install, setup, wizard, self-hosted, linux]
tracks: [app/Installer/InstallerEnvWriter.php, app/Http/Controllers/InstallerController.php, routes/install.php, app/Http/Middleware/EnforceInstallerGate.php]
---

Clockwork Control provides a web-based installation wizard (`/install`) that streamlines provisioning a self-hosted instance.

## Platform & Hardware Support: Linux & macOS

> [!IMPORTANT]
> **A Mac is NOT required.** While some agency operators run Clockwork Control locally on an Apple Silicon machine (such as a Mac Mini or MacBook), Clockwork Control is built on standard PHP 8.4 and MySQL. It runs natively and identically on **Linux** (Ubuntu 22.04/24.04 LTS, Debian 12, Fedora, Rocky/AlmaLinux, or Arch Linux) on dedicated hardware, an Intel NUC / mini-PC, a Proxmox/KVM virtual machine, or a Raspberry Pi 5 on your office network.

Clockwork Control has zero proprietary Apple or macOS-specific dependencies:
- **Runtime**: PHP 8.4 or newer (`cli`, `fpm`, `pdo_mysql`, `openssl`, `mbstring`, `curl`, `xml`, `zip`, `bcmath`, `intl`) — composer.json requires `"php": "^8.4"`, so PHP 8.3 will not install
- **Database**: MySQL 8.0+ / 9.x or MariaDB 10.11+
- **Frontend Build**: Node 20+ and NPM
- **Background Engine**: Systemd timer or crontab executing `php artisan schedule:run` every minute

## Quickstart Installation

### 1. System Requirements (Linux vs macOS)

**On Linux (Ubuntu / Debian):**
```bash
sudo apt update
sudo apt install -y php8.4 php8.4-cli php8.4-fpm php8.4-mysql php8.4-mbstring \
    php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl mysql-server git curl
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
```

**On macOS:**
Install via [Laravel Herd](https://herd.laravel.com) (PHP 8.4 + Composer) or Homebrew (`brew install php mysql composer`).

### 2. Clone & Install Dependencies

```bash
git clone git@github.com:Clockwork-Web-Dev-LLC/clockwork-control.git
cd clockwork-control
composer install
npm install && npm run build
```

`.env.example` ships with a valid fallback `APP_KEY` and temporary file/sync session drivers. This allows the Laravel runtime to boot HTTP and CSRF sessions immediately without manual edits.

### 3. Start the Application

For initial setup or development:
```bash
php artisan serve
```

### 4. Open the Web Installer

Navigate to `http://localhost:8000` (or your configured server domain / LAN IP). Because `storage/installed` does not exist yet, you will be automatically redirected to the installer wizard at `/install`.

---

## 24/7 Production Deployment on Linux

When hosting Clockwork Control 24/7 on a dedicated Linux server or local VM:

### 1. Scheduler (Cron or Systemd)
Clockwork Control requires the scheduler to drain logs, monitor uptime, and run security scans. Add this line to your Linux user's crontab (`crontab -e`):

```cron
* * * * * cd /var/www/clockwork-control && php artisan schedule:run >> /dev/null 2>&1
```

### 2. Nginx Web Server
Point Nginx to the `public/` directory:

```nginx
server {
    listen 80;
    server_name clockwork.local; # or your LAN IP / internal hostname
    root /var/www/clockwork-control/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

---

## The 8-Step Installation Wizard

1. **Welcome & Requirements Check**: Confirms PHP >= 8.2, required extensions (`pdo_mysql`, `openssl`, `mbstring`, `fileinfo`, `curl`), and write permissions on `storage/` and `bootstrap/cache/`.
2. **Database Connection**: Configure MySQL / MariaDB host, port, database, and credentials. Includes a live connection test button.
3. **Application Identity**: Set your panel's title, public URL, and timezone.
4. **Outbound Mail (Optional)**: Configure SMTP for alerts and notifications, or skip to default to the log driver.
5. **Single Sign-On / Google OAuth (Optional)**: Enter your Google Client ID, Client Secret, and optional Google Workspace hosted domain, or skip for now to use local password authentication (SSO can also be configured or changed anytime in Settings).
6. **Administrator Account & Local Password**: Provision your first administrator email and name. If Google OAuth was skipped, set a secure local password (minimum 8 characters) to sign in immediately. If Google OAuth was configured, setting a local password is an optional emergency fallback.
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
