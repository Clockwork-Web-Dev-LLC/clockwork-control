# Handoff: Companion 1.37.2 null-filter fatal fix (2026-09-15, uncommitted in BOTH repos)

## The incident

phii.org (Pressable, id 269, pressable_site_id 1282022) fataled on every
wp-admin page footer:

```
PHP Fatal error: Uncaught TypeError:
ClockworkCompanion\WhiteLabel\WhiteLabel::filterAdminFooterText():
Argument #1 ($footerText) must be of type string, null given
(WhiteLabel.php:577, via apply_filters('admin_footer_text', ...))
```

Root cause: WordPress filter callbacks receive whatever the PREVIOUS
callback in the chain returned. Some other plugin on phii.org returns
`null` from `admin_footer_text`; Companion's strictly-typed `string`
parameter turned that into a fatal. This is a latent bug on every site —
it only fires when a null-returning filter neighbor is present.

## Already done — do NOT redo, just review/commit/ship

### Companion repo (`~/Projects/clockwork-companion`) — uncommitted

- `src/WhiteLabel/WhiteLabel.php` — `filterAdminFooterText(?string)` +
  `?? ''`; `filterAllPlugins(?array)` + `?? []`;
  `filterPluginRowMeta(?array, ?string)` + null guards.
- `src/TwoFactor/LoginInterceptor.php` — `maybeShowExpiredNotice(?string)`
  (login_message filter; a null would have fataled the LOGIN SCREEN).
- `src/Sso/Interceptor.php` — `maybeShowError(?string)` (same).
- `clockwork-companion.php` — version bumped 1.37.1 → **1.37.2** (both the
  header and `CLOCKWORK_COMPANION_VERSION`).
- `CHANGELOG.md` — 1.37.2 entry dated 2026-09-15.
- `tests/WhiteLabelTest.php` — new
  `testFilterCallbacksTolerateNullFromEarlierCallbacks` regression test.
- Test suite green: `vendor/bin/phpunit` → 123 tests, 522 assertions.

Deployed live to **phii.org only** (health ok, v1.37.2, nullable signature
confirmed in the remote file). Rest of fleet still on 1.36.0 (~158 sites)
/ 1.37.1 (~20 sites) / older.

### Control repo (`~/Development/clockwork-control`) — uncommitted, related

Same-day Companion client fix (root cause of the 5-site secret-desync
incident, see memory `project_companion_secret_desync_2026_09_15`):

- `app/Services/Companion/ClockworkCompanionClient.php` — new
  `salvageJsonBody()` helper; `rotateSecret()` and `malwareScan()` now use
  it instead of raw `->json()` (a script-prefix-injecting site made a
  SUCCESSFUL plugin-side rotation look failed, desyncing secrets for days).
- `tests/Feature/Companion/ClockworkCompanionClientExtendedTest.php` —
  3 new salvage regression tests. Suite green (57 tests).

Note: the control repo working tree also carries OTHER unrelated in-flight
changes (monitoring domain-ignore-list feature — see
`plans/handoff-monitoring-domain-ignore-list.md` — plus capacity/issues
edits). Keep commits separated by concern.

## What's left to do

1. **Commit + tag Companion 1.37.2** per the usual release flow (the
   1.37.0/1.37.1 release cadence and gates are in memory
   `project_restore_kev_eol_cycle_review`).
2. **Fleet rollout decision + execution** (Aaron's call on canary vs
   direct): SpinupWP sites via
   `php artisan clockwork:install-companion --all-installed`,
   Pressable sites via `clockwork:install-companion-pressable --site=...`
   (that command is deliberately per-site only — no --all flag yet).
3. **Commit the control-repo client fix** (salvageJsonBody + tests) —
   separate commit from the monitoring feature work.

## Gotchas

- The installers tar the LOCAL checkout at `~/Projects/clockwork-companion`
  when `CLOCKWORK_COMPANION_DIST_URL` is empty (dev default) — whatever
  sits in that working tree ships. Don't run a fleet deploy with unrelated
  WIP in that checkout.
- Pressable transport writes the bootstrap secret via direct SQL + cache
  flush and rotates it away over HTTPS afterwards. If a rotate "fails" on
  a site that injects output before REST JSON, the plugin may STILL have
  rotated — that is exactly what the salvageJsonBody fix addresses. With
  the control-repo fix in place this self-heals; without it you get a
  secret desync (recovery recipe in the memory file above).
- amymartinauctioneer.com has multiple object-cache pools: CLI
  `wp cache flush` does NOT clear what web containers read — use
  `PressableClient::flushObjectCache()` (DELETE /sites/{id}/object-cache).
- Unrelated open finding: phii.org's Companion malware scans have reported
  `php_in_uploads=5` for 3+ nights running — same pattern as previous real
  compromises. Not investigated yet; flagged to Aaron.
