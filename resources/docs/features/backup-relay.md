---
title: Backup relay (Multi-Provider → S3 Glacier)
section: Features
order: 130
updated: 2026-09-05
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
Preserved for backward compatibility and air-gapped/non-internet-facing environments where a separate droplet runs a private backup agent:
- **`clockwork:push-backup-relay-targets`** (04:58 UTC) queries enabled sites across all `CAP_BACKUP_RELAY` providers and outputs a schema-versioned `targets.json` (`schema_version: 2`) to the control prefix in S3.
- **`clockwork:pull-backup-relay-report`** (06:40 UTC) reads `last-report.json`, accepts schema v1 or v2, triggers a deprecation alert if v1 is detected, and records a `BackupRelayRun` row.

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
- **Run Relay Now**: Triggers an on-demand in-repo archive run across all enabled sites.
- **Site Relay Targets**: Domain list with provider badges, adapter capability status, last archived timestamp, and instant toggle switches.
- **Run History**: Table of recent runs displaying archived, skipped, failed counts, durations, and error details.

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
