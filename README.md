# Clockwork Control

[![Tests](https://github.com/Clockwork-Web-Dev-LLC/clockwork-control/actions/workflows/tests.yml/badge.svg)](https://github.com/Clockwork-Web-Dev-LLC/clockwork-control/actions/workflows/tests.yml)
[![Coverage](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2FClockwork-Web-Dev-LLC%2Fclockwork-control%2Fmain%2F.github%2Fbadges%2Fcoverage.json)](https://github.com/Clockwork-Web-Dev-LLC/clockwork-control/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Clockwork Control** is a self-hosted control panel and fleet monitor for agencies and freelancers managing a portfolio of WordPress sites. It watches server health, site uptime, SSL, traffic, security, and performance — and surfaces what needs human attention before a client notices.

It's meant to run on your own infrastructure, not as a hosted SaaS: no public URL required, and the database holds real fleet credentials (SSH keys, DB passwords, API tokens), so it belongs on infrastructure you control. It doesn't provision or run servers itself — point it at servers/sites you already host (SpinupWP, Pressable, WP Engine, Kinsta, Cloudways, or your own DigitalOcean/Hetzner/Azure droplets) and it watches them.

> **Before you rely on this in production**, read [DISCLAIMER.md](DISCLAIMER.md) — this software holds real credentials and can take real automated actions on your fleet. It's provided under the MIT license with no warranty, and you use it at your own risk.

## What it does

- **Server health** — cloud-provider metrics every 5 minutes; hot servers turn yellow/red on the dashboard. DigitalOcean, Hetzner, Azure, and Cloudways-provisioned servers supported out of the box.
- **Uptime + SSL** — HTTP probes with chat alerts on transitions; per-site cert tracking.
- **Attack detection + bans** — nginx log tailing, LLAR/Wordfence ingest, an optional local LLM flags suspicious IPs into a review queue. A human approves every ban; approved blocks go out via fail2ban over SSH.
- **Security scans** — Sucuri SiteCheck, domain blacklists, WP core checksum verification, and an in-WP malware probe, consolidated on the `/issues` page.
- **Performance scans** — Lighthouse scores via GTmetrix (PSI fallback), or a hosting provider's own native scan engine where one exists.
- **Updates** — apt-update visibility per server with bulk patch queueing, plus nightly WordPress plugin auto-updates.
- **Companion mu-plugin** — signed REST endpoints on each WP site, and a Tools → Clockwork section in wp-admin so clients see what's being monitored for them.
- **Site migrations, contact-form testing, traffic/capacity rollups** — and more; see the docs.
- **Five hosting providers out of the box** — SpinupWP and Cloudways (real server, full SSH access), Pressable, WP Engine, and Kinsta (API/per-site SSH, no server row) — with a `HostingProvider` contract so a sixth is a module away, not a rewrite.

## Module system

Cloud providers (DigitalOcean, Hetzner, Azure, Cloudways) and hosting providers (SpinupWP, Pressable, WP Engine, Kinsta, Cloudways) are self-contained modules under `modules/`, each implementing a small contract (`Modules\Core\Contracts\CloudProvider` or `HostingProvider` — Cloudways implements both) and registering itself — its credential fields, its diagnostics check, its scheduled jobs — with `Modules\Core\ModuleRegistry`. Nothing in core app code hardcodes a provider list. Adding a new cloud or hosting provider means writing a new module, not touching a dozen files across the app. See [Reference → Artisan commands](resources/docs/reference/artisan-commands.md) and the existing `modules/Azure`/`modules/Pressable` directories for the pattern to follow.

## Docs

The real documentation is the in-app docs site: markdown files in [`resources/docs/`](resources/docs/), rendered at `/docs` when the app is running. Start with **Getting Started → Welcome** and **Architecture → System overview**. Conventions for editing pages are in [`resources/docs/_conventions.md`](resources/docs/_conventions.md).

## Stack

Laravel 13 on PHP 8.4, MySQL, server-rendered Blade with Tailwind 4 + Alpine.js + ECharts, built by Vite. SSH via phpseclib. Auth is Google OAuth against a database allowlist (self-provisioning is intentionally not supported — a `users` row *is* the allowlist; see [Getting Started → Local dev setup](resources/docs/getting-started/local-dev.md#4-add-yourself-to-the-allowlist) for how to create your first user). Deliberately traditional MVC — no SPA, no Redis required.

## Quickstart

Full walkthrough: [Getting Started → Local dev setup](resources/docs/getting-started/local-dev.md). The short version:

```bash
mysql -uroot -e "CREATE DATABASE clockwork CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
composer setup                      # install + .env + key + migrate + npm install
php artisan clockwork:add-user you@example.com --name="Your Name"   # OAuth allowlist

# Two terminals — the scheduler is load-bearing, not optional:
php artisan serve                   # http://127.0.0.1:8000
php artisan schedule:work           # without this, nothing polls, drains, or scans

npm run dev                         # third terminal, only for frontend work
```

Set `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` in `.env` to log in. Everything else — cloud/hosting provider tokens, chat webhooks, security-feed keys — is optional; a feature no-ops when its credential is blank, and every credential can also be entered later through **Settings → API credentials** in the app itself instead of `.env`. On a genuinely empty install (no servers, no sites) the dashboard redirects to a **Setup** checklist showing exactly what's configured and what isn't.

To populate inventory from a real fleet, set your hosting provider's token and run `php artisan clockwork:import-spinupwp` (or `clockwork:import-pressable`).

## Tests + quality

```bash
./vendor/bin/pest       # test suite (Pest, in-memory SQLite, no setup)
./vendor/bin/pint       # code style
composer phpstan        # static analysis (Larastan, level 5)
```

See [tests/README.md](tests/README.md) for the conventions the suite follows — factories, HTTP-fake fixtures, the authenticated-page trait, and how to mock the classes tests must never let touch real infrastructure.

## Repo layout

| Path | What |
|---|---|
| `app/Console/Commands/` | Every `clockwork:*` command — most of the system's verbs live here. |
| `app/Services/` | One directory per core integration/domain (Companion, Cloudflare, Security, Performance, …). |
| `modules/` | Self-contained cloud/hosting-provider modules — see "Module system" above. |
| `routes/console.php` | The full schedule, heavily commented. |
| `resources/docs/` | The docs site (rendered at `/docs`). |

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Author

Clockwork Control was built by [Aaron Reimann](https://github.com/aaronreimann) / [Clockwork Web Dev](https://clockworkwd.com).

## License

MIT — see [LICENSE](LICENSE).
