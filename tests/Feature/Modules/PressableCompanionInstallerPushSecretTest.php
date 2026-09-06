<?php

use App\Services\Companion\CompanionTarballBuilder;
use Mockery\MockInterface;
use Modules\Pressable\PressableClient;
use Modules\Pressable\PressableCommandRunner;
use Modules\Pressable\PressableCompanionInstaller;

/*
|--------------------------------------------------------------------------
| PressableCompanionInstaller::pushBootstrapSecret() — command-boundary
| coverage
|--------------------------------------------------------------------------
|
| Mirrors CompanionInstallerPushSecretTest.php (the SSH-transport twin) —
| this class's own internals are otherwise fully mocked wherever it's used
| (see InstallCompanionPressableTest.php's docblock). Regression coverage
| for the same real bug, found live 2026-09-03 on centerclient.example: the
| SQL upsert and the cache flush used to be chained with `&&` into one
| command, so a `wp cache flush` failure failed the whole secret push even
| though the SQL write had already succeeded — reported as "Pressable
| command failed (exit 1): Success: Query succeeded. Rows affected: 1",
| which misleadingly looks like the successful step failed.
*/

function pushBootstrapSecretHarness(MockInterface $runner): array
{
    $installer = new PressableCompanionInstaller(
        $runner,
        Mockery::mock(CompanionTarballBuilder::class),
        Mockery::mock(PressableClient::class),
    );

    $method = new ReflectionMethod(PressableCompanionInstaller::class, 'pushBootstrapSecret');
    $method->setAccessible(true);

    return [$installer, $method];
}

it('does not chain cache flush into the same command as the SQL upsert', function () {
    $runner = Mockery::mock(PressableCommandRunner::class);

    $runner->shouldReceive('runOrFail')
        ->withArgs(fn ($psId, string $cmd) => str_contains($cmd, 'config get table_prefix'))
        ->andReturn('wp_');

    $runner->shouldReceive('runOrFail')
        ->withArgs(fn ($psId, string $cmd) => str_contains($cmd, 'db query') && ! str_contains($cmd, 'cache flush'))
        ->andReturn('Success: Query succeeded. Rows affected: 1');

    $runner->shouldReceive('runOrFail')
        ->once()
        ->withArgs(fn ($psId, string $cmd) => str_contains($cmd, 'cache flush') && ! str_contains($cmd, 'db query'))
        ->andReturn('');

    [$installer, $method] = pushBootstrapSecretHarness($runner);

    $secret = $method->invoke($installer, '12345');

    expect($secret)->toBeString()->and(strlen($secret))->toBe(64);
});

it('does not fail the secret push when only the cache flush fails', function () {
    $runner = Mockery::mock(PressableCommandRunner::class);

    $runner->shouldReceive('runOrFail')
        ->withArgs(fn ($psId, string $cmd) => str_contains($cmd, 'config get table_prefix'))
        ->andReturn('wp_');

    $runner->shouldReceive('runOrFail')
        ->withArgs(fn ($psId, string $cmd) => str_contains($cmd, 'db query'))
        ->andReturn('Success: Query succeeded. Rows affected: 1');

    // The cache flush fails on its own — no active object cache, say —
    // and the failure must not propagate out of pushBootstrapSecret().
    $runner->shouldReceive('runOrFail')
        ->withArgs(fn ($psId, string $cmd) => str_contains($cmd, 'cache flush'))
        ->andThrow(new RuntimeException('Pressable command failed (exit 1): Error: cache flush failed.'));

    [$installer, $method] = pushBootstrapSecretHarness($runner);

    $secret = $method->invoke($installer, '12345');

    expect($secret)->toBeString()->and(strlen($secret))->toBe(64);
});
