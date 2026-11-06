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

it('extracts Companion into wp-content/plugins/clockwork-companion, cleans up legacy mu-plugins, and activates the plugin', function () {
    $ssh = Mockery::mock(SshClient::class);
    $server = Server::factory()->create(['ssh_password' => 'sudo-pw']);
    $site = Site::factory()->for($server, 'server')->create([
        'site_user' => 'siteuser',
        'table_prefix' => 'wp_',
    ]);

    // 1) Base64 chunk upload to /tmp
    $ssh->shouldReceive('exec')
        ->once()
        ->withArgs(fn (Server $s, string $cmd) => str_starts_with($cmd, 'printf %s '))
        ->andReturn('');

    // 2) Extract script into wp-content/plugins + legacy mu-plugins cleanup + wp plugin activate
    $ssh->shouldReceive('exec')
        ->once()
        ->withArgs(function (Server $s, string $cmd): bool {
            return str_contains($cmd, 'PLUGINS="/sites/example.com/files/wp-content/plugins"')
                && str_contains($cmd, 'MU="/sites/example.com/files/wp-content/mu-plugins"')
                && str_contains($cmd, 'mv .clockwork-stage/clockwork-companion.php .clockwork-stage/clockwork-companion/clockwork-companion.php')
                && str_contains($cmd, 'rm -f "$MU/clockwork-companion.php"')
                && str_contains($cmd, 'plugin activate clockwork-companion');
        })
        ->andReturn(sentinelOutput('OK: companion staged at /sites/example.com/files/wp-content/plugins/clockwork-companion', 0));

    // 3) Cleanup of /tmp staging file
    $ssh->shouldReceive('exec')
        ->once()
        ->withArgs(fn (Server $s, string $cmd) => str_starts_with($cmd, 'rm -f '))
        ->andReturn('');

    $installer = new CompanionInstaller($ssh, Mockery::mock(CompanionTarballBuilder::class));
    $method = new ReflectionMethod(CompanionInstaller::class, 'extractOnRemote');
    $method->setAccessible(true);

    $result = $method->invoke($installer, $site, '/sites/example.com/files', base64_encode('fake-tarball'));

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toContain('wp-content/plugins/clockwork-companion');
});
