---
title: Environment variables
section: Reference
order: 40
updated: 2026-09-12
author: Aaron Reimann
tags: [reference, configuration, env]
tracks: [.env.example, config/clockwork.php, config/services.php]
---

Every `CLOCKWORK_*` variable plus the standard Laravel ones we actually use. Defaults come from `config/clockwork.php` — most things work if the variable is empty (the feature short-circuits).

The provider-credential variables below (DigitalOcean, Hetzner, Azure, Vultr, Linode, SpinupWP, Pressable, WP Engine, Kinsta, Cloudways, Cloudflare, Twilio, Bill.com, blacklist-scan keys, GTmetrix, PSI, OAuth credentials, etc.) are managed directly in the root `.env` file — either manually or through the **Setup** dashboard (`/setup`) and **Settings → API limits** (`/settings/integrations/{service}/limits`). The built-in `EnvCredentialManager` writes atomically to `.env` and immediately reflects changes in running process memory without server reboots. `CredentialResolver` maintains backward-compatible fallback for any legacy database-stored credentials. On a fresh install (zero servers, zero sites), the dashboard redirects to **Setup** (`/setup`) — the fleet integration and credential configuration hub. See [Features → Fleet Integrations Setup](/docs/features/setup-checklist).

Group order matches `.env.example` so you can keep them side by side.

## Laravel core

| Variable | Default | Notes |
|---|---|---|
| `APP_KEY` | empty | `php artisan key:generate` once on first checkout. Re-generating breaks all encrypted columns (SSH keys, db_password, companion_secret). |
| `APP_DEBUG` | `true` | Local. Flip to `false` if you ever expose this beyond localhost. |
| `APP_URL` | `http://localhost` | |
| `DB_*` | mysql / 127.0.0.1 / clockwork / root / empty | Local-only. MySQL listens on 127.0.0.1, root has no password. |
| `SESSION_DRIVER` | `database` | |
| `QUEUE_CONNECTION` | `database` | |
| `CACHE_STORE` | `database` | |

## Mail

| Variable | Default | Notes |
|---|---|---|
| `MAIL_MAILER` | `log` | Flip to `mailgun` once SPF/DKIM are set on the sending domain. |
| `MAILGUN_DOMAIN`, `MAILGUN_SECRET`, `MAILGUN_ENDPOINT` | unset | Required when `MAIL_MAILER=mailgun`. EU region uses `api.eu.mailgun.net`. Composer pull: `composer require symfony/mailgun-mailer symfony/http-client`. |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | example.com | |

## Auth (Google OAuth)

| Variable | Default | Notes |
|---|---|---|
| `GOOGLE_CLIENT_ID` / `_SECRET` | unset | Create at `console.cloud.google.com/apis/credentials`. |
| `GOOGLE_REDIRECT_URI` | `http://localhost:8000/auth/google/callback` | Must match Google Console **exactly**. Multiple URIs OK — register your dev URL and the production one. |
| `GOOGLE_HD` | empty | Optional Workspace domain pin. Off by default so personal Google accounts work. |
| `GITHUB_CLIENT_ID` / `_SECRET` | unset | GitHub OAuth App, create at `github.com/settings/developers`. Provided by the `AuthGitHub` module. |
| `MICROSOFT_CLIENT_ID` / `_SECRET` | unset | Microsoft Entra ID (Azure AD) App Registration at `portal.azure.com`. Provided by the `AuthMicrosoft` module. |
| `MICROSOFT_TENANT_ID` | `common` | Directory (tenant) ID, or `common` to allow any Microsoft account/tenant. |

None of the three login providers is required — `LoginController` only shows a provider's button once its credentials are actually configured (`AuthProvider::isConfigured()`), so leaving GitHub/Microsoft unset just means those two buttons don't render. The allowlist itself is the `users` table regardless of which provider signed someone in — see `architecture/security-model`.

## DigitalOcean

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_DIGITALOCEAN_TOKEN` | unset | Personal Access Token. Required for `clockwork:poll-servers` against DO-provider servers. |
| `CLOCKWORK_DIGITALOCEAN_BASE_URL` | `https://api.digitalocean.com/v2` | |
| `CLOCKWORK_DIGITALOCEAN_TIMEOUT` | `15` | Seconds. |

## Hetzner Cloud

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_HETZNER_TOKEN` | unset | Per-project API token from `console.hetzner.cloud → project → Security → API Tokens`. Required for `clockwork:poll-servers` against Hetzner-provider servers. Read-only scope is enough. |
| `CLOCKWORK_HETZNER_BASE_URL` | `https://api.hetzner.cloud/v1` | |
| `CLOCKWORK_HETZNER_TIMEOUT` | `15` | Seconds. |

`poll-servers` is fine with a partial config (DO yes, Hetzner no, or vice versa) — each server uses its own provider's client and rows whose provider client isn't configured simply get skipped.

## Azure

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_AZURE_TENANT_ID` | unset | Azure AD / Entra ID tenant GUID. |
| `CLOCKWORK_AZURE_CLIENT_ID` | unset | Service Principal application GUID. |
| `CLOCKWORK_AZURE_CLIENT_SECRET` | unset | Service Principal client secret. Expires — Azure enforces a max lifetime. |
| `CLOCKWORK_AZURE_SUBSCRIPTION_ID` | unset | Subscription that owns the VMs. |
| `CLOCKWORK_AZURE_BASE_URL` | `https://management.azure.com` | |
| `CLOCKWORK_AZURE_LOGIN_URL` | `https://login.microsoftonline.com` | |
| `CLOCKWORK_AZURE_TIMEOUT` | `15` | Seconds. |

All four of tenant/client/secret/subscription are required together — `AzureClient::isConfigured()` is all-or-nothing. Third cloud-provider branch in `poll-servers` / `reconcile-provider`, alongside DO and Hetzner. See [Integrations → Azure](/docs/integrations/azure).

## Vultr

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_VULTR_API_KEY` | unset | Read-only API key from `my.vultr.com/settings/#settingsapi`. Required for `clockwork:reconcile-provider` against Vultr-provider servers. |
| `CLOCKWORK_VULTR_BASE_URL` | `https://api.vultr.com/v2` | |
| `CLOCKWORK_VULTR_TIMEOUT` | `15` | Seconds. |

Vultr's API exposes no CPU/memory/disk time series, so `clockwork:poll-servers` never gets metrics for `provider='vultr'` servers — only alive/dead state. See [Integrations → Vultr](/docs/integrations/vultr).

## Linode (Akamai)

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_LINODE_TOKEN` | unset | Read-only personal access token (Linodes scope) from `cloud.linode.com/profile/tokens`. Required for `clockwork:poll-servers`/`reconcile-provider` against Linode-provider servers. |
| `CLOCKWORK_LINODE_BASE_URL` | `https://api.linode.com/v4` | |
| `CLOCKWORK_LINODE_TIMEOUT` | `15` | Seconds. |

CPU-only metrics (normalized by `server.vcpus`) — memory/disk/load stay null, same gap as Hetzner and Azure. See [Integrations → Linode](/docs/integrations/linode).

## WP Engine

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_WPENGINE_API_USER_ID` / `_API_PASSWORD` | unset | Basic Auth REST credentials from `my.wpengine.com/api_access`. Inventory/read only. |
| `CLOCKWORK_WPENGINE_BASE_URL` | `https://api.wpengine.com/v1` | |
| `CLOCKWORK_WPENGINE_TIMEOUT` | `15` | Seconds. |
| `CLOCKWORK_WPENGINE_VIEW_ONLY` | `true` | When `true`, enforces read-only mode: blocks mutating API requests and prevents remote Companion deployment over SSH. Safe default for unverified accounts. |
| `CLOCKWORK_WPENGINE_SSH_PRIVATE_KEY` / `_SSH_PRIVATE_KEY_PASSPHRASE` | unset | Separate credential from the API creds above — WP Engine's SSH gateway auths by key, not by the REST API's Basic Auth. Command execution and Companion install go over this, not the API. Same sensitivity class as a server's own `ssh_private_key` column. |

No server concept — same shape as Pressable, every operation addressed by install name. Unverified against a live account — see [Integrations → WP Engine](/docs/integrations/wp-engine).

## Kinsta

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_KINSTA_API_KEY` | unset | Bearer token from the Kinsta MyKinsta dashboard. |
| `CLOCKWORK_KINSTA_BASE_URL` | `https://api.kinsta.com/v2` | |
| `CLOCKWORK_KINSTA_TIMEOUT` | `15` | Seconds. |
| `CLOCKWORK_KINSTA_VIEW_ONLY` | `true` | When `true`, enforces read-only mode: blocks mutating API requests (`POST`, `PUT`, `DELETE`) and prevents remote Companion deployment over SSH. Safe default for unverified accounts. |
| `CLOCKWORK_KINSTA_SSH_PASSWORD` | unset | Separate credential — Kinsta's API can manage SSH access/credentials but can't execute remote commands itself, so command execution and Companion install go over real per-environment SSH instead. |

Same "no server concept, unverified against a live account" caveat as WP Engine — see [Integrations → Kinsta](/docs/integrations/kinsta).

## Cloudways

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_CLOUDWAYS_API_KEY` / `_EMAIL` | unset | API key is exchanged for an OAuth2 bearer access token (see `CloudwaysClient::token()`); email identifies the account in that exchange. |
| `CLOCKWORK_CLOUDWAYS_BASE_URL` | `https://api.cloudways.com/api/v2` | v1 has reached end-of-life — only v2 is supported here. |
| `CLOCKWORK_CLOUDWAYS_TIMEOUT` | `15` | Seconds. |
| `CLOCKWORK_CLOUDWAYS_VIEW_ONLY` | `true` | When `true`, enforces read-only mode: blocks mutating API requests (`POST`, `PUT`, `DELETE`) and disables SSH command execution and Companion deployment. Safe default for unverified accounts. |

The one hosting provider that's also a `CloudProvider` — see [Integrations → Cloudways](/docs/integrations/cloudways) for why metrics come from Cloudways' own API rather than whichever cloud (DO/AWS/GCP/Vultr/Linode) it actually provisioned on.

## GridPane

| Variable | Default | Notes |
|---|---|---|
| `GRIDPANE_API_KEY` | unset | API token from the GridPane dashboard (Account Settings → API Keys). Bearer token. |
| `GRIDPANE_BASE_URL` | `https://my.gridpane.com/oauth/api/v1` | Base URL for the GridPane API v1. |
| `GRIDPANE_TIMEOUT` | `15` | Seconds. |
| `GRIDPANE_VIEW_ONLY` | `true` | When `true`, enforces strict read-only mode: blocks all mutating API requests (`POST`, `PUT`, `DELETE`, remote WP-CLI) and disables SSH command execution and Companion plugin deployment. Essential when connecting client-owned or third-party API keys. |

Server management panel running on real VPS servers — see [Integrations → GridPane](/docs/integrations/gridpane). Direct SSH access to servers and sites at `/var/www/{domain}/htdocs`.

## DigitalOcean Spaces (S3-compatible)

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_DO_SPACES_KEY` / `_SECRET` | unset | **S3-style HMAC**, NOT the DO PAT. Issue from the bucket's Settings → Access Keys tab. |
| `CLOCKWORK_DO_SPACES_REGION` | `nyc3` | |
| `CLOCKWORK_DO_SPACES_BUCKET` | empty | |
| `CLOCKWORK_DO_SPACES_PREFIX_TEMPLATE` | `{domain}/` | Override only if you're not using SpinupWP-style backup paths. |

## SpinupWP

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_SPINUPWP_TOKEN` | unset | API token from the SpinupWP dashboard. |
| `CLOCKWORK_SPINUPWP_BASE_URL` | `https://api.spinupwp.app/v1` | |
| `CLOCKWORK_SPINUPWP_TIMEOUT` | `15` | |
| `CLOCKWORK_SPINUPWP_VIEW_ONLY` | `false` | When `true`, blocks mutating API requests (`POST`, `PUT`, `DELETE`) and disables SSH execution and Companion deployment. Safe for inspecting third-party fleets. |

## Pressable

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_PRESSABLE_CLIENT_ID` / `_SECRET` | unset | OAuth2 `client_credentials` — no per-site "server" concept, every operation is addressed by `site_id` alone. |
| `CLOCKWORK_PRESSABLE_AUTH_URL` | `https://my.pressable.com/auth/token` | |
| `CLOCKWORK_PRESSABLE_BASE_URL` | `https://my.pressable.com/v1` | |
| `CLOCKWORK_PRESSABLE_TIMEOUT` | `15` | Seconds. |
| `CLOCKWORK_PRESSABLE_VIEW_ONLY` | `false` | When `true`, blocks mutating API actions (cache purges, bash scripts, WP-CLI execution) and prevents Companion plugin deployment. |

## Cloudflare

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_CLOUDFLARE_API_TOKEN` | unset | Read scopes only. See `integrations/cloudflare` for the per-phase scope list. |
| `CLOCKWORK_CLOUDFLARE_WRITE_TOKEN` | unset | Zone.DNS:Edit plus Cache Purge. Used for DNS cutover writes and homepage URL cache purge after update batches. Never reuse the read-only token. |
| `CLOCKWORK_CLOUDFLARE_BASE_URL` | `https://api.cloudflare.com/client/v4` | |

## Backup relay

There's no shared token or API — this app and the standalone relay droplet
never connect to each other directly. Instead both sides read/write two
small JSON files on the same S3 bucket, so the variables below are the
standard Laravel `s3` disk vars plus one prefix override. See
[Features → Backup relay](/docs/features/backup-relay).

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_BACKUP_RELAY_MODE` | `in_repo` | Operational mode: `in_repo` for native scheduled streaming directly from this app, or `external_agent` to use the legacy standalone droplet handoff. |
| `S3_BACKUP_RELAY_KEY` | `AWS_ACCESS_KEY_ID` | Dedicated S3 access key for backup relay uploads. Falls back to `AWS_ACCESS_KEY_ID` if unset. |
| `S3_BACKUP_RELAY_SECRET` | `AWS_SECRET_ACCESS_KEY` | Dedicated S3 secret key for backup relay uploads. Falls back to `AWS_SECRET_ACCESS_KEY` if unset. |
| `S3_BACKUP_RELAY_REGION` | `us-east-1` | S3 region where backup archives are uploaded. |
| `S3_BACKUP_RELAY_BUCKET` | `AWS_BUCKET` | Destination S3 bucket name. |
| `CLOCKWORK_BACKUP_RELAY_DISK` | `s3-backup-relay` | Dedicated filesystem disk configured in `config/filesystems.php` used for backup relay operations. |
| `CLOCKWORK_BACKUP_RELAY_FREQUENCY` | `weekly` | Default fleet-wide backup relay schedule frequency (`daily`, `twice_weekly`, `weekly`). Overridden per-site by `sites.backup_relay_frequency`. |
| `CLOCKWORK_BACKUP_RELAY_RETENTION_DAYS` | `90` | Default retention period in days for off-site backup archives. |
| `CLOCKWORK_BACKUP_RELAY_ARCHIVE_PREFIX` | `archives` | Destination folder prefix inside the bucket where site backups land (e.g. `archives/{domain}/...`). Production uses `_control/backup-relay/archives` due to scoped IAM permissions. |
| `CLOCKWORK_BACKUP_RELAY_S3_PREFIX` | `_control/backup-relay` | Key prefix for the two control files (`targets.json`, `last-report.json`). Used in `external_agent` mode only. Must match the external droplet's `S3_CONTROL_PREFIX`. |
| `AWS_ACCESS_KEY_ID` | unset | IAM access key used as general fallback. |
| `AWS_SECRET_ACCESS_KEY` | unset | Matching fallback secret key. |
| `AWS_DEFAULT_REGION` | `us-east-1` | General AWS region fallback. |
| `AWS_BUCKET` | unset | General bucket fallback. |

## Bill.com

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_BILL_COM_ENABLED` | `false` | Master toggle. Daily syncs no-op when off. |
| `CLOCKWORK_BILL_COM_USERNAME` / `_PASSWORD` / `_ORG_ID` / `_DEV_KEY` | unset | All required when enabled. Generate the dev key at `developer.bill.com`. |
| `CLOCKWORK_BILL_COM_BASE_URL` | `https://api.bill.com/api/v2` | The advertised v3 host has no DNS — v2 is what works. |
| `CLOCKWORK_BILL_COM_CARE_PLAN_ITEM_REGEX` | `/care plan/i` | Item names matching this regex are treated as care-plan-revealing. |
| `CLOCKWORK_BILL_COM_CUSTOMER_LINK_WINDOW_DAYS` | `1095` | 3 years of invoice history walked for site↔customer linking. |
| `CLOCKWORK_BILL_COM_CARE_PLAN_WINDOW_DAYS` | `400` | Catches both monthly and annual invoicing cadences. |

## Mattermost

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_MATTERMOST_ENABLED` | `false` | Master toggle. |
| `CLOCKWORK_MATTERMOST_WEBHOOK_URL` | unset | Required when enabled. |
| `CLOCKWORK_MATTERMOST_CHANNEL` | unset | Lowercase slug. Mixed case is silently rejected. |
| `CLOCKWORK_MATTERMOST_USERNAME` | `Clockwork` | |
| `CLOCKWORK_MATTERMOST_ICON_EMOJI` | `:lock:` | |

## Slack

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_SLACK_ENABLED` | `false` | Master toggle. Independent of `CLOCKWORK_MATTERMOST_ENABLED` — both can be on at once. |
| `CLOCKWORK_SLACK_WEBHOOK_URL` | unset | Required when enabled. |
| `CLOCKWORK_SLACK_CHANNEL` | unset | |
| `CLOCKWORK_SLACK_USERNAME` | `Clockwork` | |
| `CLOCKWORK_SLACK_ICON_EMOJI` | `:lock:` | |

See [Integrations → Slack](/docs/integrations/slack). The per-site client-facing Slack channel (`ClientSlackNotifier`) has no env vars of its own — its webhook is client-configured per site — but it does read the **Operator identity** vars below for the operator name/support link it puts in client-facing messages.

## Performance scans

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_GTMETRIX_API_KEY` | empty | GTmetrix REST API v2 — **primary engine**. Paid plan required (free tier is 5 tests/day). Empty falls through to PSI. |
| `CLOCKWORK_GTMETRIX_REGION` | `4` | Default test location, integer GTmetrix location ID (4 = San Antonio TX). Per-site override on `sites.performance_scan_region`. |
| `CLOCKWORK_GTMETRIX_BASE_URL` | `https://gtmetrix.com/api/2.0` | |
| `CLOCKWORK_GTMETRIX_TIMEOUT` | `120` | Seconds, per HTTP call. |
| `CLOCKWORK_GTMETRIX_POLL_INTERVAL` | `5` | Seconds between completion polls. |
| `CLOCKWORK_GTMETRIX_POLL_MAX_ATTEMPTS` | `36` | 5s × 36 = 180s poll ceiling. |
| `CLOCKWORK_PSI_API_KEY` | empty | Google PageSpeed Insights v5 — **fallback engine**, runs only when GTmetrix errors. Free 25k req/day. |
| `CLOCKWORK_PSI_TIMEOUT` | `90` | Seconds. PSI can be genuinely slow. |

## Security scans

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_GOOGLE_WEB_RISK_KEY` | empty | Google Cloud Web Risk API (commercial standard). Free up to 100k req/mo. Do not put a v4 Safe Browsing secret here. |
| `CLOCKWORK_GOOGLE_SAFE_BROWSING_KEY` | empty | Legacy non-commercial Safe Browsing v4. Used only when the Web Risk key is empty. |
| `CLOCKWORK_URLHAUS_AUTH_KEY` | empty | Optional. Free registration at auth.abuse.ch. |
| `CLOCKWORK_BLACKLIST_TIMEOUT` | `10` | Per-source timeout. |
| `CLOCKWORK_SUCURI_BASE_URL` | `https://sitecheck.sucuri.net` | |
| `CLOCKWORK_SUCURI_TIMEOUT` | `30` | |

## SSH defaults

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_DEFAULT_SSH_USER` | `clockwork-deploy` | The non-root sudo user we provision on every server. |
| `CLOCKWORK_DEFAULT_SSH_PORT` | `22` | |
| `CLOCKWORK_SSH_KEY_PATH` | unset | Local default private key. Per-server keys override. |
| `CLOCKWORK_SSH_KEY_PASSPHRASE` | unset | If the key is passphrase-protected. |
| `CLOCKWORK_SSH_CONNECT_TIMEOUT` | `10` | |
| `CLOCKWORK_SSH_EXEC_TIMEOUT` | `30` | Per-command override available in the client call. |
| `CLOCKWORK_SSH_PREFLIGHT_TIMEOUT` | `2` | Short `fsockopen` probe before the real SSH handshake, so a deleted/firewalled server fails fast instead of hanging past `max_execution_time`. |
| `CLOCKWORK_SCHEDULED_JOBS_RETENTION_DAYS` | `30` | How long [Settings → Scheduled Jobs](/docs/features/scheduled-jobs-dashboard) keeps run history before `clockwork:prune-scheduled-job-runs` deletes it. |

## Monitoring thresholds

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_CPU_RED_THRESHOLD` | `90` | Percent. Drives green/yellow/red on the dashboard. |
| `CLOCKWORK_CPU_YELLOW_THRESHOLD` | `70` | |
| `CLOCKWORK_MEM_YELLOW_THRESHOLD` | `80` | |
| `CLOCKWORK_DISK_YELLOW_THRESHOLD` | `85` | |
| `CLOCKWORK_METRICS_WINDOW_MINUTES` | `15` | Window for the DO jiffy delta. |
| `CLOCKWORK_SSL_RENEWAL_GRACE_HOURS` | `48` | Grace after the LE renewal date before flagging yellow. |
| `CLOCKWORK_AUTO_IGNORE_PATTERNS` | empty | Comma-separated substrings. Servers matching get auto-ignored on first import. |
| `CLOCKWORK_AUTO_IGNORE_REASON` | `Behind firewall — auto-flagged from name pattern` | |
| `CLOCKWORK_SERVER_DISPLAY_NAME_STRIP_SUFFIX` | empty | `Server::display_name` strips this suffix in UI tables — e.g. set to `.example.com` and "web36.example.com" shows as "web36". Empty (default) shows the full name unchanged. |

## LM Studio (local LLM)

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_LM_STUDIO_BASE_URL` | `http://localhost:1234/v1` | Loopback only — no data leaves the box. |
| `CLOCKWORK_LM_STUDIO_MODEL` | `meta-llama-3-8b-instruct` | |
| `CLOCKWORK_LM_STUDIO_API_KEY` | `lm-studio` | LM Studio doesn't enforce, but keep something here. |
| `CLOCKWORK_LM_STUDIO_TIMEOUT` | `120` | |

## Companion mu-plugin

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_COMPANION_DIST_URL` | unset | When set, installer fetches signed tarball. Empty = local rsync mode (dev). |
| `CLOCKWORK_COMPANION_DIST_SHA256` | unset | **REQUIRED** when `DIST_URL` is set. Mismatched hash aborts the install. |
| `CLOCKWORK_COMPANION_LOCAL_PATH` | `~/Projects/clockwork-companion` | Resolved via `posix_getpwuid` first because `env('HOME')` is null under Herd's php-fpm. |
| `CLOCKWORK_COMPANION_TIMEOUT` | `30` | Per-call timeout for HTTP to the plugin. |
| `CLOCKWORK_COMPANION_TIMEOUT_MULTISITE` | `60` | Longer timeout for calls that fan out across every subsite on a multisite install. |
| `CLOCKWORK_COMPANION_VERSION` | `1.34.0` | The mu-plugin version bundled with this Core release. Compared per-site against `sites.companion_version` to compute the fleet rollout breakdown on `/settings/updates` — bump this when a new Companion tarball ships. |

## Operator identity

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_OPERATOR_NAME` | `Clockwork operator` | Whoever runs this instance — appears in client-facing Slack messages and email footers. |
| `CLOCKWORK_OPERATOR_CONTACT_EMAIL` | unset | Appended to the uptime prober's User-Agent (`Clockwork-Uptime/1.0 (+you@example.com)`) so a monitored site's admin can identify and, if needed, whitelist or contact the bot. Sent to every monitored site on every probe — set deliberately, not by accident. |
| `CLOCKWORK_OPERATOR_SUPPORT_URL` | unset | Linked in client-facing Slack alerts (`ClientSlackNotifier`). |
| `CLOCKWORK_OPERATOR_WEBSITE_URL` | unset | Linked in the footer of outbound client emails (vulnerability reports, nightly update summaries). |

Added in the modularization roadmap's Phase 8, replacing what used to be a hardcoded name/email/URL baked directly into `UptimeProber`, `ClientSlackNotifier`, and two email templates.

## Twilio SMS

| Variable | Default | Notes |
|---|---|---|
| `TWILIO_ACCOUNT_SID` | unset | Account SID from Twilio Console. |
| `TWILIO_AUTH_TOKEN` | unset | Auth token from Twilio Console. |
| `TWILIO_FROM_NUMBER` | unset | Purchased Twilio phone number (E.164 format, e.g. `+14045550100`). |
| `TWILIO_ENABLED` | `false` | Set to `true` to activate SMS dispatch. **Feature is wired but currently paused** — no credentials configured yet. Recipients and off-windows are managed at `/settings/notifications`. |

## Alerts

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_ALERTS_EMAIL` | unset | Fallback destination when no on-call SMS recipient is available (`clockwork.alerts.email`). See [Integrations → Twilio](/docs/integrations/twilio). |

## Module Directory

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_MODULES_API_URL` | `https://clockworkcontrol.com/api/modules.json` | Official + community module feed, browsed at `/settings/modules`. Point at a local mirror if you don't want this instance calling out to clockworkcontrol.com. |
| `CLOCKWORK_MODULES_CACHE_TTL` | `21600` (6h) | Seconds the fetched feed is cached before the next scheduled/manual refresh re-pulls it. |

## Telemetry

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_TELEMETRY_ENABLED` | `true` | Anonymous usage reporting — on by default; can be disabled during install or in Settings → Maintenance. |
| `CLOCKWORK_TELEMETRY_ENDPOINT` | `https://telemetry.clockworkcontrol.com/v1/report` | Where the weekly report is sent, if enabled. |

Clockwork Control sends a small, anonymous usage report once a week: exact site and server counts, the list of enabled modules, and a per-module breakdown of how many sites/servers each one covers (e.g. how many sites are on SpinupWP vs. Pressable, how many servers are on DigitalOcean vs. Hetzner). Legacy bucketed counts (`1-5`, `6-25`, etc.) are also included for backwards compatibility with older report consumers. It never includes site URLs, hosting credentials, content, or IP data. You can toggle this anytime in Settings → Maintenance or set `CLOCKWORK_TELEMETRY_ENABLED=false` to keep this instance fully offline.

See [DISCLAIMER.md](/DISCLAIMER.md) for the full data-handling commitment.

## System updates

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_UPDATE_CHANNEL` | `stable` | Cosmetic label only, shown on the updates page — doesn't currently filter which GitHub releases are considered. |
| `CLOCKWORK_UPDATE_REPO` | `Clockwork-Web-Dev-LLC/clockwork-control` | Repo slug used to build the default releases API URL below. Change this if you're running a fork. |
| `CLOCKWORK_UPDATES_API_URL` | `https://api.github.com/repos/Clockwork-Web-Dev-LLC/clockwork-control/releases/latest` | Full override for the release-check endpoint, in case `CLOCKWORK_UPDATE_REPO` alone isn't enough (e.g. a private mirror). |
| `CLOCKWORK_UPDATES_CACHE_TTL` | `43200` (12h) | Seconds the latest-release check is cached. "Check Again" on `/settings/updates` bypasses this. |

See [Features → System updates](/docs/features/system-updates).

## Domain Expiration (RDAP)

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_RDAP_TIMEOUT` | `10` | RDAP HTTP request timeout in seconds. |
| `CLOCKWORK_RDAP_RATE_LIMIT` | `10` | Cloudflare-capped rate limit ceiling (requests per 10 seconds). |
| `CLOCKWORK_RDAP_TLD_BACKOFF_HOURS` | `2` | Registry cooldown duration in hours after an authoritative registry returns 429 or 503. |

## SEO Indexability Watchdog

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_SEO_MONITORING_ENABLED` | `true` | Global kill-switch for automated SEO indexability checks. |
| `CLOCKWORK_SEO_ROBOTS_TXT_TIMEOUT` | `10` | Timeout in seconds when fetching `/robots.txt` or homepage pre-flight checks. |

## Misc

| Variable | Default | Notes |
|---|---|---|
| `CLOCKWORK_ARCJET_BOTS_URL` | GitHub raw URL | Override only if you mirror the file. |
