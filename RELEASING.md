# Release Guide & Cadence Policy

This document outlines the versioning philosophy, release cadence, and step-by-step procedures for publishing releases of **Clockwork Control**.

---

## Semantic Versioning (SemVer) Policy

Clockwork Control follows [Semantic Versioning 2.0.0](https://semver.org/):

- **MAJOR (`X.0.0`)**: Breaking operational or architectural changes:
  - Environment variable renames or removals without fallback.
  - Destructive or non-backward-compatible database migrations.
  - Module contract modifications that remove or rename existing methods.
  - Removal of existing features or supported integrations.
- **MINOR (`0.X.0`)**: New backward-compatible functionality:
  - New modular services under `modules/` (e.g. `modules/Llar/`, theme systems, installers).
  - New settings pages, reporting views, or API endpoints.
  - Additive database migrations or new optional configuration parameters.
  - Additive interface capabilities (e.g. `HostingProvider::CAP_BACKUP_RELAY`).
- **PATCH (`0.0.X`)**: Backward-compatible bug fixes and maintenance:
  - Bug fixes and error handling improvements.
  - Documentation additions and corrections.
  - Dependency security updates with zero behavioral regressions.

---

## Release Cadence Policy

### 1. Batch-Driven, Not Commit-Driven
- **Do not release on every commit.** Commits on `main` represent continuous integration.
- A release is warranted when:
  1. At least **one complete, tested, documented feature** has landed (e.g. a new module or major UX overhaul), OR
  2. A cluster of approximately **3+ related bug fixes or polish improvements** have accumulated, AND
  3. The automated test suite (`./vendor/bin/pest`) is 100% green, AND
  4. Documentation under `resources/docs/` is updated and reflects the current codebase (`StalenessChecker` passes).

### 2. Time-Based Catch-Up Threshold
- Unreleased work should not languish indefinitely. If **6 to 8 weeks** pass with untagged commits on `main`, cut a minor or patch release even if the batch is small. This ensures self-hosted instances using `/settings/updates` remain aligned with upstream progress.

### 3. Out-of-Band Critical Patches
- Security vulnerabilities (CVEs) and critical operational regressions bypass normal batching and ship immediately as a standalone **PATCH** release.

### 4. AI & Operator Partnership Standard
- During active pair programming, the AI assistant will evaluate commits accumulated since the last git tag. When the threshold is met, the assistant will proactively propose cutting a release.
- **No release tag or git push is ever executed without explicit operator approval.**

---

## Step-by-Step Release Checklist

When cutting a release `vX.Y.Z`:

### 1. Preflight Validation
Ensure your working tree is clean, dependencies are up-to-date, and all automated tests pass:
```bash
composer test
# or
/opt/homebrew/opt/php@8.4/bin/php ./vendor/bin/pest
```

### 2. Update the Changelog
Open [`CHANGELOG.md`](./CHANGELOG.md):
1. Review the entries under `## [Unreleased]`.
2. Move those entries into a new section:
   ```markdown
   ## [X.Y.Z] - YYYY-MM-DD
   ```
3. Leave an empty `## [Unreleased]` block at the top for future work.

### 3. Bump the Application Version
Update the default version in:
1. `config/clockwork.php`:
   ```php
   'version' => env('CLOCKWORK_VERSION', 'X.Y.Z'),
   ```
2. `.env.example`:
   ```env
   CLOCKWORK_VERSION=X.Y.Z
   ```

### 4. Commit and Tag
Commit the version bump and tag the release:
```bash
git add CHANGELOG.md config/clockwork.php .env.example
git commit -m "chore: release vX.Y.Z"
git tag -a vX.Y.Z -m "Clockwork Control vX.Y.Z"
```

### 5. Push to GitHub
Push the commit and the tag upstream:
```bash
git push origin main
git push origin vX.Y.Z
```

### 6. Automated GitHub Release
The `.github/workflows/release.yml` workflow automatically triggers on the `v*` tag push:
1. It reads the section for `[X.Y.Z]` from `CHANGELOG.md`.
2. It publishes an official GitHub Release with that release body.
3. Clockwork Control instances across the fleet visiting `/settings/updates` will automatically discover the new version.
