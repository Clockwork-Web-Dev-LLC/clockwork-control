# Plan: Companion Self-Update (No WordPress.org, No SSH Required)

> **Superseded 2026-09-12** by [`../companion-renegade.md`](../companion-renegade.md). This plan's own
> "why not PUC" reasoning turned out to rest on an incomplete picture: it's true for the SSH/API-managed
> mu-plugin fleet, but the actual gap (standalone/unmanaged sites) already runs Companion as a real,
> manually-activated standard plugin, not an mu-plugin — so PUC works there natively after all. Rather
> than bolt a hand-rolled self-update REST route onto the existing MIT mu-plugin, the superseding plan
> forks a dedicated GPL-licensed "Companion Renegade" product for that population instead. Kept here for
> the file-level detail on `ArchiveExtractor` reuse and the atomic-swap/rollback reasoning, in case a
> hand-rolled push-update path is ever needed again for some other reason.

## Context

Aaron wants an easy way to push Companion mu-plugin updates that doesn't route through the
wordpress.org plugin directory. Today Companion updates ship two ways:

1. **SSH/API push** (`clockwork:install-companion`, fleet deploy) — works for SpinupWP, Pressable,
   GridPane, and any host Control has shell or provider-API access to. Already solved, untouched by
   this plan.
2. **Manual zip download** (`GET /companion/download`, behind Control's operator-auth wall) — the
   only path today for standalone/unmanaged sites (custom, WP Engine, Kinsta) where Control has no
   SSH/API foothold. An operator downloads the zip and re-uploads it by hand via wp-admin on every
   single site, every release. This is the actual gap.

## Key architectural decision: why not the Plugin Update Checker (PUC) library

The first design floated for this (self-hosted update manifest + [Yahnis Elsts' Plugin Update
Checker](https://github.com/YahnisElsts/plugin-update-checker) library) turns out to be the wrong
shape once checked against how Companion is actually loaded. **PUC's entire value proposition is
hooking into WordPress core's real plugin-upgrade machinery** (`pre_set_site_transient_update_plugins`,
the wp-admin "update available" nag, `Plugin_Upgrader`) — and that machinery only ever looks at
`wp-content/plugins/`. Companion is deliberately a **true mu-plugin** at
`wp-content/mu-plugins/clockwork-companion.php` (`clockwork-companion.php:11`) specifically so it
can't be deactivated by a client. `get_plugins()`, `wp_update_plugins()`, and every UI built on top of
them never scan `mu-plugins/` at all — there is no "Update Now" link to hook into, no matter what
library is used. Confirmed by reading `CoreUpdateRoute.php` and the existence of a separate
`PluginUpdateRoute.php`: this codebase already knows how to drive WP's real `Plugin_Upgrader`/
`Core_Upgrader` for *regular* plugins/core, and deliberately does not attempt that for Companion
itself.

**What we do instead — extend the push model Control already trusts, over HTTPS instead of SSH.**
Companion's REST API (HMAC-signed, `HmacVerifier`) is already reachable on every site Control
monitors, regardless of hosting type — that's how `/health`, backups, and every other Companion
operation already work today, SSH or not. The only thing SSH is actually needed for is the *very
first* install (no REST endpoint exists yet to talk to). Once Companion is running, updates don't
need SSH either — Control can push the next version straight through the same signed channel,
exactly like the existing SSH install already does, just swapping the transport from `exec()` over
SSH to an HTTPS POST. Net result:

- **No new public endpoint on Control.** No manifest URL, no download URL, nothing anonymous can hit.
- **No new outbound credential in Companion.** It never has to call Control; the existing one-directional
  Control→Companion HMAC trust (`Site.companion_secret`) is all that's needed, unchanged.
- **No WordPress.org involvement**, obviously — self-hosted, Control-originated only.
- Reuses infrastructure that just got hardened in the 1.7.1 / 1.37.1 security patch: `ArchiveExtractor`
  (zip-slip-safe extraction with PclZip fallback for hosts without `ext-zip`) and the general
  "Companion files copied/applied last" atomicity pattern from `FileApplier`.

The zip is small enough (~380 KB, ~510 KB base64-encoded, measured via
`CompanionTarballBuilder::buildPluginZipBytes()` against the current source tree) to embed directly
in the POST body — comfortably under PHP's default `post_max_size` (8 MB) and any sane
`client_max_body_size`. No chunking, no signed download URL, no separate fetch step required.

## Companion repo changes (`/Users/aaronr/Projects/clockwork-companion`)

### `src/Rest/SelfUpdateRoute.php` (new)

Mirrors `CoreUpdateRoute.php`'s shape (HMAC-gated POST route, structured JSON response) but the
"upgrader" is hand-rolled since WP has none for mu-plugins:

```
POST /wp-json/clockwork/v1/self-update
Body: { "zip_base64": "...", "sha256": "...", "version": "1.38.0" }
```

Handler logic:
1. Decode `zip_base64`; verify `hash('sha256', $bytes) === $sha256` (`hash_equals`, constant-time) —
   reject on mismatch before touching disk. This is integrity verification over the wire, not a trust
   decision (the request is already HMAC-authenticated as coming from Control); pair it with the
   existing "prefer `CLOCKWORK_COMPANION_DIST_URL` in production" story on the Control side (see
   below) for the actual trust boundary.
2. Write to a temp file, extract via **`(new \ClockworkCompanion\Backup\ArchiveExtractor())->extract($tmpZip, $stagingDir)`**
   — reuse it as-is; it already does zip-slip-safe extraction with the ZipArchive/PclZip fallback this
   codebase already relies on for backup restores. Delete the temp zip immediately after.
3. Sanity-check the staged payload before touching anything live: `$stagingDir/clockwork-companion.php`
   and `$stagingDir/clockwork-companion/src/` must both exist, and the staged loader's
   `CLOCKWORK_COMPANION_VERSION` define must match the `version` field in the request body. Refuse and
   clean up on any mismatch — this catches a truncated/corrupt build before it becomes a broken site.
4. **Atomic-ish swap**, applying the "copy Companion files last" lesson from `FileApplier` (it already
   defers Companion's own files to last in a restore for exactly this self-modifying-code reason):
   - Determine `$muPluginsDir = dirname(CLOCKWORK_COMPANION_DIR)` (handles both the flat dev layout and
     the `wp-content/mu-plugins/clockwork-companion/` production layout — see the existing
     `is_dir(__DIR__.'/src')` branch in `clockwork-companion.php:21-26`).
   - Move the **currently live** `clockwork-companion/` source dir aside to
     `clockwork-companion-rollback-{timestamp}/` (`rename()` — atomic on the same filesystem).
   - Move the staged `clockwork-companion/` into place at the now-empty original path.
   - Copy the new loader over the live `clockwork-companion.php` **last**, after the source dir swap
     succeeded — this is the single line that changes what code runs on the *next* request; do it only
     once everything else is confirmed on disk.
   - On any failure after the rollback-move but before the loader copy, restore from the
     `-rollback-*` dir and return `ok: false` rather than leaving the site half-upgraded.
   - Keep exactly the most recent `-rollback-*` dir (delete older ones at the top of the handler) as a
     manual recovery safety net; don't accumulate them indefinitely.
   - If the `opcache` extension is loaded, call `opcache_reset()` at the end — mu-plugins are typically
     `include`d once per request at `plugins_loaded`, but a host with
     `opcache.validate_timestamps=0` won't otherwise notice the on-disk change until a manual reset or
     FPM restart. Best-effort; don't fail the response if it's unavailable.
5. Respond `{ok: true, previous_version, applied_version, rollback_dir}` or
   `{ok: false, error, previous_version}` (never partially applied without a clear signal either way).

Register in `src/Plugin.php` alongside the other route registrations (`Plugin.php:172`-ish, next to
`BackupRestoreRoute`).

### `Plugin::CAPABILITIES` (`src/Plugin.php:47`)

Add `'self-update'` to the list. This is the version-gating mechanism Control already uses for every
other capability rollout (see `RefreshCompanionCapabilities`'s own docblock: "a plugin release that
adds a new capability... doesn't propagate to existing sites until they're individually reinstalled" —
expected and fine here too). Concretely: a site must receive **one** SSH/manual install of this
release before Control will ever attempt a self-update push to it again — after that, every
subsequent release self-updates with no further SSH involvement.

### Tests (`tests/`)

- `SelfUpdateRouteTest.php` — HMAC gating (401 without a valid signature, matching every other route's
  test), sha256 mismatch rejection, corrupt-zip rejection, successful apply against a fixture zip
  (assert the loader's `CLOCKWORK_COMPANION_VERSION` constant reflects the new version after a
  simulated apply — likely needs a filesystem fixture/temp-dir seam similar to `FileApplier::$testApplier`;
  add a `SelfUpdateRoute::$testApplier` static seam following that exact precedent rather than hitting
  the real filesystem in unit tests), rollback-on-mid-failure behavior.
- Reuse `ArchiveExtractor`'s existing zip-slip test fixtures/assertions rather than re-deriving them.

### `CHANGELOG.md` / `README.md`

Document the new route (request/response shape, HMAC-gated like every other mutating route) and the
capability-gating bootstrap requirement, matching how `BackupRestoreRoute` was documented.

## Control repo changes (`/Users/aaronr/Development/clockwork-control`)

### `app/Services/Companion/CompanionTarballBuilder.php`

Add `acquireZipBytes(): string`, mirroring `acquire()`'s existing dist_url/local_path branching
(`CompanionTarballBuilder.php:28-37`) but returning **zip** bytes instead of tar.gz, since self-update
needs the same zip shape `buildPluginZipBytes()` already produces:
- `dist_url` set: fetch, verify against `dist_sha256` (reuse the exact `hash_equals` check already in
  `acquireFromDistUrl()`), return the verified bytes directly (they're already a zip if
  `clockwork:build-companion-tarball` is updated to also emit a `.zip` — see below — or add a small
  sibling command; don't repack tar↔zip at runtime).
- `local_path` (dev default): call `buildPluginZipBytes($localPath)` directly.

This keeps the same "production sources from a hash-pinned artifact, not the operator's live laptop
directory" trust boundary that `acquire()` already documents for the SSH path — self-update should not
be a backdoor around that reasoning.

**Decision needed during implementation:** either (a) extend `BuildCompanionTarball` to also emit a
`.zip` + `.sha256` sibling artifact so `CLOCKWORK_COMPANION_DIST_URL` can point at the zip directly, or
(b) add `CLOCKWORK_COMPANION_DIST_ZIP_URL` / `_ZIP_SHA256` as a second, independent pair of env vars
for the self-update path specifically. (a) is simpler (one publish step, one set of env vars) and is
the recommended default — only reach for (b) if there's a reason to publish the tarball and zip from
different locations.

### `app/Services/Companion/ClockworkCompanionClient.php`

Add `selfUpdate(string $expectedVersion): array`, following the exact shape of `updatePlugin()`/
`updateCore()` (`ClockworkCompanionClient.php:485,539`):

```php
public function selfUpdate(string $expectedVersion): array
{
    $bytes = app(CompanionTarballBuilder::class)->acquireZipBytes();

    return $this->postJson('/self-update', [
        'zip_base64' => base64_encode($bytes),
        'sha256' => hash('sha256', $bytes),
        'version' => $expectedVersion,
    ], ['timeout' => 60]); // larger body + extraction time; override like updateCore() already does
}
```

### `app/Models/Site.php`

Add `supportsCompanionSelfUpdate(): bool`, matching the existing `supportsTrafficReport()` /
capability-check convention:

```php
public function supportsCompanionSelfUpdate(): bool
{
    return in_array('self-update', (array) $this->companion_capabilities, true);
}
```

### `app/Console/Commands/UpdateCompanionFleet.php` (new)

Mirrors `RefreshCompanionCapabilities`'s iteration/reporting shape exactly (same `--site=`/`--server=`
options, same `$stats` tally, same per-site try/catch/log pattern):

```
clockwork:update-companion-fleet {--site=} {--server=} {--dry-run}
```

- Resolve target sites the same way `RefreshCompanionCapabilities::resolveSites()` does.
- Skip any site where `companion_version === config('clockwork.companion.version')` already (nothing
  to do) or where `! $site->supportsCompanionSelfUpdate()` (needs one manual/SSH install first —
  report these separately so the operator knows which sites still need a one-time manual bootstrap).
- For the rest, call `$client->selfUpdate($targetVersion)`; on `ok: true`, immediately re-poll `/health`
  (same pattern `RefreshCompanionCapabilities` uses) to confirm `companion_version` actually moved, and
  persist it. On failure, log via `ActionLogger` (matching `ProcessServerUpdates`'s
  `TYPE_SERVER_UPDATE_FAILED`-style pattern — add a sibling `TYPE_COMPANION_SELF_UPDATE_FAILED` to
  `ActionLog`) and fire a `ChatNotifier` alert, so a fleet-wide push failure surfaces the same way a
  server update failure already does rather than silently vanishing into a command's stdout.
- `--dry-run` just prints which sites would be updated / skipped / need bootstrap, no requests made.

### UI hook (optional first pass — can ship as CLI-only initially)

A "Update Companion fleet" button somewhere on the existing Companion settings/fleet page (check
`resources/views/settings/` for the current companion rollout dashboard — the 2FA rollout work
already built fleet-status tooling worth reusing/extending rather than duplicating) that shells out to
the same command via `BackgroundArtisan`, matching the pattern `ScheduledJobsController::run()` and
`OperationsUpdatesController::refresh()` already use for "kick off a background artisan command from a
button click."

### `config/clockwork.php` / `.env.example`

No new config needed beyond what already exists (`companion.dist_url`, `companion.dist_sha256`,
`companion.version`) — self-update reuses all of it as-is.

### `resources/docs/`

- `resources/docs/architecture/companion-plugin.md` — document the self-update mechanism alongside the
  existing SSH-install description: what triggers it, the capability-gated bootstrap requirement, and
  why it deliberately doesn't use PUC/wordpress.org (the mu-plugin reasoning above, condensed).
- `resources/docs/reference/web-routes.md` / `artisan-commands.md` — new command entry.
- `resources/docs/features/` — a short new page or a section added to whatever page documents fleet
  Companion rollout today, covering the one-time bootstrap requirement clearly (this is the one thing
  an operator could get confused by: "why didn't this site update?" → check
  `supportsCompanionSelfUpdate()`).

## Rollout sequencing

1. Ship the Companion-side route + capability in the **next** Companion release (1.38.0 — this is a
   new capability, so a MINOR bump per `RELEASING.md`, not a patch).
2. That release still has to reach every site via the *existing* SSH/manual path one final time — this
   plan does not remove the need for that, it just makes every release *after* this one self-deliverable
   for any site that got this one.
3. Ship the Control-side command + client method in the same Control release as a companion piece —
   the two repos should land together so `clockwork:update-companion-fleet` has something to talk to.
4. Verify against a canary site (pick one already on the current version) before fleet-wide use, same
   caution already applied to the 2FA rollout.

## Before considering this done

Run the full suite in both repos and confirm green:
- Control: `./vendor/bin/pest`, `./vendor/bin/phpstan analyse`, `./vendor/bin/pint --test`.
- Companion: `./vendor/bin/phpunit`.

## Verification & test plan

**Companion unit/feature tests:**
- `SelfUpdateRouteTest`: unauthenticated request → 401 (matches every other route's HMAC test); wrong
  sha256 → rejected, filesystem untouched; version-mismatch in staged loader → rejected; successful
  apply → loader's `CLOCKWORK_COMPANION_VERSION` reflects new value, exactly one `-rollback-*` dir left
  behind, older rollback dirs pruned; simulated mid-swap failure → live directory restored from
  rollback, response is `ok: false`.

**Control feature tests:**
- `UpdateCompanionFleetTest`: a site already on target version is skipped (no HTTP call made — assert
  via `Http::fake()` call count); a site missing the `self-update` capability is skipped and reported
  separately, not silently dropped; a site with the capability gets exactly one `selfUpdate()` call and
  its `companion_version` is refreshed from the post-update `/health` poll on success; a failed push
  logs an `ActionLog` row and fires exactly one `ChatNotifier` call.
- `CompanionTarballBuilder::acquireZipBytes()`: dist_url mode returns verified bytes and throws on hash
  mismatch (mirror the existing `acquireFromDistUrl()` test); local_path mode returns
  `buildPluginZipBytes()`'s output unchanged.

**Manual verification:** run `clockwork:update-companion-fleet --dry-run` against the real fleet once
merged to confirm the skip/bootstrap-needed/eligible breakdown looks right, then run it for real
against one canary site and confirm `/health` shows the new version, the site's wp-admin still loads
normally, and exactly one `-rollback-*` directory exists in `mu-plugins/` afterward.
