---
title: A server is red — what now?
section: Runbooks
order: 20
updated: 2026-08-29
author: Aaron Reimann
tags: [runbook, server, incident, monitoring, pressable]
---

A server card is red on the dashboard. That means CPU is over the red threshold (default 90%), or memory or disk are pinned. Triage path.

This whole runbook is inherently SpinupWP/Azure/Hetzner-only — Pressable sites have no server, so there's no card to turn red and nothing here applies to them. A Pressable-hosted site behaving badly shows up as a slow/erroring uptime probe or a Companion resource-metrics anomaly instead, not a server incident.

## 1. Open the server detail page

`/servers/{id}/stats` shows the 7-day metric chart. Look at:

- **What's pinned** — CPU, memory, or disk?
- **When it started** — sudden spike or gradual climb?
- **Pattern** — daily? Hourly? Random?

A sudden spike is usually a single event (traffic, runaway process). A gradual climb is usually accumulation (logs, cache, growing DB).

## 2. PHP-FPM by site

The Sites tab shows which sites are using the most PHP workers right now. If one site is hogging the pool, that's your suspect. Most common causes:

- A misbehaving plugin (admin-ajax loops, infinite redirects).
- A scraper or attacker hitting a slow endpoint repeatedly.
- A backup or sync job firing in the wrong place.

## 3. SSH in

If the metric chart and the per-site PHP-FPM view aren't enough, SSH in and look directly. Things to check:

```bash
# Top by CPU:
top -b -n1 | head -20

# Top by memory:
ps aux --sort=-rss | head -10

# Disk:
df -h
du -sh /var/log/* 2>/dev/null | sort -h | tail -10
du -sh /home/*/sites/*/files 2>/dev/null | sort -h | tail -10

# Recent kernel events:
sudo dmesg --since='1 hour ago' | tail -50

# nginx errors:
sudo tail -100 /var/log/nginx/error.log
```

## CPU pinned

If a single PHP-FPM worker is at 100%:

```bash
# Find what it's doing:
sudo strace -p <pid> -e trace=network,read,write,openat 2>&1 | head -50
```

Common culprit: a plugin doing a slow remote API call inside the request cycle (no timeout). Restart the worker or `sudo systemctl restart php8.3-fpm` (adjust version) to clear it. Then find the plugin and disable it.

If many workers are at 100%, the site is genuinely under load. Check the nginx access log for the URL pattern. If it's an attack:

```bash
# Tail and group:
sudo tail -1000 /var/log/nginx/access.log | awk '{print $1}' | sort | uniq -c | sort -rn | head
```

The top IP is your candidate for a manual ban via `/servers/{id}/bans`.

## Memory pinned

`MemAvailable` (what the dashboard shows) is the right signal. If genuinely full:

- Check OpenLiteSpeed / nginx caches — sometimes they grow unbounded under sustained load.
- MySQL: `SHOW PROCESSLIST` for stuck queries; `SHOW ENGINE INNODB STATUS` for buffer pool pressure.
- Restart php-fpm and see if the pressure clears (if yes, it was a long-running worker leaking memory).

OOM events: `sudo journalctl --since='1 hour ago' | grep -i oom`. If the kernel killed something important, restart it.

## Disk full

Three usual suspects:

1. **Logs** — nginx access/error, PHP-FPM slow log, MySQL error log. Truncate the worst offenders (`sudo truncate -s 0 /var/log/...`) and configure rotation if it isn't already.
2. **Backups** — SpinupWP writes locally before pushing to DO Spaces. Check `/srv/spinupwp/backups/`.
3. **WP uploads or wp-content/cache** — a runaway plugin can fill `wp-content/cache/`.

`du -sh /var/* | sort -h` is your friend.

## Sustained vs spike

- **Sudden spike** — usually transient. After the cause is mitigated, the dashboard goes back to green within a few cycles.
- **Sustained climb** — more concerning. Either the workload has genuinely grown, or something is leaking. The Capacity page (`/capacity`) helps if this is on a Shared server — maybe it's time to move sites or upsize.

## When to escalate

- Server unresponsive over SSH after a reboot — DigitalOcean console.
- Disk full and you can't free anything material — start migrating sites off, or upsize via SpinupWP.
- Repeated OOMs not tied to a single site — server might be undersized for its current workload.

## After

- Add an `action_log` note describing what happened — `/servers/{id}` has the entry point.
- If you found a plugin / config / DB issue that affects the site too, file it on the per-site Activity card as well.
- The dashboard will go back to green automatically once the next 5-minute poll comes back below threshold.
