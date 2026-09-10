---
title: Diagnostics
section: Features
order: 90
updated: 2026-09-09
author: Aaron Reimann
tags: [diagnostics, integrations, connectivity, smoke-tests, pressable]
tracks: [app/Services/Diagnostics/**, app/Http/Controllers/DiagnosticsController.php, modules/*/src/*Check.php]
---

A single page that smoke-tests most external integrations the Clockwork app uses — **not literally every one**, see the gap list below. Lives at **`/settings/diagnostics`** (gear menu → Diagnostics). Read-only on every check — no test mail, no webhooks posted, no writes. Safe to click "Run all again" as many times as you want.

## What it tests

The page runs 26 checks in sequence and renders a row per check. Order matters: control checks first (DB, network, storage), then the integration-specific ones grouped by domain (cloud providers, then hosting APIs, then chat/SMS, then security-feed APIs) — so when several rows go red simultaneously and the network row at the top is also red, you know to look at the network before suspecting several tokens went bad at once.

As of the modularization roadmap, 15 of these checks are contributed by their respective `modules/{Provider}/src/{Provider}ServiceProvider.php` (or `modules/BillCom/`) and merged in via `Modules\Core\ModuleRegistry::diagnosticChecks()` — they're no longer hardcoded in `DiagnosticsController`: every cloud provider (Azure, Hetzner, DigitalOcean, Vultr, Linode), every hosting provider (Pressable, SpinupWP, WP Engine, Kinsta, Cloudways, GridPane), every chat/SMS notification channel (Mattermost, Slack, Twilio), and Bill.com. The other 11 checks (the 5 control checks, DigitalOcean Spaces, "Server provider values", Cloudflare, and the 3 security-feed APIs) are still listed directly in `DiagnosticsController::checks()`.

| Check | What it does |
|---|---|
| **MySQL database** | `SELECT VERSION()` against the primary connection. |
| **Outbound HTTPS** | GET `api.github.com/` — proves the box can reach the internet at all. |
| **Storage writable** | Write + delete a probe file under `storage/app`. Catches disk-full or permission breakage. |
| **Mailer (SMTP)** | TCP-connects to the configured SMTP host:port and reads the greeting banner. **Does not send mail.** Skipped for non-SMTP transports (`log`, `array`, `sendmail`). |
| **Google OAuth** | Verifies `GOOGLE_CLIENT_ID` + `GOOGLE_CLIENT_SECRET` are set, and reaches Google's OpenID discovery doc. Doesn't validate the secret (that requires a real authorization round-trip). |
| **DigitalOcean API** | `GET /v2/account` — returns the account email + status when the token is valid. |
| **DigitalOcean Spaces** | `SpacesClient::smokeTest()` — lists up to one object in the bucket root via the S3-compatible API. Separate credential from the DO API check above (HMAC key+secret, not the personal access token). |
| **Hetzner Cloud API** | `GET /locations` — Hetzner has no dedicated account endpoint, so this read-only, ungated list serves as the "is this token good" probe, same role `/account` plays for DigitalOcean. |
| **Azure API** | `GET /subscriptions/{id}` via the OAuth 2.0 client-credentials flow (tenant + client id/secret). Skips cleanly, not a failure, when any of the four required env vars is missing — not every environment has it provisioned. |
| **Vultr API** | `GET /v2/account` — returns the account name/email when the API key is valid. Vultr's API has no metrics endpoint at all, so this is purely an auth probe; it doesn't imply CPU/memory data is available. |
| **Linode API** | `GET /v4/account` — returns the account email/company when the personal access token is valid. |
| **Server provider values** | Not an external call — checks that every distinct `servers.provider` value in the DB is claimed by a registered `CloudProvider` module (Azure/Hetzner/DigitalOcean/Cloudways/Vultr/Linode). Fails, doesn't skip, if it finds one that isn't — an unrecognized provider string silently defaulted to DigitalOcean's adapter before Phase 4; now it resolves to a no-op `NullCloudProvider` instead, and this check is what surfaces that gap instead of masking it. |
| **SpinupWP API** | `GET /servers?per_page=1` — minimal payload that proves the bearer token is good. |
| **Pressable API** | `GET /account` via the same OAuth2 client-credentials flow every Pressable backup/traffic/security-summary push and command-execution transport depends on. |
| **WP Engine API** | Basic Auth (API User ID/Password) via `GET /installs`. Unverified against a live account — see [Integrations → WP Engine](/docs/integrations/wp-engine). |
| **Kinsta API** | `GET /sites` — Kinsta's API has no dedicated auth-only endpoint, so this distinguishes a rejected key (401) from an accepted one on a real data call. |
| **Cloudways API** | OAuth2 token exchange, then `GET /server` — proves both the key exchange and a follow-up authenticated call work, not just that the key is well-formed. |
| **GridPane API** | `GET /user` — returns the account email/name when the Bearer API token is valid. |
| **Cloudflare API** | `GET /user/tokens/verify` — Cloudflare's canonical "is this token alive?" endpoint. |
| **Bill.com API** | Forces a fresh `Login.json` round-trip via `BillComClient::ping()`. Verifies all four credentials (username + password + org_id + dev_key). Skipped when `CLOCKWORK_BILL_COM_ENABLED=false`. |
| **Mattermost webhook** | GETs the webhook URL (not POSTs — we don't want to spam the channel every time someone clicks the page). |
| **Slack webhook** | Same GET-not-POST shape as Mattermost. Skipped when `CLOCKWORK_SLACK_ENABLED=false` — most environments run Mattermost as primary and leave Slack off. |
| **Twilio API** | Fetches the account resource via the official SDK (`$client->api->v2010->accounts($sid)->fetch()`) — read-only, never sends an SMS. Skipped unless `TWILIO_ENABLED` and all three credential vars are set. |
| **Sucuri SiteCheck** | GETs `sitecheck.sucuri.net` — anonymous endpoint, no token. Sucuri returns 403 to non-browser User-Agents but the host is up; treated as OK as long as the response is < 500. |
| **wpvulnerability.net** | GETs a real, always-installed slug (`akismet`) — the API is free and keyless, so there's no credential to validate, only reachability. Feeds `clockwork:refresh-plugin-vulnerabilities`. |
| **Google Safe Browsing API** | POSTs the same minimal `threatMatches:find` request shape `BlacklistChecker` uses in the real daily scan, checking one known-clean URL. A 200 (even with zero matches) proves the key is valid — Google returns 400 with an explicit "API key not valid" message for a bad one. |

## Status meanings

- **OK** (green) — check passed. Duration shown in ms.
- **FAIL** (red) — check ran and failed. The "Details" disclosure has the raw error.
- **SKIP** (grey) — integration isn't configured (no env var, feature disabled). **Not** an error. If you expected it to be on, that's the cue to set the env var.

## What it doesn't test

- **Per-site Companion endpoints.** Those live on each site's page (`/sites/{id}` already has Companion health). Running 27 site probes here would drown the global signal.
- **"Send a real test email."** Mutating actions belong behind their own explicit buttons, not silent every-page-load side effects. (When we add one, it'll be a separate "Send test email to me" form next to the Mailer row.)
- **Queue worker liveness.** Not yet — the right "is the worker beating?" check needs a per-job heartbeat we don't write yet.
- **dist_url storage** for the Companion package distribution. Will land here when the dist_url rollout flips on.
- **GTmetrix, PageSpeed Insights, Spamhaus DBL.** GTmetrix is on a metered plan with a per-day credit cap, and PSI is rate-limited; adding checks that consume scan credits on every diagnostics page load is avoided. Spamhaus DBL is a DNS lookup rather than an HTTP API.

## Adding a new check

Each check is a class implementing `App\Services\Diagnostics\DiagnosticCheck`:

```php
public function id(): string;          // stable lowercase-with-hyphens
public function name(): string;        // display label
public function description(): string; // one-line "what's being checked"
public function run(): CheckResult;
```

`CheckResult::ok()`, `::fail()`, `::skipped()` are the three constructors. Skipped is for "integration isn't configured" — never use it for actual failures.

Where the new class goes depends on what it's checking:

- **Core check** (not owned by any module — Sucuri, Google Safe Browsing, Cloudflare, DigitalOcean Spaces, wpvulnerability.net, etc.): add it directly to the `checks()` method in `DiagnosticsController` (in the order you want it to render).
- **Module-owned check** (a new cloud provider, hosting provider, or notification channel): implement `hostingProvider()`/`cloudProvider()`'s diagnostics sibling, `diagnosticCheck(): ?DiagnosticCheck`, on that module's `ModuleServiceProvider` subclass. `Modules\Core\ModuleRegistry::diagnosticChecks()` picks it up automatically — nothing to add in `DiagnosticsController` itself. See `modules/Azure/src/AzureServiceProvider.php` for a cloud-provider example, `modules/Mattermost/src/MattermostServiceProvider.php` for a notification-channel one.

The runner catches Throwables, so a check that explodes mid-run becomes a fail row rather than a 500.

## Why this exists

Before this page, "is the app actually wired up right?" required running `php artisan tinker` and poking each client manually. Now it's one click. Especially useful after rotating a token, restoring from backup, or moving the app to a new box.
