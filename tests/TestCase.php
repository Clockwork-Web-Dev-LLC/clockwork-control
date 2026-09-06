<?php

namespace Tests;

use App\Support\SsrfGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every outbound domain/IP fetch this app makes (RDAP lookups, SEO
        // robots.txt/homepage checks) resolves DNS itself first as an SSRF
        // mitigation (see App\Support\SsrfGuard) — fake it here so no
        // Feature test ever depends on real network access or a given test
        // domain actually being registered. Tests exercising the guard's
        // real rejection logic live in tests/Unit/Support/SsrfGuardTest.php,
        // which never boots the app and is unaffected by this.
        SsrfGuard::fake();
    }
}
