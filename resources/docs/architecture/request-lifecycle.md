---
title: Request lifecycle
section: Architecture
order: 40
updated: 2026-09-05
author: Aaron Reimann
tags: [architecture, http, auth, middleware]
tracks: [routes/web.php, app/Http/Controllers/Auth/**, app/Http/Middleware/**]
---

What happens between a click in the browser and a Blade response. Mostly stock Laravel — the noteworthy bits are the auth gate and the docs path-traversal guard.

## The path

```
Browser
  ↓
Herd nginx → php-fpm
  ↓
Laravel HttpKernel
  ↓ (default middleware: cookies, session, CSRF, etc.)
RouteServiceProvider
  ↓
Route group: middleware(['auth'])  ← single chokepoint
  ↓
Controller method
  ↓
Service / Model / external client
  ↓
Blade view
  ↓
Response → Browser
```

## The auth gate: core routes vs. module routes

Every core-app route except the auth flow sits inside one `Route::middleware(['auth'])->group(...)` in `routes/web.php`. New core routes go INSIDE the closure — there's no other path in for them.

```php
// routes/web.php
Route::get('/login', ...);                     // public
Route::get('/auth/google/redirect', ...);      // public
Route::get('/auth/google/callback', ...);      // public

Route::middleware(['auth'])->group(function () {
    // every other core route
});
```

If you find yourself writing a **core-app** route outside that group, stop and re-read the [security model](/docs/architecture/security-model). There's almost certainly a reason it should be inside.

**Module routes:** Modules with their own settings pages (Mattermost, Slack, Bill.com, ClientSlack, …) load routes from their own `routes/web.php` via `loadRoutesFrom()` in their `ServiceProvider::register()`, and — because `loadRoutesFrom()` doesn't inherit the app's route-group middleware automatically — each module explicitly wraps its own routes in `Route::middleware(['web', 'auth'])->group(...)` itself (e.g. `modules/Mattermost/routes/web.php`). The auth gate still applies everywhere; it's just enforced per-module now instead of solely by the one `routes/web.php` closure. A new module's settings routes need to remember this wrapper themselves — nothing enforces it structurally, so a module that forgets it would silently expose an unauthenticated route.

## Login is Google OAuth

The public flow:

1. User clicks "Sign in with Google" on `/login`. POST through `LoginController` redirects to `/auth/google/redirect`.
2. `GoogleAuthController::redirect` hands off to `Laravel\Socialite` Google driver, which 302s to `accounts.google.com`.
3. Google authenticates the user and 302s back to `/auth/google/callback?code=...`.
4. `GoogleAuthController::callback` exchanges the code for a token, fetches the user info, and looks up the email in `users`.
5. Match + not revoked → log them in, redirect to `/`. No match (or revoked) → bounce back to `/login` with a denial banner. **No auto-provisioning** — an administrator adds users via `/settings/users` or `clockwork:add-user`.

Optional Workspace pinning: setting `GOOGLE_HD=your-agency.com` causes Google to limit the account picker to that domain. Off by default so personal accounts work for testing.

Login + add/revoke/restore events all land in `action_logs` (`TYPE_LOGIN`, `TYPE_USER_ADDED`, `TYPE_USER_REVOKED`, `TYPE_USER_RESTORED`).

## Authenticated requests

Inside the auth group, requests pass through Laravel's stock middleware stack: cookies, encrypted sessions, CSRF, view-share, etc. There are no custom global middleware beyond `auth`.

A handful of route-level constraints are worth knowing about:

- **Tab-aware detail pages** — `/servers/{server}/{tab?}` and `/sites/{site}/{tab?}` use `whereIn('tab', [...])` to constrain the segment. Other `/servers/{server}/*` routes (edit, test, provision) still resolve normally because Laravel falls through when the constraint doesn't match.
- **Docs slug whitelist** — `/docs/{path}` uses `where('path', '[a-z0-9\-/]+')`. Combined with `DocsManifest::normalizeSlug()` (which also rejects `..` and any non-matching string) and a `realpath`-prefix check, that's three independent layers of path-traversal defense.
- **Bookmark redirects** — `/review` and `/blocked-ips` 301 to `/bans/queue` and `/bans/active`. The mutation endpoints (`/review/{entry}/approve` etc.) keep their old paths because forms in dashboard partials still post to them.

## Controllers stay thin

The pattern across the app: controllers do request validation, call a service, and render a view. Anything non-trivial lives in `app/Services/*`. A few controllers are large because they handle a lot of routes (`SitesController` covers ~20), but each method follows the same shape.

There is no JSON REST API. Some routes return JSON fragments for AJAX (search, size-estimate, on-demand polls), but the app is fundamentally a server-rendered web app. Forms submit, controllers redirect.

## Sessions and CSRF

- Sessions are database-backed (`SESSION_DRIVER=database`).
- CSRF is enforced by Laravel's stock `VerifyCsrfToken` middleware. Every form has `@csrf`.
- POST endpoints are POST-only; we don't accept method-spoofing for state-changing actions across origins because there's no cross-origin context.

## Local dev

```bash
export PATH="$HOME/Library/Application Support/Herd/bin:$PATH"
php artisan serve   # http://127.0.0.1:8000
```

Or use Herd's `.test` resolution if you've configured the project there. Both work fine for the OAuth callback as long as the `GOOGLE_REDIRECT_URI` env var matches what's registered in Google Console.

## Where to read next

- [Security model](/docs/architecture/security-model) — encrypted columns, dual CF tokens, HMAC, allowlist.
- [System overview](/docs/architecture/system-overview) — the layers above the lifecycle.
