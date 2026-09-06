---
title: Local dev setup
section: Getting Started
order: 20
updated: 2026-05-04
author: Aaron Reimann
tags: [getting-started, dev, setup, herd, mysql]
---

How to get Clockwork running on a fresh Mac. Pretty much one-time — the toolchain is local and intentionally lean.

## Prerequisites

- macOS (Apple Silicon or Intel — both work).
- Homebrew.
- Git access to this repo.

## 1. Install the toolchain

### Laravel Herd (PHP + Composer)

PHP 8.4 + Composer 2.9 ship with the free tier of [Laravel Herd](https://herd.laravel.com).

After install, the binaries land at `~/Library/Application Support/Herd/bin/` — and **they are NOT in the PATH** of Bash shells inherited by Claude Code or most terminals at launch. Every shell command that runs `php`, `composer`, or `artisan` must prepend:

```bash
export PATH="$HOME/Library/Application Support/Herd/bin:$PATH"
```

Easiest fix: add that line to your `~/.zshrc` (or `~/.bashrc`) so it's always available. The official Herd installer does this for the GUI shell launchers but not for new tabs spawned by other tools.

### MySQL

```bash
brew install mysql
brew services start mysql
```

Defaults:

- Listens on `127.0.0.1` only (good — don't open it).
- Root has **no password** (acceptable for local dev — don't replicate this on a server).
- Create the DB:

  ```bash
  mysql -uroot -e "CREATE DATABASE clockwork CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
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
export PATH="$HOME/Library/Application Support/Herd/bin:$PATH"
php artisan serve   # http://127.0.0.1:8000

# Terminal 2 — scheduler (the load-bearing one!):
export PATH="$HOME/Library/Application Support/Herd/bin:$PATH"
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
export PATH="$HOME/Library/Application Support/Herd/bin:$PATH"

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

- **Herd PATH** — covered above. If a command says "command not found: php," you forgot the export.
- **`env('HOME')` is null under Herd's php-fpm.** No shell env. Use `posix_getpwuid(posix_getuid())['dir']` and fall back to `env('HOME')`. Bit Companion's installer once.
- **`schedule:work` is mandatory for most features.** The dashboard renders without it but nothing actually polls / drains / runs.
- **bun/bunx — not in this project.** PHP-only. Don't reach for npm install of PHP-equivalent packages.
- **Don't run `migrate:fresh` against a real DB.** Always confirm before using.
