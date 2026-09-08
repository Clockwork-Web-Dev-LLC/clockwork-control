# Contributing to Clockwork Control Panel

Thanks for taking a look! Clockwork is an open-source fleet monitoring brain built by an agency, for agencies.

> 📖 **Full in-app guide**: Check out [`resources/docs/getting-started/contributing.md`](resources/docs/getting-started/contributing.md) (or browse `/docs/getting-started/contributing` when running the app).

## The Most Helpful Way to Contribute: Agency Testing & "Vibe Coding"

We have written complete integration modules for almost every major WordPress host and cloud provider: **WP Engine**, **Kinsta**, **Cloudways**, **Azure**, **Linode**, **Vultr**, **Twilio**, and **Pressable**.

Because we can only run our own live production workloads on the services our agency actively uses (SpinupWP, DigitalOcean, Hetzner, Cloudflare, Slack, Mattermost), **we need agencies using other platforms to test the code against real accounts!**

### The 4-Step Agency Workflow:
1. **Download / enable the module** for your agency's hosting or cloud stack.
2. **Plug in real API credentials** in `/settings/integrations` or `.env`.
3. **Test live endpoints and scheduled commands**: run `/settings/diagnostics`, import sites, poll servers.
4. **Vibe code any fixes with Claude / AI**:
   - If an API returns an unexpected envelope, an SSH path is off, or an error is thrown, copy the error or raw JSON response.
   - Paste it into Claude (or your AI assistant) with:
     > *"Here is the real JSON payload from provider X. Update the client in `modules/X/src/` to parse this shape, keep the tests passing, and maintain the `HostingProvider`/`CloudProvider` contract."*
   - Let Claude patch the code and write/update Pest test fixtures.
5. **Run the quality suite** (`./vendor/bin/pint`, `composer phpstan`, `./vendor/bin/pest`) and **open a Pull Request** back!

## Local setup

Full walkthrough: [`resources/docs/getting-started/local-dev.md`](resources/docs/getting-started/local-dev.md). Quick version is in the [README](README.md#quickstart).

## Branching strategy

This applies to work done directly in this repo (the core team, and AI agents like Claude/Gemini
working alongside them) — external contributors already work from their own fork per the workflow
above.

- **Branch once work is substantial**, not for every change. A new module feature, a new
  controller + views + migration, anything spanning multiple files with real design decisions —
  branch it. A small, contained fix (one file, a clear regression, a typo, a docs correction) is
  still fine committed straight to `main`, same as this project has always done.
- **Naming**: `feature/<slug>`, `fix/<slug>` (a multi-file bugfix substantial enough to branch),
  or `chore/<slug>` (release prep, dependency bumps, tooling). `<slug>` is short and kebab-case,
  named for what it does — `feature/client-reports-templates`, not `feature/gemini-9-8` or
  `feature/aaron-wip`.
- **One feature, one branch, one PR.** Don't stack unrelated work onto a branch that's already
  open for something else — open a second branch instead.
- **Before opening a PR**, run the same quality trifecta as always (see below) — CI
  (`.github/workflows/tests.yml`) enforces it regardless, but catching it locally first saves a
  round-trip.
- **Merge via squash** — the whole branch collapses into one clean commit on `main`, matching how
  this project's history already reads (one commit per logical feature/fix). Delete the branch
  once merged (`gh pr merge --squash --delete-branch`). If a genuine reason comes up to preserve a
  branch's individual commits instead, that's a case-by-case call, not the default.
- **Multiple agents share this checkout.** Claude and Gemini (and any future assistant) may both
  be working in the same local clone at overlapping times — before creating a branch or
  committing, run `git status` and `git fetch && git log --oneline main..origin/main` first.
  Don't branch off a dirty working tree that has someone else's in-progress, uncommitted work
  mixed into it — if you find one, stash it or ask before touching it rather than assuming it's
  abandoned or folding it into your own commit.

## Before you open a PR

```bash
./vendor/bin/pint       # code style — run this, don't hand-format
composer phpstan        # static analysis (Larastan, level 5) — must be clean
./vendor/bin/pest       # test suite (in-memory SQLite, no setup needed)
```

All three run in CI-equivalent form (`.github/workflows/tests.yml`); a PR with a failing one of these won't merge as-is. Pint's rules are opinionated but non-negotiable — don't fight it, just run it.

Tests are written in [Pest](https://pestphp.com) — `describe()`/`it()` blocks and `expect()` assertions, not raw PHPUnit assertion methods, even though the underlying runner is PHPUnit and a handful of pre-Pest test classes still exist. See `tests/Feature/ArchitectureTest.php` for the structural rules the suite enforces (no debug statements, `env()` only in `config/`, every module implements its contract, etc.) and `database/factories/` for every model's factory — `SiteFactory` in particular has `spinupwp()`/`pressable()`/`wpEngine()`/`kinsta()`/`cloudways()` states since `Site` has multiple, mutually-exclusive shapes depending on `hosting_provider`.

## Adding a new cloud or hosting provider

This is the contribution shape the module system exists for. Two contracts:

- **`Modules\Core\Contracts\CloudProvider`** — IaaS providers (droplet/VM metrics, size tiers, IP↔server matching). See `modules/Azure/` for a complete, relatively thin example, or `modules/DigitalOcean/` for one with more surface area.
- **`Modules\Core\Contracts\HostingProvider`** — WordPress hosting backends (SSH access, cert sync, performance scanning, orphan detection — gated through `supports(HostingProvider::CAP_*)` since capabilities genuinely differ between providers). See `modules/Pressable/` (no SSH, API + async commands only), `modules/WPEngine/` or `modules/Kinsta/` (real per-site SSH but no `Server` row — `CAP_SSH` still `false`, since that capability is specifically about a *tracked Server row*, not "does SSH exist somewhere"), or `modules/SpinupWp/` (full SSH + a real `Server` row, formerly the entire app's assumption before this contract existed).

A module isn't limited to implementing one contract. **`modules/Cloudways/`** implements both — it provisions real servers (so it needs `CloudProvider` for metrics/reconciliation) that also host multiple WordPress sites each (so it needs `HostingProvider` too). `ModuleServiceProvider`'s `cloudProvider()`/`hostingProvider()` hooks are independent and null-default in the base class specifically so a module can override either, both, or neither.

Every module is a real, standalone Composer package wired in locally via a path repository — no separate git repo, no registry, just `modules/{Name}/` with its own minimal `composer.json` (`name`, `require: {"php": "...", "clockwork/core": "@dev"}`, `autoload.psr-4: {"Modules\\{Name}\\": "src/"}`). The root `composer.json` adds a `{"type": "path", "url": "modules/{Name}", "options": {"symlink": true}}` entry to `repositories` and `"clockwork/{name}": "@dev"` to `require` — `composer update clockwork/{name}` locks and symlinks it in. This is what makes an eventual separate-repo split a copy-and-tag operation rather than a rewrite.

Every module:
1. Lives under `modules/{Name}/src/`, wired in as above.
2. Has a `{Name}ServiceProvider extends Modules\Core\ModuleServiceProvider`, registered in `bootstrap/providers.php` after `Modules\Core\CoreServiceProvider`.
3. Implements `manifest(): ModuleManifest` (id, name, description, credential fields — this alone gets you a row on `/settings/integrations` with zero other wiring).
4. Optionally overrides `cloudProvider()`, `hostingProvider()`, `smsNotifier()`, `diagnosticCheck()`, `scheduledTasks(Schedule $schedule)`, `navItems()` — whichever it contributes. None are required; a module can contribute just credential fields if that's all it needs.

Nothing in core app code (`DiagnosticsController`, `IntegrationCredentialsController`, `CloudProviderRegistry`, `HostingProviderRegistry`, the scheduler) needs editing to add a module — that's the whole point.

### Community & 3rd-Party Modules: Build & Get Listed!

We want agencies to extend Clockwork Control for their own stacks (e.g., RunCloud, GridPane, Enhance, Scaleway, AWS Lightsail, PagerDuty). If you build a module:
1. Publish it as an open-source repository on GitHub or Packagist.
2. Tag your GitHub repository with the topic `clockworkcontrol-module`.
3. Open a Pull Request or Issue with `[3rd-Party Module] {Name}` to be indexed in [`https://clockworkcontrol.com/api/modules.json`](https://clockworkcontrol.com/api/modules.json).
4. We audit and list it in the official **Community & 3rd-Party Modules Directory** on [clockworkcontrol.com](https://clockworkcontrol.com/modules) and in the in-app Module Directory (`/settings/modules`), with full credit to your agency and a direct link to your repo!

See the complete guide and code examples in [`resources/docs/getting-started/contributing.md`](resources/docs/getting-started/contributing.md#building-3rd-party--community-modules).

## Adding a new notification channel

A third contribution shape, alongside cloud/hosting providers — notification channels are how an agency picks *which* alerting tool(s) they actually use, without unused ones baked into core app code. Two different resolution models, matched to two different real-world needs:

- **Fan-out channels** (`App\Services\Chat\ChatNotifier`) — an agency might reasonably want more than one active at once (Mattermost *and* Slack during a migration between tools, say). A module implements `ChatNotifier` and tags its class into the `'clockwork.notifiers'` container tag from its own `register()` — `App\Services\Chat\ChatNotifierDispatcher` fans every call out to whatever's tagged, and every real implementation already self-gates on its own `enabled` config, so it's safe to have several active simultaneously. See `modules/Mattermost/` and `modules/Slack/` — both are thin subclasses of the shared `Modules\Core\Support\WebhookChatNotifier` base (the two channels' actual posting/toggle logic is ~85% identical; only the config namespace and a couple of log strings differ per channel), and `modules/ClientSlack/` for a module contributing a channel with a narrower event subset.
- **Single-vendor channels** (`Modules\Core\Contracts\SmsNotifier`) — some notification types realistically have exactly one active vendor (an agency doesn't run two SMS providers side by side). A module overrides `smsNotifier(): ?SmsNotifier` instead of tagging; `Modules\Core\ModuleRegistry::smsNotifiers()` collects whichever modules return non-null, and the container binds the interface to the first one found, or to `Modules\Core\NullSmsNotifier` (every method a safe no-op) if no such module is installed at all — the same null-object shape `NullCloudProvider` already established for cloud providers. See `modules/Twilio/`.

Either way, core app code (`UptimeStateUpdater`, `NotificationSettingsController`, etc.) depends only on the interface, never a concrete vendor class — an agency leaves a module out of `composer.json` and the corresponding channel simply isn't there, with no dead settings page or half-wired integration left behind (each module owns its own settings controller/routes/nav entry too, following the same "full module ownership" pattern as `modules/BillCom`).

## Bill.com and ClientSlack

Two modules worth calling out because they started as one operator's specific need before proving generically useful enough to ship as regular modules, same as everything else in `modules/`:

- **`modules/BillCom/`** (`Modules\BillCom\*`) — ties `care_plan_enabled` to Bill.com's customer/invoice data. Gated behind `CLOCKWORK_BILL_COM_ENABLED`, off by default; safe to ignore or remove the module entirely if you don't use Bill.com.
- **`modules/ClientSlack/`** (`Modules\ClientSlack\ClientSlackNotifier`) — sends a subset of alerts to a per-site Slack webhook a client configures themselves in wp-admin.

Both are wired in exactly like any other module — see `modules/BillCom/src/BillComServiceProvider.php`.

## Why the Installer Isn't a Module

The Web-Based Installer (`/install`, living at `app/Installer/`, `app/Http/Controllers/InstallerController.php`, and `routes/install.php`) is deliberately built directly into application core rather than modeled as a Composer package under `modules/Installer/`.

Every module in `modules/{Name}/` extends `Modules\Core\ModuleServiceProvider`, which boots against an active database connection and relies on `AppSetting` / config storage. The installer's entire reason to exist is to run *before* the database, encryption keys, or authentication tables are configured. Trying to shoehorn pre-flight installation into a module creates circular dependencies against an unconfigured environment.

Additionally, the installer is permanent and mandatory for self-hosted instances rather than an optional or swappable third-party integration. The architectural rule in `tests/Feature/ArchitectureTest.php` ensures `App\Installer` classes never import `Modules\*`.

## Code style notes beyond what Pint enforces

- No comments explaining *what* code does — name things so it's obvious. A comment earns its place by explaining a non-obvious *why*: a workaround, a hidden constraint, something that would surprise a future reader.
- Don't add abstractions ahead of a second real use case. Three similar lines beat a premature helper.
- Match the existing doc style if you touch `resources/docs/` — see [`resources/docs/_conventions.md`](resources/docs/_conventions.md). The docs site has its own staleness/coverage checkers (`/docs`, when logged in, shows flagged pages); a PR that changes documented behavior should update the page that documents it.

## Questions

Open an issue, or check the in-app docs at `/docs` (rendered from `resources/docs/` — same content, easier to browse once the app's running).
