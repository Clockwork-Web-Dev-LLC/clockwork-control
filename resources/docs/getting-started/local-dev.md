---
title: Local dev setup
section: Getting Started
order: 20
updated: 2026-09-11
author: Aaron Reimann
tags: [getting-started, dev, setup, herd, mysql, linux]
---

How to get Clockwork running on a fresh Linux or macOS machine. Pretty much one-time — the toolchain is local and intentionally lean. Steps below are split by OS where they differ; everything else (Composer/npm/artisan commands, `.env`, the allowlist step) is identical on both.

> [!NOTE]
> **A Mac is NOT required**: Clockwork Control runs natively on any standard Linux distribution (Debian/Ubuntu, Fedora/RHEL, Arch) as well as macOS. The backend is 100% standard Laravel 13 + PHP 8.4 + MySQL. Linux users do not need Laravel Herd, special shims, or Apple hardware.

## Prerequisites

- **Linux** (Debian/Ubuntu, Fedora/RHEL, Arch — anything with standard packages) or **macOS** (Apple Silicon or Intel).
- Git access to this repo.
- Package manager: `apt` / `dnf` / `pacman` on Linux, Homebrew or Laravel Herd on macOS.

## 1. Install the toolchain

### PHP 8.4 + Composer

**Linux (Debian/Ubuntu):**
```bash
sudo add-apt-repository ppa:ondrej/php && sudo apt update
sudo apt install php8.4 php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```
*Note for Linux: `php` and `composer` land directly on standard system PATH (`/usr/bin` / `/usr/local/bin`), so no PATH exports are required.*

**Linux (Fedora/RHEL):**
```bash
sudo dnf install php php-cli php-mysqlnd php-mbstring php-xml php-curl php-zip php-bcmath php-intl
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

**macOS:** ships via the free tier of [Laravel Herd](https://herd.laravel.com) (PHP 8.4 + Composer 2.9 together) or Homebrew (`brew install php`).

*Note for macOS Herd users only:* After install, the binaries land at `~/Library/Application Support/Herd/bin/` — which is not in the default shell PATH of external terminals. macOS Herd users should add:
```bash
export PATH="$HOME/Library/Application Support/Herd/bin:$PATH"
```
*(Linux users can ignore this entirely).*

### MySQL

**macOS:**

```bash
brew install mysql
brew services start mysql
```

**Linux:**

```bash
sudo apt install mysql-server        # Debian/Ubuntu
# or: sudo dnf install mysql-server  # Fedora/RHEL
sudo systemctl enable --now mysqld   # some distros use `mysql` as the unit name
```

Defaults:

- Listens on `127.0.0.1` only (good — don't open it).
- macOS Homebrew MySQL: root has **no password**. Linux distro MySQL: root normally auths via the `auth_socket`/`unix_socket` plugin instead, so `mysql -uroot` from your own user will fail — run the command below with `sudo mysql` instead (or set a root password with `sudo mysql_secure_installation` and use that in `.env`).
- Create the DB:

  ```bash
  mysql -uroot -e "CREATE DATABASE clockwork CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"   # macOS
  sudo mysql -e "CREATE DATABASE clockwork CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"      # Linux
  ```

### Node + npm

For Vite. Use whatever Node version manager you have (asdf, mise, fnm, nvm). Node 20+ is fine.

```bash
node --version   # confirm
```

### Optional: LM Studio

Only needed if you're working on the nginx-log threat analyzer. Install from [lmstudio.ai](https://lmstudio.ai), download `meta-llama-3-8b-instruct` (Q4_K_M quant), start the local server. See [Integrations → LM Studio](/docs/integrations/lm-studio).

## 2. Clone and bootstrap

```bash
git clone <repo-url> clockwork-control
cd clockwork-control

# macOS/Herd only — skip this line on Linux, php/composer are already on PATH:
export PATH="$HOME/Library/Application Support/Herd/bin:$PATH"

# Composer setup script: install + key generation + migrate + npm install
composer setup
```

If `composer setup` doesn't exist (older repo state), run the equivalent manually:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
```

## 3. Configure .env

Open `.env` and fill in only what you need for the work you're doing. Most variables short-circuit (the feature no-ops) when the secret is blank — see [Reference → Environment variables](/docs/reference/env-vars) for the full list.

Minimum to get the app to render:

```
APP_KEY=<generated above>
DB_DATABASE=clockwork
DB_USERNAME=root
DB_PASSWORD=
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=http://localhost:8000/auth/google/callback
```

For **Google OAuth in dev**, register `http://localhost:8000/auth/google/callback` as an Authorized redirect URI in [Google Cloud Console](https://console.cloud.google.com/apis/credentials). See [Integrations → Google OAuth](/docs/integrations/google-oauth).

## 4. Add yourself to the allowlist

Bootstrap your user (Google login won't work until you're on the list):

```bash
php artisan clockwork:add-user you@example.com --name="Your Name"
```

## 5. Run the dev server + scheduler

You need **two terminals** (or two tmux panes):

```bash
# Terminal 1 — HTTP server:
export PATH="$HOME/Library/Application Support/Herd/bin:$PATH"   # macOS/Herd only
php artisan serve   # http://127.0.0.1:8000

# Terminal 2 — scheduler (the load-bearing one!):
export PATH="$HOME/Library/Application Support/Herd/bin:$PATH"   # macOS/Herd only
php artisan schedule:work
```

Without `schedule:work`, almost every drainer feature drifts. See [Runbooks → Scheduler stuck](/docs/runbooks/scheduler-stuck) for symptoms.

For frontend changes, you also want Vite:

```bash
# Terminal 3 — Vite dev server (HMR):
npm run dev
```

## 6. Visit the app

`http://127.0.0.1:8000` → click **Sign in with Google** → land on the dashboard.

Empty database? Of course — Clockwork has no inventory yet. Two options:

- **Connect to your real fleet.** Set `CLOCKWORK_DIGITALOCEAN_TOKEN` and `CLOCKWORK_SPINUPWP_TOKEN` in `.env`, then run `php artisan clockwork:import-spinupwp`.
- **Seed test data.** No factories yet (this is on the backlog) — manually create a few servers + sites in tinker for now.

## Common shell incantations

```bash
export PATH="$HOME/Library/Application Support/Herd/bin:$PATH"   # macOS/Herd only — not needed on Linux

php artisan migrate              # apply migrations
php artisan migrate:fresh        # drop + recreate (DESTROYS DATA — confirm)
php artisan tinker               # REPL
php artisan queue:work           # run queued jobs (less critical than schedule:work for dev)
./vendor/bin/pint                # code style
./vendor/bin/phpunit             # tests
./vendor/bin/phpunit --filter ServerPollerTest
composer phpstan                 # static analysis (Larastan @ level 5)
```

Frontend:

```bash
npm run dev      # Vite HMR
npm run build    # production build
```

## Useful artisan commands for dev

```bash
# Inventory
php artisan clockwork:digitalocean-test
php artisan clockwork:spinupwp-test
php artisan clockwork:do-spaces-test
php artisan clockwork:bill-com-test
php artisan clockwork:mattermost-test

# Force a poll / import
php artisan clockwork:poll-servers
php artisan clockwork:import-spinupwp

# Force a security scan
php artisan clockwork:check-blacklists --site=42
```

Full list at [Reference → Artisan commands](/docs/reference/artisan-commands).

## Testing

```bash
./vendor/bin/phpunit
./vendor/bin/phpunit --filter <ClassName>
./vendor/bin/phpunit tests/Unit/Services/Cloudflare/
```

Test DB is configured to use SQLite in-memory by default — no separate setup needed.

## Gotchas worth knowing up front

- **Herd PATH (macOS only)** — covered above. If a command says "command not found: php," you forgot the export.
- **`env('HOME')` is null under Herd's php-fpm (macOS).** No shell env. Use `posix_getpwuid(posix_getuid())['dir']` and fall back to `env('HOME')`. Bit Companion's installer once. Linux php-fpm pools set `HOME` explicitly by default, so this hasn't been seen there — but the same fallback is safe on any OS.
- **Linux root MySQL auth** — covered above under MySQL: `mysql -uroot` fails on a fresh distro install (`auth_socket`); use `sudo mysql` or set a root password first.
- **`schedule:work` is mandatory for most features.** The dashboard renders without it but nothing actually polls / drains / runs.
- **bun/bunx — not in this project.** PHP-only. Don't reach for npm install of PHP-equivalent packages.
- **Don't run `migrate:fresh` against a real DB.** Always confirm before using.
