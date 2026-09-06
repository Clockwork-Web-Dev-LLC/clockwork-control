<?php

use App\Services\Chat\ChatNotifier;

/*
|--------------------------------------------------------------------------
| ComposerAudit — mocks the `composer` binary itself, never runs a real one
|--------------------------------------------------------------------------
|
| The command shells out via a raw `new Process([$composer, 'audit', ...])`
| (Symfony Process, not the Laravel Process facade), and resolveComposer()
| picks the binary up either from a hardcoded Herd path under $HOME or by
| asking the shell `command -v composer`. Rather than mock Process (which
| would require touching app/ code to inject it), these tests override HOME
| and PATH for the duration of each test so resolveComposer() finds a stub
| script we control instead of any real composer install -- the stub just
| echoes canned JSON and exits with a chosen code, so `composer audit`
| itself is never actually invoked and no real network/Packagist call ever
| happens. HOME and PATH are restored in afterEach.
*/

function composerAuditStub(string $json, int $exitCode): string
{
    $dir = sys_get_temp_dir().'/composer-audit-stub-'.uniqid();
    mkdir($dir);

    $script = $dir.'/composer';
    file_put_contents($script, "#!/bin/sh\ncat <<'STUBJSON'\n{$json}\nSTUBJSON\nexit {$exitCode}\n");
    chmod($script, 0755);

    return $dir;
}

describe('ComposerAudit', function () {
    beforeEach(function () {
        $this->originalHome = getenv('HOME');
        $this->originalPath = getenv('PATH');

        // Point HOME somewhere with no Herd install so resolveComposer() falls
        // through to `command -v composer` on the (stub-prefixed) PATH below.
        putenv('HOME='.sys_get_temp_dir());
    });

    afterEach(function () {
        putenv('HOME='.$this->originalHome);
        putenv('PATH='.$this->originalPath);
    });

    it('reports a clean audit and does not notify when composer audit finds nothing', function () {
        $stubDir = composerAuditStub(json_encode(['advisories' => [], 'abandoned' => []]), 0);
        putenv('PATH='.$stubDir.':'.$this->originalPath);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldNotReceive('send');
        });

        $this->artisan('clockwork:composer-audit')
            ->expectsOutputToContain('composer.audit advisories=0 abandoned=0')
            ->expectsOutputToContain('No advisories. Dependencies are clean.')
            ->assertSuccessful();
    });

    it('reports advisories, notifies via ChatNotifier, and exits non-zero', function () {
        $payload = [
            'advisories' => [
                'guzzlehttp/guzzle' => [
                    ['cve' => 'CVE-2025-00001', 'title' => 'Fixture advisory', 'affectedVersions' => '<7.5.0'],
                ],
            ],
            'abandoned' => [],
        ];
        $stubDir = composerAuditStub(json_encode($payload), 1);
        putenv('PATH='.$stubDir.':'.$this->originalPath);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('send')
                ->once()
                ->with(Mockery::on(function ($message) {
                    return str_contains($message, 'composer audit')
                        && str_contains($message, '1 advisory')
                        && str_contains($message, 'guzzlehttp/guzzle');
                }))
                ->andReturn(true);
        });

        $this->artisan('clockwork:composer-audit')
            ->expectsOutputToContain('composer.audit advisories=1 abandoned=0')
            ->assertFailed();
    });

    it('suppresses the ChatNotifier call when --silent is passed, even with advisories present', function () {
        $payload = [
            'advisories' => [
                'symfony/http-kernel' => [
                    ['cve' => 'CVE-2025-00002', 'title' => 'Fixture advisory', 'affectedVersions' => '<6.4.0'],
                ],
            ],
            'abandoned' => [],
        ];
        $stubDir = composerAuditStub(json_encode($payload), 1);
        putenv('PATH='.$stubDir.':'.$this->originalPath);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldNotReceive('send');
        });

        $this->artisan('clockwork:composer-audit', ['--silent' => true])->assertFailed();
    });

    it('reports abandoned packages alongside advisories', function () {
        $payload = [
            'advisories' => [],
            'abandoned' => ['some/abandoned-package' => 'some/replacement-package'],
        ];
        $stubDir = composerAuditStub(json_encode($payload), 0);
        putenv('PATH='.$stubDir.':'.$this->originalPath);

        $this->mock(ChatNotifier::class, function ($mock) {
            // advisoryCount is 0, so the command's own `$advisoryCount > 0` guard
            // means no notification fires even without --silent.
            $mock->shouldNotReceive('send');
        });

        $this->artisan('clockwork:composer-audit')
            ->expectsOutputToContain('composer.audit advisories=0 abandoned=1')
            ->expectsOutputToContain('Abandoned packages: some/abandoned-package')
            ->assertFailed();
    });

    it('fails cleanly with no composer binary resolvable on PATH or the Herd fallback', function () {
        // Empty PATH -> `command -v composer` finds nothing, and HOME already
        // points somewhere with no Herd install from beforeEach.
        putenv('PATH=');

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldNotReceive('send');
        });

        $this->artisan('clockwork:composer-audit')
            ->expectsOutputToContain('Could not find a composer binary on PATH.')
            ->assertFailed();
    });
});
