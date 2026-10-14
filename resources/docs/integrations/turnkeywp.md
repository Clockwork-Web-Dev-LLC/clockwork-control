---
title: TurnkeyWP
section: Integrations
order: 26
updated: 2026-10-14
author: Aaron Reimann
tags: [integrations, turnkeywp, hosting, control-panel, wordpress, migration]
tracks: [modules/TurnkeyWp/**, app/Console/Commands/ImportSpinupWp.php]
---

TurnkeyWP is Clockwork's own self-hosted WordPress hosting control plane (`app.turnkeywp.com`). Our servers and sites are moving to it from SpinupWP over time. Like SpinupWP, it runs **real Ubuntu servers** with one Linux user, PHP-FPM pool and database per site, and the same on-disk layout: each site lives in `/sites/<domain>/`, with its webroot at `/sites/<domain>/files`.

Clockwork Control talks to TurnkeyWP in two ways:

1. **TurnkeyWP REST API v1** for inventory: servers and sites are imported every hour.
2. **SSH** for everything else (WP-CLI, Companion installs, log tailing, apt polling), as the server's `turnkey` sudo user. TurnkeyWP disables root login once a server is provisioned.

The full API reference lives in the TurnkeyWP repo at `docs/integrations/turnkeywp-api-v1.md`.

---

## Setup

### 1. Issue a token in TurnkeyWP

Sign in to TurnkeyWP as an **Owner** and open **Settings → API tokens** (`/settings/api-tokens`). Create a token named "Clockwork Control" and copy it; it's only shown once.

Use an Owner's token. TurnkeyWP only lists the servers and sites the token's user can see, so a Site Admin or Server Admin token imports part of the fleet. Tokens carry the user's full permissions and don't expire, so revoke this one in the same screen if it leaks.

### 2. Give Clockwork Control the token

Either in `.env`:

```env
TURNKEYWP_TOKEN=your-token
# Optional (this is the default):
TURNKEYWP_BASE_URL=https://app.turnkeywp.com/api/v1
# Optional: true blocks TurnkeyWP API writes, SSH commands and Companion installs.
TURNKEYWP_VIEW_ONLY=false
```

or in the app under **Settings → Integrations → TurnkeyWP**.

### 3. Check the connection

```bash
php artisan clockwork:test-turnkeywp
```

It calls `GET /user`, `GET /servers` and `GET /sites` and prints what the token can see. It never writes. The same check runs on `/settings/diagnostics` as **TurnkeyWP API**.

### 4. Import

```bash
php artisan clockwork:import-turnkeywp --dry-run   # report only
php artisan clockwork:import-turnkeywp
```

After that the scheduler runs the import every hour at :32.

### 5. Give Clockwork Control SSH access

New TurnkeyWP servers are imported with `ssh_user = turnkey`. Add Clockwork Control's public key to that user on each server:

```bash
php artisan clockwork:turnkeywp-authorize-ssh            # every TurnkeyWP server with ssh_user turnkey
php artisan clockwork:turnkeywp-authorize-ssh --server=12 # one server (Clockwork server id)
```

This posts the key to TurnkeyWP (`POST /servers/{id}/ssh-keys`), and TurnkeyWP deploys it to the server in the background. The key comes from `CLOCKWORK_SSH_KEY_PATH` with `.pub` appended, or from `--public-key-file`. Servers that already have the key are skipped. This is a write, so it refuses to run in View-Only mode, and it asks for confirmation unless `--force` is passed.

---

## What the import does

| TurnkeyWP | Clockwork Control |
|---|---|
| server `id` | `servers.turnkeywp_server_id` |
| `ip_address`, `ssh_port`, `name`, `ubuntu_version` | `hostname`, `ssh_port`, `name`, `ubuntu_version` |
| `provider_name` (`Digitalocean`, `Hetzner`, `Custom`) | `provider`, lowercased, set on create only. `clockwork:reconcile-provider` fills `provider_id` by IP, which turns on cloud metrics. |
| `ssh_user` | `ssh_user`, set on create only, so a manual change sticks |
| `upgrade_required`, `reboot_required` | the same columns, with the same staleness guards as the SpinupWP import |
| site `id` | `sites.turnkeywp_site_id`, `hosting_provider = turnkeywp` |
| `domain`, `site_user`, `is_wordpress`, `database.table_prefix` | the same columns, and `wp_path = /sites/<domain>/files` |

Certificates are tracked by the live TLS probe (`cert_source = live_probe`). TurnkeyWP's site payload has a certificate expiry date, but its renewal date and its WordPress update flags (`wp_core_update` and friends) are placeholders today, so they aren't imported.

**Skipped by default**

- **Staging sites** (temporary `sslip.io`-style domains). Pass `--include-staging` to import them; they are imported with uptime alerts off.
- **Sites still installing or failed.** They're picked up once TurnkeyWP reports them as deployed.
- **Servers without an IP yet** (still being created).
- **Domains dropped from ingest** (`site_ingest_exclusions`).

**Deletions**

TurnkeyWP filters its listings by the token's team role, and the API doesn't say which role that is. So "not listed" can mean "deleted" or "not visible to this token", and the import is careful about clearing anything:

- **Sites.** A site TurnkeyWP stops listing has its `turnkeywp_site_id` cleared, but only on servers this run *did* list (visibility is granted per server). Nothing is cleared when a listing comes back partial (TurnkeyWP stopped answering partway through) or empty.
- **Servers.** These are never cleared automatically. The import prints the ones TurnkeyWP no longer lists (`not_listed` in the summary), and you decide what to do with them.

**One domain listed twice.** Move Site keeps the old copy listed in TurnkeyWP, under its own id, until you delete it. The row stays on the copy it already follows, which avoids swapping servers every hour. It moves to the new copy once the old one is no longer `deployed` or is gone.

---

## Moving sites from SpinupWP

During the migration the same server or domain can be listed by both SpinupWP and TurnkeyWP. Each row has a single owner:

- **Sites.** The TurnkeyWP import never takes over a domain that another host still tracks (`spinupwp_id`, `pressable_site_id`, … set). It prints `still tracked by …` and moves on. Once the old copy is deleted from SpinupWP, the SpinupWP import (:30) clears its `spinupwp_id`, and the TurnkeyWP import (:32) adopts the same row with its history intact, before `clockwork:find-orphan-sites` (:35) could flag it.
- **Moving a site at DNS cutover**, before you delete the old SpinupWP copy:

  ```bash
  php artisan clockwork:import-turnkeywp --adopt=example.com
  ```

  This clears the row's `spinupwp_id` and moves it to TurnkeyWP. From then on the SpinupWP import skips the domain, because it is tracked under TurnkeyWP. `--adopt` only releases rows SpinupWP owns.
- **Servers TurnkeyWP adopted from SpinupWP.** These have the same IP in both. The server row keeps belonging to SpinupWP (SSH user, update flags, name), and the TurnkeyWP import only records `turnkeywp_server_id`, so sites can link to it. Once SpinupWP lets go of the server, the TurnkeyWP import takes over the remaining fields. It still keeps the existing `ssh_user`, so SSH access doesn't change underneath you.

---

## Capabilities

| Capability | TurnkeyWP |
|---|---|
| SSH / WP-CLI (`CAP_SSH`) | Yes, unless View-Only |
| Companion install (`CAP_COMPANION`) | Yes, unless View-Only |
| Server linkage | Yes |
| Certificate sync from the host | No. The live TLS probe is used. |
| Orphan detection | No. `find-orphan-sites` is SpinupWP-only; deletions are handled by the import's own sweep. |
| Backup relay, native performance scans | No |

The "open in panel" link goes to `https://app.turnkeywp.com/servers/{server}/sites/{site}`.

---

## Rate limits

TurnkeyWP allows 60 API requests per minute per token. On a 429 the client waits for `Retry-After` and retries twice. The import only uses the list endpoints, at 100 records per page, so even a large fleet takes a few requests.
