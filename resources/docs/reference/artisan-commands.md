---
title: Artisan commands
section: Reference
order: 50
updated: 2026-09-12
author: Aaron Reimann
tags: [reference, artisan, cli, modules]
tracks: [app/Console/Commands/**, modules/*/src/Commands/**]
---

Every `clockwork:*` command, alphabetical, with a one-line summary and an example invocation. Commands provided by modules (`modules/*/src/Commands`) are registered automatically when their respective module is enabled. Most are also wired into the scheduler — see [Scheduled jobs](/docs/reference/scheduled-jobs) for cadence.

> [!NOTE]
> **Environment & PATH**: On Linux, `php` and `composer` are installed in standard system paths (`/usr/bin/php`), so commands can be executed directly. On macOS using Laravel Herd, remember to export Herd's binary directory (`export PATH="$HOME/Library/Application Support/Herd/bin:$PATH"`).

## Inventory + bootstrap

| Command | Purpose | Example |
|---|---|---|
| `clockwork:add-user` | Add or restore a user on the auth allowlist with optional display name, local password, and `--role=admin|operator` (default admin). Idempotent. | `php artisan clockwork:add-user alice@example.com --name=Alice --password=secret --role=operator` |
| `clockwork:set-password` | Set or reset an operator's local password via masked CLI prompt or option. | `php artisan clockwork:set-password alice@example.com` |
| `clockwork:sync-allowed-bots` | Pull arcjet/well-known-bots → `allowed_bots`. | `php artisan clockwork:sync-allowed-bots` |
| `clockwork:digitalocean-test` | Verify the DO token + list droplets. | `php artisan clockwork:digitalocean-test` |
| `clockwork:hetzner-test` | Verify the Hetzner Cloud token + list servers in the project. | `php artisan clockwork:hetzner-test` |
| `clockwork:spinupwp-test` | Verify the SpinupWP token + list servers/sites. Supports `clockwork:test-spinupwp` alias. | `php artisan clockwork:spinupwp-test` |
| `clockwork:pressable-test` | Verify the Pressable OAuth2 credentials + list sites. Supports `clockwork:test-pressable` alias. | `php artisan clockwork:pressable-test` |
| `clockwork:wpengine-test` | Verify the WP Engine API credentials + list installs. Supports `clockwork:test-wpengine` alias. | `php artisan clockwork:wpengine-test` |
| `clockwork:kinsta-test` | Verify the Kinsta API key + test authentication. Supports `clockwork:test-kinsta` alias. | `php artisan clockwork:kinsta-test` |
| `clockwork:cloudways-test` | Verify the Cloudways API credentials + list servers and apps. Supports `clockwork:test-cloudways` alias. | `php artisan clockwork:cloudways-test` |
| `clockwork:test-gridpane` | Verify the GridPane API key + list servers and sites. | `php artisan clockwork:test-gridpane` |
| `clockwork:do-spaces-test` | Verify the DO Spaces HMAC creds. | `php artisan clockwork:do-spaces-test` |
| `clockwork:bill-com-test` | Verify Bill.com auth + count customers. | `php artisan clockwork:bill-com-test` |
| `clockwork:mattermost-test` | Post a test message to the configured channel. | `php artisan clockwork:mattermost-test` |
| `clockwork:azure-test` | Verify Azure creds by listing VMs + public IPs in the subscription. | `php artisan clockwork:azure-test` |
| `clockwork:import-spinupwp` | Idempotent server + site import + cert refresh. Skips domains / SpinupWP site ids listed in `site_ingest_exclusions` (`skipped_excluded`). Accepts `--dry-run` to simulate without writing to database. | `php artisan clockwork:import-spinupwp --dry-run` |
| `clockwork:import-pressable` | Idempotent Pressable site import (no `Server` rows — Pressable has no server concept). Skips domains still actively hosted on SpinupWP if the same domain appears in both platforms, and skips `site_ingest_exclusions` matches. Accepts `--dry-run`. Not scheduled — run manually. | `php artisan clockwork:import-pressable --dry-run` |
| `clockwork:import-gridpane` | Idempotent GridPane server + site import, linking WordPress sites to their parent servers. Accepts `--dry-run` to simulate without writing to database. | `php artisan clockwork:import-gridpane --dry-run` |
| `clockwork:reconcile-provider` | Match `provider_id`-less servers against DO/Hetzner/Azure/Vultr/Linode inventory and fill provider + size columns. Idempotent. | `php artisan clockwork:reconcile-provider` |
| `clockwork:find-orphan-sites` | Detect sites whose SpinupWP linkage was lost. | `php artisan clockwork:find-orphan-sites` |
| `clockwork:installer:reopen` | Disaster recovery: remove the `storage/installed` sentinel so `/install` becomes reachable again on a live instance. | `php artisan clockwork:installer:reopen --force` |

## Health + metrics

| Command | Purpose | Example |
|---|---|---|
| `clockwork:poll-servers` | Pull cloud-provider metrics (DO, Hetzner, Azure, Vultr, Linode) → `server_metrics`. Branches per row on `servers.provider`. | `php artisan clockwork:poll-servers` |
| `clockwork:prune-server-metrics` | Drop rows older than 90 days. | `php artisan clockwork:prune-server-metrics` |
| `clockwork:prune-threat-logs` | Chunked delete of `threat_logs` older than the saved retention window (default 30 days), or `DROP PARTITION` when the MySQL table is monthly-partitioned. `--days=` overrides. `--dry-run` counts only. | `php artisan clockwork:prune-threat-logs --dry-run` |
| `clockwork:rebuild-threat-logs-partitions` | MySQL only. Copy the retention window into a new monthly-partitioned table, swap, drop the old `.ibd` so disk shrinks. | `php artisan clockwork:rebuild-threat-logs-partitions` |
| `clockwork:check-ssl-certs` | Per-site SSL state + Mattermost transitions. | `php artisan clockwork:check-ssl-certs` |
| `clockwork:check-cloudflare` | Per-site CF detection. | `php artisan clockwork:check-cloudflare` |
| `clockwork:check-site-uptime` | HTTP probe each monitored site. | `php artisan clockwork:check-site-uptime` |
| `clockwork:scheduler-heartbeat` | Cheap Settings write that proves crontab spawned `schedule:run`. First among every-minute jobs. Detection of a missing tick happens on page load (a scheduled command cannot watch itself): 5+ minutes stale → layout banner + Issues + `scheduler_stale` chat once; the next tick fires `scheduler_recovered`. Never-ticked is a yellow UI warning only. See [Runbooks → Scheduler stuck](/docs/runbooks/scheduler-stuck). | `php artisan clockwork:scheduler-heartbeat` |
| `clockwork:backfill-uptime-seed` | One-off: seed initial uptime state on first run. | `php artisan clockwork:backfill-uptime-seed` |
| `clockwork:pull-site-metrics` | Pull per-site CPU/memory hourly rollups from Companion (`resource-sampler` cap) into `site_metrics`. | `php artisan clockwork:pull-site-metrics` |
| `clockwork:push-site-metrics-state` | Push the current `monitoring.site_metrics_enabled` flag to Companion sites advertising `resource-sampler-toggle`. On-demand only — the Capacity toggle launches it in the background. | `php artisan clockwork:push-site-metrics-state` |
| `clockwork:check-domain-expirations` | Check domain registration expiration dates via ICANN RDAP and alert on impending expiration. | `php artisan clockwork:check-domain-expirations` |
| `clockwork:check-robots-txt` | Check `/robots.txt` directives across monitored sites for search-engine disallow rules. | `php artisan clockwork:check-robots-txt` |
| `clockwork:capture-site-screenshots` | Capture/refresh each site's homepage screenshot via Automattic's mShots service, feeding the visual fleet grid view. `--site=` targets one site (domain or ID), `--force` re-captures even if recent, `--limit=` caps the batch (default 50), `--sync` runs synchronously instead of queueing. | `php artisan clockwork:capture-site-screenshots --site=example.com --force` |

## Logs + ingest

| Command | Purpose | Example |
|---|---|---|
| `clockwork:tail-nginx-logs` | Inode-tracked tail of every site's nginx access log. | `php artisan clockwork:tail-nginx-logs` |
| `clockwork:rollup-traffic` | `threat_logs` → `site_traffic_daily`. | `php artisan clockwork:rollup-traffic --backfill=2` |
| `clockwork:pull-llar-lockouts` | Direct-DB pull of LLAR lockouts. | `php artisan clockwork:pull-llar-lockouts` |
| `clockwork:pull-wordfence-blocks` | Direct-DB pull of Wordfence blocks. | `php artisan clockwork:pull-wordfence-blocks` |
| `clockwork:warm-weird-stats` | Pre-warm the `/settings/weird-stats` cache. | `php artisan clockwork:warm-weird-stats` |
| `clockwork:ingest-rotated-logs` | One-time recovery: ingest date-stamped rotated nginx logs (`access.log-YYYYMMDD.gz`) to fill gaps in `threat_logs` left by a stalled cursor. Only inserts rows not already present for the requested window. | `php artisan clockwork:ingest-rotated-logs --dates=YYYY-MM-DD,YYYY-MM-DD` |

## WordPress probing + plugins

| Command | Purpose | Example |
|---|---|---|
| `clockwork:detect-wp-plugins` | SSH `wp plugin list` per WP site, refresh LLAR/Wordfence flags. | `php artisan clockwork:detect-wp-plugins` |
| `clockwork:install-llar` | Install + activate LLAR + suppress notification emails. | `php artisan clockwork:install-llar --site=42` |
| `clockwork:extract-wp-configs` | One-off: parse wp-config.php → DB creds for sites missing them. | `php artisan clockwork:extract-wp-configs` |

## Companion mu-plugin

| Command | Purpose | Example |
|---|---|---|
| `clockwork:install-companion` | Push the mu-plugin via SSH + register secret. Honors the policy denylist (`companion.excluded_domain_suffixes`, e.g. `stateschools.example`) on every path including single `--site`; `--force` overrides deliberately. | `php artisan clockwork:install-companion --site=42` |
| `clockwork:install-companion-pressable` | Same end state, Pressable transport (chunked upload over the async command API instead of SSH). Same policy denylist + `--force`. Repeatable `--site=` (id or domain), no fleet-wide flag yet. | `php artisan clockwork:install-companion-pressable --site=a.com --site=b.com` |
| `clockwork:companion-canary-set` | Set / list / clear the canary site set used by the two deploy commands. | `php artisan clockwork:companion-canary-set example.com --list` |
| `clockwork:companion-canary-deploy` | Install the current plugin to canary sites (provider-aware: SSH installer or `PressableCompanionInstaller`, purging Pressable's edge cache before verification) and verify the expected capability appears in `/detect`. Stamps `companion.canary_verified_at`. | `php artisan clockwork:companion-canary-deploy --expect-capability=malware-scan` |
| `clockwork:companion-fleet-deploy` | Roll the current plugin to every installed site after canary verification. Gated by `companion.canary_verified_at`; policy-excluded domains are always skipped (no `--force` here — use `install-companion --site=X --force` for a one-off). | `php artisan clockwork:companion-fleet-deploy` |
| `clockwork:diagnose-companion-install` | Read-only triage for Companion install/health failures on one site. | `php artisan clockwork:diagnose-companion-install --site=42` |
| `clockwork:backfill-companion-action-log` | Re-push `action_log` rows missing from a site's Companion mirror (post-reinstall recovery). | `php artisan clockwork:backfill-companion-action-log` |
| `clockwork:ensure-companion-trust-proxy` | Idempotently define `CLOCKWORK_COMPANION_TRUST_PROXY` in wp-config.php on care-plan auto-update sites. | `php artisan clockwork:ensure-companion-trust-proxy` |
| `clockwork:sync-companion-form-subscriptions` | Reconcile client-chosen form-test subscriptions from Companion into `contact_form_tests` (`created_by=client`). | `php artisan clockwork:sync-companion-form-subscriptions` |
| `clockwork:cleanup-spam-comments` | Bulk-purge spam and trash comments older than N days (default 30) from every Companion-equipped site advertising the `comments-moderation` capability. Runs weekly. | `php artisan clockwork:cleanup-spam-comments --days=30` |
| `clockwork:send-client-reports` | Generate and email scheduled white-labeled client reports for care-plan sites. `--site=` limits to one; `--force` bypasses the due-date check. | `php artisan clockwork:send-client-reports --site=example.com` |
| `clockwork:build-companion-tarball` | Produce signed `.tgz` + `.sha256` for `dist_url` mode. | `php artisan clockwork:build-companion-tarball --out=~/Downloads` |
| `clockwork:refresh-companion-snapshot` | Pull `/snapshot` per site → `companion_snapshot` JSON column. Purges Pressable's edge cache first (best-effort) — otherwise a stale cached response can mask a real update. | `php artisan clockwork:refresh-companion-snapshot` |
| `clockwork:refresh-companion-capabilities` | Refresh `companion_capabilities` per site. Same Pressable edge-cache purge as the snapshot refresh above. | `php artisan clockwork:refresh-companion-capabilities` |
| `clockwork:push-companion-branding` | Push white-label branding configuration (logo, company name, support email) to Companion-equipped WordPress sites. | `php artisan clockwork:push-companion-branding` |
| `clockwork:push-companion-backups` | Push SpinupWP config + DO Spaces history per site. | `php artisan clockwork:push-companion-backups` |
| `clockwork:pressable-backups-report` | Pressable counterpart — pushes real backup run history from Pressable's own `/backups` endpoint. No 30/90-day retention filter applied (Pressable's API has no pagination to request more than it hands back). | `php artisan clockwork:pressable-backups-report` |
| `clockwork:pressable-traffic-report` | Pushes Pressable's period-total traffic stats (today/yesterday/current+last month/last 12 months) to Companion's Traffic page. No daily breakdown — structurally different from the nginx-log rollup the SpinupWP command sources from. | `php artisan clockwork:pressable-traffic-report` |
| `clockwork:pressable-security-summary-report` | Pushes Pressable's own plugin/theme CVE feed + Defensive Mode status to Companion's Security page. Pressable-only capability, no SpinupWP equivalent. | `php artisan clockwork:pressable-security-summary-report` |
| `clockwork:push-backup-relay-targets` | Writes the Pressable + care-plan site list to S3 (`{S3_BUCKET}/{prefix}/targets.json`) for the standalone backup-relay droplet to read — no direct connection to that droplet. See [Features → Backup relay](/docs/features/backup-relay). | `php artisan clockwork:push-backup-relay-targets` |
| `clockwork:pull-backup-relay-report` | Reads the backup-relay droplet's last run summary back from S3 and records it to `backup_relay_runs` + `Settings`, deduped by `finished_at`. Also checks staleness every run (6+ days since the last recorded run → `backup_relay_stale` alert once; a fresh run after → `backup_relay_recovered` once). | `php artisan clockwork:pull-backup-relay-report` |
| `clockwork:backup-relay-run` | In-repo backup relay mode's own runner — archives enabled sites' backups to S3 Glacier natively via `ArchiveSiteBackupJob`, no external droplet involved. `--site=` limits to one site; `--force` ignores cadence (Backup Now). See [Features → Backup relay](/docs/features/backup-relay). | `php artisan clockwork:backup-relay-run --site=42 --force` |
| `clockwork:rotate-companion-secret` | Rotate per-site HMAC secret. | `php artisan clockwork:rotate-companion-secret --all` |
| `clockwork:detect-contact-forms` | Companion-aware contact-form detection. | `php artisan clockwork:detect-contact-forms` |
| `clockwork:test-contact-forms` | Run the due contact-form tests across the fleet (care-plan only). `--site=X` bypasses the care-plan filter; `--form=ID` targets a single contact_form_tests row; `--force` bypasses the frequency-due check. | `php artisan clockwork:test-contact-forms --force --site=example.com` |
| `clockwork:run-nightly-plugin-updates` | Nightly care-plan auto-update path — runs `wp plugin update` per opted-in site. Runs 02:00 ET. Per-site opt-out toggle on the site Updates tab. | `php artisan clockwork:run-nightly-plugin-updates` |
| `clockwork:nightly-update-summary` | Email summary of the previous night's auto-update run. Runs 06:15 ET. | `php artisan clockwork:nightly-update-summary` |
| `clockwork:push-companion-traffic` | Push the previous day's traffic rollup to each Companion-equipped site for display in the WP admin. | `php artisan clockwork:push-companion-traffic` |
| `clockwork:ensure-queue-worker` | Watchdog for the `com.clockwork.queue` launchd service — checks `launchctl list` for a live PID and kickstarts the worker if it has crashed or been throttled into a backoff loop. Scheduled every 5 min so recovery gap ≤ 5 min. A successful revival is log-only; if the kickstart itself fails, fires a `queue_worker_restart_failed` Mattermost/Slack alert (every queued job in the app is stuck at that point). Crontab itself is watched by `clockwork:scheduler-heartbeat` (Health + metrics). | `php artisan clockwork:ensure-queue-worker` |

## Security + bans

| Command | Purpose | Example |
|---|---|---|
| `clockwork:check-blacklists` | URLhaus + Spamhaus DBL + optional GSB. | `php artisan clockwork:check-blacklists` |
| `clockwork:scan-sitecheck` | Sucuri SiteCheck remote scan per site. | `php artisan clockwork:scan-sitecheck` |
| `clockwork:verify-wp-core-checksums` | `wp core verify-checksums` per site — SSH for SpinupWP, Pressable's async command API for Pressable. | `php artisan clockwork:verify-wp-core-checksums` |
| `clockwork:run-companion-malware-scans` | In-WP malware probe — Companion plugin endpoint preferred, SSH wp-cli fallback. Bypasses Cloudflare so CF-fronted sites get real signal instead of the 403 wall Sucuri's external scanner hits. | `php artisan clockwork:run-companion-malware-scans` |
| `clockwork:suppress-malware-path` | Add or remove a path from the per-site malware scan suppression list (`php_in_uploads` findings). Requires `--site=` and `--path=`; `--unsuppress` removes instead of adds. Use for known-safe upload-resident PHP files (e.g. MC4WP debug logs that start with `<?php exit;`). | `php artisan clockwork:suppress-malware-path --site=example.com --path=wp-content/uploads/mailchimp-for-wp/debug-log.php` |
| `clockwork:cf-rules` | Dump CF redirect/transform/WAF rules for a zone. | `php artisan clockwork:cf-rules example.com` |
| `clockwork:block-ua` | Push nginx User-Agent blocking rules to a server. Writes a `map` directive + per-site `if-return-403` snippets. Safe: `nginx -t` runs before any reload. `--remove` cleans up. | `php artisan clockwork:block-ua --server=web29 --ua="Chrome/126.0.0.0"` |
| `clockwork:sweep-cf-bans` | One-shot: unban any CF-edge IPs misattributed in the past. | `php artisan clockwork:sweep-cf-bans` |
| `clockwork:refresh-fail2ban-ignoreip` | Refresh CF + fleet IPs in every server's jail. | `php artisan clockwork:refresh-fail2ban-ignoreip` |
| `clockwork:refresh-cloudflare-real-ip` | Push the nginx CF-Connecting-IP snippet to every server **and bridge it into sites-enabled/** (conf.d alone is inert on SpinupWP boxes). Skips the bridge when `nginx -T` shows real-IP already active another way. | `php artisan clockwork:refresh-cloudflare-real-ip` |
| `clockwork:cf-rate-limit` | Add / list / remove a Cloudflare rate-limiting rule for a zone. | `php artisan clockwork:cf-rate-limit example.com --list` |
| `clockwork:refresh-plugin-vulnerabilities` | Refresh the wpvulnerability.net CVE mirror for every installed plugin slug. | `php artisan clockwork:refresh-plugin-vulnerabilities` |
| `clockwork:auto-approve-repeats` | Promote 2+-occurrence IPs to the ban queue. | `php artisan clockwork:auto-approve-repeats` |
| `clockwork:process-pending-bans` | Drain `queued_for_ban` → fail2ban over SSH. | `php artisan clockwork:process-pending-bans` |
| `clockwork:composer-audit` | Composer dependency CVE scan. | `php artisan clockwork:composer-audit` |
| `clockwork:security-check` | System-wide audit (`--ssh` runs SSH-side checks). | `php artisan clockwork:security-check --ssh --quiet-ok` |
| `clockwork:run-performance-scans` | Lighthouse run per care-plan site — GTmetrix primary, PSI fallback. `--engine=` forces one engine; `--weekly-rotation` (what the scheduler passes) scans only tonight's 1/7th fleet slice to fit the GTmetrix credit budget. | `php artisan clockwork:run-performance-scans --site=42 --engine=gtmetrix` |

## Server ops

| Command | Purpose | Example |
|---|---|---|
| `clockwork:process-server-updates` | Drain queued apt-update jobs. A failed run logs `server_update_failed` (`action_logs`) and fires the matching Mattermost/Slack alert; success is not logged. | `php artisan clockwork:process-server-updates` |
| `clockwork:reap-stale-update-jobs` | Flip `plugin_update_jobs` orphaned in running (>10 min) or pending (>60 min) to failed. Also pings Mattermost/Slack (`plugin_update_failed`, same nightly-batch-only gate as a live failure) for any reaped row — reaped jobs never go through the normal failure path, so they used to reap silently. | `php artisan clockwork:reap-stale-update-jobs` |
| `clockwork:detect-stuck-companion-state` | Daily sweep for two states nothing else catches: an install that failed >24h ago with no successful retry, or a `companion_installed=true` site that's gone silent (no snapshot/health touch) for 3+ days. Fires `companion_unreachable` once on newly-stuck, `companion_reachable` once on recovery; state tracked on `sites.companion_stuck_since`/`companion_stuck_reason`. `--dry-run` reports without writing or alerting. | `php artisan clockwork:detect-stuck-companion-state --dry-run` |
| `clockwork:reap-stale-server-updates` | Flip servers stuck in `update_status=running` >2h back to failed for re-queueing. Fires a `server_update_failed` Mattermost/Slack alert for every reaped row. | `php artisan clockwork:reap-stale-server-updates` |
| `clockwork:clear-site-update-lock` | Force-release the `site_update:{id}` cache lock for one site. | `php artisan clockwork:clear-site-update-lock 42` |
| `clockwork:scan-wp7-truncation` | Detect (and `--repair`) sites with truncated WP 7.0 `php-ai-client` core files. | `php artisan clockwork:scan-wp7-truncation --repair` |
| `clockwork:drop-orphan-prefix-tables` | Drop MySQL tables matching an orphan prefix on a site (refuses the live prefix). | `php artisan clockwork:drop-orphan-prefix-tables` |
| `clockwork:provision-droplet-agent` | Install/start DO droplet-agent so the DO Web Console works. `--restart` clears "Registering SSH Keys" hangs. | `php artisan clockwork:provision-droplet-agent --server=web35` |
| `clockwork:poll-system-updates` | SSH apt-check + reboot-required.pkgs + apt list --upgradable → `server_update_snapshots`. Default gate: SpinupWP-managed servers flagged `upgrade_required=true`, plus every non-SpinupWP-managed server unconditionally (nothing else sets that flag for GridPane/Hetzner/custom-VPS boxes). `--all` bypasses the gate entirely, for the weekly full-fleet safety-net sweep. | `php artisan clockwork:poll-system-updates --all` |
| `clockwork:clean-failed-provision` | Recovery script for the v0 provisioner bug — paste into SpinupWP "Run a Custom Script". | `php artisan clockwork:clean-failed-provision myhost.example.com` |
| `clockwork:provision-console-access` | Drop a `Match Address 127.0.0.1,::1` sshd config so the DigitalOcean Web Console can log in as root via droplet-agent on SpinupWP-hardened boxes. Validates with `sshd -t`, reloads (not restarts), auto-rollback on failure. | `php artisan clockwork:provision-console-access --server=web-test3.example.com` |

## Bill.com sync

| Command | Purpose | Example |
|---|---|---|
| `clockwork:sync-bill-customers` | Pull customer list + invoice history → link sites. | `php artisan clockwork:sync-bill-customers` |
| `clockwork:sync-bill-care-plans` | Flip `care_plan_enabled` per site based on care-plan-Item invoicing. | `php artisan clockwork:sync-bill-care-plans` |

## Maintenance

| Command | Purpose | Example |
|---|---|---|
| `clockwork:rebuild-threat-logs-partitions` | Rebuild threat_logs as monthly partitions and copy only the retention window. Reclaims InnoDB disk space. MySQL only. | `php artisan clockwork:rebuild-threat-logs-partitions --days=90` |
| `clockwork:prune-threat-logs` | Prune threat_logs older than configured retention, dropping obsolete monthly partitions or deleting rows. | `php artisan clockwork:prune-threat-logs` |
| `clockwork:prune-scheduled-job-runs` | Delete `scheduled_job_runs` rows older than the retention window (default 30 days, `CLOCKWORK_SCHEDULED_JOBS_RETENTION_DAYS`), keeping [Settings → Scheduled Jobs](/docs/features/scheduled-jobs-dashboard) history bounded. `--days=` overrides. | `php artisan clockwork:prune-scheduled-job-runs` |
| `clockwork:reencrypt-secrets` | Re-encrypts every `'encrypted'`-cast column (SSH keys, DB passwords, Companion secrets, integration credentials) under the current `APP_KEY`. Run once, immediately after rotating `APP_KEY`, while the old key is still in `APP_PREVIOUS_KEYS` — bypasses Eloquent's dirty-tracking (which no-ops `save()` for unchanged plaintext) via a direct `Crypt::encryptString()` + raw `DB::table()->update()` per row. | `php artisan clockwork:reencrypt-secrets` |
| `clockwork:check-updates` | Check the GitHub Releases API for a newer Clockwork Control Core version, and print the Companion fleet rollout breakdown. Same data `/settings/updates` shows. `--force` bypasses the 12h cache. See [Features → System updates](/docs/features/system-updates). | `php artisan clockwork:check-updates --force` |
| `clockwork:self-update` | Operator-triggered self-update: `git pull` → `composer install --no-dev` → `migrate --force` → `optimize:clear`. Aborts before touching anything if the working copy has uncommitted changes. Prompts for confirmation unless `--force`. | `php artisan clockwork:self-update --force` |
| `clockwork:send-telemetry` | Sends the anonymous usage report (exact site/server counts, per-module breakdown, enabled modules — nothing else) to the project maintainer. On by default (`CLOCKWORK_TELEMETRY_ENABLED=true`); disable anytime via Settings → Maintenance or the env var. Runs weekly. | `php artisan clockwork:send-telemetry` |
