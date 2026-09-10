---
title: DigitalOcean Spaces
section: Integrations
order: 15
updated: 2026-09-10
author: Aaron Reimann
tags: [integrations, digitalocean, spaces, backups]
tracks: [app/Services/DigitalOcean/SpacesClient.php, app/Console/Commands/DoSpacesTest.php, resources/views/dashboard/site/widgets/_widget-backups.blade.php, app/Http/Controllers/SitesController.php]
---

DigitalOcean Spaces is the S3-compatible object storage where SpinupWP writes our backups. We list it directly because SpinupWP's REST API exposes backup *configuration* but never backup *history*.

## Why we use it

SpinupWP can tell us "backups are enabled, retain 30 days, run nightly at 03:00." It cannot tell us when each backup actually ran or how big it was. The Companion plugin's Backups admin page would have nothing to show. So we list the Spaces bucket directly: SpinupWP names files `<domain>/<YYYY-MM-DD-HH-MM-SS>-<suffix>.{sql.gz|tar.gz}`, and we group those into one row per backup run with sizes and timestamps.

## Setup

1. In the DO control panel: Spaces → your bucket → **Settings → Access Keys** → New Access Key. Scope it to the bucket.
2. **These are S3-style HMAC keys, NOT a DO Personal Access Token.** Don't reuse the DO API token here — it won't work.
3. Set in `.env`:

   ```
   CLOCKWORK_DO_SPACES_KEY=...
   CLOCKWORK_DO_SPACES_SECRET=...
   CLOCKWORK_DO_SPACES_REGION=nyc3
   CLOCKWORK_DO_SPACES_BUCKET=your-bucket-name
   ```

4. Test:

```bash
php artisan clockwork:do-spaces-test
```

If the key is wrong you get a 403 from S3.

## Auth

S3 v4 HMAC signature, handled by `league/flysystem-aws-s3-v3`.

## Endpoints we call

Standard S3:

- `ListObjectsV2` against `<bucket>/<domain>/` for each Companion-equipped site with a `spinupwp_id`.

We never write or delete from the bucket. SpinupWP owns it.

## How the inference works

Once we have the raw object list:

- **`toHistoryRows($objects)`** — group objects by leading timestamp token. One row per backup run with `{date, type, database_bytes, files_bytes, notes}`.
- **`inferSchedules($historyRows)`** — derive daily / weekly / monthly cadence per hour-of-day bucket:
  - 5+ distinct dates observed → `daily` (confirmed)
  - 2+ runs all on same weekday → `weekly` (confirmed)
  - 2+ runs all on same day-of-month → `monthly` (confirmed)
  - Otherwise provisional. **Cross-bucket disambiguation**: when ≥2 unconfirmed buckets coexist, the earliest hour on Sunday / 1st-of-month is reclassified as weekly / monthly. Handles the bootstrap case where today's daily and weekly backups look identical until enough days accrue.

## Files

- `app/Services/DigitalOcean/SpacesClient.php` — `listSiteBackupObjects`, `toHistoryRows`, `inferSchedules`.
- `app/Http/Controllers/SitesController.php` — `backupsHistory()` JSON for the overview Snapshots modal.
- `resources/views/dashboard/site/widgets/_widget-backups.blade.php` — overview card; does not claim Protected until history exists.
- `app/Console/Commands/DoSpacesTest.php` — connectivity check.
- Config: `config/clockwork.php` → `do_spaces` key.

## Scheduled jobs that depend on it

| Cadence | Command |
|---|---|
| daily 06:30 | `clockwork:push-companion-backups` — fetches SpinupWP config + Spaces history → POSTs each to its Companion site. |

Push runs after the 03:30 SpinupWP inventory import so the local copy of `backups` config is fresh.

## Site Overview Backups widget

`_widget-backups.blade.php` used to treat “SpinupWP + Spaces credentials exist” as **Protected** / “Backups are successful.” That was a fleet-wide config flag, not evidence this site has objects in the bucket.

Current behavior:

- **Pressable native backups** or **S3 Glacier relay** (`backup_relay_enabled` / `backup_relay_last_archived_at`) still SSR as Protected, with a last-archive timestamp when we have one.
- **SpinupWP + Spaces:** first paint is **Checking…**. Alpine prefetches `GET /sites/{site}/backups-history` (`sites.backups.history`) after load — the same JSON the Snapshots modal uses. If `spaces_history` has rows, the pill becomes Protected, copy becomes “Backups are successful,” and **Last Spaces run** is the newest `date`. If the list is empty, the pill is **No snapshots**.
- The history endpoint lists Spaces objects only for SpinupWP sites and does not block the overview PHP render.

## Gotchas

- **Wrong credentials = wrong-page redirect in the DO dashboard.** Bookmark the bucket Settings tab directly.
- **The prefix template is overridable** — `CLOCKWORK_DO_SPACES_PREFIX_TEMPLATE` defaults to `{domain}/`. Override only if you're not on SpinupWP.
- **List-only access pattern.** We never write or delete. Don't promote the credential to a write key just because it's there.
- **Configured ≠ Protected.** Fleet Spaces credentials do not mean this site has been backed up. The overview widget waits for `spaces_history` before claiming success.
