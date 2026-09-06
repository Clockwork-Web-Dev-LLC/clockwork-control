---
title: Bad IP ban recovery
section: Runbooks
order: 40
updated: 2026-08-29
author: Aaron Reimann
tags: [runbook, bans, fail2ban, recovery, pressable]
---

You banned an IP that turned out to be legitimate (a client's office, a search engine, your own VPN). Or fail2ban blocked half of Cloudflare and sites are 521-flapping. Recovery path.

None of this applies to Pressable sites — there's no fail2ban, no bans queue, and the Bans tab is hidden entirely for them (structural gap, not a missing feature — see [Integrations → Pressable](/docs/integrations/pressable)). If a Pressable site is getting hammered, Pressable's own Defensive Mode (edge-cache) is the nearest equivalent lever, not this runbook.

## 1. Single IP, single site

Open `/sites/{id}/bans`. Find the row, click **Unban**. Behind the scenes:

- `BlockedIpsController` issues `sudo -n fail2ban-client unban <ip>` over SSH.
- `unbanned_at = now()` is set on the local `BlockedIp` row.
- The row stays visible in History so the audit trail is intact.

## 2. All bans on one site

`/sites/{id}/bans` → **Unban all on this site**. Same mechanism, batched. Useful if you've discovered the site's ingest was misconfigured (e.g. recording CF edges as `REMOTE_ADDR`) and you need to clear the slate before fixing the cause.

## 3. Single IP, fleet-wide

`/bans/active` → search → **Unban globally**. Removes the IP from every server it's banned on, marks the local row.

## 4. Mass-unban every CF edge ever banned

This is the one that resolves a 521-flap incident.

```bash
# Dry run first — see what would change:
php artisan clockwork:sweep-cf-bans --dry-run

# Live:
php artisan clockwork:sweep-cf-bans
```

The sweep iterates every active row in `blocked_ips`, asks `CloudflareDetector::isCloudflareIp()` per IP, and where it matches: SSH-issues `fail2ban-client unban`, marks `unbanned_at = now()` locally.

The first time we ran this, it cleared 440 bans across 15 servers. Reversible — anything that's actually a real attacker will get re-banned on the next 4 lockouts (now from the right IP, thanks to the nginx CF-real-IP snippet).

## 5. Make sure it stays fixed

If you just swept CF edges, run the two refresh commands to make sure preventive defenses are current on every server:

```bash
# Refresh fail2ban's ignoreip list (CF v4+v6 + fleet IPs):
php artisan clockwork:refresh-fail2ban-ignoreip

# Push the nginx CF-Connecting-IP snippet (so future LLAR/Wordfence lockouts
# attribute to the real visitor, not the CF edge):
php artisan clockwork:refresh-cloudflare-real-ip
```

Both are idempotent and skip reload when content is unchanged. They run weekly via the scheduler, but a one-off manual run is fine after a recovery.

## 6. fail2ban itself is broken

Symptoms: bans don't take effect, `fail2ban-client status clockwork` returns "no such jail," or the daemon won't start.

Most common cause: a server provisioned before the `<HOST>`-in-filter fix. fail2ban ≥1.0 rejects any `failregex` lacking a `<HOST>` capture group, even one designed to never match. The next `reload clockwork` triggers re-validation, which unloads the jail.

Fix: re-run the refresh, which rewrites both the jail file AND the filter file:

```bash
php artisan clockwork:refresh-fail2ban-ignoreip --server=<name>
```

If the jail still doesn't come back: SSH in, `sudo systemctl restart fail2ban`, then re-check.

## 7. Provisioning broke `sudo` on the box

Symptom: every privileged operation on a server starts failing. SSH still works, but `sudo` says the sudoers file is malformed.

Cause: the v0 provisioner had a bug that wrote the SSH password into `/etc/sudoers.d/clockwork`. Sudo then can't parse the file, and refuses to grant any privileges.

Recovery:

```bash
php artisan clockwork:clean-failed-provision <name|hostname|id>
```

Prints a recovery script. Paste it into SpinupWP's **Run a Custom Script** feature (executes as root, bypassing the broken sudo). The script removes the bad sudoers file, the filter, the jail, then restarts fail2ban. Refresh the server page in the app and re-run **Provision fail2ban**.

## When you can't unban

- **The server is unreachable over SSH.** Fix that first — see [Server is red](/docs/runbooks/server-red).
- **fail2ban jail isn't loaded** — see step 6.
- **The IP isn't actually banned anywhere.** Local `blocked_ips` might be wrong. Try `sudo fail2ban-client banned` on the server directly to confirm.

## After

- Add an `action_log` note at `/maintenance-history` describing what was unbanned and why.
- If the cause was systemic (a misconfigured ingest, a CF edge IP issue), document the fix in this runbook so next time someone finds it in 30 seconds.
