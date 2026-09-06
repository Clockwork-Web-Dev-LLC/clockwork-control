---
title: Companion secret rotation
section: Runbooks
order: 60
updated: 2026-08-29
author: Aaron Reimann
tags: [runbook, companion, secrets, security, pressable]
---

Companion uses a per-site HMAC-SHA256 secret for every signed REST call. Rotating it is a one-command operation per site (or `--all`). When and why to rotate, and how to recover when something goes sideways.

The rotation flow itself (`POST /secret/rotate` over HTTPS) is host-agnostic — it works identically for Pressable sites, no special-casing needed. The **recovery** commands below aren't: they use `clockwork:install-companion`, the SSH installer. For a Pressable site, swap that for `clockwork:install-companion-pressable --site=<domain>` — see [Integrations → Pressable](/docs/integrations/pressable) for that installer's bootstrap-then-rotate secret handling, which is relevant background if you're rotating a Pressable site's secret for suspected-compromise reasons.

## When to rotate

- **Quarterly cadence.** Hygiene. Set a calendar reminder.
- **Laptop loss / theft.** The local copy of the encrypted DB went with it. Rotate everything.
- **Contractor offboarding** if the contractor had access to the laptop.
- **Suspicious HMAC failures** in the audit log (Companion's Tools → Clockwork → Security → Authentication audit). 30+ failures from one IP in a short window means someone's probing.
- **Untrusted plugin added to a site.** WordPress plugins run in the same process as Companion; a malicious plugin could read `wp_options` and exfiltrate the secret. Rotate to invalidate any exfiltrated copy.

## How

```bash
# Single site:
php artisan clockwork:rotate-companion-secret --site=42

# Single site by domain:
php artisan clockwork:rotate-companion-secret --site=example.com

# Whole fleet:
php artisan clockwork:rotate-companion-secret --all
```

The flow:

1. Clockwork signs `POST /secret/rotate` to the site **with the OLD secret**.
2. Companion verifies, generates a NEW 256-bit secret, stores it in `wp_options`, returns it in the response.
3. Clockwork persists the new secret into `sites.companion_secret` (encrypted at rest) inside the same DB transaction that records the rotation in `action_logs` (`TYPE_COMPANION_SECRET_ROTATED`).

## What can go wrong

### The site uses constant-mode

If the site has `CLOCKWORK_COMPANION_SECRET` defined in `wp-config.php`, the rotation route returns **409 `secret_pinned`** and is skipped. Constant-mode sites are operator-managed: edit `wp-config.php` to a new value, then re-run `clockwork:install-companion <site>` to push the new secret to Clockwork's DB.

This is by design — operators who pinned the secret to a constant did so to keep it out of DB backups + plugin SQL injection blast radius. We honor that.

### Mid-flight failure

Race risk: Clockwork loses the response **after** Companion has already rotated. Now Companion has the new secret; Clockwork has the old one. Every subsequent call 401s with `invalid_signature`.

Recovery:

```bash
php artisan clockwork:install-companion --site=<id>            # SpinupWP
php artisan clockwork:install-companion-pressable --site=<id>  # Pressable
```

The installer regenerates a fresh secret end-to-end — pushes to Companion, mirrors to Clockwork. The race window is tiny, the recovery is straightforward.

### Brief overlap window

`installOrUpdate($site, rotateSecret: true)` overwrites the secret in place. The old secret is dead the moment `wp option update` (or the underlying SQL upsert) succeeds. **Any in-flight Clockwork call signed with the old secret will 401.** Acceptable because rotations are user-initiated — pause your console session, run the rotation, retry.

### Clock drift > 5 minutes

Every Companion call 401s with `stale_timestamp`. This isn't a rotation issue, but it surfaces during testing-after-rotation. Fix the clock first — `sudo systemctl restart systemd-timesyncd` on Linux; on macOS, System Settings → General → Date & Time should always be on.

## Verify after rotation

```bash
php artisan tinker --execute="
  \$site = App\Models\Site::where('domain','example.com')->first();
  dump((new App\Services\Companion\ClockworkCompanionClient(\$site))->health());
"
```

Should return `200 OK` with a JSON health payload. If it 401s, the rotation didn't complete cleanly — fall back to the install recovery above.

## Bulk rotation

`--all` iterates every site with `companion_installed=true`. Constant-mode sites are skipped (409, with a message in the output). Failures don't abort the loop — each site is independent.

The audit log (`/maintenance-history` filtered by `companion_secret_rotated`) shows the full result set.

## What rotation does NOT do

- **Doesn't change the WP-side audit log.** The `wp_clockwork_auth_failures` table is untouched. If you rotated because of suspicious failures, those failure rows stay (intentionally — they're the evidence).
- **Doesn't invalidate active SSO nonces.** Those expire on their own 60-second TTL.
- **Doesn't re-install the plugin.** Just swaps the secret. If the plugin itself is suspect, run `clockwork:install-companion` (SpinupWP) or `clockwork:install-companion-pressable` (Pressable) to refresh the source.
