---
title: Backup relay (Multi-Provider → S3 Glacier)
section: Features
order: 130
updated: 2026-09-12
author: Aaron Reimann
tags: [pressable, spinupwp, backups, s3, glacier, backup-relay]
tracks: [modules/BackupRelay/**, app/Console/Commands/PushBackupRelayTargets.php, app/Console/Commands/PullBackupRelayReport.php, app/Console/Commands/BackupRestoreCommand.php, app/Models/BackupRelayRun.php, app/Http/Controllers/Settings/BackupRelaySettingsController.php, resources/views/dashboard/site/widgets/_widget-backups.blade.php]
---

Archives off-host snapshots across supported hosting providers (Pressable, SpinupWP, and any provider implementing `HostingProvider::CAP_BACKUP_RELAY`) directly to S3 Glacier Instant Retrieval. Provides long-term off-host disaster recovery beyond host-limited retention windows.

## Why this exists

Third-party backup retention windows (e.g. ManageWP's 90-day retention or host-local limits) are often insufficient for agency client compliance and disaster recovery. S3 Glacier Instant Retrieval offers durable, low-cost long-term storage that remains immediately retrievable when needed.

## Operational Modes

Backup Relay operates in one of two modes, configured via `CLOCKWORK_BACKUP_RELAY_MODE`:

### 1. In-Repo Mode (`in_repo` — Default)
In-repo mode runs natively within Clockwork Control with zero external server dependencies:
- **Scheduler**: `clockwork:backup-relay-run` executes daily at 04:58 UTC.
- **Provider Adapters**: Queries each provider adapter (`BackupRelayAdapter`) for `latestBackupRef()`.
- **Deduplication**: Verifies that the backup hasn't already been uploaded to S3 or already recorded in `sites.backup_relay_last_archived_at`. Companion/direct-to-S3 archives use a stable daily key (`archives/{domain}/{Y-m-d}.zip`); if the object already exists (HMAC timed out after the PUT), the job treats that as success and records `backup_relay_last_archived_at` rather than minting a second key.
- **Direct Streaming**: Streams the backup payload directly into S3 using `Modules\BackupRelay\Services\GlacierUploader` with the `GLACIER_IR` storage class. Standalone Companion sites mint a presigned PUT URL and never buffer the zip on Clockwork; Companion must send the signed `x-amz-storage-class: GLACIER_IR` header.
- **Run Tracking**: Writes execution summaries directly to the `backup_relay_runs` table.

### 2. External Agent Mode (`external_agent`)
Recommended for production agency fleets with high-bandwidth requirements and air-gapped/non-internet-facing environments. A separate cloud droplet (e.g. $4–$6/mo DigitalOcean or Hetzner VPS) runs the open-source [Clockwork Backup Relay](https://github.com/Clockwork-Web-Dev-LLC/clockwork-backup-relay) CLI agent (MIT licensed).

**Why use an external droplet?**
1. **Bandwidth Isolation**: Large backup archives stream directly between Pressable and S3 in the datacenter, never consuming the operator's local office internet bandwidth or competing with monitoring tasks.
2. **Credential & Blast-Radius Isolation**: Account-wide Pressable API and AWS write credentials reside strictly on the single-purpose droplet, completely isolated from developer workstations or web-facing servers.
3. **Zero Inbound Connections**: The monitoring app never opens ports, tunnels, or public webhooks. Both sides communicate exclusively via an S3 control channel.

**The S3 Control Channel Timeline (Sundays & Wednesdays)**:
- **`04:58 UTC` — Clockwork Control**: `clockwork:push-backup-relay-targets` runs locally, queries all sites with `backup_relay_enabled = 1`, and writes `s3://{bucket}/_control/backup-relay/targets.json` (schema v2).
- **`05:00 UTC` — External Droplet**: Cron runs `bin/relay.php`. It reads `targets.json`, checks S3 (`HeadObject`) for existing archives to avoid redundant downloads, streams new snapshots from Pressable directly to S3 Glacier Instant Retrieval (`{domain}/fs/{date}.bz2` and `{domain}/db/{date}.sql`), generates 5-day presigned S3 download links, and writes `last-report.json` and `download-links.json` to S3.
- **`06:32 UTC` — Clockwork Control**: `clockwork:pressable-backups-report` pulls `download-links.json` and pushes the offsite archive download links to each site's WordPress Companion plugin (`wp-admin/admin.php?page=clockwork-backups`).
- **`06:40 UTC` — Clockwork Control**: `clockwork:pull-backup-relay-report` reads `last-report.json`, logs the completed run to `backup_relay_runs`, and updates the dashboard.

> [!NOTE]
> **Dashboard UI Note**: In `external_agent` mode, the droplet reports aggregate run metrics (`sites_total`, `sites_archived`, `sites_failed`) rather than per-site database updates. As a result, the "Last Archived" column in the site targets table will display `—`. Full run health and completed archive counts are tracked in the **Last Relay Run** card and **Relay Run History** table at the bottom of the page.

---

## Setting up the DigitalOcean Backup Relay Droplet

The external agent is available as a standalone repository: [clockwork-backup-relay](https://github.com/Clockwork-Web-Dev-LLC/clockwork-backup-relay) (MIT licensed).

### 1. Provision the Droplet
- **Provider**: DigitalOcean (or any cloud VPS provider).
- **OS**: Ubuntu 24.04 LTS (or 22.04 LTS).
- **Size**: Basic Droplet with **1 vCPU, 1 GB RAM, 25 GB SSD** ($4–$6/month). Streaming operates in small, bounded memory chunks; 1 GB RAM is plenty.
- **Region**: Select a region close to your Pressable datacenter or S3 bucket (e.g. `nyc3` or `sfo3`).

### 2. Install Dependencies
SSH into the fresh droplet:
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y php-cli php-curl php-mbstring composer git unzip
```

### 3. Service User & Application Directory
Create a dedicated system user and clone the repository:
```bash
sudo adduser --system --group --home /opt/clockwork-backup-relay relay
sudo git clone https://github.com/Clockwork-Web-Dev-LLC/clockwork-backup-relay.git /opt/clockwork-backup-relay
cd /opt/clockwork-backup-relay
sudo composer install --no-dev --optimize-autoloader
sudo chown -R relay:relay /opt/clockwork-backup-relay
```

### 4. Configure Environment Variables
Copy `.env.example` to `.env` and restrict permissions:
```bash
sudo cp .env.example .env
sudo chmod 600 .env
sudo chown relay:relay .env
sudo nano .env
```

Configure your credentials:
```dotenv
# Pressable OAuth2 API credentials (client_credentials grant)
PRESSABLE_CLIENT_ID=your_pressable_client_id
PRESSABLE_CLIENT_SECRET=your_pressable_client_secret
PRESSABLE_AUTH_URL=https://my.pressable.com/auth/token
PRESSABLE_BASE_URL=https://my.pressable.com/v1

# Destination S3 Bucket & Control Prefix (matches Clockwork Control)
AWS_ACCESS_KEY_ID=your_aws_access_key
AWS_SECRET_ACCESS_KEY=your_aws_secret_key
AWS_REGION=us-east-2
S3_BUCKET=your-agency-pressable-backups
S3_CONTROL_PREFIX=_control/backup-relay
```

### 5. S3 Bucket & Lifecycle Setup (AWS)
1. **Create Bucket**:
   ```bash
   aws s3api create-bucket \
     --bucket your-agency-pressable-backups \
     --region us-east-2 \
     --create-bucket-configuration LocationConstraint=us-east-2
   ```
2. **Lifecycle Rule (90-Day Retention)**:
   Create `lifecycle.json`:
   ```json
   {
     "Rules": [
       {
         "ID": "expire-backups-after-90-days",
         "Filter": {},
         "Status": "Enabled",
         "Expiration": { "Days": 90 }
       }
     ]
   }
   ```
   Apply the policy:
   ```bash
   aws s3api put-bucket-lifecycle-configuration \
     --bucket your-agency-pressable-backups \
     --lifecycle-configuration file://lifecycle.json
   ```
3. **IAM Least-Privilege Policy**:
   Grant the IAM user permissions to `s3:ListBucket` on the bucket and `s3:PutObject`, `s3:GetObject`, `s3:AbortMultipartUpload` on `arn:aws:s3:::your-bucket/*`.

### 6. Verification & Test Commands
Run the offline test to verify everything is wired correctly without needing real credentials:
```bash
php bin/relay.php --offline-test
```
Run a dry-run test to verify real Pressable authentication and S3 connectivity without downloading or uploading payloads:
```bash
php bin/relay.php --dry-run
```

### 7. Configure Crontab
Configure the relay to execute twice weekly (Sundays and Wednesdays at 05:00 UTC):
```bash
sudo crontab -u relay -e
```
Add the cron line:
```cron
0 5 * * 0,3 cd /opt/clockwork-backup-relay && /usr/bin/php bin/relay.php >> /var/log/clockwork-backup-relay.log 2>&1
```

Create and permission the log file:
```bash
sudo touch /var/log/clockwork-backup-relay.log
sudo chown relay:relay /var/log/clockwork-backup-relay.log
sudo chmod 664 /var/log/clockwork-backup-relay.log
```

## Provider Generality

Backup relay is not hardcoded to Pressable. Any hosting provider module in `modules/` can participate by:
1. Declaring `supports(HostingProvider::CAP_BACKUP_RELAY)` as true.
2. Returning an instance of `Modules\Core\Contracts\BackupRelayAdapter` from `backupRelayAdapter()`.
3. Implementing `latestBackupRef(Site $site): ?BackupRef` and `openBackupStream(Site $site, BackupRef $ref)`.

Built-in provider adapters:
- **Pressable**: Queries Pressable's backups API for the newest filesystem and database snapshots.
- **SpinupWP**: Inspects DigitalOcean Spaces backup objects via `SpacesClient` to stream the newest archive run.

## Dedicated Per-Site Enablement

Site selection uses the dedicated boolean column `sites.backup_relay_enabled`.
- Migrations automatically backfilled existing Pressable care-plan sites to `backup_relay_enabled = true`.
- Operators can toggle backup relay on or off per site or in bulk from the `/settings/backup-relay` dashboard.

## Unhosted / standalone sites (ManageWP replacement)

SpinupWP and Pressable already take their own backups. Glacier relay on those hosts is optional off-site copy, not the primary schedule.

For WordPress sites we **do not host** (WP Engine, Kinsta, a client box, any `custom` enroll), Companion dumps files + database and PUTs a zip straight to agency S3 Glacier Instant Retrieval. That is the backup.

- Connecting a site at `/sites/create` turns relay **on** and sets `backup_relay_frequency = daily`.
- The site Overview **Backups** card is the per-site control: on/off, Daily / Twice weekly / Weekly, last/next, a month calendar of archives, and **Backup Now**.
- Nightly `clockwork:backup-relay-run` (04:58) respects that per-site cadence. Daily uses calendar day so a 15:00 Backup Now does not skip tomorrow morning.
- **Backup Now** runs `clockwork:backup-relay-run --site={id} --force` in the background and writes `archives/{domain}/{Y-m-d_H-i-s}.zip` so it never collides with the scheduled daily key.

### Restore for Custom Sites (Two-Step Stage & Apply)

For custom unhosted sites, Clockwork provides a safe two-step restore flow directly from the site's Backups widget (`/sites/{id}`):

1. **Two-Step Architecture**:
   - **Step 1 (Stage)**: The operator selects an archive from the historical archives table and confirms the exact domain name. Control verifies the SHA-256 integrity hash for the archive (from the `{key}.sha256.json` sidecar created during backup, or from `sites.backup_relay_last_sha256` for the newest archive). If no hash is on record, restore is strictly refused. Control generates a 120-minute presigned S3 GET URL and triggers `POST /wp-json/clockwork/v1/backup/restore/stage` on Companion. Companion streams the zip into `wp-content/clockwork-backups/restore-staging/`, validates the SHA-256 checksum, and unpacks the archive via `ZipArchive` (or one-shot `PclZip` fallback on hosts lacking `ext-zip`). Control polls `GET /wp-json/clockwork/v1/backup/restore/status` (a GET request that does not burn the HMAC replay window) until staging finishes.
   - **Step 2 (Apply)**: Once staged, the operator reviews the detected staging details (database dump presence, table prefix, files archive) in the modal and clicks **Apply Restore**. Companion places WordPress into maintenance mode (`.maintenance`), imports the SQL dump using gzip streaming scoped strictly to `$wpdb->prefix` (failing closed with `prefix_mismatch` if 0 tables match), and copies the restored `wp-content/` files over the active filesystem.

2. **Fail-Closed Maintenance Mode**:
   - If SQL import fails or file copy fails after maintenance mode has been engaged, Companion **intentionally leaves maintenance mode enabled** to prevent visitors from hitting a partially applied database or missing assets.
   - Control flags `maintenance_left_on` in the restore state cache and renders a prominent red alert banner on the Backups widget alerting the operator to SSH in and inspect the host.

3. **Additive Copy-Over Limitation**:
   - Restoring files is an additive copy-over operation: archive files overwrite active files, but files added on the site *after* the backup was created (e.g. newly uploaded media or newly installed plugins) are not deleted. A full disk wipe is intentionally avoided over REST for safety.

4. **Audit Logging & Orchestration**:
   - Control runs the restore via `BackgroundArtisan` running `clockwork:backup-restore {--site=} {--phase=} {--key=} {--actor=}`.
   - Every stage, apply, and failure records an `ActionLog` entry (`backup_restore_staged`, `backup_restore_applied`, `backup_restore_failed`) visible in the site's Activity history.

`sites.backup_relay_frequency` is nullable. Hosted sites leave it null and inherit `/settings/backup-relay`. Custom sites set their own.

## S3 Destination Configuration

Backup relay uses the dedicated `s3-backup-relay` filesystem disk (`config/filesystems.php`). You can configure dedicated AWS credentials separate from general storage:

```dotenv
# Dedicated backup relay S3 credentials (falls back to AWS_* if omitted)
S3_BACKUP_RELAY_KEY=your-aws-access-key-id
S3_BACKUP_RELAY_SECRET=your-aws-secret-access-key
S3_BACKUP_RELAY_REGION=us-east-1
S3_BACKUP_RELAY_BUCKET=your-agency-backups-bucket

# Operational Mode ('in_repo' or 'external_agent')
CLOCKWORK_BACKUP_RELAY_MODE=in_repo

# Storage disk, cadence, retention, and archive path prefix
CLOCKWORK_BACKUP_RELAY_DISK=s3-backup-relay
CLOCKWORK_BACKUP_RELAY_FREQUENCY=weekly
CLOCKWORK_BACKUP_RELAY_RETENTION_DAYS=90
# Production uses '_control/backup-relay/archives' because Control's scoped IAM
# policy only permits PutObject operations under '_control/backup-relay/'
CLOCKWORK_BACKUP_RELAY_ARCHIVE_PREFIX=archives
```

## Settings Dashboard (`/settings/backup-relay`)

The web UI provides full visibility and control:
- **Operational Mode Badge**: Shows whether in-repo native archiving or external droplet handoff is active.
- **Run Relay Now**: Launches `clockwork:backup-relay-run` in the background (`BackgroundArtisan`, 2-hour lock). The request returns immediately — a full archive + Glacier upload per site cannot finish in-request.
- **Site Relay Targets**: Domain list with provider badges, adapter capability status, last archived timestamp, and instant toggle switches. Each row is expandable — see [Viewing & Downloading Historical Archives](#viewing--downloading-historical-archives) below.
- **Run History**: Table of recent runs displaying archived, skipped, failed counts, durations, and error details.

## Viewing & Downloading Historical Archives

Beyond scheduling and run status, `/settings/backup-relay` lets an operator inspect and download the actual archived S3 objects for each site — not just whether the last run succeeded.

### `BackupArchiveEnumerator`

`Modules\BackupRelay\Services\BackupArchiveEnumerator::forSite(Site $site)` lists every archive object for a site directly from S3, searching both the in-repo layout (`archives/{domain}/*`) and the external-agent layout (`{domain}/fs/*`, `{domain}/db/*`). It returns a summary (`total_count`, `total_bytes`/`total_size_formatted`, `last_archived_at`) plus a per-archive array with a detected component type (`fs`/`db`/`full`, guessed from the path/filename), size, an archived-at timestamp (parsed from the filename's date pattern, falling back to S3's `LastModified`), and a resolved download URL.

**Why `listContents()`, not `size()`/`lastModified()`**: an earlier version of this code called `$disk->size()` and `$disk->lastModified()` per object — each of those issues a `HeadObject` request, which needs `s3:GetObject` on the IAM policy. The monitoring IAM user only has `s3:ListBucket`, so every size/timestamp lookup silently failed and every archive showed as **0 B** with no date. Switching to `$disk->listContents($prefix, true)` fixed it: `ListObjectsV2` already returns `Size`/`LastModified` for every object in its response, so no second per-object API call — and no extra IAM permission — is needed at all. (Verified against a real production bucket: one site showed 6 real archives, 169–273 MB each, 1.3 GB total, where the old code reported 0 B for every one of them.) The `HeadObject`-triggering fallback was deliberately removed rather than kept as a backstop — it would just re-fail with the same `AccessDenied` the `listContents()` switch exists to avoid.

**Download URL resolution** (`getDownloadUrl()`): if the disk's configured driver is `s3` (`BackupArchiveEnumerator::supportsPresignedUrls()`), returns a real presigned S3 URL valid for `DOWNLOAD_URL_TTL_HOURS` (24 hours). Otherwise it falls back to an operator-authenticated Clockwork Control route (`settings.backup-relay.download`) — Laravel's `FilesystemAdapter::temporaryUrl()` exists on every driver, so a `method_exists()` check would always be true and never actually gate anything; checking the configured driver directly is what makes the fallback real. In `external_agent` mode, if the droplet's `download-links.json` control-channel manifest already has a presigned link for a given object, that link is used instead of minting a new one.

### Expandable site rows

Each row in the **Site Relay Targets** table is expandable: clicking it lazy-loads `GET /settings/backup-relay/sites/{site}/archives` (`settings.backup-relay.archives`, JSON) and renders loading/error/empty states plus, on success, summary metrics and a snapshot table — one row per archive with a type badge (`fs`/`db`/`full`), size, archived-at, and a direct download link.

### Download route

`GET /settings/backup-relay/sites/{site}/download` (`settings.backup-relay.download`) takes a base64-encoded `key` query parameter, validates it belongs to that site's own domain prefix (`archives/{domain}/` or `{domain}/` — 403s otherwise, so one site's row can't be used to fetch another site's backup), confirms the object still exists in S3 (404s otherwise), and either redirects to a freshly-minted 1-hour presigned URL or streams the file directly if the disk doesn't support presigned URLs.

### Pushing offsite archive links to the Companion plugin

`PressableBackupsReport` and `PushCompanionBackupsReport` — which populate the `offsite_archive` block of the backups payload pushed to each site's Companion plugin, surfaced on the client-facing `wp-admin/admin.php?page=clockwork-backups` page — now enrich that payload from `BackupArchiveEnumerator` for any `backup_relay_enabled` site not already covered by an `external_agent`-mode manifest entry: picking the newest `fs`/`db` (or `full`) archive's download URL, and setting `download_expires_at` to the real ~24h presigned-URL expiry (previously hardcoded `null` in this enrichment path).

This enrichment is gated on `BackupArchiveEnumerator::supportsPresignedUrls()`: without a real presigned S3 URL, `getDownloadUrl()` would fall back to an operator-authenticated Clockwork Control route — which, handed to a client on the Companion wp-admin page, would just bounce them to the Clockwork Control login screen. Rather than hand a client a dead-end link, both commands omit `offsite_archive` entirely when presigned URLs aren't available.

`PushCompanionBackupsReport` also covers **custom/unhosted sites** (`hosting_provider=custom`, `backup_relay_enabled`) in a dedicated pass that runs even when no SpinupWP token is configured. There's no host backup API for these sites, so the pushed report is built purely from the Glacier archive store: `source=clockwork-companion`, `history_scope=combined`, an empty `config` (the plugin 400s on a missing/non-array `config`), and history rows (`date`/`type=full`/`size_bytes`) enumerated from `BackupArchiveEnumerator`. Without this pass, a custom site's client-facing Backups page stays permanently empty even though archives exist — the scheduled push previously only ever looked at `spinupwp_id` sites.

## Cadence and Retention Policy

Backup Relay supports configurable snapshot frequency and retention policies managed directly in `/settings/backup-relay` or via environment variables:

- **Frequency / Cadence**:
  - Fleet default on `/settings/backup-relay`: `weekly`, `twice_weekly`, or `daily`.
  - Per-site override on custom/unhosted sites (`sites.backup_relay_frequency`). Daily skips when an archive already exists for **today**; weekly / twice-weekly still use the hour floor (144h / 72h).
  - `weekly` (`1 a week` — Default for hosted relay): Runs once per week off-peak.
  - `twice_weekly` (`2 a week`): About every three days.
  - `daily` (`Daily`): Every morning at 04:58, and the default for newly enrolled standalone sites.
- **Retention Period**:
  - Default: `90 days`. Configurable to `30`, `60`, `90`, `180`, or `365` days.
  - In external agent mode, `targets.json` includes `frequency` and `retention_days` in the manifest schema v2 so the remote droplet enforces the retention lifecycle.
  - In in-repo mode, `ArchiveSiteBackupJob` respects the site's effective frequency (per-site or fleet) unless `--force` / Backup Now is set.

```dotenv
# Cadence & Retention settings (can also be changed in /settings/backup-relay)
CLOCKWORK_BACKUP_RELAY_FREQUENCY=weekly
CLOCKWORK_BACKUP_RELAY_RETENTION_DAYS=90
```

## Silence & Staleness Detection

If the relay fails to record a successful run within the dynamic staleness threshold (10 days for weekly, 6 days for twice-weekly, 3 days for daily), `clockwork:pull-backup-relay-report` triggers a `backup_relay_stale` alert via Mattermost, Slack, or configured webhook channels. A recovery notification (`backup_relay_recovered`) fires automatically when a new run completes.
