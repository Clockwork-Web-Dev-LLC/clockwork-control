---
title: System updates
section: Features
order: 92
updated: 2026-09-09
author: Aaron Reimann
tags: [system-updates, self-update, core, companion, releases]
tracks: [app/Services/Updates/**, app/Http/Controllers/SystemUpdatesController.php, app/Console/Commands/CheckSystemUpdates.php, app/Console/Commands/ApplySystemUpdate.php, resources/views/settings/updates.blade.php, routes/web.php]
---

Lives at **`/settings/updates`** (gear menu → System Updates, also reachable via the centralized [Settings Hub](/docs/features/settings-hub) at `/settings` under System & Workspace). This is Clockwork Control updating *itself* — not to be confused with [Features → Updates](/docs/features/updates), the fleet-wide page for updating plugins/themes/core on the WordPress sites you manage. Same word, two completely different systems; the naming collision is unfortunate but the URLs (`/updates` vs `/settings/updates`) keep them apart.

## What it shows

Three sections, one page:

1. **Core & bundled modules** — your installed version (`config('clockwork.version')`, a literal string in `config/clockwork.php` — not an env var, so it can't drift from what's actually checked out) vs. the latest GitHub release for this repo. Up to date shows a green checkmark card with your current git branch/commit; an update available shows an amber card with the release name, changelog body, and an **Update Now** button.
2. **Companion plugin (fleet)** — how many monitored sites have the mu-plugin installed, how many are on the bundled version (`CLOCKWORK_COMPANION_VERSION`) vs. an older one. Purely informational here — actually rolling Companion out to sites is `clockwork:companion-fleet-deploy` / `clockwork:install-companion`, not this page.
3. **Module Directory** — a jump link to `/settings/modules`.

## Checking for updates

`SystemUpdateService::checkForUpdates()` hits `CLOCKWORK_UPDATES_API_URL` (default: this repo's GitHub Releases API) and compares the tag against `config('clockwork.version')` via `version_compare()`. Cached 12h (`CLOCKWORK_UPDATES_CACHE_TTL`) so the page doesn't hit GitHub on every load — **Check Again** bypasses the cache. A network failure or rate-limit degrades gracefully (shows a "could not reach update server" notice) rather than crashing the page. Same check runs via `php artisan clockwork:check-updates`.

## Applying an update

**Update Now** (or `php artisan clockwork:self-update`) runs a fixed pipeline, aborting and reporting exactly which step failed if anything goes wrong:

1. **Preflight**: `git status --porcelain` must be clean. Any uncommitted change in your working copy — even one you made by hand for local testing — blocks the whole update rather than risk clobbering it. This is the single most important safety property here: a self-hosted instance is somebody's real production checkout, and this pipeline runs unattended shell commands against it.
2. `git pull origin <current-branch>`
3. `composer install --no-dev --optimize-autoloader`
4. `php artisan migrate --force`
5. `php artisan optimize:clear`

Each step's output is captured and shown back to the operator, success or failure, so a broken update isn't a silent black box — you can see exactly which of the five steps it got through.

**Subprocess environment forwarding**: Steps 2 and 3 explicitly pass `HOME`/`COMPOSER_HOME` into the `git pull` and `composer install` subprocesses via `SystemUpdateService::subprocessEnv()` — preferring whatever the ambient environment already provides, and falling back to a Clockwork-owned `storage/app/subprocess-home` directory (created on demand) when neither is set. This exists because `php artisan serve` run without `--no-reload` strips almost every environment variable (including `HOME`) from its worker process, to support hot-reload-on-`.env`-change; without the explicit forward, Composer has nowhere to write its cache/config and the composer-install step fails purely as an artifact of which dev server happens to be in front of PHP. A real php-fpm/nginx deployment doesn't have this problem, but self-update works regardless of how the operator is running the app.

**This only works if the app is actually a git checkout.** If `.git` doesn't exist (e.g. you deployed via a tarball), the page still shows whether an update is available, but skips straight to migrations — there's no code to pull. In that case, use the manual terminal command shown on the page (`git clone`... doesn't apply; you'd `composer install --no-dev && php artisan migrate --force && php artisan optimize:clear` after replacing the files yourself).

## Why it uses Laravel's `Process` facade, not raw Symfony `Process`

Every shell command here goes through `Illuminate\Support\Facades\Process` rather than `Symfony\Component\Process\Process` directly, specifically so it's fakeable in tests via `Process::fake()`. This matters more than it sounds: without a fake, a test that exercises the apply-update path would run a **real** `git pull` and `composer install` against whatever checkout `./vendor/bin/pest` happens to run in — which, for a self-hosted single-instance app like this, is very likely your actual production directory. `tests/Feature/Controllers/SystemUpdatesControllerTest.php` fakes every process invocation for exactly this reason; if you touch `SystemUpdateService`, keep it that way.

## Authorization

Gated behind the same `auth` middleware group as the rest of the app — any allowlisted user can trigger a self-update, matching this app's existing no-admin-tier model (see [Architecture → Security model](/docs/architecture/security-model)). `clockwork:self-update` is **not** scheduled anywhere; it only ever runs when an operator clicks the button or runs the command by hand.

## Config

The installed version itself is deliberately **not** an env var — it's `config('clockwork.version')`, a literal string bumped in `config/clockwork.php` at each release (see [RELEASING.md](/RELEASING.md)). Env-backing it would let a self-hoster's `.env` silently pin the reported version forever, since updates never touch `.env` by design.

| Env var | Default | Notes |
|---|---|---|
| `CLOCKWORK_UPDATE_CHANNEL` | `stable` | Cosmetic label on the page; doesn't filter releases yet. |
| `CLOCKWORK_UPDATE_REPO` | `Clockwork-Web-Dev-LLC/clockwork-control` | Used to build the default releases API URL. |
| `CLOCKWORK_UPDATES_API_URL` | (derived from the repo above) | Full override. |
| `CLOCKWORK_UPDATES_CACHE_TTL` | `43200` (12h) | Release-check cache. |
| `CLOCKWORK_COMPANION_VERSION` | `1.34.0` | Bundled Companion version, drives the fleet-rollout numbers on this page. |

See [Reference → Environment variables](/docs/reference/env-vars#system-updates).

## What's NOT here

- **No rollback.** If an update breaks something, recover the same way you would from any bad `git pull` — `git log`, `git reset`, redeploy. This page doesn't snapshot anything before applying.
- **No scheduled/automatic self-update.** Fully operator-triggered, on purpose — nobody wants their monitoring tool silently updating itself at 3am.
- **No cross-instance rollout.** If you run more than one Clockwork Control instance, each updates independently; there's no fleet concept for the app updating itself the way there is for Companion.
