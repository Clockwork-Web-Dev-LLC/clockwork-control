# Plan: Web-based installer (`/install`)

## Context

Clockwork Control is being released as a product other agencies will self-host. Today, standing up
a fresh instance requires: manually creating a MySQL database, running `composer setup` (which
copies `.env.example`→`.env`, runs `key:generate`, `migrate --force`, builds assets), manually
setting `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` in `.env` (required for any login at all, since
auth is Google OAuth against a `users` allowlist table with no self-registration), and running
`php artisan clockwork:add-user email --name=...` from the CLI to create the first login. There is
no automation for any of this, and no seeder creates a default admin. This plan replaces that with
a guided, modern-looking web wizard.

Note: this is **not** about the existing `/setup` page (`SetupController`) — that already exists,
runs post-login, and handles hosting-provider/integration onboarding well via a service-toggle
grid. It stays exactly as-is. This plan only covers the pre-auth gap that happens before anyone can
even log in.

## Key decision: lives at `app/Installer/` + `routes/install.php`, NOT `modules/Installer/`

Every real module (`modules/{Name}/`) is a Composer path-repository package (own `composer.json`,
PSR-4 namespace, registered via a `repositories` path entry with `"symlink": true`) whose
`{Name}ServiceProvider extends Modules\Core\ModuleServiceProvider`. That base class's `enabled()`
gate resolves through `ModuleStateResolver`, which assumes `CoreServiceProvider` already booted
against a **working database connection** (`AppSetting`/config-backed state). The installer's
entire purpose is to run *before* any of that is true — modeling it as "just another module, gated
by `enabled()` instead of a toggle" is dishonest and will crash against an unconfigured DB.

It also isn't a pluggable, swappable, multi-vendor concern the way a hosting-provider or
notification-channel module is (the two "blessed" module recipes documented in `CONTRIBUTING.md`).
It is mandatory, permanent, and structurally closer to `SetupController` — a plain
`App\Http\Controllers` controller — than to a provider integration. So: it's "built in" in the
sense that matters (ships with every install, cannot be disabled/uninstalled, is a first-class
subsystem with its own directory), but is honestly not shaped like the plugin-style provider
modules. State this explicitly in the code/docs rather than forcing a bad architectural fit.

## The core technical problem: booting before the app is configured

- **`.env.example` must change** to ship a real, valid placeholder `APP_KEY` (not blank), plus
  `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`. Today's defaults
  (`DB_CONNECTION=mysql` pointing at a real DB, `SESSION_DRIVER=database`) mean the app cannot boot
  and serve HTTP at all pre-install. With these placeholder defaults, a bare `composer install`
  (no `.env` edits, no DB) produces an app that boots, has working sessions/CSRF, and can serve the
  installer itself.
- New `App\Http\Middleware\EnforceInstallerGate`, registered globally in `bootstrap/app.php`'s
  `withMiddleware()`. The "installed" check is: **does `storage/installed` (a plain sentinel file)
  exist?** Deliberately not "is APP_KEY set" or "are there any users" — both require a DB
  connection, which may not exist yet; a filesystem check is the one thing guaranteed to always
  resolve. Sentinel absent → every request except `/install/*` (and assets) redirects to
  `GET /install`. Sentinel present → everything under `/install/*` returns a hard **404**, not a
  redirect — this closes the "someone bookmarked a deep wizard-step URL" hole outright.
- `routes/install.php` — new file, registered unconditionally in `bootstrap/app.php`'s
  `withRouting()` as a sibling to `web`/`console`/`health` (always loaded; the middleware is what
  gates it, not conditional route registration).
- The database step tests the connection **live**, before writing anything: runtime
  `config(['database.connections.mysql' => [...user input...]])` + `DB::purge('mysql')` +
  `DB::connection('mysql')->getPdo()` inside a try/catch, entirely in-memory. Only a successful
  test unlocks writing to `.env`.
- New `App\Installer\InstallerEnvWriter` — atomic `.env` write, then reflects into
  `putenv`/`$_ENV`/`$_SERVER`/`config()` for the running process, then `chmod(0600)`. This mirrors
  the technique `App\Support\EnvCredentialManager` already uses for provider secrets, but is its
  own small class — `EnvCredentialManager`'s `DEFINITIONS` array is oriented around
  already-configured-app secrets and doesn't cover first-boot keys like `APP_KEY`/`DB_*`/session
  driver.
- Once DB env is written: `Artisan::call('migrate', ['--force' => true])` runs in-process (no
  shell-out). Then the sentinel file is written.
- The success screen explicitly tells the operator: "If you deployed with `config:cache`, run
  `php artisan config:clear` (or restart php-fpm) now" — surfaced as visible instructions, not
  silently assumed to be unnecessary.

## Wizard steps

One controller, `App\Http\Controllers\InstallerController`, one action per step (mirrors
`SetupController`'s existing single-controller/multiple-actions shape):

1. **Welcome + requirements check** — PHP version, required extensions (`pdo_mysql`, `openssl`,
   `mbstring`, `fileinfo`), `storage/`+`bootstrap/cache/` writability. If `storage/installed`
   already exists, redirect straight to the done screen. No writes at this step.
2. **Database connection** — host/port/db/user/pass form; "Test Connection" is an AJAX
   `POST /install/database/test` (Alpine `fetch`) using the live-test technique above; "Continue"
   stays disabled until a test succeeds. On submit: writes `DB_*` via `InstallerEnvWriter`, runs
   `migrate --force`.
3. **App identity** — app name, `APP_URL` (defaulted from the current request host), timezone.
   Generates a real `APP_KEY` (`Illuminate\Encryption\Encrypter::generateKey()`), replacing the
   placeholder; flips `SESSION_DRIVER`/`CACHE_STORE`/`QUEUE_CONNECTION` to `database` now that a
   DB exists.
4. **Mail (optional, skippable)** — mailer/host/port/user/pass/from address, with a prominent
   "Skip for now" link (leaves `MAIL_MAILER=log`), and an AJAX "Send test email" mirroring the
   DB test-connection UX pattern.
5. **Google OAuth** — client ID + secret. **Required, cannot be skipped** — blocks all login.
   Inline help text linking to Google Cloud Console credential setup. Writes
   `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET`/`GOOGLE_REDIRECT_URI` (derived from `APP_URL`).
6. **First admin user** — email + display name. Must produce an identical result to
   `php artisan clockwork:add-user`. Extract that command's logic into a new
   `App\Support\UserProvisioner::addOrRestore(email, name)` service, used by both the Artisan
   command and the installer controller (cleaner to test than calling `Artisan::call()` from HTTP
   code). Update `app/Console/Commands/AddUser.php` to call the extracted service.
7. **Hosting provider quick-connect (optional)** — reuse `SetupController`'s existing
   service-toggle grid/partial rather than re-implementing it. A "Skip — I'll do this after login"
   link is present. Selecting a provider here just marks intent so the finish step deep-links to
   `/setup` after login instead of the dashboard; it does not duplicate the credential-entry modal.
8. **Review** — read-only recap of every value from steps 2–7, secrets masked. **All step input
   accumulates in the session only** (a single `install.wizard` array keyed by step) — nothing is
   written to `.env`/DB/sentinel until this step's single "Install" submit. This makes every prior
   step a no-op if abandoned: refresh or back-navigation just re-renders from session state, and a
   half-finished attempt leaves no `.env` changes, no partial migration, no sentinel file.
9. **Success** — CSS-only confetti animation (a handful of absolutely-positioned spans with
   staggered `@keyframes`, respecting `prefers-reduced-motion`), "Go to Login" button, and the
   config-cache-clear reminder. `storage/installed` is written as part of step 8's confirm; from
   this point on the gate 404s `/install/*` on every future visit.

## Re-entry / disaster-recovery safety

`storage/installed` is the sole, always-resolvable gate. To deliberately reopen the installer
(e.g. disaster recovery), a new **CLI-only** command,
`php artisan clockwork:installer:reopen` — interactive confirmation prompt, `--force` required for
non-interactive use, logs the action via the existing `ActionLogger` (same as `AddUser` does).
Deliberately no env-flag alternative — a stray env var left set in production would otherwise
silently reopen the installer to the public internet.

## UI

New minimal layout `resources/views/layouts/install.blade.php` (distinct from the authenticated
`layouts/app.blade.php`), built from the existing brand tokens already defined in
`resources/css/app.css` (`--color-brand`, ink/surface scale, `.card`). A server-rendered stepper
rail partial (`resources/views/install/_stepper.blade.php`) — numbered circles, current step in
brand color, completed steps checkmarked, upcoming steps muted; no client JS needed for the rail
itself. Per-step content animates in via Alpine `x-transition` (fade + slight slide, consistent
with the existing subtle-motion feel of `.btn-pill-nav` hovers) — each step is still a real
page load/route (server-side validation stays authoritative), only the card's entrance animates.
AJAX test buttons (DB connection, mail send) use small Alpine components
(`x-data="{ testing:false, ok:null, message:'' }"`) rendering spinner → green check / red X,
styled with the existing `--color-status-*` tokens.

## Security

- `throttle:10,1` on the whole `routes/install.php` group, plus the default `web` middleware
  group (session + CSRF); every step form uses `@csrf`.
- A dedicated exception-rendering path for `/install/*` in `bootstrap/app.php`'s
  `withExceptions()`: any throwable renders a generic branded error page, **never** Laravel's
  debug/Whoops output, regardless of `APP_DEBUG` — installer pages are reachable by definition by
  someone who hasn't proven any identity.
- `.env` gets `chmod(0600)` immediately after every atomic write.
- No secrets are ever round-tripped through hidden form fields or query strings across steps —
  session storage only, server-side.

## Files

**New:**
- `app/Http/Middleware/EnforceInstallerGate.php`
- `app/Http/Controllers/InstallerController.php`
- `app/Installer/InstallerEnvWriter.php`
- `app/Support/UserProvisioner.php`
- `app/Console/Commands/ReopenInstaller.php`
- `routes/install.php`
- `resources/views/layouts/install.blade.php`
- `resources/views/install/{welcome,database,app,mail,google,admin,hosting,review,done}.blade.php`
- `resources/views/install/_stepper.blade.php`
- `resources/docs/getting-started/installation.md` (new primary documented install path; keep
  today's manual steps documented as an "advanced/headless" alternative)

**Modified:**
- `bootstrap/app.php` (register middleware + `install` route file)
- `.env.example` (placeholder `APP_KEY`, file/sync drivers)
- `app/Console/Commands/AddUser.php` (calls the extracted `UserProvisioner`)
- `tests/Feature/ArchitectureTest.php` (add a rule: `App\Installer` classes never import
  `Modules\*` — keeps the "not a module" boundary honest)
- `CONTRIBUTING.md` (short section: "why the installer isn't a module")
- `resources/css/app.css` (small additive confetti-keyframes block only — no token changes)

## Verification

New `tests/Feature/Installer/` suite:
- **Gate test** — fresh app (no `storage/installed`) redirects any route to `/install`; once the
  sentinel exists, `/install` and every known sub-step route return 404.
- **Database step test** — bad credentials fail the AJAX test-connection endpoint with a clean JSON
  error and no stack trace; good credentials pass, and `.env` only contains the submitted `DB_*`
  values after the final review-step confirm (not before).
- **Env-write test** — exact `.env` keys/values written per step, asserted against a temp `.env`
  path (mirror how `EnvCredentialManager`'s own tests inject a temp env path).
- **Admin-user step test** — installer-created admin is asserted equivalent (row shape, allowlist
  behavior, `revoked_at` null) to one created via `Artisan::call('clockwork:add-user', ...)`,
  proving the shared `UserProvisioner` path.
- **Re-entry test** — refreshing/re-GETting an earlier step after later steps are filled preserves
  session data without erroring; abandoning mid-wizard leaves no `.env` changes and no sentinel.
- **Reopen-command test** — `clockwork:installer:reopen --force` removes the sentinel and the gate
  redirects again; without `--force` it's a no-op.

**Manual end-to-end:** spin up a throwaway MySQL database, use a fresh clone with `.env` deleted
(only `.env.example` present), run `composer install` only (no `composer setup` — proving the app
boots on placeholder env alone), `php artisan serve`, hit `/`, confirm redirect to `/install`, walk
all 9 steps (including an intentionally wrong DB password on step 2, to see the inline failure
state), finish, confirm `/login` works via Google OAuth with the entered client ID/secret, confirm
`/install` now 404s, then run `clockwork:installer:reopen --force` and confirm it's reachable again.
