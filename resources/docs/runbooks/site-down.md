---
title: A site is down — what now?
section: Runbooks
order: 10
updated: 2026-09-02
author: Aaron Reimann
tags: [runbook, uptime, incident, triage, pressable]
---

You got a Mattermost/Slack alert that a site is down (or a client is asking). Triage path — start at step 1 and stop when you find the cause.

**If the site is on Pressable** (check the provider pill on `/sites/{id}` or `/sites`): skip step 5 entirely — there's no server to open. You also won't get the auto-diagnosis line in the alert body (`UptimeDiagnostician` is an SSH probe, SpinupWP-only) — the alert will just say "failed 2 probes in a row" with no root-cause guess. Steps 1-4 and 6-8 all still apply; for anything that would otherwise mean SSHing in, your options are Pressable's own dashboard/support, or `PressableCommandRunner`-backed commands where one exists (e.g. `wp core verify-checksums` via `clockwork:verify-wp-core-checksums --site=`).

## 1. Confirm in the app

Open `/sites/{id}/overview` (search by domain with `/`). Check:

- **Status card** — what state are we in? `down`, `unknown`, or `up` (recovered already)?
- **`uptime_consecutive_failures`** — is this a single bad probe, or sustained?
- **Last check time** — has it been recent? If not, the scheduler may be the actual problem.

If the status card says `up` but the alert was real, the site recovered while you were getting coffee. Note it; move on.

## 2. Check the alert details

Look at the Mattermost message. Two clues to pay attention to:

- **HTTP code**. 502/503 = origin in trouble. 521 = Cloudflare can't reach origin (often this app banning a CF edge — see [Bad IP ban recovery](/docs/runbooks/bad-ip-ban-recovery)). 401/403 = "possibly a WAF block" — check whether the probe was rate-limited rather than the site being broken.
- **Server name**. If multiple sites on one server are down, jump to step 5 (server-level, not site-level).

## 3. Manually probe the site

```bash
curl -I https://example.com/
```

Look at the actual response. If `curl` succeeds and the body looks fine, our probe is wrong (probably WAF). If `curl` fails with the same error the alert reports, it's real.

## 4. Open the site in a real browser

CF cached pages can mask origin death. Our HTTP probe can see a CF-cached homepage as `up` even when the origin has imploded. Conversely, a real visitor might get a different result than `curl` because the WAF treats them differently.

## 5. Check the server

Open `/servers/{id}`. Is the server card red? Yellow? Look at the sparklines:

- **CPU pinned** — usually a runaway process or a traffic spike. SSH in, `top`, find the offender.
- **Memory pinned** — likely OOM. Check `/var/log/syslog` for the kernel killing things.
- **Disk full** — `df -h`. Most common cause: a runaway log file or an accumulated backup.
- **Server unreachable over SSH** — DigitalOcean console, or contact SpinupWP support.

If the server is fine but multiple sites on it are down, check whether the fail2ban jail is misbehaving (banning too aggressively).

## 6. Cert problem?

A broken SSL chain fails our probe. Check the per-site cert card on `/sites/{id}/overview`. If `cert_state=expired` or `expiring_soon`, that's the cause. See [SSL renewal failed](/docs/runbooks/ssl-renewal-failed).

A missing intermediate CA fails the probe with cURL 60 — real visitors hit the same error.

## 7. CF rule broke something

If the site went `down` shortly after a Cloudflare rule change, check `clockwork:cf-rules <domain>`. Common culprits:

- Geo-redirects that include `/.well-known/acme-challenge/` (LE renewal fails → cert expires → probe fails).
- URL rewrites that mutate the path before reaching origin.
- Custom WAF rules that 403 our probe (User-Agent: `Clockwork-Uptime/1.0`, plus `(+CLOCKWORK_OPERATOR_CONTACT_EMAIL)` if that's configured — see `reference/env-vars`).

## 8. Check the SpinupWP dashboard

If the site disappears entirely (404 from SpinupWP's edge), the site might have been deleted or paused. Rare but possible.

## When to escalate

- Server unreachable over both Clockwork and SpinupWP — contact SpinupWP support.
- DO droplet shows healthy but everything inside is broken — DO support.
- Site's DNS isn't resolving to anywhere — registrar / CF support.

## When you're done

- If the site is back up, our probe will catch it within one cycle (default 5 min) and post a recovery message to Mattermost.
- Add an `action_log` note at `/sites/{id}/overview` describing what happened. Future-you will thank present-you.
- If the cause was systemic (CF rule, fail2ban misconfig), update the relevant runbook here so the next person finds it faster.
