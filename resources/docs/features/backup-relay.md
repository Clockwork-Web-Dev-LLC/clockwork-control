---
title: Backup relay (Multi-Provider → S3 Glacier)
section: Features
order: 130
updated: 2026-09-08
author: Aaron Reimann
tags: [pressable, spinupwp, backups, s3, glacier, backup-relay]
tracks: [modules/BackupRelay/**, app/Console/Commands/PushBackupRelayTargets.php, app/Console/Commands/PullBackupRelayReport.php, app/Models/BackupRelayRun.php, app/Http/Controllers/Settings/BackupRelaySettingsController.php]
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
- **Deduplication**: Verifies that the backup hasn't already been uploaded to S3 or already recorded in `sites.backup_relay_last_archived_at`.
- **Direct Streaming**: Streams the backup payload directly into S3 using `Modules\BackupRelay\Services\GlacierUploader` with the `GLACIER_IR` storage class.
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

## Cadence and Retention Policy

Backup Relay supports configurable snapshot frequency and retention policies managed directly in `/settings/backup-relay` or via environment variables:

- **Frequency / Cadence**:
  - `weekly` (`1 a week` — Default): Runs once per week off-peak (Sundays at 04:58 UTC). Perfect balance of snapshot protection and host API budget.
  - `twice_weekly` (`2 a week`): Runs Sundays and Wednesdays.
  - `daily` (`Daily`): Runs every morning at 04:58 UTC.
- **Retention Period**:
  - Default: `90 days`. Configurable to `30`, `60`, `90`, `180`, or `365` days.
  - In external agent mode, `targets.json` includes `frequency` and `retention_days` in the manifest schema v2 so the remote droplet enforces the retention lifecycle.
  - In in-repo mode, `ArchiveSiteBackupJob` respects the interval before initiating new snapshot downloads.

```dotenv
# Cadence & Retention settings (can also be changed in /settings/backup-relay)
CLOCKWORK_BACKUP_RELAY_FREQUENCY=weekly
CLOCKWORK_BACKUP_RELAY_RETENTION_DAYS=90
```

## Silence & Staleness Detection

If the relay fails to record a successful run within the dynamic staleness threshold (10 days for weekly, 6 days for twice-weekly, 3 days for daily), `clockwork:pull-backup-relay-report` triggers a `backup_relay_stale` alert via Mattermost, Slack, or configured webhook channels. A recovery notification (`backup_relay_recovered`) fires automatically when a new run completes.
