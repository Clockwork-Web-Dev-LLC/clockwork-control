# Testing conventions

This suite is [Pest](https://pestphp.com): BDD-style `describe()`/`it()` blocks and `expect()` assertions. A handful of pre-Pest `PHPUnit\Framework\TestCase` classes still exist from before the suite was converted (e.g. `tests/Feature/ProcessServerUpdatesTest.php`) — Pest runs them unchanged, and new tests should be Pest-style, not PHPUnit-style.

Run it with:

```bash
vendor/bin/pest                          # full suite
vendor/bin/pest tests/Feature/Console     # one directory
vendor/bin/pest --filter="fires ipBlocked" # by test name
vendor/bin/pest --coverage --min=0        # with coverage (needs pcov or Xdebug installed)
```

This file documents the conventions the suite already follows, so new tests read and feel the same as existing ones. If you're about to write a test, skim the closest existing file in the same directory first — the pattern is almost always already established.

## Directory layout

Mirrors `app/`'s own shape, not a flat pile:

| Directory | What |
|---|---|
| `tests/Feature/Controllers/` | One file per controller — HTTP-level: auth-gate, happy path, validation errors. |
| `tests/Feature/Console/` | One file per `clockwork:*` command — CLI entry point + behavior. |
| `tests/Feature/Models/` | `Site`/`Server` computed state, scopes, relationships, factory round-trips. |
| `tests/Feature/Modules/` | `HostingProvider`/`CloudProvider` capability matrices, registry aggregation, module diagnostic checks. |
| `tests/Feature/Diagnostics/` | The core-app `DiagnosticCheck` classes under `app/Services/Diagnostics/Checks/`. |
| `tests/Feature/Jobs/`, `Uptime/`, `Security/`, `Backups/`, `BillCom/`, `Companion/`, `Chat/`, `Ssl/`, `Sites/`, `Forms/` | Deep coverage for the highest-risk business logic — update pipeline, uptime state machine, security scans, backup retention, billing sync, Companion auth, notification call sites, SSL state transitions. |
| `tests/Fixtures/` | Reusable realistic HTTP-fake payloads, one file per external integration (see below). |
| `tests/Concerns/` | Shared traits — currently just `RendersAuthenticatedPages`. |
| `tests/Pest.php` | Global config: base `TestCase` + `RefreshDatabase` for every `Feature` test, plus global helper functions. |

A new controller, command, or module gets a new file in the matching directory — don't append to an unrelated existing file just because it's nearby.

## The database

Every `Feature` test gets `RefreshDatabase` automatically (wired once in `tests/Pest.php`, not per-file) against an in-memory SQLite database — no setup needed, no shared state between tests.

**SQLite ≠ MySQL.** A few services run raw MySQL-only SQL (`JSON_EXTRACT`/`JSON_UNQUOTE`, `REGEXP`, index hints, `TIMESTAMPDIFF`, `HOUR()`) that SQLite can't parse. The most common one you'll hit: `AppServiceProvider`'s `layouts.app` view composer calls `App\Support\IssueCounter::total()` on **every authenticated page render**. Before hitting any authenticated route:

```php
// Pest-style file:
mockIssueCounterZero();   // global helper, defined in tests/Pest.php

// Pre-Pest PHPUnit-style class:
use Tests\Concerns\RendersAuthenticatedPages;
// ... use RendersAuthenticatedPages;  then  $this->mockIssueCounterZero();
```

If a new test hits `RollupTraffic`, `RefreshCompanionSnapshot`, or `WarmWeirdStats`'s underlying aggregator directly and trips a similar raw-SQL error, the fix is the same shape: `partialMock()` the offending method rather than trying to make SQLite understand MySQL syntax.

## Model factories

Every model has a factory (`database/factories/`). `Site` is the one with real branching — it has a distinct state per hosting provider, since `hosting_provider` drives two structurally different row shapes (a real `server_id` FK vs. none at all):

```php
Site::factory()->spinupwp()->create();   // server_id set, spinupwp_id set
Site::factory()->pressable()->create();  // server_id null, pressable_site_id set
Site::factory()->wpEngine()->create();   // server_id null, wpengine_install_name set
Site::factory()->kinsta()->create();     // server_id null, kinsta_environment_id set
Site::factory()->cloudways()->create();  // server_id set (a real Cloudways-tagged Server), cloudways_app_id set
```

`Server::factory()` has a matching `->cloudways()` state (and one per `CloudProvider`, e.g. `->digitalOcean()`, `->hetzner()`, `->azure()`). Check a factory's own file for available states before hand-rolling attribute overrides in a test — most of what you need already exists.

## Never let real I/O happen

Nothing in this suite should ever attempt a real HTTP request, SSH connection, or subprocess exec. Every external client/transport gets mocked or faked at the real boundary. **The one narrow, documented exception**: `tests/Feature/Diagnostics/TwilioCheckTest.php` makes a real round-trip to Twilio's live API — the Twilio SDK hard-instantiates its own cURL-based transport with no injectable seam and no container binding, so `Http::fake()` can't intercept it without touching `app/` code (read that test's own docblock for the full reasoning). It's tagged `->group('network')` and excluded from CI's default run (`--exclude-group=network`) so CI itself stays fully hermetic; run the full suite without that flag locally if you want the extra confidence. If you ever find a genuine seam to make this mockable without changing app code, remove the group tag and this exception.

**HTTP-based integrations** — `Http::fake([...])`, ideally reusing an existing fixture from `tests/Fixtures/*Fixtures.php` (Azure, Cloudflare, Companion, DigitalOcean, Gtmetrix, Hetzner, PageSpeedInsights, Pressable, SpinupWp) rather than hand-rolling a new payload:

```php
Http::fake([
    'api.hetzner.cloud/v1/servers/*/metrics*' => Http::response(HetznerFixtures::metricsResponse(50.0)),
]);
```

**SSH-based transports** (`App\Services\Ssh\SshClient`, `Modules\Core\Support\SshConnector`-based command runners) — mock the class directly:

```php
$this->mock(SshClient::class, fn ($mock) => $mock->shouldReceive('exec')->andReturn("output\n__SENTINEL__:0"));
```

Match whatever sentinel/exit-code convention the real caller expects — read the source first, several classes append `echo "SENTINEL:$?"` to recover an exit code from a raw string.

**Service-class collaborators** (anything injected via the constructor, e.g. `ChatNotifier`, `Fail2banClient`, `CompanionInstaller`, a provider's own `*Client`) — `$this->mock(ClassName::class, fn ($mock) => $mock->shouldReceive('method')->once()->with(...)->andReturn(...))`. This is the single most common pattern in the suite. Always assert both directions where relevant: `->once()` (or `->times(N)`) for "this must fire," `->never()` (or `shouldNotReceive(...)`) for "this must NOT fire under this condition" — a test that only checks the happy path can't catch a regression that makes something fire unconditionally.

**Filesystem** — `Storage::fake('disk-name')` for anything going through Laravel's Storage/Flysystem abstraction (e.g. DigitalOcean Spaces). Real temp-directory writes are fine for a check whose entire job is confirming real filesystem access works (`StorageWritableCheck`) — don't mock the thing you're testing.

## Testing an Artisan command

```php
$this->artisan('clockwork:command-name', ['--option' => 'value'])->assertSuccessful();
// or ->assertExitCode(N) for a deliberate non-zero exit
```

Mock every constructor/`handle()`-injected collaborator the command touches. See `tests/Feature/Console/` for dozens of examples across every shape this app has: single-site commands, fleet-sweep commands, report-pushers, connectivity checks.

## Testing a controller

```php
$this->actingAs(User::factory()->create())
    ->post(route('servers.ban', $server), ['ip' => '198.51.100.1'])
    ->assertRedirect();
```

- Every app route except `/login` and the two `/auth/google/*` routes sits behind the `auth` middleware group — always `actingAs()`.
- CSRF needs no special handling in tests — Laravel's test client disables it automatically.
- Use `route('name', $params)`, never a hardcoded path.
- An "auth-gate" test (one per controller is enough) confirms an unauthenticated request redirects rather than 200ing.
- Cover the real validation/rejection path, not just the happy path — read the controller for what it actually does on bad input (a controller's own early-return isn't always a standard Laravel 422; some redirect back with a flash message instead).

## Mocking `ChatNotifier`

`ChatNotifierDispatcher`'s own gating logic (`is_inactive` short-circuiting "routine maintenance" events, never gating "active incident" events) is fully covered in `tests/Feature/Chat/ChatNotifierGatingTest.php` — don't re-test that when you're testing a new call site. What's usually still worth testing per call site: does the business logic actually call `ChatNotifier` (or the right method on it) exactly when it should, and stay silent when it shouldn't:

```php
$this->mock(ChatNotifier::class, function ($mock) use ($site) {
    $mock->shouldReceive('sslStateChanged')
        ->once()
        ->withArgs(fn (Site $s, string $from, string $to) => $s->is($site) && $from === 'green' && $to === 'renewal_needed')
        ->andReturn(true);
});
```

## Architecture tests

`tests/Feature/ArchitectureTest.php` (via `pestphp/pest-plugin-arch`) enforces structural invariants — no debug statements, `env()` only in `config/`, every module's `HostingProvider`/`CloudProvider`/`ServiceProvider` implements its contract, console commands extend the base `Command`, etc. **When you add a new module, add it to the relevant `arch()` expectation lists here** — this file does not discover modules automatically, and it silently stops checking anything you forget to add (this happened once already: the WP Engine/Kinsta/Cloudways modules shipped without being added here, and the gap sat unnoticed until Phase 8 found it).

## Secrets in test fixtures

The repo's pre-commit hook runs `gitleaks` on every commit. A fixture value that happens to *look* like a secret (a PEM private-key marker, a token-shaped random string) can trip it even though it's clearly fake. If you hit a `BLOCKED: gitleaks found a likely secret` error on a genuinely fake fixture:

1. Confirm by hand it's actually safe (no real credential ever belongs in a test file, even "for realism").
2. Add an exact-fingerprint entry to `.gitleaksignore` (never a blanket path/rule exception) with a comment explaining why it's safe — see the existing entries for the format.

## Verification bar for any new test file

Before considering a test done:

```bash
vendor/bin/pest {your file}     # must pass for real, not just "should work"
vendor/bin/pint --test          # style — fix with vendor/bin/pint {file} if it fails
vendor/bin/phpstan analyse --memory-limit=1G   # expect exactly 30 pre-existing errors, unrelated to tests/ — don't chase these down unless asked
```

A test that was never actually run is not verified — `assertTrue(true)` and a test that silently never executes its assertions both "pass" without proving anything.

## Coverage

CI runs `vendor/bin/pest --coverage --min=0 --coverage-clover=coverage.xml` on every push to `main` (via `pcov`, not Xdebug — coverage-only, no step-debugger overhead) and posts the percentage to the job summary. `--min=0` means coverage is reported, not gate-kept — there's no enforced floor today. The README's coverage badge reads `.github/badges/coverage.json`, written back by the same CI run on pushes to `main` only (not PRs) using the default `GITHUB_TOKEN`, no extra secret required.

To generate coverage locally you need a coverage driver installed (`pcov` or `xdebug`) — neither ships with a stock PHP install. Without one, `--coverage` fails with a clear error naming what's missing.

## What's not done yet

Mutation testing (Pest's mutation-testing plugin, `--mutate`) is a real, higher-value-than-coverage signal for a suite this size, but wasn't set up as part of this build-out — noted as a stretch goal, not committed to, given its cost relative to the coverage already delivered by the phases that built this suite (see git log for `Tests Phase 0` through `Tests Phase 7`).
