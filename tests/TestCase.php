<?php

namespace Tests;

use App\Services\Process\DetachedShell;
use App\Support\SsrfGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Process;

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

        Process::preventStrayProcesses();
        Process::fake([
            'git *' => Process::result(output: 'main', exitCode: 0),
        ]);

        // BackgroundArtisan launches detached `php artisan` jobs through a raw
        // exec() that Process::fake() can't see. Swallow them so no Feature
        // test can start a real background process; tests that assert the
        // launched command mock DetachedShell or BackgroundArtisan themselves.
        $this->instance(DetachedShell::class, new class extends DetachedShell
        {
            public function run(string $command): void {}
        });
    }
}
