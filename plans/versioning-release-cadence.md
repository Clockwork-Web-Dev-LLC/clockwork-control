# Plan: Versioning & release cadence

## Context

`app/Services/Updates/SystemUpdateService.php` already implements a complete self-update system,
live at `/settings/updates`: it compares the installed `CLOCKWORK_VERSION` (env var, currently
defaulted to `1.0.0` in both `.env.example` and `config/clockwork.php`) against the latest GitHub
Releases API tag for this repo, shows an "up to date" or "update available" card, and its
**Update Now** button runs a real pipeline (preflight clean-working-tree check → `git pull` →
`composer install --no-dev` → `migrate --force` → `optimize:clear`), each step's output shown back
to the operator. None of this has ever actually been exercised: there are **zero git tags** on this
repo, no `CHANGELOG.md`, and `CLOCKWORK_VERSION` has sat at its default since the fresh-history
migration. This is a **process gap**, not a missing-code gap.

The user also wants an explicit standing policy for judging *when* to cut a release — not a release
after every commit, but also not letting real work sit unreleased indefinitely.

## SemVer policy for this app

- **MAJOR** — breaking changes an operator/agency would feel: env var renames/removals, a
  non-backward-compatible migration, a module contract change that removes/renames an existing
  method (note: the additive `HostingProvider` capability added in the backup-relay-generalization
  plan is NOT a major bump — it's purely additive), removed features.
- **MINOR** — new features, new modules, new settings pages, additive migrations, new env vars with
  safe defaults. The installer, theme system, and backup-relay generalization plans in this same
  `plans/` directory are all MINOR-shaped work — purely additive, nothing existing breaks.
- **PATCH** — bug fixes, doc fixes, security patches, dependency bumps with no behavior change.

## Cadence policy — batch-driven, not calendar-driven, not commit-driven

- **Don't release on every commit.** A release is warranted once at least one complete, tested,
  documented feature (or a cluster of roughly 3+ related fixes) has landed on `main`, CI is green,
  and the relevant `resources/docs/` pages have been updated for what changed — the existing
  docs-staleness banner / `StalenessChecker` mechanism is a ready-made mechanical check that docs
  aren't lagging behind a release.
- **Don't let unreleased work sit forever either.** If roughly 6–8 weeks pass with untagged commits
  on `main`, that's a signal to cut a release even if the batch is small — otherwise the self-update
  system just silently drifts from "unused" toward "actively misleading."
- **Exception:** a security fix or critical regression fix ships immediately as an out-of-band PATCH
  regardless of where the batch-cadence clock currently sits.
- **AI's role going forward:** after a meaningful, merged, verified piece of work, check commits
  since the last tag against this threshold and proactively flag "this looks release-worthy" —
  don't propose a release after routine/small commits, and don't cut a release without explicit
  approval (same standard as any other git push/tag action).

## Mechanics (the actual new work — currently 100% manual/nonexistent)

1. `CHANGELOG.md` in [Keep a Changelog](https://keepachangelog.com/) format, with an `Unreleased`
   section that accumulates entries as work lands — a lightweight manual habit (one line per
   notable change) rather than retrofitting a conventional-commit convention this repo doesn't use.
2. `.github/workflows/release.yml` — triggered on a pushed `v*` tag; creates a GitHub Release using
   that version's `CHANGELOG.md` section as the release body (via `gh release create` or an
   equivalent action). This is the piece that actually feeds
   `SystemUpdateService::checkForUpdates()`, which already reads exactly this API and has sat
   unused since the repo's fresh-history migration.
3. A release cut = bump `CLOCKWORK_VERSION` in `.env.example` and `config/clockwork.php`'s default,
   finalize the `CHANGELOG.md` section (move it out of `Unreleased`), tag `vX.Y.Z`, push the tag.
   `composer.json` gets **no** version field — Laravel apps don't conventionally version there, and
   `CLOCKWORK_VERSION` + the git tag are already the established single source of truth per
   `resources/docs/reference/env-vars.md`'s existing note ("bump this yourself after a manual
   update if you're not relying on the git-tag-driven release flow").
4. Document all of the above as a new `RELEASING.md` (or a CONTRIBUTING.md section): the SemVer
   rules, the cadence policy, and the exact step-by-step cut checklist.

## Phased implementation

**Phase 1** — Write `CHANGELOG.md`, seeding `Unreleased` retroactively with everything merged since
the initial commit (`d2b4322`) — e.g. the `.env` credentials/provider-tunables fix, the WP 7.0
core-change filtering, etc. Write `RELEASING.md`.

**Phase 2** — Add `.github/workflows/release.yml`, verified against the existing
`SystemUpdateService` consumption path (its expected JSON shape from the GitHub Releases API).

**Phase 3** — Cut the actual first real tagged release (bundles whatever has accumulated by then).
Proves the entire loop end-to-end via `/settings/updates` for the first time ever.

**Phase 4** — Ongoing: apply the cadence-judgment habit described above every time a meaningful
batch of work lands, rather than on any fixed schedule.

## Files

**New:** `CHANGELOG.md`, `RELEASING.md`, `.github/workflows/release.yml`.

**Modified (at each release cut, not just once):** `.env.example`, `config/clockwork.php` (the
`CLOCKWORK_VERSION` default).

## Verification

This is process/workflow, not application code, so verification is manual:
- Cut a real tag, confirm `/settings/updates` immediately shows "up to date."
- Temporarily point a local `.env`'s `CLOCKWORK_VERSION` at an older value, confirm the amber
  "update available" card appears and the "Update Now" pipeline still runs end-to-end.
- Confirm `tests/Feature/Controllers/SystemUpdatesControllerTest.php` (which already fakes every
  `Process` call — untouched by this plan) still passes.
