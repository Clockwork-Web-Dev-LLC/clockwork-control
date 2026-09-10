---
title: WordPress plugin inventory
section: Features
order: 80
updated: 2026-09-09
author: Aaron Reimann
tags: [wordpress, plugins, inventory, updates, pressable]
tracks: [app/Services/Sites/WpPluginDetector.php, app/Console/Commands/{DetectWpPlugins,RefreshCompanionSnapshot}.php, app/Http/Controllers/WordPressPluginsController.php]
---

A fleet-wide view of which WordPress plugins are installed, which need updates, and which security plugins are active per site. Updates can be applied per-site through Companion's `Plugin_Upgrader` flow.

## Where to look

- **`/settings/wordpress-plugins`** — fleet inventory. Per-site rows with LLAR + Wordfence active state, SpinupWP update flags, install-LLAR action. **Pressable sites don't appear on this page at all** — `WordPressPluginsController` still queries `whereHas('server', ...)`, which no Pressable site ever matches (no `server` row). Unlike the other fleet-wide monitoring commands, this controller wasn't switched to `Site::hostMonitored()` during the Pressable rollout, so this looks like an overlooked gap rather than a deliberate exclusion — worth revisiting if Pressable plugin inventory needs to show up here too.
- **`/sites/{id}/updates`** — per-site Updates tab. Reads from `companion_snapshot.plugins`. Shows outdated plugins, lets you tick which to update.
- **Issues page → "WordPress plugins out of date"** — counts sites with `companion_snapshot.plugins.counts.updates_available > 0`.

## Two data paths

**Where Companion is installed**, plugin data comes from the `/snapshot` route — full per-plugin inventory (slug, name, version, active, update_available, new_version, auto_update). Cached in `sites.companion_snapshot` JSON, refreshed nightly at 01:30 ET by `clockwork:refresh-companion-snapshot` (plus on-demand after any update batch from the Updates page).

**Where Companion isn't installed**, we fall back to an SSH + `wp-cli` probe (`clockwork:detect-wp-plugins`, daily 04:45 UTC) — server-hosted sites only (any site with a `Server` row: SpinupWP, GridPane, Cloudways), no Pressable transport exists for this probe today since Pressable sites have no `Server` row to SSH against. Scoped to the slugs we care about (`limit-login-attempts-reloaded`, `wordfence`) — full inventory requires Companion. The on-disk WordPress path comes from `Site::resolveWpPath()` (the recorded `wp_path`, or a per-provider convention — `/sites/{domain}/files` for SpinupWP, `/var/www/{domain}/htdocs` for GridPane); a site with neither fails cleanly rather than probing a guessed, possibly-wrong path. SpinupWP's update *booleans* (`wp_core_update`, `wp_theme_updates`, `wp_plugin_updates`) are still imported and surface as a coarse "updates available" badge — always false for Pressable sites regardless of real update state, same caveat as the per-site Overview tab pills (see [Features → Security scans](/docs/features/security-scans)).

## Per-site updates

The Updates tab lists outdated active plugins (and lets you optionally include outdated inactive ones). Tick the boxes, click **Update selected**:

1. Browser JS iterates the selection, POSTs each slug one at a time to `sites.companion.plugin-update`.
2. Per-plugin progress renders inline ("5.3.7 → 5.4.0 ✓ (2.4s)").
3. Companion's `PluginUpdateRoute` validates, runs `Plugin_Upgrader::upgrade()`, captures before/after versions, **re-activates if it was active before**, returns the result.
4. After the run, JS fires a fire-and-forget snapshot refresh so subsequent page loads see the post-update state.

Updates are **synchronous** — the user watches them happen. No background job, no batching. Per-plugin live feedback is more useful than a five-minute spinner.

## Re-activation matters

`Plugin_Upgrader::upgrade()` deactivates the plugin during the upgrade and does **not** re-activate it. If you don't call `activate_plugin()` yourself, the site is left running with the plugin off — silent until someone notices. The runner records `is_plugin_active($slug)` before, then re-activates after if it was active. The response includes `was_active` and `reactivated`; the UI flags re-activation failures with a red pill.

## Care plan banner

The Updates tab shows a banner: "Updates included" (care plan) or "Billable" (no care plan). The `care_plan_enabled` flag is purely informational here — it doesn't gate the action. Anyone on the team can update plugins on any site; the banner just reminds you who pays.

## LLAR install

The fleet inventory at `/settings/wordpress-plugins` has an "Install LLAR" button per site without it (moot for Pressable sites today, since they don't appear on this page at all — see above). POSTs to `/sites/{id}/install-llar`, runs `App\Services\Sites\LlarInstaller` over SSH:

1. `wp plugin install limit-login-attempts-reloaded`
2. `wp plugin activate ...`
3. Suppress notification emails (otherwise the client gets a flood of lockout notifications during the first day).

LLAR install is deliberately **per-site, not fleet-wide**. The bulk version exists as a CLI flag for one-off rollouts.

## Manual probe

```bash
# SSH-based probe (LLAR + Wordfence active state):
php artisan clockwork:detect-wp-plugins

# Per-site Companion-based snapshot refresh:
php artisan clockwork:refresh-companion-snapshot --site=42
```

## What this isn't

- **Not a theme inventory.** Themes change rarely; not worth the build cost.
- **Not a core update path.** WordPress core auto-updates handle minor releases. Major upgrades are still manual on the SpinupWP side.
- **No fleet-wide pivot.** "Update Akismet across all 12 sites" needs a queue table + drainer + new UI. Defer until someone actually asks.
- **No pre-update backup trigger.** Would pair beautifully but Companion doesn't have a backup-trigger capability yet. The Backups page shows recent SpinupWP backups so you can verify recency before clicking.

## Gotchas

- **Plugin updates and transients:** The nightly refresh calls `/plugins` (which triggers `TransientRefresher` via an HMAC-signed loopback `wp_remote_post()` to force a fresh wordpress.org check, rate-limited to once per 30 min per site) immediately before `/snapshot`. This ensures cached update inventories reflect current available versions. See [Features → Updates](/docs/features/updates) for details.
- **SpinupWP can't enumerate plugins.** `wp_plugin_updates` is a count, not a list. The full list comes from Companion (or the SSH probe for the security plugins).
- **`echo` not `printf %s`** when feeding sudo's stdin password during `wp-cli` probes. `printf %s` (no trailing newline) makes sudo wait on EOF and `wp` exits silently with empty output. The detector hides this footgun via a trailing `| grep ... || true`; don't strip it without switching to `echo`.
