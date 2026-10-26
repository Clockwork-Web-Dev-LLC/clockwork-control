---
title: TurnkeyWP API v1 reference
section: Integrations
order: 27
updated: 2026-10-14
author: Aaron Reimann
tags: [integrations, turnkeywp, api, reference]
tracks: [modules/TurnkeyWp/**]
---

> Copied from the TurnkeyWP repo (`docs/integrations/turnkeywp-api-v1.md`), which is the source of truth. How Clockwork Control uses this API: [Integrations → TurnkeyWP](/docs/integrations/turnkeywp).

This reference is for developers building an API client in another application, such as a `Modules\TurnkeyWp` module in Clockwork Control. You don't need the TurnkeyWP codebase to use it. Every statement here was checked against the code as of 2026-10-14: `routes/api.php`, `app/Http/Controllers/Api/V1/*`, `app/Http/Resources/Api/V1/*`, `app/Http/Middleware/AuthorizeFleetAccess.php` and the models and enums they use. Where the code is surprising or looks wrong, this document describes what the code actually does, and the [Known gaps and inconsistencies](#13-known-gaps-and-inconsistencies) section lists each case with file and line references.

Contents:

1. [Basics: base URL, auth, tokens, rate limit](#1-basics)
2. [Authorization: team roles](#2-authorization-team-roles)
3. [Errors](#3-errors)
4. [Pagination](#4-pagination)
5. [The async model: events](#5-the-async-model-events)
6. [Endpoint reference](#6-endpoint-reference)
7. [Resource schemas](#7-resource-schemas)
8. [Identifiers, status values, and reaching a server over SSH](#8-identifiers-status-values-and-reaching-a-server-over-ssh)
9. [Git deploy webhook](#9-git-deploy-webhook)
10. [Quick start (curl)](#10-quick-start-curl)
11. [Client implementation checklist](#11-client-implementation-checklist)
12. [Relationship to the existing hosting client](#12-relationship-to-the-existing-hosting-client)
13. [Known gaps and inconsistencies](#13-known-gaps-and-inconsistencies)

---

## 1. Basics

| Item | Value |
|---|---|
| Base URL (production) | `https://app.turnkeywp.com/api/v1` |
| Auth | Laravel Sanctum personal access token, sent as `Authorization: Bearer <token>` |
| Required headers | `Authorization: Bearer <token>`, `Accept: application/json`; add `Content-Type: application/json` on requests with a body |
| Request bodies | JSON (form-encoded also works, since Laravel reads both) |
| Timestamps | ISO 8601 with offset, e.g. `2026-10-14T09:30:00+00:00`, or `null` |
| IDs | TurnkeyWP's own auto-increment integers |
| Rate limit | 60 requests per minute **per token** |

### Issuing a token

1. Sign in to TurnkeyWP. Public registration is off; an operator creates users with `php artisan turnkey:user`.
2. Go to **Settings → API** at `/settings/api-tokens`.
3. Enter a token name (e.g. `Clockwork Control`) and click **Create Token**.
4. Copy the plain-text token. It is shown only once. It looks like `12|aBcD...` (Sanctum's `<id>|<secret>` format), and you send the whole string.

The same page lists your tokens with their last-used time and lets you revoke them. Revocation takes effect immediately.

### Token abilities and expiry

- Tokens are created with Sanctum's default abilities (`["*"]`). No API endpoint checks token abilities (`tokenCan`), so **you cannot issue a read-only token**.
- Tokens never expire (`config/sanctum.php` `expiration => null`). They stay valid until revoked.
- A token acts with the full permissions of the user who created it. Those permissions come from the user's **team role** (section 2). To limit what an integration can do, create a dedicated TurnkeyWP user with the role you want, and issue the token as that user.

### Rate limit

All `/api/v1/*` routes except the git webhook use the `api` limiter (`AppServiceProvider::configureRateLimiting`):

- 60 requests per minute.
- The key is the token id. Without a token it falls back to the user id, then the IP address. Each token therefore has its own budget, and every request made with it shares that budget, **including event polling**.
- Responses carry `X-RateLimit-Limit` and `X-RateLimit-Remaining`.
- When you exceed the limit, you get **HTTP 429** with `Retry-After` and `X-RateLimit-Reset` headers and the body `{"message": "Too Many Attempts."}`.

The git webhook (`POST /api/v1/webhooks/git-deploy/{token}`) has its own limit of 60 per minute (`throttle:60,1`), keyed by client IP because the request is unauthenticated.

### Other routes outside the v1 group

`GET /api/user` (no `v1`) also exists. It requires `auth:sanctum` but has no `api` throttle and no fleet middleware. Use `GET /api/v1/user` instead.

---

## 2. Authorization: team roles

TurnkeyWP has one team. Every user is the **Owner** or a member with one of the roles below (`App\Enums\TeamRole`). A member can also have a list of assigned server ids (`team_user.server_ids`). **A user who isn't on a team has almost no access**: the server, site and event lists come back empty, and every server, site or task route returns 403. Such a user can still read account-level SSH keys (`GET /ssh-keys`, `GET /ssh-key`) and `GET /providers`, because those endpoints don't filter by team (gap G12).

| Role | Sees | `view-server` | `manage-server` | `create-servers` | `delete-server` |
|---|---|---|---|---|---|
| `owner` | all servers | yes | yes | yes | yes |
| `account_admin` | all servers | yes | yes | yes | no |
| `server_admin` | assigned servers (**all** servers if no assignment list is stored) | assigned | assigned | no | no |
| `site_admin` | assigned servers (**all** servers if no assignment list is stored) | assigned | **no** | no | no |

Notes:

- Access is scoped **per server**, not per site. A `site_admin` assigned to a server can see and operate on every site on that server. The role description says "assigned WordPress sites", but the code has no per-site assignment.
- A `null` `server_ids` list for a `server_admin` or `site_admin` means "every server" (`Team::userServerIds()` returns `null` and `canAccessServer()` then returns `true`).

### How the API enforces roles

**List endpoints** filter with `visibleTo($user)`. Owners and account admins see every row; other roles see only rows on their accessible servers. This applies to `GET /servers`, `GET /sites` and `GET /servers/{id}/sites`. `GET /events` and `GET /ssh-keys` apply the same filter by hand.

**Routes with a `{server}`, `{site}` or `{task}` parameter** go through `AuthorizeFleetAccess`:

1. A `{site}` resolves to its server. A `{task}` (event) resolves to the task's server. A task with no server requires `create-servers`.
2. The caller must pass `view-server` on that server, or gets **403**.
3. For **server-level writes** (any non-GET API request whose route has `{server}` but no `{site}`), the caller also needs `manage-server`. `DELETE /servers/{id}` needs `delete-server` (Owner only) instead.
4. **Site-level writes need only `view-server`.** That includes delete site, change domain, clone, restore backup, enable/disable, HTTPS and cache purges. So a `site_admin` can do all of them. Two site endpoints add an explicit `manage-server` check: `POST /sites/{id}/sync-remote-data` and `POST /sites/{id}/collect-traffic`.

**Route-name checks.** `POST /servers` requires `create-servers`.

**Controller-level checks:**

| Endpoint | Extra check |
|---|---|
| `POST /sites` (with `server_id` in the body) | Target server must be visible to the caller (`visibleTo`), otherwise 404 |
| `POST /ssh-keys` with `server_id` | `manage-server` on that server |
| `POST /ssh-keys` without `server_id` (account-level key) | `create-servers` |
| `DELETE /ssh-keys/{key}` | `manage-server` on the key's server, or `create-servers` for an account-level key |
| `GET/POST/DELETE /backup-destinations` | `create-servers` |
| `POST /sites/{id}/sync-remote-data` | `manage-server` on the site's server, plus on `source_server_id` if given |
| `POST /sites/{id}/collect-traffic` | `manage-server` on the site's server |

Two endpoints have no role check beyond a valid token:

- `GET /providers` and `GET /server-providers`. The route-name allowlist in `AuthorizeFleetAccess` names the web route `providers.index`, not `api.v1.providers.index`.
- `GET /ssh-key`, which returns the account-level key.

---

## 3. Errors

The app renders JSON for every `api/*` request (`bootstrap/app.php`). Most errors use Laravel's standard shapes:

| Status | When | Body |
|---|---|---|
| 401 | Missing, invalid or revoked token | `{"message": "Unauthenticated."}` |
| 403 | Team role does not allow it | `{"message": "This action is unauthorized."}` |
| 404 | Unknown id in the path (route-model binding), a resource not visible to you in a `firstOrFail`, a backup that belongs to a different site, or an unknown route | `{"message": "..."}` (for a missing model the message names it, e.g. `No query results for model [App\\Models\\Server] 99`) |
| 409 | Backup download requested before the backup completed | `{"message": "This backup has no archive to download yet."}` |
| 422 | Validation failure | `{"message": "The domain field is required. (and 1 more error)", "errors": {"domain": ["The domain field is required."], "...": ["..."]}}` |
| 422 | Business-rule rejection from a controller | `{"message": "..."}`, **without** an `errors` key (e.g. "HTTPS can only be enabled once the site is ready."). A few include `errors`, see the endpoint notes. |
| 422 | `POST /servers/{id}/start-provisioning` on a server in the wrong state | `abort(422)` with no message, so `message` is empty or generic |
| 429 | Rate limit | `{"message": "Too Many Attempts."}` plus a `Retry-After` header |
| 500 | Unhandled exception | `{"message": "Server Error"}` in production |

Treat any 2xx as success. Read `message` when present, and `errors` (field → list of strings) when present. The git webhook is the exception: it returns `{"error": "..."}` instead of `message` (section 9).

---

## 4. Pagination

Paginated list endpoints use **Laravel's standard paginated resource response**. There is no `pagination` key.

**Query parameters**

| Param | Meaning |
|---|---|
| `page` | 1-based page number (default `1`) |
| `limit` | Page size. **`per_page` is ignored.** |

Defaults and limits for `limit` vary by endpoint:

| Endpoint | Default `limit` | Clamp |
|---|---|---|
| `GET /servers`, `GET /sites`, `GET /servers/{id}/sites`, `GET /events`, `GET /sites/{id}/backups` | 20 | 1–100 |
| `GET /sites/{id}/events` | 25 | 1–100 |
| `GET /ssh-keys`, `GET /servers/{id}/ssh-keys` | 100 | **no clamp** |
| `GET /backup-destinations` | fixed 20 (`limit` ignored) | – |

**Response envelope** (exact keys):

```json
{
  "data": [ { "...": "resource" } ],
  "links": {
    "first": "https://app.turnkeywp.com/api/v1/servers?page=1",
    "last": "https://app.turnkeywp.com/api/v1/servers?page=3",
    "prev": null,
    "next": "https://app.turnkeywp.com/api/v1/servers?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 3,
    "links": [
      {"url": null, "label": "&laquo; Previous", "page": null, "active": false},
      {"url": "https://app.turnkeywp.com/api/v1/servers?page=1", "label": "1", "page": 1, "active": true},
      {"url": "https://app.turnkeywp.com/api/v1/servers?page=2", "label": "2", "page": 2, "active": false},
      {"url": "https://app.turnkeywp.com/api/v1/servers?page=2", "label": "Next &raquo;", "page": 2, "active": false}
    ],
    "path": "https://app.turnkeywp.com/api/v1/servers",
    "per_page": 20,
    "to": 20,
    "total": 47
  }
}
```

The exact members of each `meta.links` item depend on the Laravel version. Don't rely on them; use `links.next` or `meta.last_page`.

**Iterating.** Loop `page=1..meta.last_page` and pass your own `limit` and any filters on every request. **Don't follow `links.next` verbatim.** None of the controllers call `withQueryString()`, so `links.*` URLs keep only `page` and drop `limit`, `server_id` and `site_id`.

Non-paginated lists (`GET /providers`, `GET /sites/{id}/domains`) return just `{"data": [...]}`.

---

## 5. The async model: events

Almost every write is asynchronous. The API saves what it can, creates a **Task**, queues a job that runs a bash script on the server over SSH, and returns immediately with an `event_id`. An "event" in the API is a Task.

### Endpoints that return an event

All of these return an `event_id`; the details are in section 6.

- **Servers:** create, delete, reboot, restart-services, restart a single service (and its shortcuts), check-updates, apply-updates, start-provisioning, traffic collect.
- **SSH keys:** add a key to a server, either via `POST /servers/{id}/ssh-keys` or `POST /ssh-keys` with `server_id`.
- **Sites:**
  - lifecycle: create, update (only when a server change is needed), delete, enable, disable, clone;
  - cache: all three purge forms;
  - domains: add or remove a domain, change-domain;
  - HTTPS: enable or disable;
  - operations: correct-permissions, git deploy, sync-remote-data, update-wp, magic-login, collect-traffic.
- **Backups:** create, restore.

Three writes **don't** return an event: removing an SSH key, deleting a backup and creating or deleting a backup destination.

Typical async response:

```json
{ "event_id": 812, "message": "Cache purge queued successfully." }
```

Create endpoints also return the new object in `data`, with HTTP **201**; clone and backup create return HTTP **202**. `PUT/PATCH /sites/{id}` can queue several jobs, so it returns `event_id` (the first) and `event_ids` (all of them).

### `GET /events/{id}`

```json
{
  "data": {
    "id": 812,
    "server_id": 3,
    "site_id": 41,
    "name": "purge-cache",
    "status": "completed",
    "turnkey_status": "finished",
    "current_step": "Purging page cache",
    "error": null,
    "output": "::step:: Purging page cache\n...",
    "started_at": "2026-10-14T09:30:00+00:00",
    "finished_at": "2026-10-14T09:30:04+00:00",
    "created_at": "2026-10-14T09:30:00+00:00"
  }
}
```

### Status values

| `status` (mapped) | `turnkey_status` (raw) | Meaning | Terminal? |
|---|---|---|---|
| `running` | `pending` | Queued, not started (some jobs wait up to 30 min for a per-server lock) | no |
| `running` | `running` | Script running, or created as running and waiting in the queue | no |
| `completed` | `finished` | Script exited 0 | **yes** |
| `failed` | `failed` | Script exited non-zero, SSH failed, or the job failed | **yes** |

There are exactly these four raw values (`App\Enums\TaskStatus`) and three mapped values.

### Polling

1. Make the call and read `event_id`.
2. `GET /events/{event_id}` every 3–5 s (back off to 10–15 s for long jobs). Polling shares the token's 60/min budget.
3. Stop when `status` is `completed` or `failed`.
4. **Set your own timeout.** Some jobs don't close their task when they die (see gaps). Reasonable ceilings: 5 min for cache, vhost, permissions, SSL and restarts; 60 min for site create, clone, restore and sync-remote-data; 60 min for server provisioning (`ProvisionServer` can run 45 min). If a task is still `running` past your ceiling, treat it as unknown and surface it to a human.

### Reading the result

- `current_step` is the last `::step::` line the script printed, a human progress string. It's `null` before the first step.
- `error` is the last `::error::` line. On failure this is the message to show. It may be `null` if the script failed without printing one; then show the tail of `output`.
- `output` is the raw combined stdout/stderr, including the `::step::`, `::result::` and `::error::` marker lines, and can be large. While the task runs it is the controller's placeholder text (e.g. `Purging cache for example.com`) followed by streamed output, flushed about once per second. When the task finishes it is replaced with the script's output only.
- The structured `::result::` JSON (`tasks.result`) is **not exposed** by the API. This matters for magic login (see gaps).

A failed event looks like this:

```json
{
  "data": {
    "id": 815, "server_id": 3, "site_id": 41, "name": "issue-ssl",
    "status": "failed", "turnkey_status": "failed",
    "current_step": "Checking DNS for example.com",
    "error": "example.com does not resolve to 203.0.113.10; not requesting a certificate",
    "output": "...", "started_at": "...", "finished_at": "...", "created_at": "..."
  }
}
```

The exact `error` wording comes from the script and varies.

### Event `name` values

`name` is a slug set by the controller, unless the job created its own task:

- **Servers:** `provision-server`, `delete-server`, `reboot-server`, `restart-services`, `restart-service-{service}`, `check-system-updates`, `apply-security-updates`, `apply-all-system-updates`, `update-server-ssh-key`, `collect-traffic`.
- **Sites:** `create-site`, `delete-site`, `purge-cache`, `configure-site-vhost`, `configure-site-php`, `issue-ssl`, `disable-site`, `correct-permissions`, `deploy-site-git`, `clone-site`, `rename-site`, `sync-remote-site`, `update-site-wp`, `magic-login`, `backup-site`, `restore-site`.

Tasks created by background jobs (the scheduler, webhook deploys, SSL reissue after a domain change) have descriptive names such as `Deploy Git (main) for example.com`.

---

## 6. Endpoint reference

All paths are relative to `/api/v1`. The **Role** column gives the minimum check from section 2: `token` means any authenticated user, though the result is still filtered by visibility. **Async** means the response includes `event_id`.

### 6.1 Account and providers

| Method | Path | Role | Async |
|---|---|---|---|
| GET | `/user` | token | no |
| GET | `/providers` (alias `/server-providers`) | token | no |

**`GET /user`** returns the raw User model, **not** wrapped in `data`:

```json
{
  "id": 1,
  "name": "Ops Bot",
  "email": "ops@example.com",
  "email_verified_at": null,
  "google_id": null,
  "two_factor_confirmed_at": null,
  "notification_settings": null,
  "created_at": "...",
  "updated_at": "..."
}
```

Use it as a connection test.

**`GET /providers`**

```json
{
  "data": [
    {"id": "digitalocean", "name": "DigitalOcean", "has_api": true, "connected": false},
    {"id": "hetzner", "name": "Hetzner Cloud", "has_api": true, "connected": true},
    {"id": "custom", "name": "Custom server", "has_api": false, "connected": true}
  ]
}
```

`connected` means a provider account (API token) is stored in TurnkeyWP. `custom` is always `connected: true`.

### 6.2 Servers

| Method | Path | Role | Async |
|---|---|---|---|
| GET | `/servers` | token (filtered) | no, paginated |
| POST | `/servers` | `create-servers` | yes, 201 |
| GET | `/servers/{server}` | view-server | no |
| DELETE | `/servers/{server}` | `delete-server` (Owner) | yes |
| POST | `/servers/{server}/reboot` | manage-server | yes |
| POST | `/servers/{server}/restart-services` | manage-server | yes |
| POST | `/servers/{server}/services/{service}/restart` | manage-server | yes |
| POST | `/servers/{server}/restart-nginx` / `restart-redis` / `restart-php` / `restart-mysql` | manage-server | yes |
| POST | `/servers/{server}/check-updates` | manage-server | yes |
| POST | `/servers/{server}/apply-updates` | manage-server | yes |
| POST | `/servers/{server}/start-provisioning` | manage-server | yes |

**`GET /servers`**

- Query: `page`, `limit`. Results are ordered newest first (`id` desc).
- Returns a paginated list of Server resources (section 7.1).

**`GET /servers/{server}`** returns `{"data": Server}`.

**`POST /servers`** creates a server.

| Field | Type | Rules | Default |
|---|---|---|---|
| `name` | string | required, max 255 | – |
| `provider` | string | `hetzner`, `digitalocean` or `custom`; **any other value silently becomes `custom`** | `custom` |
| `ip_address` | string | valid IP; used only for `custom` | null |
| `ssh_port` | int | 1–65535 | 22 |
| `region` | string | provider region slug | null |
| `size` | string | provider size slug | null |
| `php_versions` | string[] | e.g. `["8.3","8.4"]` | `["8.3"]` |
| `default_php` | string | e.g. `"8.3"` | `"8.3"` |
| `timezone` | string | | `"UTC"` |

Response (201):

```json
{ "data": { "...": "Server" }, "event_id": 900 }
```

Behaviour:

- **Cloud providers (`hetzner`, `digitalocean`):** see gap G1. The API cannot pass a provider account, so these creates currently fail.
- **`custom` with `ip_address`:** the status goes to `booting` and TurnkeyWP starts polling SSH as root for about 15 minutes. That only succeeds if the server's own public key is already in root's `authorized_keys` (see gap G2).
- **`custom` without `ip_address`:** the server stays in `awaiting_key` (API `status: "needs-attention"`). No API endpoint sets the IP later.

**`DELETE /servers/{server}`**

- Returns `{"event_id": n, "message": "Server deletion initiated."}`.
- The server's status becomes `deleting` immediately.
- For cloud servers the job destroys the provider machine; for custom servers it disconnects them.
- After the job finishes, the server row is deleted and the task's `server_id` becomes `null`. Polling that event then requires `create-servers`, which the Owner who deleted it has.

**`POST /servers/{server}/reboot`** returns `{"event_id": n}`.

**`POST /servers/{server}/restart-services`**

- Body: `services` (string[], optional). If you omit it, the default is all of `nginx`, `mysql`, `php` and `redis`.
- Returns `{"event_id": n}`.

**`POST /servers/{server}/services/{service}/restart`** restarts one service and returns `{"event_id": n}`.

- `{service}` is passed to the restart script **without validation**.
- The script understands `nginx` (runs `nginx -t`, then reload/restart), `mysql`, `redis`/`redis-server`, `php`/`php-fpm` (all installed PHP-FPM versions) and `php8.3` or `php8.3-fpm` (one version).
- Any other name is restarted if it is an active or enabled systemd unit; see gap G13.
- The shortcuts `restart-nginx`, `restart-redis`, `restart-php` and `restart-mysql` are this same action with a fixed `{service}`.

**`POST /servers/{server}/check-updates`** refreshes the update fields on the Server resource (`security_updates_count`, `upgradable_packages`, …). Read the server again after the event completes. Returns `{"event_id": n, "message": "System updates check dispatched."}`.

**`POST /servers/{server}/apply-updates`**

- Body: `security_only` (bool, default `false`).
- Returns `{"event_id": n, "message": "System updates installation dispatched."}`.

**`POST /servers/{server}/start-provisioning`** (re)starts provisioning of a custom server.

- Allowed only when `turnkey_status` is `awaiting_key` or `failed`; otherwise it returns 422 (no message).
- Returns `{"event_id": n, "message": "Server provisioning started."}`.

### 6.3 SSH keys

There are two kinds of key:

- **Server keys** (`server_id` set) are deployed to the `turnkey` user's `authorized_keys` on that server.
- **Account-level keys** (`server_id` null) are a library stored in TurnkeyWP. Creating one deploys nothing.

| Method | Path | Role | Async |
|---|---|---|---|
| GET | `/servers/{server}/ssh-keys` | view-server | no, paginated |
| POST | `/servers/{server}/ssh-keys` | manage-server | yes, 201 |
| DELETE | `/servers/{server}/ssh-keys/{key}` | manage-server | no (a removal job is queued, but no `event_id` is returned) |
| GET | `/ssh-keys` | token (filtered) | no, paginated |
| GET | `/ssh-key` | token | no |
| POST | `/ssh-keys` | `create-servers`, or manage-server on `server_id` | yes if `server_id` is given, 201 |
| DELETE | `/ssh-keys/{key}` | manage-server on the key's server, or `create-servers` | no |

**GET (server scope)** returns keys for that server **plus** all account-level keys. The global list returns account-level keys plus keys on servers you can see. Query: `page`, `limit` (default 100).

**POST body**

| Field | Type | Rules |
|---|---|---|
| `name` | string | required, max 255 |
| `public_key` | string | required, max 16384, one line matching `^(ssh-rsa|ssh-ed25519|ecdsa-sha2-[a-z0-9]+) <base64>( comment)?$` |
| `server_id` | int | optional; ignored on the `/servers/{server}/ssh-keys` path |

Response (201):

```json
{
  "data": { "...": "SshKey" },
  "event_id": 77,
  "message": "SSH key added and deployment queued."
}
```

`event_id` and `message` appear only when the key is deployed to a server.

**DELETE** returns `{"message": "SSH key deleted successfully."}`. If the key belongs to a server, a removal job is queued, but its task id isn't returned.

**`GET /ssh-key`** returns the oldest account-level key as `{"data": SshKey}`, or 404 with `{"message": "No account-level SSH key exists. Add one with POST /api/v1/ssh-keys."}`. It never returns a server's own provisioning key.

### 6.4 Sites

| Method | Path | Role | Async |
|---|---|---|---|
| GET | `/sites` | token (filtered) | no, paginated |
| GET | `/servers/{server}/sites` | view-server | no, paginated |
| POST | `/sites` | token; `server_id` must be visible | yes, 201 |
| POST | `/servers/{server}/sites` | **manage-server** (server-level write) | yes, 201 |
| GET | `/sites/{site}` | view-server | no |
| PUT/PATCH | `/sites/{site}` | view-server | sometimes |
| DELETE | `/sites/{site}` | view-server | yes |
| GET | `/sites/{site}/events` | view-server | no, paginated Event list (default `limit` 25) |

**`GET /sites`**

- Query: `page`, `limit`, and `server_id` (filter).
- Ordered newest first.

**`POST /sites`** and **`POST /servers/{server}/sites`** create a site.

| Field | Type | Rules | Default |
|---|---|---|---|
| `server_id` | int | required on `POST /sites`; ignored on the scoped path | – |
| `domain` | string | required, max 253, lowercase bare domain matching `^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$`, unique across all sites | – |
| `additional_domains` | string[] | each matches the same pattern | `[]` |
| `php_version` | string | `^\d\.\d$` and must be in the server's `php_versions`, otherwise 422 | server `default_php` |
| `install_wordpress` | bool | `false` gives an empty webroot (for migrations) | `true` |
| `page_cache` | bool | | `true` |
| `admin_email` | email | WordPress admin email | `admin@<domain>` |
| `admin_username` | string | **accepted but ignored** (gap G9) | – |
| `admin_password` | string | **accepted but ignored** (gap G9) | – |

The response is `{"data": Site, "event_id": n}` with status 201. The site starts as `turnkey_status: "pending"` (API `status: "deploying"`) and ends `ready`/`deployed` or `failed`. The site's Linux user, database name and user, and table prefix are generated (section 8).

**`PUT|PATCH /sites/{site}`** updates a site. Send only the fields you want to change.

| Field | Type | Rules | Effect |
|---|---|---|---|
| `php_version` | string | must be in the server's `php_versions` | queues `configure-site-php` |
| `page_cache` | bool | | queues `configure-site-vhost` |
| `admin_email` | email | | stored only |
| `additional_domains` | string[] | each a bare domain; **replaces** the full alias list (lowercased, de-duplicated, primary removed) | queues `configure-site-vhost`, plus `issue-ssl` if HTTPS is on and the set changed |

Response:

```json
{
  "data": { "...": "Site" },
  "event_id": 901,
  "event_ids": [901, 902],
  "message": "Site updated and server changes queued."
}
```

`event_id` and `event_ids` are omitted when nothing needed a server change; then `message` is `"Site updated successfully."`.

**`DELETE /sites/{site}`**

- Returns `{"event_id": n, "message": "Site deletion initiated."}`.
- The status becomes `deleting` at once.
- The site row is removed when the job finishes. The event stays readable because its `site_id` becomes `null` while `server_id` is kept.

### 6.5 Cache

| Method | Path | Role | Async |
|---|---|---|---|
| POST | `/sites/{site}/purge-cache` | view-server | yes |
| POST | `/sites/{site}/purge-page-cache` (alias `/sites/{site}/page-cache/purge`) | view-server | yes |
| POST | `/sites/{site}/purge-object-cache` (alias `/sites/{site}/object-cache/purge`) | view-server | yes |

- `purge-cache` takes body `scope`: `all`, `page` or `object`, default `all`. **Any other value silently becomes `all`.**
- "Page" is the Nginx FastCGI cache; "object" is the WordPress Redis object cache.
- All three return `{"event_id": n, "message": "Cache purge queued successfully."}`.

### 6.6 Domains

`domains` and `additional-domains` are interchangeable aliases for every route below.

| Method | Path | Role | Async |
|---|---|---|---|
| GET | `/sites/{site}/domains` | view-server | no |
| POST | `/sites/{site}/domains` | view-server | yes, 201 |
| DELETE | `/sites/{site}/domains/{domain}` | view-server | yes |
| POST | `/sites/{site}/change-domain` | view-server | yes |

**GET**

```json
{
  "data": [
    {"id": 1, "server_id": 3, "site_id": 41, "domain": "example.com", "is_primary": true, "status": "active"},
    {"id": 2, "server_id": 3, "site_id": 41, "domain": "www.example.com", "is_primary": false, "status": "active"}
  ]
}
```

- `id` is a **position**, not a stored id: 1 is the primary, then 2, 3… in alias order. It changes when aliases change.
- `status` is always `"active"`.
- Delete by domain string, not by id.

**POST** takes body `domain` (required): a bare domain, lowercased and trimmed. Rejections:

- 422 `{"message": "The domain is already the primary domain."}`
- 422 `{"message": "Domain already added to this site."}`

The alias is saved immediately and a vhost reconfiguration is queued. If HTTPS is enabled, a certificate reissue is also queued **without** an `event_id` (gap G5). Response (201):

```json
{
  "data": {"id": 3, "server_id": 3, "site_id": 41, "domain": "blog.example.com", "is_primary": false, "status": "active"},
  "event_id": 905,
  "message": "Domain added and vhost reconfiguration queued."
}
```

**DELETE `/sites/{site}/domains/{domain}`** works the same way in reverse.

- Rejections: 422 `{"message": "Cannot delete the primary domain."}` and 404 `{"message": "Domain not found on this site."}`.
- Returns `{"event_id": n, "message": "Domain removed successfully."}`. The `event_id` is the vhost reconfiguration; any SSL reissue is not reported.

**POST `/sites/{site}/change-domain`** renames the primary domain.

- Body: `new_domain` (required, max 253, same lowercase domain pattern, must differ from the current domain).
- The script moves `/sites/<old>/` to `/sites/<new>/`, sets it as the site user's home, and moves the nginx include directory and PHP-FPM pool to the new name.
- `site_user`, the database name and the database user **don't change**.
- The site's `domain` field changes only after the script succeeds.
- Returns `{"event_id": n, "message": "Primary domain change to <new> initiated."}`.

### 6.7 HTTPS

| Method | Path | Role | Async |
|---|---|---|---|
| POST | `/sites/{site}/enable-https` | view-server | yes |
| POST | `/sites/{site}/disable-https` | view-server | yes |

**enable-https**

- Body: `email` (optional email). It falls back to the server-wide `TURNKEY_SSL_EMAIL`, then the token user's email.
- Requires `turnkey_status: "ready"`, otherwise 422 `{"message": "HTTPS can only be enabled once the site is ready."}`. Without a usable email it returns 422 with `message` and `errors.email`.
- It issues one Let's Encrypt (webroot) certificate for the primary domain and all aliases. Before calling certbot it checks that DNS points at the server; **there are no automatic retries**. Each issuance counts toward Let's Encrypt's limit of 5 certificates per identical domain set per week, so don't loop on it.
- Returns `{"event_id": n, "message": "SSL certificate issuance queued for example.com."}`.

**disable-https**

- Requires `ready`, otherwise 422 `{"message": "HTTPS can only be changed once the site is ready."}`.
- `https.enabled` flips to `false` only after the server has switched.
- Returns `{"event_id": n, "message": "Disabling HTTPS for example.com queued."}`.

### 6.8 Site operations

| Method | Path | Role | Async |
|---|---|---|---|
| POST | `/sites/{site}/enable` | view-server | yes |
| POST | `/sites/{site}/disable` | view-server | yes |
| POST | `/sites/{site}/correct-permissions` | view-server | yes |
| POST | `/sites/{site}/git/deploy` (alias `/sites/{site}/git-deploy`) | view-server | yes |
| POST | `/sites/{site}/clone` | view-server | yes, 202 |
| POST | `/sites/{site}/sync-remote-data` | **manage-server** | yes |
| POST | `/sites/{site}/update-wp` | view-server | yes |
| POST | `/sites/{site}/magic-login` | view-server | yes |

**enable**

- Sets `turnkey_status` to `ready` **immediately**, before the job runs (gap G10), and queues a vhost rebuild.
- Returns `{"event_id": n, "message": "Site enabled successfully."}`. The message is returned even though the job hasn't run yet; poll the event.

**disable**

- Queues the disable job; `turnkey_status` becomes `disabled` when it finishes.
- Returns `{"event_id": n, "message": "Site disabled successfully."}`.

**correct-permissions** resets file ownership and modes under the site home. Returns `{"event_id": n, "message": "Site permissions repair initiated."}`.

**git deploy**

- Body: `branch` (optional, max 200, `^[A-Za-z0-9._/][A-Za-z0-9._/-]*$`) and `deploy_script` (optional, max 20000).
- Omitted values fall back to the site's saved git settings. The repo itself is configured in the UI; there is no API for git settings.
- Returns `{"event_id": n, "message": "Git deployment initiated."}`.

**clone** copies a site to a **new site on the same server**.

| Field | Type | Rules | Default |
|---|---|---|---|
| `target_domain` | string | required, max 255, unique among sites (**no domain-format check**, gap G9) | – |
| `target_server_id` | int | must equal the source server, otherwise 422 with `errors.target_server_id` | source server |
| `scope` | string | `all`, `database` or `files` | `all` |
| `is_staging` | bool | | `true` |

Response (202):

```json
{
  "data": { "...": "the NEW Site" },
  "event_id": 910,
  "message": "Clone operation queued successfully."
}
```

`event_id` is the **clone** task. The new site's own `create-site` task is not returned; find it with `GET /sites/{new_id}/events`.

**sync-remote-data** pulls files and the database from another host into this site (migration).

| Field | Type | Rules |
|---|---|---|
| `source_server_id` | int | a TurnkeyWP server other than this site's server; you need manage-server on it. When given, its IP and key are used. |
| `remote_host` | string | required unless `source_server_id`; hostname or IP, max 253 |
| `remote_port` | int | 1–65535, default 22 |
| `remote_user` | string | default `root` |
| `remote_private_key` | string | PEM/OpenSSH private key for `remote_host` (ignored with `source_server_id`) |
| `remote_webroot` | string | max 255, no `..` segments; the source WordPress path |
| `dry_run` | bool | default `false` |
| `import_database` | bool | default `true` |

Returns `{"event_id": n, "message": "Remote site data migration initiated."}`.

**update-wp** runs WP-CLI updates.

- Body: `update_core`, `update_plugins` and `update_themes` (bools, each default `true`).
- Returns `{"event_id": n, "message": "WordPress updates initiated."}`.

**magic-login** generates a one-time wp-admin login link.

- Body: `user` (optional WordPress username; default is the site's stored admin user).
- Returns `{"event_id": n, "message": "Magic login link generation queued."}`.
- **The link can't currently be read through the API** (gap G3).

### 6.9 Events

| Method | Path | Role | Async |
|---|---|---|---|
| GET | `/events` | token (filtered) | no, paginated |
| GET | `/events/{id}` | view-server on the task's server (or `create-servers` if it has no server) | no |
| GET | `/sites/{site}/events` | view-server | no, paginated |

`GET /events`:

- Query: `page`, `limit`, `server_id`, `site_id`. Ordered newest first.
- Owners and account admins see every task. Other roles see tasks on their accessible servers only; tasks with no server are hidden from them.

### 6.10 Traffic

Traffic metrics come from parsed Nginx access logs. The scheduler collects them in production; you can also trigger a collection.

| Method | Path | Role | Async |
|---|---|---|---|
| GET | `/sites/{site}/traffic` | view-server | no |
| GET | `/servers/{server}/traffic` | view-server | no |
| POST | `/servers/{server}/traffic` | manage-server | no, 201 (ingest) |
| POST | `/servers/{server}/collect-traffic` | manage-server | yes |
| POST | `/sites/{site}/collect-traffic` | manage-server | yes |

**Query params for the two GETs**

| Param | Values | Default |
|---|---|---|
| `period` | bucket type: `hourly`, `daily` or `realtime`. **It isn't validated**; an unknown value returns empty data. | `hourly` |
| `limit` | number of most-recent buckets, 1–168 | 24 |

**`GET /sites/{site}/traffic`.** This response is **not** wrapped in `data`.

```json
{
  "site": {"id": 41, "domain": "example.com"},
  "period": "hourly",
  "summary": {
    "total_requests": 18234,
    "total_bytes": 734003200,
    "total_bytes_formatted": "700 MB",
    "total_unique_visitors": 2210,
    "status_codes": {"2xx": 17100, "3xx": 800, "4xx": 320, "5xx": 14},
    "cache": {"hits": 12000, "misses": 4000, "hit_rate_percentage": 75.0},
    "top_paths": [{"path": "/", "count": 5200}],
    "top_referrers": [{"referrer": "https://www.example.net/", "count": 140}]
  },
  "timeline": [ { "...": "TrafficMetric, oldest first" } ]
}
```

`top_paths` and `top_referrers` hold at most 10 entries each. `total_unique_visitors` is a sum of per-bucket uniques, so it over-counts (gap G14).

**`GET /servers/{server}/traffic`.** Also not wrapped.

```json
{
  "server": {"id": 3, "name": "web-1", "ip_address": "203.0.113.10"},
  "summary": {"total_requests": 50210, "total_bytes": 2147483648, "total_bytes_formatted": "2 GB", "total_unique_visitors": 6100},
  "sites_breakdown": [
    {"id": 41, "domain": "example.com", "requests": 18234, "bytes": 734003200, "bytes_formatted": "700 MB"}
  ],
  "recent_samples_count": 24
}
```

The summary uses server-level buckets (`site_id` null) when any exist for that period; otherwise it sums the per-site buckets.

**`POST /servers/{server}/traffic`** ingests one metric bucket pushed from outside. TurnkeyWP's own collector (`CollectServerTraffic`) writes to the database directly and doesn't call this endpoint, so a client normally doesn't need it.

| Field | Type | Rules |
|---|---|---|
| `requests_count` | int | required, ≥ 0 |
| `site_id` | int | must exist (**not** checked against this server, gap G12) |
| `domain` | string | used to find the site on this server when `site_id` is absent |
| `period` | string | `hourly`, `daily` or `realtime`; default `hourly` |
| `recorded_at` | date | default now |
| `bytes_sent`, `unique_visitors`, `status_2xx`, `status_3xx`, `status_4xx`, `status_5xx`, `cache_hits`, `cache_misses` | int | ≥ 0, default 0 |
| `avg_response_time_ms` | number | ≥ 0 |
| `top_paths` | array | `[{"path": "/", "count": 1}]` |
| `top_referrers` | array | `[{"referrer": "...", "count": 1}]` |

Response (201): `{"message": "Telemetry metric ingested successfully.", "metric": TrafficMetric}`.

**collect-traffic** (server or site) parses the access logs now. Returns `{"event_id": n, "message": "Web traffic collection queued on server."}`.

### 6.11 Backups

| Method | Path | Role | Async |
|---|---|---|---|
| GET | `/sites/{site}/backups` | view-server | no, paginated |
| POST | `/sites/{site}/backups` | view-server | yes, 202 |
| GET | `/sites/{site}/backups/{backup}` | view-server | no |
| POST | `/sites/{site}/backups/{backup}/restore` | view-server | yes |
| DELETE | `/sites/{site}/backups/{backup}` | view-server | no |
| GET | `/sites/{site}/backups/{backup}/download` | view-server | no |

If `{backup}` belongs to a different site, the response is 404.

**POST (create)**

| Field | Type | Rules | Default |
|---|---|---|---|
| `scope` | string | `full`, `database_only` or `files_only` | `full` |
| `name` | string | max 255 | `<domain>-<Y-m-d-His>-<scope>` |
| `destination_id` | int | an existing backup destination | the default destination, if any |
| `retention_tier` | string | `hourly`, `daily`, `weekly`, `monthly` or `snapshot` | `snapshot` |

Response (202): `{"data": Backup, "event_id": n, "message": "Backup queued successfully."}`.

- The backup starts with `status: "running"`.
- `checksum_sha256` and `file_path` are **placeholders** until `status` becomes `completed` (gap G11). Re-read the backup after the event finishes.

**restore** restores the site from the backup. Returns `{"event_id": n, "message": "Restore operation initiated."}`.

- The job first takes a **pre-restore safety backup**: a new Backup record with scope `full`, `retention_tier: "safety"`, expiring after 7 days. It runs as its own task, which isn't returned.
- The restore goes ahead even if the safety backup fails.
- The controller doesn't check that the backup being restored is `completed` (gap G20).
- The restored backup's own `status` isn't changed.

**DELETE** deletes the record and queues removal of the archive. Returns `{"message": "Backup deleted; its archive removal is queued."}`.

**download**

- Requires `status: "completed"` and a stored path, otherwise 409.
- `download_url` is a signed web URL that streams the archive, valid for 30 minutes. It needs no bearer token; the signature is the credential.

```json
{
  "backup_id": 55,
  "name": "example.com-2026-10-14-030000-full",
  "checksum_sha256": "9f2c...e1",
  "download_url": "https://app.turnkeywp.com/backups/55/download?expires=1760430000&signature=...",
  "expires_at": "2026-10-14T10:00:00+00:00"
}
```

### 6.12 Backup destinations

All three endpoints need `create-servers` (Owner or account admin).

| Method | Path | Async |
|---|---|---|
| GET | `/backup-destinations` | no, paginated (fixed 20/page) |
| POST | `/backup-destinations` | no, 201 |
| DELETE | `/backup-destinations/{destination}` | no |

**POST**

| Field | Type | Rules |
|---|---|---|
| `name` | string | required, max 255 |
| `type` | string | required: `s3`, `r2`, `spaces`, `wasabi`, `b2`, `gcs`, `akamai`, `vultr` or `local` |
| `bucket` | string | max 255 |
| `endpoint` | string | max 255 (S3-compatible endpoint URL) |
| `region` | string | max 100 |
| `path_prefix` | string | max 255 |
| `access_key` | string | |
| `secret_key` | string | |
| `is_default` | bool | `true` unsets the default flag on all other destinations |

Response (201): `{"data": Destination, "message": "Backup destination connected successfully."}`. No connection test is run; `status` is set to `active`.

**DELETE** returns `{"message": "Backup destination removed successfully."}`.

---

## 7. Resource schemas

### 7.1 Server

| Field | Type | Null? | Notes |
|---|---|---|---|
| `id` | int | no | TurnkeyWP server id |
| `name` | string | no | Label |
| `provider_name` | string | no | `ucfirst(provider)`: `Hetzner`, `Digitalocean` (lowercase o) or `Custom` |
| `ip_address` | string | yes | Public IPv4; null until known (cloud servers before boot, custom servers created without an IP) |
| `ssh_port` | int | no | Default 22 |
| `ssh_user` | string | no | `root` until provisioning succeeds, then `turnkey` (passwordless sudo) |
| `region` | string | yes | Provider region slug |
| `size` | string | yes | Provider size slug |
| `status` | string | no | Mapped: `provisioned`, `provisioning`, `failed`, `needs-attention`, `deleting` |
| `turnkey_status` | string | no | Raw: `awaiting_key`, `creating`, `booting`, `provisioning`, `ready`, `failed`, `deleting` |
| `ubuntu_version` | string | no | From provisioning; defaults to `"24.04"` if unknown |
| `upgrade_required` | bool | no | Packages are upgradable |
| `reboot_required` | bool | no | `/var/run/reboot-required` present |
| `security_updates_count` | int | no | |
| `standard_updates_count` | int | no | |
| `total_updates_count` | int | no | Sum of the two |
| `reboot_required_packages` | string[] | no | `[]` if none |
| `upgradable_packages` | object[] | no | `[]` if none. Items are written by the update-check script (e.g. `package`, `current_version`, `candidate_version`, `is_security`). Treat it as loosely typed. |
| `updates_last_checked_at` | string | yes | ISO 8601 |
| `database` | object | no | Always `{"server": "mysql", "port": 3306}` (hardcoded) |
| `php_versions` | string[] | no | Installed PHP versions, e.g. `["8.3","8.4"]` |
| `default_php` | string | no | |
| `timezone` | string | no | Default `UTC` |
| `sites_count` | int | no | |
| `traffic` | object | no | Last-24h summary, see 7.6 |
| `provisioned_at` | string | yes | |
| `created_at` | string | yes | |
| `updated_at` | string | yes | |

The resource doesn't include the raw `provider` value, the server's own SSH public key, `status_message` (the reason for a failure), or software versions such as nginx, MySQL, CPU, RAM and disk.

Example:

```json
{
  "id": 3,
  "name": "web-1",
  "provider_name": "Hetzner",
  "ip_address": "203.0.113.10",
  "ssh_port": 22,
  "ssh_user": "turnkey",
  "region": "fsn1",
  "size": "cpx31",
  "status": "provisioned",
  "turnkey_status": "ready",
  "ubuntu_version": "24.04",
  "upgrade_required": true,
  "reboot_required": false,
  "security_updates_count": 2,
  "standard_updates_count": 9,
  "total_updates_count": 11,
  "reboot_required_packages": [],
  "upgradable_packages": [
    {"package": "openssl", "current_version": "3.0.13-0ubuntu3.4", "candidate_version": "3.0.13-0ubuntu3.5", "is_security": true}
  ],
  "updates_last_checked_at": "2026-10-14T03:00:12+00:00",
  "database": {"server": "mysql", "port": 3306},
  "php_versions": ["8.3", "8.4"],
  "default_php": "8.3",
  "timezone": "UTC",
  "sites_count": 12,
  "traffic": {
    "has_data": true,
    "period": "hourly",
    "period_hours": 24,
    "samples_count": 24,
    "total_requests": 50210,
    "total_bytes": 2147483648,
    "total_bytes_formatted": "2 GB",
    "cache_hit_rate_percentage": 71.4,
    "status_codes": {"2xx": 47000, "3xx": 2100, "4xx": 1050, "5xx": 60},
    "telemetry_url": "https://app.turnkeywp.com/api/v1/servers/3/traffic"
  },
  "provisioned_at": "2026-10-11T14:02:55+00:00",
  "created_at": "2026-10-11T13:40:01+00:00",
  "updated_at": "2026-10-14T03:00:12+00:00"
}
```

### 7.2 Site

| Field | Type | Null? | Notes |
|---|---|---|---|
| `id` | int | no | TurnkeyWP site id |
| `server_id` | int | no | |
| `domain` | string | no | Primary domain, lowercase |
| `additional_domains` | string[] | no | Aliases, `[]` if none |
| `site_user` | string | no | The site's Linux user, which owns `/sites/<domain>/` |
| `php_version` | string | no | e.g. `"8.3"` |
| `page_cache` | object | no | `{"enabled": bool}` (Nginx FastCGI cache) |
| `https` | object | no | `{"enabled": bool, "expires_at": str\|null, "certificate_expires": str\|null, "certificate_renews": str\|null}`. `expires_at` and `certificate_expires` are the same value; `certificate_renews` is computed as expiry minus 30 days. |
| `database` | object | no | `{"name": str, "user": str, "table_prefix": str}`. MySQL on the same server, `localhost:3306`. The password is never returned. |
| `status` | string | no | Mapped: `deployed`, `deploying`, `failed`, `deleting`, `disabled` |
| `turnkey_status` | string | no | Raw: `pending`, `installing`, `ready`, `failed`, `deleting`, `disabled` |
| `is_wordpress` | bool | no | Whether WordPress was installed at creation (`install_wordpress`) |
| `is_staging` | bool | no | True if flagged as staging, **or** the domain contains `staging` or `sslip.io` |
| `wp_core_update` | bool | no | **Always `false`** (gap G6) |
| `wp_theme_updates` | bool | no | **Always `false`** (gap G6) |
| `wp_plugin_updates` | bool | no | **Always `false`** (gap G6) |
| `backups` | object | no | **Hardcoded placeholder** (gap G7): `{"files": {"enabled": true}, "database": {"enabled": true}, "retention": 30, "next_run_time": "<tomorrow 03:00>", "storage_provider": "turnkey"}` |
| `backups_count` | int | no | Real count of backup records |
| `traffic` | object | no | Last-24h summary, see 7.6 |
| `created_at` | string | yes | |
| `updated_at` | string | yes | |

The resource doesn't include the webroot path, the site URL, the WordPress admin username, the `install_type` (`wordpress`, `git` or `blank`), the profile, or `status_message`. Derive the webroot as described in section 8.

Example:

```json
{
  "id": 41,
  "server_id": 3,
  "domain": "example.com",
  "additional_domains": ["www.example.com"],
  "site_user": "example",
  "php_version": "8.3",
  "page_cache": {"enabled": true},
  "https": {
    "enabled": true,
    "expires_at": "2026-12-28T10:15:00+00:00",
    "certificate_expires": "2026-12-28T10:15:00+00:00",
    "certificate_renews": "2026-11-28T10:15:00+00:00"
  },
  "database": {"name": "example", "user": "example", "table_prefix": "k3q_"},
  "status": "deployed",
  "turnkey_status": "ready",
  "is_wordpress": true,
  "is_staging": false,
  "wp_core_update": false,
  "wp_theme_updates": false,
  "wp_plugin_updates": false,
  "backups": {
    "files": {"enabled": true},
    "database": {"enabled": true},
    "retention": 30,
    "next_run_time": "2026-10-15T03:00:00+00:00",
    "storage_provider": "turnkey"
  },
  "backups_count": 14,
  "traffic": {
    "has_data": true,
    "period": "hourly",
    "period_hours": 24,
    "samples_count": 24,
    "total_requests": 18234,
    "total_bytes": 734003200,
    "total_bytes_formatted": "700 MB",
    "cache_hit_rate_percentage": 75.0,
    "status_codes": {"2xx": 17100, "3xx": 800, "4xx": 320, "5xx": 14},
    "telemetry_url": "https://app.turnkeywp.com/api/v1/sites/41/traffic"
  },
  "created_at": "2026-10-11T15:20:44+00:00",
  "updated_at": "2026-10-14T09:31:02+00:00"
}
```

### 7.3 Event (Task)

| Field | Type | Null? | Notes |
|---|---|---|---|
| `id` | int | no | The `event_id` |
| `server_id` | int | yes | Null for account-level tasks, or after the server is deleted |
| `site_id` | int | yes | Null for server tasks, or after the site is deleted |
| `name` | string | no | Slug or descriptive name (section 5) |
| `status` | string | no | `running`, `completed` or `failed` |
| `turnkey_status` | string | no | `pending`, `running`, `finished` or `failed` |
| `current_step` | string | yes | Last `::step::` line |
| `error` | string | yes | Last `::error::` line |
| `output` | string | yes | Raw script output with markers; may be large |
| `started_at` | string | yes | |
| `finished_at` | string | yes | Set on `completed` or `failed` |
| `created_at` | string | yes | |

### 7.4 Backup

| Field | Type | Null? | Notes |
|---|---|---|---|
| `id` | int | no | |
| `site_id` | int | no | |
| `name` | string | no | |
| `scope` | string | no | `full`, `database_only` or `files_only` |
| `status` | string | no | `running`, `completed` or `failed` (plain strings, not an enum) |
| `file_size_bytes` | int | no | 0 until completed |
| `database_size_bytes` | int | no | 0 until completed |
| `total_size_bytes` | int | no | |
| `size_formatted` | string | no | e.g. `"412.5 MB"` |
| `checksum_sha256` | string | yes | **Placeholder (a random hash) until `completed`** |
| `destination` | object | yes | `{"id": int, "name": str, "type": str}` or null |
| `retention_tier` | string | no | `hourly`, `daily`, `weekly`, `monthly` or `snapshot`; `safety` for pre-restore backups |
| `started_at` | string | yes | |
| `completed_at` | string | yes | Also set on failure |
| `expires_at` | string | yes | Set by scheduled backups with a retention period |
| `error_message` | string | yes | On failure: the full task output |
| `metadata` | object | yes | e.g. `remote_key`, `remote_bucket`, `storage_class` after offsite upload |
| `download_url` | string | no | URL of the **API** download endpoint (section 6.11), which needs the bearer token and returns JSON with the signed link. It isn't the archive itself. |
| `created_at` | string | yes | |

### 7.5 SshKey

| Field | Type | Null? | Notes |
|---|---|---|---|
| `id` | int | no | |
| `server_id` | int | yes | null = account-level key |
| `name` | string | no | |
| `public_key` | string | no | One line, OpenSSH format |
| `fingerprint` | string | yes | `SHA256:<base64>` without padding |
| `created_at` | string | yes | |

### 7.6 Traffic summary (embedded `traffic` on Server and Site)

These are totals over the last 24 hourly buckets.

| Field | Type | Notes |
|---|---|---|
| `has_data` | bool | false when no bucket exists in the window |
| `period` | string | Always `"hourly"` |
| `period_hours` | int | Always `24` |
| `samples_count` | int | Buckets counted |
| `total_requests` | int | |
| `total_bytes` | int | |
| `total_bytes_formatted` | string | |
| `cache_hit_rate_percentage` | float | 0–100, one decimal |
| `status_codes` | object | `{"2xx": int, "3xx": int, "4xx": int, "5xx": int}` |
| `telemetry_url` | string | Absolute URL of the matching `GET .../traffic` endpoint |

### 7.7 TrafficMetric (one bucket, in `timeline` and ingest responses)

| Field | Type | Null? |
|---|---|---|
| `id` | int | no |
| `server_id` | int | no |
| `site_id` | int | yes |
| `period` | string | no |
| `recorded_at` | string | no |
| `requests_count` | int | no |
| `bytes_sent` | int | no |
| `bytes_formatted` | string | no |
| `unique_visitors` | int | no |
| `status_breakdown` | object | no: `{"2xx","3xx","4xx","5xx","total"}` (ints) |
| `cache` | object | no: `{"hits": int, "misses": int, "hit_rate_percentage": float}` |
| `avg_response_time_ms` | number | yes |
| `top_paths` | array | no: `[{"path": str, "count": int}]` |
| `top_referrers` | array | no: `[{"referrer": str, "count": int}]` |
| `created_at` | string | yes |

### 7.8 Backup destination

| Field | Type | Null? | Notes |
|---|---|---|---|
| `id` | int | no | |
| `name` | string | no | |
| `type` | string | no | `s3`, `r2`, `spaces`, `wasabi`, `b2`, `gcs`, `akamai`, `vultr` or `local` |
| `bucket` | string | yes | |
| `endpoint` | string | yes | |
| `region` | string | yes | |
| `path_prefix` | string | yes | |
| `is_default` | bool | no | |
| `status` | string | no | `active` on creation |
| `backups_count` | int | no | |
| `created_at` | string | yes | |

Credentials (`access_key`, `secret_key`) are write-only; they are never returned.

---

## 8. Identifiers, status values, and reaching a server over SSH

### IDs

All `id`, `server_id`, `site_id` and `event_id` values are TurnkeyWP's own integers. They are unrelated to provider ids (Hetzner or DigitalOcean server ids) and to ids in any previous host. If your client also tracks sites from another source, store the TurnkeyWP id separately and match on `domain` (sites) or `ip_address` (servers).

### Status mapping

| Server `turnkey_status` | API `status` |
|---|---|
| `awaiting_key` | `needs-attention` |
| `creating`, `booting`, `provisioning` | `provisioning` |
| `ready` | `provisioned` |
| `failed` | `failed` |
| `deleting` | `deleting` |

| Site `turnkey_status` | API `status` |
|---|---|
| `pending`, `installing` | `deploying` |
| `ready` | `deployed` |
| `failed` | `failed` |
| `deleting` | `deleting` |
| `disabled` | `disabled` |

Use `turnkey_status` when you need the exact state, for example to tell `awaiting_key` from other attention states, or `disabled` from `deployed`. Mapped `status` exists for clients that expect the older vocabulary.

### Filesystem layout on every server

Every site on every server follows the same layout:

| Thing | Value |
|---|---|
| Site home | `/sites/<domain>/` (home directory of `site_user`) |
| Webroot / WordPress path | `/sites/<domain>/files` |
| Linux user | `site_user` (generated from the domain without its TLD: `example.com` → `example`, `blog.example.com` → `blog_example`; letters, digits and underscores, max 24 characters, suffixed `_2`, `_3`… on clashes) |
| PHP-FPM | One pool per site, running as `site_user` |
| Database | MySQL 8 on the same server; name and user default to `site_user`, table prefix random (`abc_`) |
| Site metadata | `/etc/turnkey/sites/<domain>.env` (root-only) |
| Custom nginx includes | `/etc/nginx/turnkey/sites/<domain>/{before,server}/*.conf` |
| WP-CLI | `/usr/local/bin/wp` |

After `change-domain`, every path above uses the new domain. Read the site again after the event completes.

### SSH access for a monitoring tool

1. Add your tool's public key: `POST /servers/{id}/ssh-keys` with `{"name": "...", "public_key": "ssh-ed25519 AAAA... monitor"}`. It is deployed to the **`turnkey`** user. Wait for the event to reach `completed`.
2. Connect with `ssh -p <ssh_port> <ssh_user>@<ip_address>` using the Server resource fields. On a provisioned server (`turnkey_status: ready`), `ssh_user` is `turnkey`. Root login is disabled after provisioning.
3. `turnkey` has passwordless sudo. Run WP-CLI as the site user:

   ```bash
   sudo -u <site_user> /usr/local/bin/wp --path=/sites/<domain>/files core version
   ```

4. The API has no endpoint for per-site SSH keys (logging in directly as `site_user`).

---

## 9. Git deploy webhook

`POST /api/v1/webhooks/git-deploy/{token}`

- **No bearer token.** It is authenticated by the per-site `{token}` in the URL plus a shared secret. Both are configured on the site's Git settings in the TurnkeyWP UI, where you can copy the webhook URL.
- Rate limit: 60/min per IP.
- Git must be enabled for the site, and a webhook secret must be set.

**Signature verification**

- GitHub: `X-Hub-Signature-256: sha256=<HMAC-SHA256(body, secret)>`.
- Bitbucket Cloud: the same `sha256=` value in `X-Hub-Signature`. SHA-1 signatures are rejected.
- GitLab: `X-Gitlab-Token: <secret>`.

**Branch handling.** The pushed branch is read from `ref` (`refs/heads/<branch>`), Bitbucket's `push.changes.0.new.name`, or a plain `branch` field. Pushes to other branches are acknowledged and ignored. An event header of `ping` returns `pong`.

**Responses.** These use `error` and `status` keys, not the API's `message`-only shape:

| Case | Status | Body |
|---|---|---|
| Unknown token | 404 | `{"error": "Invalid or expired webhook token."}` |
| Git disabled | 422 | `{"error": "Git integration is disabled for this site."}` |
| No secret configured | 401 | `{"error": "Webhook secret is required."}` |
| Missing signature | 401 | `{"error": "Webhook signature required."}` |
| Bad signature or token | 401 | `{"error": "Invalid webhook signature."}` or `{"error": "Invalid webhook secret token."}` |
| Ping | 200 | `{"status": "pong", "message": "...", "site": "example.com"}` |
| Other branch | 200 | `{"status": "ignored", "message": "Push was for branch 'dev', but site is configured to deploy 'main'."}` |
| Queued | 200 | `{"status": "queued", "site": "example.com", "branch": "main", "message": "Deployment job queued successfully."}` |

The webhook returns **no `event_id`**. To follow the deploy, poll `GET /sites/{id}/events` for the newest `Deploy Git (...)` task.

---

## 10. Quick start (curl)

```bash
export TK=https://app.turnkeywp.com/api/v1
export TOKEN='12|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
H=(-H "Authorization: Bearer $TOKEN" -H "Accept: application/json")

# 1. Check the token
curl -s "${H[@]}" "$TK/user"

# 2. List servers (page 1, 50 per page)
curl -s "${H[@]}" "$TK/servers?limit=50&page=1"

# 3. List sites on server 3
curl -s "${H[@]}" "$TK/servers/3/sites?limit=100"

# 4. Create a site on server 3
curl -s "${H[@]}" -H "Content-Type: application/json" \
  -d '{"domain":"example.com","additional_domains":["www.example.com"],"php_version":"8.3"}' \
  "$TK/servers/3/sites"
# -> {"data":{...,"id":41,...},"event_id":900}

# 5. Poll the event until it's completed or failed
while :; do
  s=$(curl -s "${H[@]}" "$TK/events/900" | jq -r .data.status)
  echo "$s"; [ "$s" = running ] || break; sleep 5
done

# 6. Purge the page cache
curl -s -X POST "${H[@]}" "$TK/sites/41/purge-page-cache"

# 7. Enable HTTPS (after DNS points at the server)
curl -s -X POST "${H[@]}" -H "Content-Type: application/json" \
  -d '{"email":"ops@example.com"}' "$TK/sites/41/enable-https"

# 8. Check pending OS updates, then apply security patches only
curl -s -X POST "${H[@]}" "$TK/servers/3/check-updates"
curl -s -X POST "${H[@]}" -H "Content-Type: application/json" \
  -d '{"security_only":true}' "$TK/servers/3/apply-updates"

# 9. Take a backup and fetch a download link once it's completed
curl -s -X POST "${H[@]}" -H "Content-Type: application/json" -d '{"scope":"full"}' "$TK/sites/41/backups"
curl -s "${H[@]}" "$TK/sites/41/backups/55/download"

# 10. Last 24h of traffic for a site
curl -s "${H[@]}" "$TK/sites/41/traffic?period=hourly&limit=24"
```

---

## 11. Client implementation checklist

- Send `Accept: application/json` on every request. Without it, some error paths may not render as JSON.
- Paginate with `page` and `limit` and stop at `meta.last_page`. Re-send your filters on every page.
- Treat 401 as "token revoked", 403 as "role does not allow it", and 404 on a known id as "deleted or not visible to this user".
- On 429, wait for `Retry-After` seconds. Keep polling to one request every 3–5 s per event, because all requests share 60/min per token.
- Give every event poll a ceiling, and treat `running` past the ceiling as unknown.
- Re-fetch the resource after an event completes, since the event itself carries no resource payload.
- Never auto-retry `enable-https` or anything that reissues certificates (domain add/remove, alias updates on HTTPS sites).
- Prefer `turnkey_status` over `status` for decisions.

---

## 12. Relationship to the existing hosting client

Clockwork Control's existing hosting client (`Modules\SpinupWp\SpinupWpClient`) is meant to work against this API unchanged during the migration off SpinupWP. Some field names and mapped status words exist for that compatibility: `provider_name`, `status` = `provisioned`/`deployed`, `site_user`, `additional_domains`, the `https`/`database`/`backups` sub-objects, `event_id` on writes, `/events/{id}`, and the `additional-domains` and `purge-page-cache` aliases.

The pagination envelope is **not** a `{"data", "pagination"}` shape. It is Laravel's `data`/`links`/`meta`, so a client that reads `pagination.*` will not page correctly (gap G4). For that reason, and because the SpinupWP client would store TurnkeyWP ids in `spinupwp_id`, Clockwork Control has its own `Modules\TurnkeyWp` client built to this document instead. See TurnkeyWP's `docs/integrations/clockwork-control.md` for what it relies on.

---

## 13. Known gaps and inconsistencies

Each item gives the evidence in the code. Paths are relative to the repository root.

**G1. Cloud server creation through the API always fails.**

- `ServerController::store` never passes `provider_account_id` to `CreateServerRecord` (`app/Http/Controllers/Api/V1/ServerController.php:80-90`). `CreateServerRecord` only sets it from the input data (`app/Actions/CreateServerRecord.php:64`).
- `CreateServer` then throws `Server has no provider account.` (`app/Jobs/CreateServer.php:37`), so the event ends `failed`.
- In practice only `provider: custom` works via the API.
- Separately, an unknown `provider` silently becomes `custom` (`ServerController.php:71`).

**G2. A custom server can't be adopted through the API alone.**

- The operator must put the server's generated public key in root's `authorized_keys`, but `ServerResource` doesn't expose `public_key` (`app/Http/Resources/Api/V1/ServerResource.php:21-55`).
- `GET /ssh-key` returns a different key: the account-level library key, never the server key (`SshKeyController.php:148-161`).
- When `ip_address` is given, `store` immediately moves the server to `booting` and starts SSH polling (`ServerController.php:92-95`). That fails after about 15 min (`WaitForServer.php:24-25,70-73`) unless the key was somehow installed already. `start-provisioning` can then retry.
- No endpoint sets an IP on a server created without one.

**G3. The magic-login URL can't be retrieved via the API.**

- `CreateMagicLogin` writes `login_url` and `expires_in` into `tasks.result`, with the comment "API clients read the link from the task result" (`app/Actions/CreateMagicLogin.php:51-56`).
- `EventResource` never emits `result` (`app/Http/Resources/Api/V1/EventResource.php:22-35`).
- No other API structured result (e.g. `restarted` from restart-services) is visible either.

**G4. The pagination envelope differs from the existing doc and the hosting client's expectation.**

- The previous version of `docs/integrations/clockwork-control.md` (rewritten 2026-10-14) said `{"data": [...], "pagination": {...}}`. The code returns Laravel's default `data`/`links`/`meta` (e.g. `ServerController.php:40` returns `ServerResource::collection($paginator)`; nothing customizes `paginationInformation`).
- No controller calls `withQueryString()`, so `links.*` drop `limit`, `server_id` and `site_id`.
- The page-size param is `limit`; `per_page` is ignored.
- Defaults are inconsistent: 20 for most lists, 25 for site events (`SiteController.php:132`), 100 and unclamped for SSH keys (`SshKeyController.php:36`), and a fixed 20 for destinations (`BackupController.php:168`).

**G5. Some queued work has no `event_id`.**

- `addDomain` and `deleteDomain` dispatch `IssueSsl` without a task when HTTPS is on (`SiteController.php:294-296,343-345`), so the certificate reissue isn't trackable from the response.
- SSH key removal queues `UpdateServerSshKey` but returns only a message (`SshKeyController.php:123-140`).
- The webhook dispatches `DeploySiteGit` with no task (`GitWebhookController.php:90`).
- Clone returns only the clone task, not the new site's `create-site` task (`SiteController.php:483-525`).

**G6. WordPress update flags are hardcoded to `false`.**

- `SiteResource.php:46-48` always emits `false` for `wp_core_update`, `wp_theme_updates` and `wp_plugin_updates`.
- The data exists: `CheckSiteWpUpdates` stores it in `settings.wp_updates` (`app/Jobs/CheckSiteWpUpdates.php:46`).

**G7. The `backups` block on Site is fake.**

- `SiteResource.php:49-55` returns constants: both kinds enabled, `retention: 30`, `next_run_time` = tomorrow 03:00 (computed at request time), `storage_provider: "turnkey"`.
- None of these reflect the site's real schedule (`RunScheduledBackupsCommand` keeps that elsewhere).
- `https.certificate_renews` is likewise derived as expiry minus 30 days (`SiteResource.php:35`), not an actual renewal time.

**G8. Tasks can stay `running` forever.**

- Only `CreateSite`, `CreateServer`, `WaitForServer`, `ProvisionServer`, `AdoptServer` and `DeleteServer` define `failed()` handlers, and only the first four call `Task::markFailed` (`app/Jobs/*.php`).
- For every other job, a failure before or outside `TaskRunner::run` leaves the task as created. Examples: waiting out the 30-minute `retryUntil` on the per-server lock (`app/Jobs/Concerns/RunsOnServerExclusively.php:34-37`), or an exception while building the script. Most controllers create tasks already in `running` with `started_at` set.
- The mapped status also hides `pending` (`EventResource.php:41-43`).
- Clients must apply their own timeout.

**G9. Validation gaps.**

- `POST /sites` validates `admin_username` and `admin_password` but never uses them (`SiteController.php:88-89` vs `108-119`). The WordPress admin user and password are generated server-side and never returned.
- `clone` `target_domain` has no domain-format regex, unlike every other domain input (`SiteController.php:461`).
- `change-domain` `new_domain` isn't checked for uniqueness against other sites (`SiteController.php:584`). The `sites` table is only unique on `(server_id, domain)` (`database/migrations/2026_10_10_000003_create_sites_table.php:35`), and `RenameSite` saves the new domain only after the script has already moved the files (`app/Jobs/RenameSite.php:48-54`).
  - A clash with another site on the **same server** makes that save throw after the task has finished as `completed`. The server is then renamed but the record keeps the old domain.
  - A clash with a site on a **different server** is accepted, leaving two sites with the same domain.
- `purge-cache` silently maps an invalid `scope` to `all` (`SiteController.php:171-174`).
- Traffic `period` on the GET endpoints isn't validated (`TrafficController.php:24,120`).
- `POST /servers` `provider` falls back to `custom` (G1).

**G10. `POST /sites/{id}/enable` marks the site `ready` before anything runs.**

- `SiteController.php:368` sets `status = ready` synchronously, even for a `failed` or `pending` site.
- If the vhost job then fails, the site still reads `deployed`.

**G11. Backup placeholders look real.**

- On create, `checksum_sha256` is `hash('sha256', Str::random(32))` and `file_path` is a guessed path (`BackupController.php:63,66`). Both are replaced only on success (`app/Jobs/BackupSite.php:94-103`), so a `running` or `failed` backup shows a random checksum.
- `SiteBackupResource.download_url` points to the JSON API endpoint, not the archive (`SiteBackupResource.php:41`).
- On failure, `error_message` holds the entire task output (`BackupSite.php:105-109`).

**G12. Some ownership isn't checked.**

- `DELETE /servers/{server}/ssh-keys/{key}` doesn't verify that `{key}` belongs to `{server}`. It deletes any key id and runs the removal against the `{server}` in the path (`SshKeyController.php:101-136`).
- Traffic ingest accepts any existing `site_id` without checking that it belongs to the `{server}` (`TrafficController.php:178,196-204`).
- `GET /providers` isn't gated by `create-servers`, because `AuthorizeFleetAccess::CREATE_ROUTES` lists the web route `providers.index` but not `api.v1.providers.index` or `api.v1.server-providers.index` (`app/Http/Middleware/AuthorizeFleetAccess.php:20-22`). It only reveals which providers are connected.
- Account-level SSH keys are visible to any authenticated user, even one who isn't on a team. `scopeToVisibleServers` always includes `server_id IS NULL` rows (`SshKeyController.php:178-182`), and `GET /ssh-key` has no check (`SshKeyController.php:148-161`). These are public keys, so the exposure is low.

**G13. Service restart accepts any systemd unit.**

- The `{service}` path segment and the `services[]` items aren't validated (`ServerController.php:150-196`). They are split on spaces (`app/Jobs/RestartServerService.php:34`) and reach the script's catch-all branch, which restarts any active or enabled unit (`resources/scripts/restart-services.sh:72-77`). This requires manage-server.
- A name that matches nothing still ends `completed`, with nothing restarted.

**G14. Traffic `total_unique_visitors` over-counts.**

- `TrafficController.php:37,140` sums `unique_visitors` across buckets. `app/Models/Site.php:174-175` notes that summing per-bucket uniques doesn't give unique visitors, which is why the embedded summary omits the field.
- The server summary prefers server-level rows when any exist, but `sites_breakdown` always uses per-site rows, so the two may not add up.

**G15. Site writes need only `view-server`.**

- `AuthorizeFleetAccess.php:57-64` skips `manage-server` whenever `{site}` is present. A `site_admin` (who can't manage servers) can therefore delete sites, rename domains, clone, restore backups and toggle HTTPS on any site of an assigned server.
- Only `sync-remote-data` (`SiteController.php:622`) and site `collect-traffic` (`SiteController.php:814`) re-check `manage-server`.
- `POST /sites` with `server_id` in the body checks visibility only (`SiteController.php:94`), while `POST /servers/{server}/sites` requires `manage-server` through the middleware. So the same operation has different permissions depending on the path.

**G16. Unassigned members see every server.**

- A `server_admin` or `site_admin` with a null `server_ids` pivot can access every server: `Team::userServerIds()` returns `null` (`app/Models/Team.php:95-98`), and `User::canAccessServer()` treats `null` as all (`app/Models/User.php:163-166`).
- Assign servers explicitly to keep a scoped integration user scoped.

**G17. Error shapes are inconsistent.**

- Several business-rule 422s return only `message` with no `errors` (e.g. `SiteController.php:271,276,320,766,795`); others include `errors` (`SiteController.php:470-473,772-775`).
- `start-provisioning` uses a bare `abort_unless(..., 422)` with no message (`ServerController.php:250`).
- The webhook uses `error` instead of `message` (`GitWebhookController.php:23-55`).
- `GET /user` and both traffic GETs return unwrapped objects, while everything else uses `data`.

**G18. Token scoping is impossible.**

- Tokens are created with default abilities (`resources/views/pages/settings/⚡api-tokens.blade.php:23`). Nothing checks `tokenCan`, and Sanctum expiration is `null` (`config/sanctum.php:53`).
- A token can do everything its user's role allows, indefinitely.

**G19. Smaller inaccuracies.**

- `provider_name` is `ucfirst()` of the enum value, giving `Digitalocean`, not `DigitalOcean` as the previous version of `docs/integrations/clockwork-control.md` stated (`ServerResource.php:24`).
- `ServerResource.database` is hardcoded `mysql:3306` (`ServerResource.php:41-44`).
- `ubuntu_version` defaults to `"24.04"` when unknown (`ServerResource.php:32`).
- `is_staging` is a heuristic that also matches any domain containing `staging` or `sslip.io` (`app/Models/Site.php:365-371`).
- The domain-list `id` is positional and unstable (`SiteController.php:224-251,300`).
- The previous version of `clockwork-control.md` made traffic claims that don't match the code: windows of `1h/24h/7d/30d`, cache states `HIT/MISS/BYPASS` and "live log websocket streams". The API only offers `period=hourly|daily|realtime` with `limit` buckets, hit and miss counts, and no websocket.
- `clone` may race with the new site's `create-site` job. Both queue on the same per-server lock and `CloneSite` doesn't check that the target is `ready` (`SiteController.php:483-519`, `app/Jobs/CloneSite.php`). Released jobs can reorder.

**G20. Restore issues.**

- `POST /sites/{site}/backups/{backup}/restore` doesn't check `backup.status` (`BackupController.php:103-123`). Restoring a `running` or `failed` backup passes its placeholder `file_path` and random checksum to the script (`app/Jobs/RestoreSite.php:83-84`); the checksum check should then fail the task.
- The pre-restore safety backup (`RestoreSite.php:35-73`) runs as an untracked task. Its failure doesn't stop the restore.
