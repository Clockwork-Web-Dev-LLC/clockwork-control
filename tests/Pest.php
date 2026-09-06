<?php

use App\Support\IssueCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests get Laravel's TestCase (HTTP calls, `actingAs`, etc.) plus
| RefreshDatabase — cheap even for the handful of Feature tests that don't
| touch the DB at all, so every Feature test gets a consistent, isolated
| sqlite schema without having to remember the trait per file.
|
| Unit tests get plain PHPUnit\Framework\TestCase (Pest's default) — no
| Laravel bootstrapping, no DB, for pure-function tests.
*/

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| Project-specific expectation extensions go here as they're needed. None
| yet — Pest's built-in expect() API covers everything written so far.
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| Global helpers usable from any Pest test file. See
| Tests\Concerns\RendersAuthenticatedPages for the equivalent trait used by
| the PHPUnit-style test classes that predate Pest.
*/

/**
 * AppServiceProvider's `layouts.app` view composer calls IssueCounter::total()
 * on every authenticated page render. Two of its queries use MySQL-only
 * JSON_EXTRACT/JSON_UNQUOTE/CAST(...AS UNSIGNED) syntax the sqlite test DB
 * can't parse. Call this before hitting any authenticated route.
 */
function mockIssueCounterZero(): void
{
    test()->partialMock(IssueCounter::class, function ($mock) {
        $mock->shouldReceive('total')->andReturn(0);
    });
}
