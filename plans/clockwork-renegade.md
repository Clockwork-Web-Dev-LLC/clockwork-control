# Plan: Clockwork Renegade — a wordpress.org-submitted companion plugin

Supersedes [`archive/companion-self-update.md`](./archive/companion-self-update.md) and the earlier
draft of this file (which assumed a self-hosted PUC update mechanism — moot once the destination is
wordpress.org, since **Guideline 8** of the plugin directory's detailed guidelines
(`developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/`) explicitly bans serving
updates "from servers other than WordPress.org's." Once listed, WordPress core's own updater is the
entire update mechanism — no plugin code needed for that part at all).

## Naming

Two separate, differently-licensed products going forward:

- **Clockwork Companion** (existing, MIT, unchanged) — the full-featured agent for the SSH/API-managed
  fleet (SpinupWP, Pressable, GridPane). Never distributed publicly; Control pushes it directly.
- **Clockwork Renegade** (new) — a GPL-2.0-or-later plugin submitted to the wordpress.org directory,
  scoped to what a ManageWP-Worker-style "connects your site to a central dashboard" plugin can
  actually contain per wordpress.org's rules. Name is a throwback to the Renegade BBS software; no
  functional meaning. Slug: `clockwork-renegade`. PHP namespace: `ClockworkRenegade\`. REST namespace:
  `clockwork-renegade/v1`. Text domain: `clockwork-renegade`.

## Why ManageWP Worker is the right benchmark, and where Companion's current surface fails it

ManageWP's own Worker plugin is a real, currently-listed wordpress.org plugin occupying the exact same
product category this is going for: a lightweight agent that pairs a site with a central fleet
dashboard and exposes remote-triggered updates, backups, security scanning, uptime data, one-click
login, and comment moderation. Its existence is itself proof that category is acceptable — Guideline 6
("Software as a Service") explicitly allows a plugin whose entire purpose is acting as an interface to
an external service, provided the service is "clearly documented in the readme file... with a link to
the service's Terms of Use," and Guideline 7 requires "explicit and authorized consent" before any
server contact, documented in the readme.

**Verified against the actual `src/Rest/` route inventory in `/Users/aaronr/Projects/clockwork-companion`,**
almost everything already fits that model cleanly — one route is a flat disqualifier.

### Keep (maps directly onto the ManageWP Worker feature category)

| Route | What it does | Why it's fine |
|---|---|---|
| `HealthRoute`, `DetectRoute`, `PluginsRoute`, `ThemesRoute`, `AdminsRoute`, `CronRoute`, `SnapshotRoute`, `ResourceReportRoute`, `ResourceSamplerConfigRoute`, `CommentsSummaryRoute`, `WordfenceBlocksRoute`, `LockoutsRoute`, `TwoFactorStatusRoute`, `FormSubscriptionsRoute` | Read-only status/inventory reporting | Pure telemetry — no different in kind from what any monitoring agent (or a client checking their own Site Health screen) already exposes |
| `BackupsReportRoute`, `TrafficReportRoute`, `ActionLogAppendRoute`, `SecuritySummaryReportRoute` | Inbound: Control pushes data *to* the site for local display | Not remote execution at all — the site is the receiver |
| `PluginUpdateRoute`, `ThemeUpdateRoute`, `CoreUpdateRoute` | Trigger WP core's own `Plugin_Upgrader`/`Theme_Upgrader`/`Core_Upgrader` | These only ever pull from wordpress.org's own servers — Renegade is just the remote trigger for the exact same action a click in wp-admin's Updates screen already does. Satisfies Guideline 8: the *source* is still wordpress.org, only the *trigger* is remote — exactly what ManageWP Worker itself does |
| `PostUpdateVerifyRoute`, `MaintenanceModeRoute`, `CacheFlushRoute` | Bounded, well-defined site operations | Standard fleet-management actions, no arbitrary input |
| `CommentsActionRoute` | List/moderate/cleanup comments | ManageWP Worker has an equivalent comment-moderation feature |
| `MalwareScanRoute` | Runs an in-plugin scanner, returns findings | Local analysis, not code execution — same category as Wordfence/Sucuri's own remote-triggerable scans |
| `BrandingRoute` | White-label config | Cosmetic, same as ManageWP's white-label feature |
| `SecretRotateRoute` | Rotates the site's *own* HMAC secret | Self-contained, no external input executed |
| `SsoRoute` | Mints a one-time magic login link, consumed locally | This is precisely ManageWP Worker's well-known "one-click login" feature |
| `TwoFactorMigrateRoute` | Migrates one user from WFLS to native 2FA | Bounded, single well-defined operation |
| `TestContactFormRoute` | Submits a test lead through the site's own contact form | Bounded, no arbitrary code |
| `BackupCreateRoute`, `BackupRestoreRoute` | S3-backed backup/restore | Same category as ManageWP's own backup feature — needs the S3 destination disclosed in the readme per Guideline 6 |

### Cut — do not carry into Clockwork Renegade at all

| Route | Why |
|---|---|
| `CodeSnippetRoute` | **Confirmed by full file read**: `handle()` takes `$params['code']` straight from the request body and runs `eval($code)` (the code's own comment: *"eval() still runs with full WordPress privileges — HMAC is the gate"*). No allowlist, no predefined-operation constraint — genuinely unrestricted remote PHP execution, gated only by a `CLOCKWORK_COMPANION_DISABLE_CODE_SNIPPETS` define (off by default) and HMAC auth. **A feature flag defaulting off is not sufficient for wordpress.org** — automated and manual review both flag raw `eval()` on remote input as close to an automatic rejection regardless of what gates it, because the gate itself is just more code in the same plugin. Delete the file and its `Plugin.php` registration entirely from the Renegade fork; it stays exclusive to the private, SSH-only Clockwork Companion. |

## Existing architecture that already fits the wordpress.org "connector plugin" model — reuse as-is

Two things confirmed already built, matching exactly what a submission needs, requiring no redesign:

1. **`src/Admin/Pages/ConnectionPage.php`** — its own docblock literally says *"ManageWP-style pairing
   screen."* The site generates its own HMAC secret on first load (`Secret::ensure()`), displays a
   base64 Connection Key (`{url, secret}`) for the site owner to copy into Control, and shows live
   connected/disconnected status. This already satisfies Guideline 7's "explicit and authorized
   consent" requirement structurally — the owner takes an affirmative, visible action to pair, nothing
   silent. No redesign needed, just carry it over and keep it accurate to Renegade's trimmed feature
   set.
2. **A real, substantial local admin UI already exists** beyond the connection screen:
   `ActivityPage`, `BackupsPage`, `FormsPage`, `NotificationsPage`, `PerformancePage`, `SecurityPage`,
   `TrafficPage`, `TwoFactorPage`, `UnlockPage`, `UptimePage`, `WhiteLabelPage`. This matters for
   Guideline 5 (trialware/sandbox-only plugins aren't allowed) — Renegade isn't a bare shell that only
   works when paired; there's real local functionality regardless of connection state.

## What's genuinely missing and needs to be built (not carried over from Companion, because Companion
## never needed it as a private mu-plugin)

Confirmed by grep: **zero** `register_activation_hook`/`register_deactivation_hook`/`register_uninstall_hook`
calls exist anywhere in the current codebase, and **zero** i18n wrapping (`__()`/`_e()`/`esc_html__()`)
exists anywhere — reasonable for an internal mu-plugin nobody but the agency ever saw, not acceptable
for public directory submission.

### 1. Activation / uninstall lifecycle

- `register_activation_hook(__FILE__, ...)` — set a transient redirecting the admin to the Connection
  page on first activation (standard, expected first-run UX; mirrors ManageWP Worker's own "click here
  to connect" flow).
- `uninstall.php` at the plugin root (WP core auto-runs this on delete, no hook registration needed) —
  must remove every option/table Renegade creates: the stored secret, `clockwork_companion_last_contact_at`
  (rename to a Renegade-prefixed option name during the fork), any 2FA/branding/notification settings
  persisted to `wp_options`. Reviewers expect complete cleanup; leaving orphaned options behind is a
  common rejection note.

### 2. Internationalization (i18n) — full pass required, not optional

Every user-facing string across all `src/Admin/Pages/*.php` files and any REST error messages surfaced
in the UI needs `__()`/`_e()`/`esc_html__()`/`esc_attr__()` wrapping with the `clockwork-renegade` text
domain, matching the `Text Domain` plugin header field exactly. This is a large, mechanical but
non-trivial workstream — budget real time for it, don't treat it as a footnote. `Domain Path: /languages`
in the header if a `.pot` file is bundled (optional at first submission; translate.wordpress.org
generates translations automatically once listed).

### 3. Nonces and capability checks — full audit required

Every admin-page form/action (rotate secret, disconnect, run a manual scan, etc.) needs
`wp_nonce_field()` + `check_admin_referer()`/`wp_verify_nonce()`, and every admin page and AJAX/REST
handler needs an explicit `current_user_can('manage_options')` (or narrower) check. Audit all of
`src/Admin/` and `src/Admin/Actions/`/`FormsAjaxHandlers.php`/`UpdatesRefreshAjaxHandler.php` for this —
don't assume "it's behind wp-admin" is sufficient; wordpress.org review checks for this explicitly.

### 4. Sanitize/escape audit — full pass required

Every REST route's input needs proper sanitization (`sanitize_text_field`, `absint`, explicit type
casts — most routes already cast/validate reasonably per the existing code review pattern seen
elsewhere in this codebase, but this needs a dedicated pass specifically against wordpress.org's stricter
bar) and every admin-page output needs escaping (`esc_html`, `esc_attr`, `esc_url`) at the point of
output, not just "trust it's already safe." Run the official **Plugin Check** tool
(`wordpress/plugin-check`, install via `wp plugin install plugin-check --activate` in a local dev site)
before submission — it automates a large fraction of this audit (i18n, escaping, deprecated functions,
readme.txt format) and is what the wordpress.org review team itself runs first.

### 5. `readme.txt` (wordpress.org format — not `README.md`)

Standard sections: `=== Clockwork Renegade ===`, `Contributors`, `Tags`, `Requires at least`,
`Tested up to`, `Stable tag`, `Requires PHP: 8.0`, `License: GPLv2 or later`,
`License URI: https://www.gnu.org/licenses/gpl-2.0.html`, `== Description ==`, `== Installation ==`,
`== Frequently Asked Questions ==`, `== Screenshots ==`, `== Changelog ==`, `== Upgrade Notice ==`.

Must explicitly cover, per Guidelines 6 and 7:
- What external service this connects to (Clockwork Control), operated by whom, and a link to its
  Terms of Use / Privacy Policy — **open item: Aaron needs to provide/host a real public URL for this**
  (e.g. on clockworkcontrol.com); nothing here should link to a page that doesn't exist yet.
- Exactly what data is transmitted to that service (site health, plugin/theme inventory, traffic
  reports, backup contents to S3, etc.) and why.
- That connection requires the site owner's affirmative action (pasting the Connection Key into
  Control) — nothing happens before that.

Also wire `wp_add_privacy_policy_content()` on activation so WP core's built-in Privacy Policy Guide
picks up a blurb about this plugin's data collection automatically — increasingly expected, not just
nice-to-have, for a plugin in this category.

### 6. Plugin header additions

`clockwork-renegade.php` header needs fields the mu-plugin-only original never carried:
`Requires at least`, `Tested up to`, `Stable tag` (readme.txt only, must match `Version` in the header),
`License: GPLv2 or later`, `License URI`, `Text Domain: clockwork-renegade`.

### 7. `assets/` (SVN-only, not shipped in the plugin zip)

Banner (`banner-772x250.png`, optionally `banner-1544x500.png` retina), icon
(`icon-128x128.png`/`icon-256x256.png`), and numbered screenshots (`screenshot-1.png`, ...) matching
captions listed in readme.txt's `== Screenshots ==` section — these live in the SVN repo's `/assets/`
folder, separate from `/trunk/`, and never ship inside the installable zip.

## Repo setup — a clean, independent repository, not a visible fork

Per explicit instruction: this must not read as "Companion with things removed." Concretely:

- `git init` fresh at `~/Projects/clockwork-renegade` — **not** `git clone` from Companion, no shared
  git history, no `git subtree`/`filter-repo` history carryover. Files land via a plain filesystem copy
  of the kept subset (everything in the "Keep" table above, plus `src/Auth/`, `Admin/`, the connection
  flow, and the new lifecycle/i18n/readme work), then a normal `git add` + a single clean initial
  commit.
- New, standalone `README.md` (dev-facing, separate from `readme.txt`) and commit messages describing
  this as what it is going forward — a distinct product — not narrating "forked from Companion" or
  referencing the private repo's internal history anywhere in committed, published material. (This
  plan file itself, and internal engineering conversation, can and should be honest about the lineage —
  that's not what's being hidden; what's being avoided is publishing Companion's actual commit history
  or internal references inside the public repo.)
- New GitHub repo `Clockwork-Web-Dev-LLC/clockwork-renegade` — visibility is Aaron's call, independent
  of the wordpress.org submission itself (the wordpress.org SVN repo is the canonical distribution
  point regardless of whether the GitHub mirror is public or private).
- `LICENSE`: GPL-2.0-or-later text, `Copyright (c) 2026 Clockwork Web Dev, LLC`.
- `composer.json`: `"name": "clockwork/renegade"`, PSR-4 `ClockworkRenegade\` → `src/`. No runtime
  Composer dependencies (matching Companion's own dependency-light model) — nothing here needs PUC or
  any other library now that self-hosted updates are off the table.
- Copy `.githooks/` (client-identifier denylist + gitleaks pre-commit hook) and re-enable via
  `git config core.hooksPath .githooks` — still worth keeping even though this repo is meant to be
  public, as a backstop against an accidental leak during the copy/edit process.

## SVN release pipeline

wordpress.org's actual distribution point is Subversion (`plugins.svn.wordpress.org/clockwork-renegade/`),
not git — `trunk/` (the current release), `tags/{version}/` (immutable per-release snapshots), and
`assets/` (banner/icon/screenshots, outside `trunk/`). Standard, low-friction pattern: develop entirely
in git as above, and mirror only tagged releases to SVN via an automated step rather than hand-running
`svn` commands per release:

- A GitHub Action (several off-the-shelf ones exist for exactly this — e.g. a git→SVN plugin-deploy
  action) triggered on a git tag push: checks out the tag, copies the built plugin tree into
  `trunk/` and a matching `tags/{version}/` in the SVN working copy, commits, and pushes via `svn`.
  `assets/` is updated independently (it doesn't change every release).
- First-ever submission is manual regardless (wordpress.org's plugin review process for a brand-new
  plugin requires a manual submission + human review before an SVN repo is even provisioned) — the
  automation only matters starting with the *second* release onward.
- `readme.txt`'s `Stable tag` must point at the current release tag; wordpress.org serves whatever
  `Stable tag` names, not necessarily `trunk`.

## Control repo changes (much smaller now that self-hosted updates are out of scope)

Control's role shrinks to pairing/detection only — it does **not** need to serve any update manifest or
zip for Renegade; wordpress.org is the sole distribution and update channel.

- `app/Models/Site.php`: add `companion_variant` (`'companion'|'renegade'`, nullable, default
  `'companion'`) — same pattern as `companion_capabilities`/`companion_version`
  (`Site.php` lines 70-73 docblock, 286-289 `$fillable`). New migration mirroring
  `database/migrations/2026_05_02_110000_add_companion_and_contact_form_fields_to_sites_table.php`'s
  shape.
- `ClockworkCompanionClient::ROUTE_NAMESPACE` (currently a single `public const`,
  `ClockworkCompanionClient.php:28`, used at the three URL-building/signing call sites) becomes an
  instance property resolved from `$site->companion_variant` (`'renegade' → 'clockwork-renegade/v1'`,
  default `'clockwork/v1'`).
- Enrollment (`SitesController::store()`, `app/Http/Controllers/SitesController.php:1922+`): probe both
  namespaces during standalone enrollment (try `clockwork-renegade/v1/health` first — the intended path
  for all new standalone enrollments going forward — fall back to `clockwork/v1/health` for sites that
  already manually installed classic Companion pre-Renegade), store whichever answers.
- Standalone-enrollment instructions/UI (`resources/views/sites/create.blade.php` and related) should
  point new operators at the wordpress.org listing URL for Renegade instead of `/companion/download`'s
  zip — installing "from the WordPress Plugin Directory" is now the correct, expected instruction for
  this population.
- `resources/docs/architecture/companion-plugin.md`: correct the existing "dual mode" section (confirmed
  in earlier research to describe manual-zip-upload-plus-Activate as if it were an engineered mode) to
  describe the real, current state: Companion (SSH-managed, MIT, private) vs. Renegade (standalone,
  GPL, wordpress.org-listed, self-updating via WP core).
- New `resources/docs/features/clockwork-renegade.md`: the full picture for future maintainers — why
  two products exist, the route keep/cut list above, the wordpress.org compliance requirements, and the
  SVN release process.

## Test plan

**Renegade repo (PHPUnit):**
- Fork Companion's existing test suite for every kept route, adapted to the new namespace/package name.
  Delete tests for `CodeSnippetRoute` entirely (nothing to keep).
- New tests for the added lifecycle code: activation hook sets the expected transient/redirect;
  `uninstall.php` removes every option it's responsible for (assert via `get_option()` returning false
  post-uninstall for each one) — this is the single most reviewer-visible thing to get right, worth
  explicit test coverage rather than trusting manual verification alone.
- A drift-guard test: every string-bearing admin page renders without a raw (unescaped/untranslated)
  literal where the pattern above says one shouldn't exist — even a lightweight grep-based test
  (assert no `<?php echo $` without an `esc_*`/`__`/`_e` wrapper in `src/Admin/Pages/*.php`) catches
  regressions cheaply going forward.

**Control repo:**
- `EnrollmentVariantDetectionTest` — faked Renegade `/health` → `companion_variant` stored as
  `'renegade'`; faked classic-Companion-only `/health` (renegade namespace 404s, classic namespace ok)
  → falls back correctly, stores `'companion'`.

## Before considering this done

- Run the official **Plugin Check** tool locally against the built plugin and resolve every flagged
  issue before submission — treat a clean Plugin Check run as a hard gate, not optional.
- Full PHPUnit green in the new Renegade repo; full Pest/PHPStan/Pint green in Control.
- Manually verify: fresh install on a real test site from a locally-built zip (not yet through
  wordpress.org) — activation redirects to the Connection page, the Connection Key round-trips into
  Control's enrollment flow, `companion_variant` is stored as `'renegade'`, every admin page renders
  correctly, uninstalling the plugin leaves zero orphaned options in `wp_options`.
- Confirm the `readme.txt` external-service disclosure links to a real, live Terms of Use / Privacy
  Policy page before submitting — not a placeholder.
- Submit through the actual wordpress.org "Add Your Plugin" flow (manual, human-reviewed — budget real
  calendar time for review turnaround, which varies) before any SVN automation matters.

## Open items that are Aaron's call, not something to resolve unilaterally

1. A real, live URL for Clockwork Control's Terms of Use / Privacy Policy — needed before the
   `readme.txt` disclosure section can be finalized.
2. Exact `Requires at least` WP version floor and `Tested up to` value at submission time.
3. GitHub repo visibility for the new `clockwork-renegade` mirror (independent of wordpress.org
   listing, which is public regardless).
4. Whether to also submit a `.pot` translation template at first submission or leave that to
   translate.wordpress.org after listing (either is fine; just a scope-for-v1 call).
