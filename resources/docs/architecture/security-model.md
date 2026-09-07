---
title: Security model
section: Architecture
order: 50
updated: 2026-09-07
author: Aaron Reimann
tags: [architecture, security, auth, secrets, pressable]
tracks: [app/Http/Controllers/Auth/**, app/Http/Controllers/UsersSettingsController.php, app/Http/Controllers/MaintenanceController.php, app/Services/Companion/**, modules/Pressable/src/**, config/clockwork.php]
---

How we protect a database that holds the SSH and WP-DB credentials for ~150 SpinupWP sites, plus the OAuth2 credentials reaching ~90 more on Pressable (no SSH — see [Integrations → Pressable](/docs/integrations/pressable) for that provider's different trust model). The short version: defense in depth — local-LAN-only network posture, OAuth + allowlist for humans, HMAC for plugin calls, dual-scoped CF tokens, encrypted-at-rest credentials.

## Threat model in one paragraph

Clockwork is local-LAN-only. The DB is the highest-value target on the host: SSH private keys for every SpinupWP server, MySQL credentials for every WordPress site, plus the per-site Companion HMAC secrets and the Pressable API credentials. We assume an attacker who reaches the LAN, an attacker who steals an API token from the env, and an attacker who compromises a single managed WordPress site. Each gets a different bounded blast radius.

## Network posture

- The app is bound to localhost. Don't run `php artisan serve --host 0.0.0.0` and don't expose Herd `.test` domains beyond the LAN without a deliberate decision.
- The home network NAT is the actual perimeter. There is no public URL and no inbound from outside.
- Outbound calls are HTTPS to known third parties only (DigitalOcean, Hetzner Cloud, Azure, Vultr, Linode, SpinupWP, Pressable, WP Engine, Kinsta, Cloudways, Cloudflare, Bill.com, Mattermost, Slack, Twilio, Mailgun, GTmetrix, PageSpeed Insights, Companion sites, blacklist/malware-scan services). LM Studio is loopback-only.

## Physical & host security

The network posture above assumes the machine itself is safe. It might not be — and that machine is a bigger prize than it looks, for a reason encryption-at-rest doesn't fix:

- **The DB holds ciphertext; `.env` on the same disk holds the key that reads it.** `servers.ssh_private_key`, `servers.ssh_password`, `sites.db_password`, and `sites.companion_secret` are all `Crypt`-cast (AES-256-GCM, derived from `APP_KEY`) — safe against someone who steals *only* a database dump (see "App database backups" below). But `APP_KEY` lives in `.env` on the exact same host that runs the app and (typically) the DB. Whoever walks off with the machine walks off with both halves at once — the ciphertext and the key — which collapses "encrypted at rest" down to roughly the protection of an unlocked filing cabinet. This is a different risk from the network posture above, and encryption-at-rest doesn't address it at all.
- **Run this on a desktop, not a laptop.** A laptop is designed to leave the building — a bag on a train, a car break-in, a conference. A desktop that never leaves a locked office removes the theft-in-transit scenario this app is specifically exposed to: a single stolen device would otherwise hand an attacker SSH keys and passwords for the entire managed fleet, not just this app's own data.
- **If a portable machine is genuinely unavoidable**, full-disk encryption (FileVault/BitLocker/LUKS) plus a locked screen and a real login password are the floor, not the solution — they reduce the window of opportunity, they don't change the "one stolen device = one fully compromised fleet" property above.
- **Never put this app on a public IP.** Reinforcing the [Network posture](#network-posture) section above: no port-forwarding, no public DNS record, no `php artisan serve --host 0.0.0.0`. Combined with the point above, a publicly reachable instance turns a *remote* compromise into the same blast radius as a *physical theft* — an attacker doesn't even need to be in the same city.

## Human auth

- **Dual Auth: Local Password & Allowlisted OAuth.** Local email/password authentication is universally available, alongside modular OAuth single sign-on (Google, GitHub, and Microsoft Entra ID).
  - **Local Passwords**: Stored as Bcrypt/Argon2 hashes via Eloquent's `hashed` cast on `users.password`. Handled securely at `POST /login` with rate limiting (`throttle:5,1`) and session regeneration.
  - **OAuth Providers**: Each implements `Modules\Core\Contracts\AuthProvider` in its own module (`AuthGoogle`/`AuthGitHub`/`AuthMicrosoft`) and shares one allowlist/audit path through `App\Services\Auth\OAuthLoginHandler`. `LoginController` only shows a provider's button once it's actually configured, avoiding broken sign-in paths when SSO is not set up.
- **The `users` table is the allowlist**, regardless of whether the operator authenticates via local password or OAuth. A row exists ⇒ allowed; `revoked_at IS NULL` ⇒ active. **No auto-provisioning** — the user must already exist on the allowlist, or login is denied with an informative banner.
- **Bootstrap and recovery** via `clockwork:add-user <email> [--name=] [--password=]` and `clockwork:set-password <email> [--password=]`. Idempotent — restores revoked rows and sets or resets operator credentials via masked CLI prompts. Always-works escape hatch when the UI is locked out.
- **Optional Workspace pin** — `GOOGLE_HD=your-agency.com` restricts the Google account picker to that domain. Off by default so personal accounts work for testing.
- **Revoke does not kill active sessions.** A revoked user keeps their existing session until logout/expiry, but is blocked at any subsequent password or OAuth authentication attempt. Login + add/revoke/restore/password-change all land in `action_logs`.

### Users settings page (`/settings/users`)

`App\Http\Controllers\UsersSettingsController` is the UI for the allowlist described above — a row in `users` *is* the allowlist, and this page is where an admin edits that table without touching `clockwork:add-user` from a shell.

- **`index`** lists every user (active and revoked), active first (`orderBy('revoked_at')` — NULLs sort first), then by email. Indicates whether the user has a local password configured, SSO only, or both.
- **`store`** (`POST /settings/users`) adds a new allowlist row with an optional initial password, or — if the email already exists and is revoked — restores it (clears `revoked_at`, updates name and optional password) rather than erroring on a duplicate. Logs `TYPE_USER_ADDED` or `TYPE_USER_RESTORED` accordingly.
- **`updatePassword`** (`PATCH /settings/users/{user}/password`) allows administrators to set or change an operator's local password directly from the UI. Logs `TYPE_USER_PASSWORD_CHANGED`.
- **`revoke`** (`PATCH /settings/users/{user}/revoke`) sets `revoked_at = now()`. **Blocked at the controller for self-revoke** — `Auth::id() === $user->id` bounces back with an error rather than letting you lock yourself out of the UI, since the artisan recovery path doesn't help if you can't reach the host to run it. Logs `TYPE_USER_REVOKED`.
- **`restore`** (`PATCH /settings/users/{user}/restore`) clears `revoked_at` for an already-revoked user. Logs `TYPE_USER_RESTORED`.

All user actions go through `App\Services\ActionLog\ActionLogger` with the acting admin's email as `actor`, landing in `action_logs` alongside every other audited action — see [Architecture → Data model](/docs/architecture/data-model).

## App-side encrypted columns

`Crypt`-cast in the model, ciphertext at rest using `APP_KEY`-derived AES-256-GCM:

- `servers.ssh_private_key`, `servers.ssh_password`
- `sites.db_password`, `sites.companion_secret`

Never log them. Never render them in views or API responses. The `companion_secret` cast also marks it hidden from serialization so it can't accidentally end up in a JSON response.

**Re-generating `APP_KEY` breaks all encrypted columns** in place. Don't do it casually — there's no rotation flow.

## Outbound API tokens

| Token | Scope | Why scoped narrowly |
|---|---|---|
| `CLOCKWORK_DIGITALOCEAN_TOKEN` | Read | Used only for monitoring metrics. Never provisions or destroys droplets. |
| `CLOCKWORK_SPINUPWP_TOKEN` | Inventory | Used for read + the migration runner's create-site call only. |
| `CLOCKWORK_CLOUDFLARE_API_TOKEN` | Zone:Read + per-phase Read | Diagnostics only. Cannot mutate DNS or firewall rules. |
| `CLOCKWORK_CLOUDFLARE_WRITE_TOKEN` | Zone.DNS:Edit + Zone.Firewall Services:Edit | Migration cutover (DNS) and rate-limit/custom-WAF-rule writes. Independently rotatable from the read token — a leak of the read token grants neither. See [Integrations → Cloudflare](/docs/integrations/cloudflare) for why it needs two scopes, not one. |
| `CLOCKWORK_DO_SPACES_KEY/SECRET` | Bucket scope | S3-style HMAC, not the DO PAT. List-only access pattern; we never write or delete. |
| `CLOCKWORK_BILL_COM_*` | Account login | Read-only sync. We don't mutate Bill.com. |
| `CLOCKWORK_PRESSABLE_CLIENT_ID/SECRET` | OAuth2 client_credentials, account-level | No per-site scoping available — Pressable's API doesn't offer it. This token can reach every Pressable site on the account, including running arbitrary shell/wp-cli commands via the async command API. Treat it at the same sensitivity as an SSH private key, not as a scoped read token. |
| `CLOCKWORK_AZURE_CLIENT_ID/SECRET` | Reader on the subscription/resource group | Monitoring only — same posture as the DO/Hetzner tokens, just OAuth2 instead of a static bearer token. |
| `CLOCKWORK_VULTR_API_KEY` / `CLOCKWORK_LINODE_TOKEN` | Read-only | Monitoring + IP-matching only, same posture as DO/Hetzner. Vultr's API has no metrics surface to begin with; Linode's is CPU-only. |
| `CLOCKWORK_WPENGINE_SSH_PRIVATE_KEY` / `CLOCKWORK_KINSTA_SSH_PASSWORD` | Real per-install/per-environment SSH | Same sensitivity class as a server's own `ssh_private_key`/`ssh_password` columns — not scoped per-site by the provider, treat with SSH-key handling discipline. The WP Engine/Kinsta REST API credentials themselves (`api_user_id`/`api_password`, bearer token) are separate and lower-stakes — inventory/read only, command execution goes over the SSH credential instead. |
| `CLOCKWORK_CLOUDWAYS_API_KEY` | OAuth2 (API key → bearer token), account-level | Cloudways provisions real servers on a cloud of its own choosing (DO/AWS/GCP/Vultr/Linode) that this app never holds credentials for — see [Integrations → Cloudways](/docs/integrations/cloudways). Command execution and Companion install go through Cloudways' own async command API, same account-wide-reach caveat as the Pressable token below. |

## Companion plugin auth

The mu-plugin sits inside each WordPress site and exposes signed REST endpoints under `/wp-json/clockwork/v1/`. Auth is HMAC-SHA256 with a per-site shared secret.

- Secret is 32 random bytes (`bin2hex(random_bytes(32))` = 64 hex chars, 256 bits), generated Laravel-side at install, pushed into `wp_options` via wp-cli, mirrored encrypted into `sites.companion_secret`.
- Two storage modes: `wp_options` (default; rotation via `clockwork:rotate-companion-secret`) or a `CLOCKWORK_COMPANION_SECRET` constant in `wp-config.php` (operator-pinned; rotation request returns 409 `secret_pinned`).
- **Per-site secret** — compromising one site doesn't pwn the fleet.
- **Encrypted at rest** in Clockwork's DB; in WP `wp_options` it's plaintext, same trust posture as `wp-config.php` keys.

Signature payload (both sides must match exactly):

```
METHOD\n/wp-json/clockwork/v1<route>\nTIMESTAMP\nBODY
```

Headers on every request: `X-Clockwork-Signature` (hex), `X-Clockwork-Timestamp` (unix seconds). Verifier rejects on missing headers, `|now − ts| > 300s`, missing secret, or signature mismatch (`hash_equals` constant-time compare). Replay window is 5 minutes.

POST bodies use Laravel's `withBody($jsonBody, 'application/json')` so the byte-exact JSON we signed is what gets sent — `->post($url, $array)` would re-encode and could drift.

The signature is computed over the *logical* route string (`/wp-json/clockwork/v1<route>`), not the literal URL, which matters because `ClockworkCompanionClient` has two transport forms for the same route: the normal pretty-permalink URL, and a `?rest_route=` query-string fallback used when a site's web server configuration is missing the WordPress REST rewrite (where `/wp-json/...` might resolve to homepage HTML with a 200 status instead of dispatching to WordPress — `isNonJsonResponse()` detects that case and retries via `?rest_route=`). Because both forms hit the same logical route, the signature is identical either way and doesn't need special-casing.

### Companion-side defense in depth

- **HMAC failure rate limit** — 30 fails per IP per 60s → 429. Per-IP transient counter. Defends `wp_options` and CPU from noise; not the cryptographic protocol.
- **HMAC failure audit log** — `wp_clockwork_auth_failures` table, lazy-pruned to 1000 rows. Surfaces in Tools → Clockwork → Security → Authentication audit.
- **SSO nonce ceiling** — `/sso/magic-link` refuses to mint past 100 active. Prevents `wp_options` bloat under abuse.
- **Plugin-side admin pages** — Tools → Clockwork is gated to authorized agency emails. REST endpoints remain HMAC-gated regardless.

See [Architecture → Companion plugin](/docs/architecture/companion-plugin) for the full picture.

### Pressable transport: a different secret-exposure risk

Pressable has no SSH equivalent — the Companion secret has to reach the site some other way, and Pressable logs **every** command it runs verbatim in its own control panel for ~30 days. Pushing the real long-lived HMAC secret through that channel (the way SSH tarball push does over an encrypted, non-logged connection) would put it in a third party's logs.

`PressableCompanionInstaller` avoids this with a **bootstrap-then-rotate** sequence: a throwaway secret goes through the logged install command, `/health` verifies the plugin loaded, then `rotateSecret()` replaces it with the real secret over authenticated HTTPS — a channel Pressable doesn't log. If rotation fails, the install is reported as a loud warning rather than a silent success, precisely so a site left running on the throwaway (logged) secret doesn't go unnoticed. See [Integrations → Pressable](/docs/integrations/pressable).

## SSH

- Auth precedence: per-server stored key → local default key (`CLOCKWORK_SSH_KEY_PATH`) → per-server password.
- Default user is `clockwork-deploy` (overridable per server). Non-root, with NOPASSWD on `/usr/bin/fail2ban-client` only — granted by `/etc/sudoers.d/clockwork` during fail2ban provisioning.
- Other privileged commands run via `sudo -S` with the SSH password fed on stdin. Never write that password into a file (the v0 provisioner did and broke sudo on the box — see [Runbook → Bad IP ban recovery](/docs/runbooks/bad-ip-ban-recovery) and the `clockwork:clean-failed-provision` recovery flow).

**This section (and [Integrations → SSH + fail2ban](/docs/integrations/ssh-and-fail2ban)) is SpinupWP/Cloudways-scoped** — those go through `servers.ssh_private_key`/`ssh_password` and `App\Services\Ssh\SshClient`. **WP Engine and Kinsta use a separate, parallel SSH credential store**: `modules/Core/src/Support/SshConnector.php` connects directly via `phpseclib3\Net\SSH2` (not `SshClient`/the `servers` table) using per-environment env vars — `CLOCKWORK_WPENGINE_SSH_PRIVATE_KEY`, `CLOCKWORK_KINSTA_SSH_PASSWORD` (see the outbound-token table above) — solely to install the Companion mu-plugin on those hosts. Same sensitivity class as the encrypted `servers` columns, same physical/network-security stakes above apply to those env vars too, just a different storage location — don't assume "it's not in the `servers` table" means "not SSH."

**Is SSH avoidable for any of this?** Not really, and not just for one feature. SSH is the substrate for a dozen unrelated things on an SSH-capable provider — apt-get updates, reboot scheduling, fail2ban bans, `wp-cli` plugin/checksum checks, and nginx log tailing for the Traffic feature (see [Features → Traffic + capacity](/docs/features/traffic-and-capacity)) all go through the same credential. Even where an alternative exists for one narrow piece — Pressable proves traffic data *can* be sourced over plain HTTPS instead of a log tail, see that doc — the same server still needs the SSH credential stored for everything else on this list. Isolating "just the traffic feature" behind its own SSH toggle wouldn't shrink the fleet's actual credential-storage risk at all, since updates/fail2ban/plugin-detection would still need the exact same key on the exact same server. The credential is fleet-wide the moment you want any one of these; there's no smaller boundary to draw.

## App database backups (`/settings/maintenance`)

`MaintenanceController::index()` shows the operator a one-glance summary of Clockwork's own database (name, host, driver, and a rough total-byte size via a single `information_schema.tables` query) before they commit to downloading anything. `downloadBackup()` then streams a gzipped `mysqldump` (`--single-transaction --quick --no-tablespaces`, piped through `gzip`) straight to the browser via `passthru()` — no temp file ever touches the server's disk, and the password goes through the `MYSQL_PWD` env var rather than the command line so it never shows up in `ps` output.

This is the highest-value single file an attacker could get: it contains every encrypted column's ciphertext (SSH private keys, per-site DB passwords, Companion secrets) plus all snapshot/threat-log data. The ciphertext alone is safe — decrypting it requires pairing the dump with `APP_KEY` from `.env`, which never leaves the server through this path — but treat any downloaded copy of this file with the same handling discipline as `.env` itself. There's no audit-log entry for who downloaded a backup and when; if that becomes a real need, it's a one-line `ActionLogger::record` addition to `downloadBackup()`.

## Don't do these

- Do not `php artisan serve --host 0.0.0.0`.
- Do not log decrypted credentials. The `ActionLogger` is generic JSON — sanitize before passing details.
- Do not generate a single Cloudflare token with both Read and Edit scopes. Keep them separate.
- Do not run `php artisan migrate:fresh` against a real DB. Confirm before using.
- Do not commit `.env`. The `.gitignore` covers it; don't override.
- Do not push a long-lived Companion secret through Pressable's command API — it's logged verbatim for ~30 days. Use the bootstrap-then-rotate pattern (see above).
