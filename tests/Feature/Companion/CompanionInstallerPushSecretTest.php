<?php

use App\Models\Server;
use App\Models\Site;
use App\Services\Companion\CompanionInstaller;
use App\Services\Companion\CompanionTarballBuilder;
use App\Services\Ssh\SshClient;
use Mockery\MockInterface;

/*
|--------------------------------------------------------------------------
| CompanionInstaller::pushSecret() — SSH command-boundary coverage
|--------------------------------------------------------------------------
|
| Every other Companion-install test mocks CompanionInstaller wholesale
| (see InstallCompanionTest.php's docblock) — this is the one place its
| actual wp-cli command construction is exercised, via reflection since
| pushSecret() is private. Regression coverage for a real bug found live
| 2026-09-03: the SQL upsert and the cache flush used to be chained with
| `&&` into one command, so a `wp cache flush` failure (seen on a site
| with no active object cache) failed the whole secret push even though
| the SQL write had already succeeded — and its own error was invisible,
| swallowed into /dev/null behind the db query's unrelated success text.
*/

function pushSecretHarness(MockInterface $ssh): array
{
    $server = Server::factory()->create(['ssh_password' => 'sudo-pw']);
    $site = Site::factory()->for($server, 'server')->create([
        'site_user' => 'siteuser',
        'table_prefix' => 'wp_',
    ]);

    $installer = new CompanionInstaller($ssh, Mockery::mock(CompanionTarballBuilder::class));

    $method = new ReflectionMethod(CompanionInstaller::class, 'pushSecret');
    $method->setAccessible(true);

    return [$installer, $method, $site];
}

function sentinelOutput(string $output, int $exit): string
{
    return $output."\n__CLOCKWORK_COMPANION_EXIT__:{$exit}";
}

it('does not chain cache flush into the same command as the SQL upsert', function () {
    $ssh = Mockery::mock(SshClient::class);

    $ssh->shouldReceive('exec')
        ->withArgs(fn (Server $server, string $cmd) => str_contains($cmd, 'option get clockwork_companion_secret'))
        ->andReturn(sentinelOutput('mismatched-secret', 0));

    $ssh->shouldReceive('exec')
        ->withArgs(fn (Server $server, string $cmd) => str_contains($cmd, 'config get table_prefix'))
        ->andReturn(sentinelOutput('wp_', 0));

    $ssh->shouldReceive('exec')
        ->withArgs(fn (Server $server, string $cmd) => str_contains($cmd, 'db query') && ! str_contains($cmd, 'cache flush'))
        ->andReturn(sentinelOutput('Success: Query succeeded. Rows affected: 1
OK: secret upserted', 0));

    $ssh->shouldReceive('exec')
        ->once()
        ->withArgs(fn (Server $server, string $cmd) => str_contains($cmd, 'cache flush') && ! str_contains($cmd, 'db query'))
        ->andReturn(sentinelOutput('', 0));

    [$installer, $method, $site] = pushSecretHarness($ssh);

    $result = $method->invoke($installer, $site, '/sites/example.com/files', 'the-real-secret');

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toContain('OK: secret upserted');
});

it('does not fail the secret push when only the cache flush fails', function () {
    $ssh = Mockery::mock(SshClient::class);

    $ssh->shouldReceive('exec')
        ->withArgs(fn (Server $server, string $cmd) => str_contains($cmd, 'option get clockwork_companion_secret'))
        ->andReturn(sentinelOutput('mismatched-secret', 0));

    $ssh->shouldReceive('exec')
        ->withArgs(fn (Server $server, string $cmd) => str_contains($cmd, 'config get table_prefix'))
        ->andReturn(sentinelOutput('wp_', 0));

    $ssh->shouldReceive('exec')
        ->withArgs(fn (Server $server, string $cmd) => str_contains($cmd, 'db query'))
        ->andReturn(sentinelOutput('Success: Query succeeded. Rows affected: 1
OK: secret upserted', 0));

    // The cache flush fails on its own — no active object cache, say —
    // and the failure must not propagate to the overall result.
    $ssh->shouldReceive('exec')
        ->withArgs(fn (Server $server, string $cmd) => str_contains($cmd, 'cache flush'))
        ->andReturn(sentinelOutput('Error: cache flush failed.', 1));

    [$installer, $method, $site] = pushSecretHarness($ssh);

    $result = $method->invoke($installer, $site, '/sites/example.com/files', 'the-real-secret');

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toContain('OK: secret upserted');
});
