---
title: Google OAuth
section: Integrations
order: 50
updated: 2026-09-09
author: Aaron Reimann
tags: [integrations, auth, google, github, microsoft, oauth]
tracks: [app/Http/Controllers/Auth/**, app/Services/Auth/**, app/Http/Controllers/LoginController.php, modules/AuthGoogle/src/**, modules/AuthGitHub/src/**, modules/AuthMicrosoft/src/**, modules/Core/src/Contracts/AuthProvider.php, config/services.php]
---

Google OAuth is the original and still primary SSO provider — this page keeps its name and most of its detail for that reason — but it's no longer the only one, and OAuth itself is no longer the only way in. GitHub and Microsoft (365 / Azure AD / Entra ID) ship as their own auth modules alongside Google. All three implement `Modules\Core\Contracts\AuthProvider` and share one login flow: OAuth handles "is this person who they say they are," the `users` table handles "should they be allowed in," and `App\Services\Auth\OAuthLoginHandler` enforces the allowlist + logs the audit trail identically regardless of which provider they signed in with. No auto-provisioning on any of the three — a first-time OAuth user is denied unless someone added them first.

Local email/password sign-in also exists now, handled directly by `LoginController::login()` (`POST /login`), and is the always-available fallback: `/login` always renders the local sign-in form, with OAuth provider buttons appended below a divider only when at least one is actually configured. This page stays focused on the Google OAuth path; see [Architecture → Request lifecycle](/docs/architecture/request-lifecycle#login-local-password--modular-oauth) and [Architecture → Security model](/docs/architecture/security-model) for the local-password flow and the full picture of both auth paths together.

`LoginController::show()` only lists an OAuth provider once its credentials are actually configured (`AuthProvider::isConfigured()`) — an installed-but-uncredentialed module simply doesn't show a button, rather than rendering one that fails when clicked.

## Why we use OAuth at all

Everyone on the team already has a Google account (the agency runs Google Workspace); GitHub and Microsoft cover contributors/agencies who'd rather sign in with those instead. Building our own password store would be more attack surface, more recovery flows, more calls to me. OAuth + an allowlist keeps the auth surface tiny and each provider's own security posture (Workspace 2FA, GitHub 2FA, Entra Conditional Access) protects the app for free.

## Setup

1. Create an OAuth 2.0 Client at `console.cloud.google.com/apis/credentials` (type: Web application).
2. Add an Authorized redirect URI matching `GOOGLE_REDIRECT_URI` **exactly**. Multiple URIs are allowed — register `http://localhost:8000/auth/google/callback` for dev plus the LAN hostname callback for the team.
3. Set in `.env`:

   ```
   GOOGLE_CLIENT_ID=...
   GOOGLE_CLIENT_SECRET=...
   GOOGLE_REDIRECT_URI=http://localhost:8000/auth/google/callback
   GOOGLE_HD=             # optional: pin to a Workspace domain
   ```

4. Add yourself to the allowlist:

```bash
php artisan clockwork:add-user you@example.com --name="Your Name"
```

5. Visit `/login`, click "Sign in with Google."

## Auth

`laravel/socialite` Google driver. Standard OAuth 2.0 flow:

- Authorize → `accounts.google.com/o/oauth2/auth`
- Token exchange → `oauth2.googleapis.com/token`
- Userinfo → `www.googleapis.com/oauth2/v2/userinfo`

`GOOGLE_HD` (the `hd` parameter — "hosted domain") restricts the Google account picker to a Workspace domain (e.g. `your-agency.com`). Off by default so personal Google accounts work for testing.

## Endpoints we expose

| Method | Path | Purpose |
|---|---|---|
| GET | `/login` | Renders the local email/password form, plus a configured-provider's OAuth button below a divider. |
| POST | `/login` | Local password sign-in (`LoginController::login()`), rate-limited `throttle:5,1`. |
| GET | `/auth/google/redirect` | Kick off the Socialite flow. |
| GET | `/auth/google/callback` | Where Google sends the user back. Allowlist check happens here. |
| GET | `/auth/github/redirect` / `/auth/github/callback` | Same shape, GitHub. Routes registered by `Modules\AuthGitHub\GitHubAuthServiceProvider::boot()` — module-owned, gated on the module being enabled. |
| GET | `/auth/microsoft/redirect` / `/auth/microsoft/callback` | Same shape, Microsoft. Routes registered by `Modules\AuthMicrosoft\MicrosoftAuthServiceProvider::boot()`. |

All are public (the only public routes besides `/login`). Google's pair is the one exception to "module-owned" — they're still declared in core `routes/web.php` pointing at `GoogleAuthController`, not self-registered by `Modules\AuthGoogle` the way the other two are. Functionally equivalent today, but worth knowing if you're looking for where to add a fourth provider — copy the GitHub/Microsoft module-owned-routes pattern, not Google's.

## Files

- `modules/AuthGoogle/src/GoogleAuthProvider.php`, `modules/AuthGitHub/src/GitHubAuthProvider.php`, `modules/AuthMicrosoft/src/MicrosoftAuthProvider.php` — each implements `AuthProvider`: builds its Socialite driver (Google/GitHub) or hand-rolled OAuth2 flow (Microsoft — no Socialite driver used there), does the provider-side redirect/callback dance, then hands off to `OAuthLoginHandler`.
- `app/Services/Auth/OAuthLoginHandler.php` — shared allowlist check, `users` row sync (name/avatar/provider id/`last_login_at`), session login, and audit log entry. One implementation, all three providers call it.
- `app/Http/Controllers/Auth/GoogleAuthController.php` — thin `redirect()`/`callback()` delegator to `GoogleAuthProvider`. GitHub/Microsoft have their own equivalent controllers living inside their modules instead of `app/Http/Controllers/Auth/`.
- `app/Http/Controllers/Auth/LoginController.php` — `show()` renders `/login` (local form always, OAuth buttons filtered to configured providers only); `login()` handles local `POST /login`; `logout()` handles `/logout`.
- `app/Console/Commands/AddUser.php` — bootstrap + recovery escape hatch; takes an optional `--password=` to set a local password on creation/restore.
- `app/Console/Commands/SetPassword.php` — `clockwork:set-password` CLI recovery path (masked prompt or `--password=`) for setting/resetting an existing operator's local password.
- `app/Http/Controllers/UsersSettingsController.php` — `/settings/users` UI.
- `database/migrations/2026_09_04_000003_add_oauth_provider_ids_to_users_table.php` — adds `github_id`/`microsoft_id` alongside the existing `google_id`.
- Config: `config/services.php` → `google`/`github`/`microsoft` keys, or set per-instance via `/settings/integrations` (resolved through `CredentialResolver`, DB first).

## Allowlist semantics

The `users` table IS the allowlist, for OAuth and local password sign-in alike:

- A row exists ⇒ allowed.
- `revoked_at IS NULL` ⇒ active.
- No matching row, or revoked ⇒ OAuth callback bounces back to `/login` with a denial banner; local login gets an equivalent "account has been revoked" message.
- A row with `password IS NULL` is SSO-only — attempting local password login on it returns a message pointing the user at their configured OAuth provider (or asking an admin to set a local password).
- **No auto-provisioning.** A first-time Google (or GitHub/Microsoft) user is denied unless someone added them first.

## Bootstrap and recovery

```bash
# Add or restore a user (idempotent — restores revoked rows), optionally with a local password:
php artisan clockwork:add-user alice@example.com --name=Alice --password=secret

# Set or reset an existing operator's local password (masked prompt, or --password=):
php artisan clockwork:set-password alice@example.com

# UI version (administrators only):
# /settings/users  — add, revoke, restore, and set/reset a local password
```

`clockwork:add-user` and `clockwork:set-password` are the always-works escape hatch when the UI is locked out — useful now that local password is a valid recovery path even on an instance that only ever used OAuth.

## Audit trail

Every login + add/revoke/restore/password-change lands in `action_logs`:

- `TYPE_LOGIN`
- `TYPE_USER_ADDED`
- `TYPE_USER_REVOKED`
- `TYPE_USER_RESTORED`
- `TYPE_USER_PASSWORD_CHANGED`

Surfaces on `/maintenance-history`.

## Gotchas

- **Redirect URI must match exactly.** Google's error message is unhelpful — usually a wrong port, missing `/callback`, or http vs https.
- **Revoke and password change kill live sessions.** `User::invalidateSessions()` cycles `remember_token` and deletes stored database sessions; `EnsureUserIsActive` logs a revoked operator out on the next request.
- **Revoke-self is blocked at the controller.** The `clockwork:add-user` / `clockwork:set-password` commands + restore flow are the always-works recovery if the team accidentally locks themselves out.
- **`GOOGLE_HD` is enforced on callback.** The account picker's `hd` parameter is a hint; `GoogleAuthProvider::handleCallback()` also requires the token's `hd` claim to match.
- **A `NULL` password isn't a broken account, it's SSO-only by design.** Don't "fix" it by setting a random password unless you actually want to offer that user a local-login fallback.
