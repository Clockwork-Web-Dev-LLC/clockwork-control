---
title: Testing & Quality Standards Playbook
section: Internal
order: 20
updated: 2026-09-27
author: Aaron Reimann
tags: [developer, testing, pest, quality, phpunit, architecture]
---

# Testing & Quality Standards Playbook

Clockwork Control protects hundreds of client WordPress sites and critical server infrastructure. Our test suite is designed to be lightning fast, thoroughly isolated, and run on every commit.

---

## 1. Running the Test Suite

We use [Pest PHP v4](https://pestphp.com/) backed by PHPUnit 12.

```bash
# Run the entire test suite
composer test

# Run a specific test file
vendor/bin/pest tests/Feature/SitesTest.php

# Run tests matching a specific description filter
vendor/bin/pest --filter="gatekeeper"

# Run architecture invariant tests
vendor/bin/pest tests/Feature/ArchitectureTest.php
```

All 2,300+ tests complete in under 2 minutes locally and run in parallel in CI.

---

## 2. Testing Invariants & Architecture Tests

We enforce structural code quality with `tests/Feature/ArchitectureTest.php`. These tests run in milliseconds and verify:

1. **No Debug Statements**: Catches forgotten `dd()`, `dump()`, `var_dump()`, `ray()`, or `print_r()` before code can be merged.
2. **Configuration Hygiene**: Guarantees `env()` is only called inside `config/*.php` files. Application code must always use `config('...')`.
3. **Module Contracts**: Ensures all 31 provider modules and feature modules (like `Gatekeeper` and `BackupRelay`) properly extend `Modules\Core\ModuleServiceProvider`.
4. **Command & Job Contracts**: Verifies all console commands extend Laravel's `Command` and queued jobs implement `ShouldQueue`.

---

## 3. Mocking Server & Network Boundaries

Never run real SSH commands or make live external HTTP calls in automated tests. Use the built-in test doubles:

### Mocking SSH Execution
Never invoke real SSH commands in automated tests. `SshClient::exec(Server, string)` and `SiteCommandRunner::run(Site, string)` return strings (stdout) directly. Mock them via Mockery or `$this->mock()`:

```php
use App\Models\Server;
use App\Services\Ssh\SshClient;
use Modules\Core\Contracts\SiteCommandRunner;

// Mock low-level server SSH execution:
$this->mock(SshClient::class, function ($mock) {
    $mock->shouldReceive('exec')
        ->once()
        ->with(\Mockery::type(Server::class), \Mockery::pattern('/fail2ban-client/'), \Mockery::any())
        ->andReturn("1\n");
});

// Or mock high-level site command runner:
$this->mock(SiteCommandRunner::class, function ($mock) {
    $mock->shouldReceive('run')
        ->once()
        ->with(\Mockery::type(\App\Models\Site::class), 'wp core version', null)
        ->andReturn("6.5.2\n");
});
```

### Mocking External HTTP & WordPress Companion
Use Laravel's `Http::fake()`:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'https://example.com/wp-json/clockwork/v1/health' => Http::response([
        'status' => 'ok',
        'version' => '1.8.1',
    ], 200),
]);
```

---

## 4. Code Quality & Formatting Gate

Before submitting any commit or opening a PR, always run:

```bash
composer gate
```

This runs:
1. `vendor/bin/pint --test` (PHP code style)
2. `composer phpstan` (PHP static analysis)
3. `npm run lint` (Biome frontend linter)
4. `npm run typecheck` (TypeScript compiler)
5. `composer test` (Full Pest test suite)

If `composer gate` passes cleanly, your code is guaranteed to merge without breaking CI or fleet operations.
