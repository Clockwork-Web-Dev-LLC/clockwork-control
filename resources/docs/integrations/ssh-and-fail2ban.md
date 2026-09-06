---
title: SSH + fail2ban
section: Integrations
order: 120
updated: 2026-09-05
author: Aaron Reimann
tags: [integrations, ssh, fail2ban, security, bans, pressable]
tracks: [app/Services/Ssh/**, app/Services/Fail2ban/**, app/Http/Controllers/ServerProvisionController.php, app/Console/Commands/ProcessPendingBans.php, app/Console/Commands/RefreshFail2banIgnoreip.php, app/Console/Commands/SweepCfBans.php]
---

SSH is the substrate — for SpinupWP. Every SpinupWP server we manage is reached over SSH; fail2ban is the bans executor running on the other end. They're paired here because we never use fail2ban over anything but SSH and we never use SSH without leaning on fail2ban for the actual blocking.

**Everything on this page is SpinupWP-only.** Pressable has no SSH and no fail2ban equivalent — not "not built yet," but structurally absent from its API surface. See [Integrations → Pressable](/docs/integrations/pressable) for what fills the gaps this page's mechanisms leave for that provider (a direct TLS probe for SSL, an async command API for anything that needs code execution, and nothing at all for inbound IP blocking).

## Why we use them

Pre-Companion, every WordPress data point came from `ssh + mysql + grep + wp-cli` against the live server. With Companion, more goes through HTTPS — but the substrate is still SSH for everything privileged: tailing nginx logs, running `wp-cli`, pushing fail2ban config, queueing apt-get upgrades, scheduling reboots.

For bans specifically, fail2ban handles iptables, persistence across reboots, and TTL. We never push iptables rules directly — letting fail2ban manage state means our `blocked_ips` table is a mirror, not a source of truth, and the on-server jail is the authoritative state.

Every `BlockedIp::create()` — manual ban, single review-queue approval, the batch bulk-approve in `clockwork:process-pending-bans`, and the two auto-ban paths in `pull-llar-lockouts`/`pull-wordfence-blocks` — fires `ChatNotifier::ipBlocked()` right after the row is created, alerting Mattermost/Slack with the IP and whether it was auto- or human-decided. See [Integrations → Mattermost](/docs/integrations/mattermost)'s `ipBlocked` event.

## Setup

### SSH

1. Generate an ed25519 keypair on the agency laptop. Add the public key to `~clockwork-deploy/.ssh/authorized_keys` on every managed server (SpinupWP supports SSH keys via the dashboard).
2. Set in `.env`:

   ```
   CLOCKWORK_DEFAULT_SSH_USER=clockwork-deploy
   CLOCKWORK_DEFAULT_SSH_PORT=22
   CLOCKWORK_SSH_KEY_PATH=/Users/you/.ssh/clockwork_ed25519
   CLOCKWORK_SSH_KEY_PASSPHRASE=    # if the key is protected
   ```

3. Per-server credentials (key OR password) live in encrypted columns on `servers`. The "Test SSH" button on the server detail page verifies them.

### fail2ban

A server can't ban anything until you click **Provision fail2ban** on its detail page once. The button POSTs to `/servers/{id}/provision/fail2ban`, handled by `ServerProvisionController::fail2ban()`, which bails immediately with a 422 if the server has neither `ssh_password` nor `ssh_private_key` on record (see [Features → Servers → Credentials](/docs/features/servers) for how those get set), otherwise delegates straight to `Fail2banProvisioner::provision()` over SSH:

1. Detects whether `sudo` is NOPASSWD or needs the SSH password (`sudo -S`).
2. Installs `fail2ban` via `apt-get` if missing.
3. Writes `/etc/fail2ban/filter.d/clockwork.conf` (regex containing `<HOST>` so the daemon doesn't reject it).
4. Writes `/etc/fail2ban/jail.d/clockwork.local` with `iptables-allports[name=clockwork]` and the **live `ignoreip` list** (CF v4+v6 + every fleet server's own public IP + loopback).
5. Writes `/etc/sudoers.d/clockwork` (validated with `visudo -cf` first) granting NOPASSWD on `/usr/bin/fail2ban-client`. After this, ban operations don't need the SSH password.
6. Restarts fail2ban and verifies the jail loaded.

On success: `clockwork_jail_provisioned_at = now()` and the full output goes to `last_provision_log`.

## Auth precedence (SSH)

`App\Services\Ssh\SshClient` (phpseclib3) tries in order:

1. Per-server stored key (`servers.ssh_private_key`, encrypted)
2. Local default key (`CLOCKWORK_SSH_KEY_PATH`)
3. Per-server password (`servers.ssh_password`, encrypted)

The default user is `clockwork-deploy` (overridable per server). Non-root, with NOPASSWD on `fail2ban-client` only.

## Commands we run over SSH

A non-exhaustive sample of what we run remotely:

- `tail -n1000 /var/log/nginx/access.log` — log tailing.
- `wp plugin list --format=json` — plugin inventory.
- `wp core verify-checksums` — security checksums.
- `mysql --defaults-file=...` — LLAR / Wordfence direct-DB pulls (where Companion isn't installed).
- `sudo -n fail2ban-client set clockwork banip <ip>` — bans.
- `sudo apt-get update && apt-get -y upgrade` — system updates queue.
- `sudo shutdown -r +1` — scheduled reboot (the `+1` lets the SSH command return cleanly).

`sudo` precedence:

- `fail2ban-client` is NOPASSWD via `/etc/sudoers.d/clockwork`.
- Other privileged commands run with `sudo -S` and the SSH password fed on stdin.

## Files

- `app/Services/Ssh/SshClient.php` — the main client.
- `app/Services/Ssh/SshCommandRunner.php` — thin `Modules\Core\Contracts\SiteCommandRunner` adapter over `SshClient::exec()`, used by `SpinupWpHostingProvider::commandRunner()` (part of the modularization roadmap's HostingProvider abstraction; `modules/Pressable/src/PressableApiCommandRunner.php` is the equivalent for Pressable).
- `app/Services/Ssh/CredentialFeedParser.php` — paste-from-vault flow.
- `app/Services/Fail2ban/Fail2banProvisioner.php`
- `app/Services/Fail2ban/Fail2banClient.php` — `banIp`, `unbanIp`, `unbanIps`, `status`.
- `app/Services/Fail2ban/IgnoreIpListBuilder.php` — produces the `ignoreip` directive from CF + fleet IPs.
- `app/Services/Fail2ban/IgnoreIpMatcher.php` — in-app mirror checked at ingest + execution boundaries.
- Config: `config/clockwork.php` → `ssh` key.

## Scheduled jobs that depend on this

| Cadence | Command |
|---|---|
| every minute | `clockwork:process-pending-bans` — drain `queued_for_ban` → `fail2ban-client banip` over SSH. |
| every minute | `clockwork:process-server-updates` — drain queued apt upgrades. |
| every 5 min | `clockwork:tail-nginx-logs` — pull new lines, inode-tracked. |
| every 15 min | `clockwork:pull-llar-lockouts` / `pull-wordfence-blocks` — direct-DB fallback when Companion isn't installed. |
| Sun 05:30 | `clockwork:refresh-fail2ban-ignoreip` — refresh CF + fleet IPs in every jail. |
| Sun 05:45 | `clockwork:refresh-cloudflare-real-ip` — push the nginx CF-Connecting-IP snippet. |

## Recovery

If provisioning fails halfway and breaks `sudo` on a server, run:

```bash
php artisan clockwork:clean-failed-provision <name|hostname|id>
```

Prints a recovery script to paste into SpinupWP's "Run a Custom Script" feature (which executes as root, bypassing the broken sudo). The script removes the bad sudoers file, the filter, the jail, then restarts fail2ban. Refresh the server page in the app and re-run "Provision fail2ban."

## Gotchas

- **The `do_sudo` + outer-pipe footgun.** Never attach a heredoc, pipe, or stdin redirect to a sudo wrapper that internally pipes the password to `sudo -S`. The wrapper discards your stdin and replaces it with the password — your heredoc content gets the password written into it. The v0 provisioner did this and **wrote the SSH password into `/etc/sudoers.d/clockwork`**, breaking all sudo on the box. Always: write to `mktemp -d` first, then `do_sudo cp $STAGE/foo /etc/...`. The current provisioner uses this pattern; the recovery script (`clockwork:clean-failed-provision`) cleans up after a hit.
- **fail2ban filter must contain `<HOST>`.** fail2ban ≥1.0 rejects any `failregex` lacking a `<HOST>` capture group, even when the regex is designed to never match. We use `^__CLOCKWORK_NEVER_MATCHES__ <HOST>$` — literal prefix guarantees nothing matches; `<HOST>` satisfies the validation.
- **Refresh rewrites both jail file AND filter file.** Otherwise legacy servers from before the `<HOST>` fix get unloaded on the next refresh.
- **Don't whitelist all of DigitalOcean.** Attackers rent DO droplets too. Only IPs we own.
- **`tcpdump` and `iptables -L` for ban diagnosis** require sudo; our SSH user has NOPASSWD only on `fail2ban-client`. Use `fail2ban-client banned` instead.
- **`echo` not `printf %s`** when feeding sudo's stdin password — `printf %s` (no trailing newline) makes sudo wait on EOF, and `wp` then exits silently with empty output.
