---
title: APIs we call
section: Reference
order: 10
updated: 2026-09-10
author: Aaron Reimann
tags: [reference, api, integrations]
tracks: [app/Services/*/*Client.php, modules/*/src/*Client.php, app/Services/Companion/ClockworkCompanionClient.php]
---

Every outbound HTTP call this app makes, grouped by service. If something feels mysterious ("where does this number come from?"), the source is almost certainly in this list. Endpoints are the exact paths the corresponding `*Client.php` actually hits — kept synchronized across core services and modules by the `tracks:` glob above.


## DigitalOcean — `https://api.digitalocean.com/v2`

`modules/DigitalOcean/src/DigitalOceanClient.php` · Bearer `CLOCKWORK_DIGITALOCEAN_TOKEN`

| Method | Path | What it does |
|---|---|---|
| GET | `/account` | Sanity-check the token (`clockwork:digitalocean-test`). |
| GET | `/droplets` | List every droplet, paginated 200 at a time. Used for IP↔server matching during import. |
| GET | `/monitoring/metrics/droplet/cpu` | Per-droplet CPU jiffy series. |
| GET | `/monitoring/metrics/droplet/load_1` | 1-minute load. |
| GET | `/monitoring/metrics/droplet/memory_{free,total,available}` | Memory series. We use `available`, not `free` — see CLAUDE.md gotcha. |
| GET | `/monitoring/metrics/droplet/filesystem_{free,size}` | Disk series. |

Driven by `clockwork:poll-servers` every 5 minutes for servers whose `provider='digitalocean'`.

## Hetzner Cloud — `https://api.hetzner.cloud/v1`

`modules/Hetzner/src/HetznerClient.php` · Bearer `CLOCKWORK_HETZNER_TOKEN`

| Method | Path | What it does |
|---|---|---|
| GET | `/locations` | Auth probe (Hetzner has no `/account`). Used by `clockwork:hetzner-test` and the inventory bootstrap. |
| GET | `/servers` | List every server in the project, paginated (50/page). Used for IP↔server matching during import. |
| GET | `/servers/{id}/metrics?type=cpu` | Per-server CPU%, ready-to-use percentage (no jiffy delta). |
| GET | `/servers/{id}/metrics?type=disk` | Disk IO rate (not capacity %). |
| GET | `/servers/{id}/metrics?type=network` | Network throughput. |

Driven by `clockwork:poll-servers` every 5 minutes for servers whose `provider='hetzner'`. Hetzner's metrics surface is narrower than DO's — no memory, no filesystem-free, no load. Secondary panels stay null on Hetzner servers.

## Azure — `https://management.azure.com` (ARM) + Azure Monitor Metrics

`modules/Azure/src/AzureClient.php` · OAuth 2.0 client-credentials (Service Principal) against `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`, Bearer token cached in-memory for its lifetime.

| Method | Path | What it does |
|---|---|---|
| GET | `/subscriptions/{id}` (`api-version=2022-12-01`) | Auth probe (`clockwork:azure-test`). |
| GET | `/subscriptions/{id}/providers/Microsoft.Compute/virtualMachines` (`api-version=2024-03-01`) | List every VM in the subscription, paginated via `nextLink`. Used for IP↔server matching during import/reconcile. |
| GET | `/subscriptions/{id}/providers/Microsoft.Network/publicIPAddresses` (`api-version=2024-03-01`) | Public IPs — Azure doesn't expose them directly on the VM object, so `ReconcileProvider` cross-references NIC linkage to match by `Server::hostname`. |
| GET | `{resourceId}/providers/microsoft.insights/metrics` (`api-version=2018-01-01`) | Per-VM metrics (`Percentage CPU`, `Available Memory Bytes`), 1-minute average interval over a caller-supplied timespan. |

Third cloud provider alongside DigitalOcean and Hetzner — `clockwork:poll-servers` and `clockwork:reconcile-provider` branch on all three. Azure Monitor exposes CPU and memory directly; disk % would need the Azure Monitor Agent installed per-VM, so disk stays null on Azure servers the same way Hetzner's does. See [Integrations → Azure](/docs/integrations/azure).

## Vultr — `https://api.vultr.com/v2`

`modules/Vultr/src/VultrClient.php` · Bearer `CLOCKWORK_VULTR_API_KEY`

| Method | Path | What it does |
|---|---|---|
| GET | `/account` | Auth probe — `/settings/diagnostics` Vultr check. |
| GET | `/instances` | List every instance, cursor-paginated (`meta.links.next`). Used for IP↔server matching during reconcile. |

Driven by `clockwork:reconcile-provider` for IP matching. No metrics endpoint is called — Vultr's API v2 doesn't expose CPU/memory/disk time series, so `VultrCloudProvider::metrics()` always returns nulls and `clockwork:poll-servers` gets no signal for `provider='vultr'` servers beyond alive/dead. See [Integrations → Vultr](/docs/integrations/vultr).

## Linode (Akamai) — `https://api.linode.com/v4`

`modules/Linode/src/LinodeClient.php` · Bearer `CLOCKWORK_LINODE_TOKEN`

| Method | Path | What it does |
|---|---|---|
| GET | `/account` | Auth probe — `/settings/diagnostics` Linode check. |
| GET | `/linode/instances` | List every linode, page-paginated. Used for IP↔server matching during reconcile. |
| GET | `/linode/instances/{id}/stats` | CPU time series (`data.cpu = [[unix_ts, pct], …]`, summed across all vCPUs — `LinodeCloudProvider::metrics()` divides by `server.vcpus` to normalize to a single-core percentage). |

Driven by `clockwork:poll-servers` (CPU only — memory/disk/load stay null, same gap as Hetzner/Azure) and `clockwork:reconcile-provider` for IP matching. See [Integrations → Linode](/docs/integrations/linode).

## DigitalOcean Spaces — S3-compatible

`app/Services/DigitalOcean/SpacesClient.php` · HMAC `CLOCKWORK_DO_SPACES_KEY` + `_SECRET` (NOT the DO PAT)

S3 `ListObjectsV2` against `<bucket>/<domain>/`. SpinupWP writes backups as `<domain>/<YYYY-MM-DD-HH-MM-SS>-<suffix>.{sql.gz|tar.gz}`; we group by timestamp into one row per backup run. Used because SpinupWP's REST API exposes backup *config* but not *history*.

## SpinupWP — `https://api.spinupwp.app/v1`

`modules/SpinupWp/src/SpinupWpClient.php` · Bearer `CLOCKWORK_SPINUPWP_TOKEN`

| Method | Path | What it does |
|---|---|---|
| GET | `/servers`, `/servers/{id}` | Inventory bootstrap + per-server refresh. |
| GET | `/sites`, `/sites/{id}` | Same for sites. Includes `backups` sub-object (config only). |
| GET | `/sites/{id}/events` | Recent events; falls back gracefully on older accounts. |
| POST | `/sites`, `/sites/{id}/domains` | Used by the migration runner to create destination sites and add domains. |

Daily import at 03:30 (`clockwork:import-spinupwp`).

## Pressable — `https://my.pressable.com/v1`

`modules/Pressable/src/PressableClient.php` · OAuth 2.0 client-credentials against `https://my.pressable.com/auth/token`, Bearer token cached ~55 of its 60-minute life. No server concept — every call is addressed by `site_id` alone.

| Method | Path | What it does |
|---|---|---|
| GET | `/account` | Auth probe (`clockwork:pressable-test`). |
| GET | `/sites` | Inventory bootstrap (paginated). |
| GET | `/sites/{id}` | Per-site refresh. |
| DELETE | `/sites/{id}/edge-cache`, `/sites/{id}/object-cache` | Cache-flush buttons on the site detail page. |
| POST | `/sites/{id}/wordpress/commands`, `/sites/{id}/wordpress/wpcli` | Fire-and-forget shell / wp-cli commands — see `PressableCommandRunner` for how this becomes synchronous (result read back from the activity log, not the response body). |
| GET | `/sites/{id}/backups`, `/backups/fs`, `/backups/db` | Backup run history — `clockwork:pressable-backups-report` (06:32 daily). |
| GET | `/sites/{id}/reports/performance/latest` | Pressable's own Lighthouse report — see [Integrations → Pressable](/docs/integrations/pressable). |
| GET | `/sites/{id}/statistics` | Page-view stats — `clockwork:pressable-traffic-report` (06:37 daily). |
| GET | `/sites/{id}/security-alerts/{plugins,themes}` | Known-vulnerability + Defensive Mode status — `clockwork:pressable-security-summary-report` (06:39 daily), Pressable-only, no SpinupWP equivalent. |
| POST | `/sites/{id}/metrics`, `/sites/{id}/logs/activity` | CPU/MySQL resource metrics and the activity-log poll `PressableCommandRunner` uses for command results. |

## Cloudflare — `https://api.cloudflare.com/client/v4`

`app/Services/Cloudflare/CloudflareClient.php`

Two scoped tokens — separated so a leak of the read token can't mutate DNS:

- `CLOCKWORK_CLOUDFLARE_API_TOKEN` — read (Zone:Read + Zone WAF:Read + the per-phase scopes documented in `integrations/cloudflare`).
- `CLOCKWORK_CLOUDFLARE_WRITE_TOKEN` — Zone.DNS:Edit, used only by the migration runner's cutover phase.

| Method | Path | What it does |
|---|---|---|
| GET | `/zones?name=...` | Zone lookup by hostname. |
| GET | `/zones/{id}/rulesets/phases/{phase}/entrypoint` | Redirect / Transform / WAF / Config rules. |
| GET | `/zones/{id}/pagerules` | Legacy Page Rules. |
| GET | `/zones/{id}/dns_records` | DNS table for the diagnostic dump. |
| PATCH/POST | `/zones/{id}/dns_records[/...]` | Migration cutover only. Write token required. |

## Bill.com — `https://api.bill.com/api/v2`

`app/Services/BillCom/BillComClient.php` · Session-based: POST `/Login.json` with username/password/orgId/devKey, cache `sessionId` for 25 min. The advertised v3 hostname has no DNS — v2 is what works.

`POST /List/{Customer,Item,Invoice}.json` (paginated 100 at a time, date-filtered for invoices).

Daily at 01:00 + 01:30, gated on `CLOCKWORK_BILL_COM_ENABLED`.

## Companion mu-plugin — `https://{domain}/wp-json/clockwork/v1/*`

`app/Services/Companion/ClockworkCompanionClient.php` · HMAC-SHA256 with per-site secret (`X-Clockwork-Signature` + `X-Clockwork-Timestamp`, 5-min replay window).

Routes: `/health`, `/detect`, `/snapshot`, `/plugins`, `/admins`, `/wp-cron`, `/comments-summary`, `/lockouts`, `/wordfence-blocks`, `/test-contact-form`, `POST /backups-report`, `POST /sso/magic-link`, `POST /plugins/update`, `POST /action-log/append`, `POST /secret/rotate`, `POST /malware-scan` (in-WP malware probe — bypasses Cloudflare; SSH wp-cli fallback exists for sites without the plugin). Full details on the `architecture/companion-plugin` page.

## wpvulnerability.net — `https://www.wpvulnerability.net/plugin/{slug}`

`app/Services/Security/WpVulnerabilityClient.php` · No auth, keyless. `GET` per unique plugin slug installed anywhere in the fleet (~150-250 slugs), 100ms between requests, replaces the local `plugin_vulnerabilities` mirror in one transaction. Per-slug failures are recorded but don't abort the run. Replaced Wordfence's free Threat Intelligence v2 feed after it moved to authenticated v3 — wpvulnerability.net aggregates CVE/Patchstack/WPScan/Wordfence into one free feed. Feeds the Issues page's vulnerable-plugin flags. Daily at 03:15 (`clockwork:refresh-plugin-vulnerabilities`). Separate from Pressable's own CVE feed (`security-alerts/plugins`/`themes` on `PressableClient`) — Pressable-hosted sites get both.

## Sucuri SiteCheck — `https://sitecheck.sucuri.net/api/v3/?scan=<url>`

No auth, ~30 req/min ceiling — `clockwork:scan-sitecheck` sleeps 250 ms between sites. Same engine ManageWP resold. Weekly Monday 02:00.

## GTmetrix REST v2 — `https://gtmetrix.com/api/2.0`

`modules/GTmetrix/src/GtmetrixClient.php` · HTTP Basic, API key as username, blank password. **Primary performance engine.** `POST /tests` (JSON:API — `Content-Type: application/vnd.api+json` is mandatory; `location` is an integer ID, not a slug) then poll `GET /tests/{id}` at 5s intervals, 180s ceiling. Completion = `data.type` flips to `report` (the `state` attribute disappears). Nightly 03:30 cron.

## Google PageSpeed Insights v5 — `https://www.googleapis.com/pagespeedonline/v5/runPagespeed`

`modules/PageSpeedInsights/src/PageSpeedInsightsClient.php` · `key=CLOCKWORK_PSI_API_KEY` (free 25k/day). **Fallback engine** — called when GTmetrix encounters an error or reaches capacity; rows tagged `engine='psi-fallback'`. One request asks for four Lighthouse categories via repeating `category=` params (`performance`, `accessibility`, `best-practices`, `seo`). Bracket arrays (`category[0]=`) are ignored by PSI v5.

## Google Cloud Web Risk — `https://webrisk.googleapis.com/v1/uris:search`

`GET` with `key=CLOCKWORK_GOOGLE_WEB_RISK_KEY` and repeating `threatTypes=`. Primary source for `clockwork:check-blacklists`. Empty JSON `{}` means clean.

## Google Safe Browsing v4 — `https://safebrowsing.googleapis.com/v4/threatMatches:find`

`POST` with `key=CLOCKWORK_GOOGLE_SAFE_BROWSING_KEY`. Used only when `CLOCKWORK_GOOGLE_WEB_RISK_KEY` is empty. The v4 secret must not be copied into the Web Risk env var — they are different APIs, and stuffing a v4 key into the Web Risk slot would call `webrisk.googleapis.com` with a key that API will reject.

## URLhaus — `https://urlhaus-api.abuse.ch/v1/host/`

`POST`, `Auth-Key: CLOCKWORK_URLHAUS_AUTH_KEY` (free registration, optional).

## Spamhaus DBL — DNS, no API

`{domain}.dbl.spamhaus.org` lookup. 127.0.1.x return codes mean spam (2), phish (4), malware (5), botnet (6). Always runs, no key needed.

## Twilio — SMS, via the official PHP SDK (`twilio/sdk`)

`modules/Twilio/src/TwilioClient.php` · Auth is Account SID + Auth Token (`TWILIO_ACCOUNT_SID`/`TWILIO_AUTH_TOKEN`), no raw `Http::` calls — the SDK's `Client::messages->create()` handles the actual `api.twilio.com` request. Sends one SMS per on-call recipient for care-plan site-down/site-up alerts (`App\Services\Twilio\SmsNotifier`), mirroring `MattermostNotifier`'s public surface so `UptimeStateUpdater` can fire both channels with parallel signatures. Gated on `TWILIO_ENABLED` — kept `false` pre-A2P-10DLC-approval to preserve Mattermost-only behavior even with credentials present. Zero on-call recipients falls back to email + a Mattermost `@channel` warning so an alert is never silently dropped. See [Integrations → Twilio](/docs/integrations/twilio).

## Mattermost — webhook `CLOCKWORK_MATTERMOST_WEBHOOK_URL`

`modules/Mattermost/src/MattermostNotifier.php` · `POST {text, channel?, username, icon_emoji, attachments?}`. Channel slug must be lowercase — Mattermost silently rejects mixed case.

## Google OAuth — `accounts.google.com` + `oauth2.googleapis.com`

Via `laravel/socialite` Google driver. Authorize → token → userinfo. `GOOGLE_HD` optionally pins to a Workspace domain.

## Mailgun — `MAILGUN_ENDPOINT` (api.mailgun.net | api.eu.mailgun.net)

Symfony Mailgun transport. Only active when `MAIL_MAILER=mailgun`. Sends contact-form failure / recovery alerts and the monthly summaries.

## Arcjet — `https://raw.githubusercontent.com/arcjet/well-known-bots/main/well-known-bots.json`

Plain GET, no auth. Refreshed daily at 03:00 into `allowed_bots`.

## ICANN RDAP — `https://rdap.org`

`app/Services/Domains/RdapClient.php` (`RdapClient`) · Public ICANN Registration Data Access Protocol (RFC 7483 / 9083), no authentication key required.

| Method | Path | What it does |
|---|---|---|
| GET | `/domain/{domain}` | Resolves domain registration, registrar vCard, and expiration timestamp. Follows 302 redirects to authoritative TLD registries (e.g. Verisign, PIR). |

Driven by `clockwork:check-domain-expirations` daily at 06:05 UTC. Rate-limited to 10 requests per 10 seconds, with automated 2-hour backoff upon HTTP 429/503 responses from specific TLD endpoints. See [Features → Domain expiration tracking](/docs/features/domain-expiration).

## LM Studio — `http://localhost:1234/v1` (loopback only)

OpenAI-compatible local LLM. No data leaves the box. Used by the experimental nginx-log threat analyzer.

## Out-of-process: SSH

Not HTTP, but worth listing here because it is by far the highest-volume external dependency. Every server we manage is reached via `phpseclib3` against the user in `CLOCKWORK_DEFAULT_SSH_USER` (default `clockwork-deploy`). See `integrations/ssh-and-fail2ban` for the auth precedence and the commands we run.
