---
title: Servers (inventory + credentials)
section: Features
order: 12
author: Aaron Reimann
updated: 2026-09-07
tags: [servers, ssh, credentials, inventory, fleet]
tracks: [app/Http/Controllers/ServersController.php, app/Http/Controllers/ServerCredentialsController.php, resources/views/dashboard/server-create.blade.php, resources/views/dashboard/credentials-bulk.blade.php, resources/views/dashboard/credentials-edit.blade.php, resources/views/dashboard/credentials-feed.blade.php]
---

Two controllers own the server row itself, as opposed to what happens to it once it exists: `ServersController` (create/remove/ignore/auto-ban toggles/health recheck) and `ServerCredentialsController` (SSH user/port/password, one at a time or in bulk). Neither had a doc page before this one — everything else in Features assumes a server already exists and has working SSH.

Almost every SpinupWP server arrives via `clockwork:import-spinupwp`, not through this page — see [Integrations → SpinupWP](/docs/integrations/spinupwp). What's here is for the boxes that import doesn't reach (Hetzner, hand-rolled, anything outside the SpinupWP account) and for fixing/rotating credentials on any server regardless of how it got created.

## Adding a server by hand

`/servers/new` (`ServersController::create` / `store`) is a plain form: name, hostname/IP, SSH user (defaults to `clockwork.ssh.default_user`), SSH port (defaults to `clockwork.ssh.default_port`), and an optional SSH password. The new row starts at `status=unknown` — it only turns green/yellow/red once a poll cycle or "Recheck health" runs.

`store()` immediately calls the same internal `runSpinupWpImport()` helper `refreshFromSpinupWp()` uses (see below) — harmless for a genuinely hand-rolled server (no SpinupWP match, no-op), but it means a server you *thought* wasn't in SpinupWP can suddenly pick up sites on creation if it turns out it was already there under a different name.

Use this form for one-off additions. For several servers at once, the page itself links to the **bulk paste-and-import flow** (`/servers/credentials/feed`, below) instead.

## Refresh from SpinupWP, on demand

The **Refresh from SpinupWP** button (`POST /servers/refresh-spinupwp` → `ServersController::refreshFromSpinupWp`) runs `clockwork:import-spinupwp` synchronously, then `clockwork:poll-servers` so a brand-new server gets a real status immediately instead of sitting at "unknown" until the next scheduled tick. Import is the gating step: if it fails, poll is skipped and the whole action reports failure. If import succeeds but poll throws, the action still reports success — the user's actual intent (refresh the server/site list) was met, poll is a bonus. The flash message concatenates the artisan output's `Servers:` / `Sites:` / `Done. ...` summary lines so you get real numbers, not just "done."

## Removing a server

**Destroy** (`DELETE /servers/{server}`) is permanent — it cascades to sites, server metrics, blocked-IP records, and the tag pivot via FK constraints. The operator must type the server's exact name as confirmation, checked server-side (not just a JS `confirm()`). Use this only when the box is actually gone at the provider — for "stop polling but keep the record," use **Toggle ignore** instead, which just flips `is_ignored` (with an optional reason) and leaves everything else intact.

## Auto-ban toggles

`toggleAutoBanLlar` / `toggleAutoBanWordfence` (`POST /servers/{server}/auto-ban-llar` and `.../auto-ban-wordfence`) flip `auto_ban_llar` / `auto_ban_wordfence` on the server row. When on, LLAR/Wordfence lockouts from that server skip the human review step in the [Review queue](/docs/features/review-queue) and go straight to fail2ban. When off, they land in the queue like any other source.

## Recheck health

**Recheck health** (`POST /servers/{server}/recheck-health`) re-polls the cloud provider's CPU metrics API on demand — the same evaluators the scheduled `clockwork:poll-servers` job uses (DigitalOcean, Hetzner, or Azure, dispatched on `server.provider`), just scoped to one server so you don't wait for the next cycle after, say, resizing a droplet. Requires `provider_id` to be set (a hand-rolled server with no cloud-provider link returns a 422 — there's nothing to poll). Updates `status`, `last_polled_at`, and `last_alert_at` (only on a fresh red transition) exactly like the fleet-wide poller does.

## SSH credentials

Three ways to set SSH credentials, all landing in `ServerCredentialsController`:

### One server at a time

`/servers/{server}/edit` (`edit` / `update`) — SSH user, port, and password, plus a **Clear password** checkbox (for switching a server to key-only auth). Changing user or port, or setting/clearing the password, triggers an inline `SshClient::test()` after save so the confirmation message tells you immediately whether the new credentials actually work — no separate "Test SSH" click needed on this path (though the button exists everywhere else, see [Integrations → SSH + fail2ban](/docs/integrations/ssh-and-fail2ban)).

### Bulk edit

`/servers/credentials` (`bulk` / `bulkUpdate`) lists every non-ignored server with a password field each. Submitting only touches rows where a password was actually typed (blank fields are skipped, not cleared) — sets the password, backfills `ssh_user` from the default if the row has none, saves, then runs `SshClient::test()` per updated row. The flash message reports updated / verified / failed counts. This is the fast path for "I just rotated the clockwork-deploy password fleet-wide."

### Paste-and-import feed

`/servers/credentials/feed` (`feed` / `feedParse` / `feedApply`) accepts a block-separated text dump in a specific format (name, IP, `ssh <user>@<ip>` line, password, repeated per server, `---`-separated) — the shape SpinupWP's own credential export uses. `CredentialFeedParser::parse()` splits it into rows; `feedParse()` then matches each row against existing servers by name (exact) or hostname (only if unambiguous — multiple hostname matches are flagged `ambiguous`, a name match against a *different* IP is flagged `name_ip_conflict`), producing a preview table with a status per row (`matched` / `unmatched` / `ambiguous` / `name_ip_conflict`).

The operator reviews the preview and checks which rows to `apply`. `feedApply()` then, per checked row: updates credentials on a matched server (SSH user only changes if `update_user` was explicitly checked — accidental user drift from a mismatched paste is exactly what the `ambiguous`/`name_ip_conflict` statuses exist to catch before this step), or creates a new `Server` row for `unmatched` entries (re-checking for a name/IP collision at apply time, in case something changed between parse and apply). Every touched server gets an `SshClient::test()` immediately after, same as the other two paths.

## What this isn't

- **Not tag management.** Assigning tags to a server (`TagsController::syncServer`) happens from the server detail page, and is documented under [Features → Server updates + reboots → Tags](/docs/features/server-updates-and-reboots) because of its load-bearing effect on the update pipeline.
- **Not fail2ban provisioning.** That's a separate one-time action (`ServerProvisionController::fail2ban`) — see [Integrations → SSH + fail2ban](/docs/integrations/ssh-and-fail2ban).
- **Not the fleet health grid.** That's the main [Dashboard](/docs/features/dashboard) — this page is about the CRUD/credentials layer underneath it.
