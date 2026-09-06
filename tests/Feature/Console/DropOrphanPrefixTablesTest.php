<?php

use App\Models\Server;
use App\Models\Site;
use App\Services\Ssh\SshClient;

/*
|--------------------------------------------------------------------------
| clockwork:drop-orphan-prefix-tables
|--------------------------------------------------------------------------
|
| Every real path shells to the target server via the injected SshClient —
| mocked throughout so this suite never opens a real SSH session or issues
| a real DROP TABLE. The command's own safety rails (dry-run default,
| refusing to touch the live prefix, requiring --force) are what's under
| test; SshClient::exec responses are matched by distinguishing substrings
| in the command text rather than call order, since that's what the
| command actually branches on.
*/

const CLOCKWORK_DROP_SENTINEL = '__CLOCKWORK_DROP_EXIT__';

function dropSentinel(string $output, int $exit = 0): string
{
    return $output === '' ? CLOCKWORK_DROP_SENTINEL.":{$exit}" : "{$output}\n".CLOCKWORK_DROP_SENTINEL.":{$exit}";
}

function dropOrphanSite(array $siteOverrides = [], array $serverOverrides = []): Site
{
    $server = Server::factory()->create(array_merge(['ssh_password' => 'sudo-pass'], $serverOverrides));

    return Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'site_user' => 'siteuser',
        'domain' => 'orphan.example.com',
    ], $siteOverrides));
}

describe('clockwork:drop-orphan-prefix-tables — argument validation', function () {
    it('refuses a prefix with characters outside [a-zA-Z0-9_] without touching SSH', function () {
        $site = dropOrphanSite();

        $this->mock(SshClient::class)->shouldNotReceive('exec');

        $this->artisan('clockwork:drop-orphan-prefix-tables', [
            'site' => $site->domain,
            'prefix' => 'delete-this; DROP TABLE users;',
        ])
            ->expectsOutputToContain('Refusing: prefix')
            ->assertFailed();
    });

    it('fails when no site matches the given id-or-domain', function () {
        $this->mock(SshClient::class)->shouldNotReceive('exec');

        $this->artisan('clockwork:drop-orphan-prefix-tables', [
            'site' => 'no-such-site.example.com',
            'prefix' => 'delete_this_',
        ])
            ->expectsOutputToContain('No site matched')
            ->assertFailed();
    });

    it('fails when the site is missing server/site_user/ssh_password prerequisites', function () {
        $site = dropOrphanSite(['site_user' => null]);

        $this->mock(SshClient::class)->shouldNotReceive('exec');

        $this->artisan('clockwork:drop-orphan-prefix-tables', [
            'site' => $site->domain,
            'prefix' => 'delete_this_',
        ])
            ->expectsOutputToContain('Missing prerequisites')
            ->assertFailed();
    });
});

describe('clockwork:drop-orphan-prefix-tables — live-prefix safety rail', function () {
    it('fails when wp-config.php\'s $table_prefix cannot be read at all', function () {
        $site = dropOrphanSite();

        $this->mock(SshClient::class)->shouldReceive('exec')
            ->once()
            ->withArgs(fn ($server, $cmd) => str_contains($cmd, 'table_prefix'))
            ->andReturn(dropSentinel(''));

        $this->artisan('clockwork:drop-orphan-prefix-tables', [
            'site' => $site->domain,
            'prefix' => 'delete_this_',
        ])
            ->expectsOutputToContain('Could not read $table_prefix')
            ->assertFailed();
    });

    it('refuses to drop a prefix that IS the live wp-config.php prefix', function () {
        $site = dropOrphanSite();

        $this->mock(SshClient::class)->shouldReceive('exec')
            ->once()
            ->withArgs(fn ($server, $cmd) => str_contains($cmd, 'table_prefix'))
            ->andReturn(dropSentinel("\$table_prefix = 'wp_';"));

        $this->artisan('clockwork:drop-orphan-prefix-tables', [
            'site' => $site->domain,
            'prefix' => 'wp_',
        ])
            ->expectsOutputToContain('IS the live prefix')
            ->assertFailed();
    });
});

describe('clockwork:drop-orphan-prefix-tables — listing + dry-run', function () {
    it('reports nothing-to-drop and succeeds when no tables match the orphan prefix', function () {
        $site = dropOrphanSite();
        $ssh = $this->mock(SshClient::class);

        $ssh->shouldReceive('exec')
            ->once()
            ->withArgs(fn ($server, $cmd) => str_contains($cmd, 'table_prefix'))
            ->andReturn(dropSentinel("\$table_prefix = 'wp_';"));
        $ssh->shouldReceive('exec')
            ->once()
            ->withArgs(fn ($server, $cmd) => str_contains($cmd, 'SHOW TABLES LIKE'))
            ->andReturn(dropSentinel(''));

        $this->artisan('clockwork:drop-orphan-prefix-tables', [
            'site' => $site->domain,
            'prefix' => 'delete_this_',
        ])
            ->expectsOutputToContain("No tables match 'delete_this_%'. Nothing to drop.")
            ->assertSuccessful();
    });

    it('fails when the SHOW TABLES wp-cli query itself exits nonzero', function () {
        $site = dropOrphanSite();
        $ssh = $this->mock(SshClient::class);

        $ssh->shouldReceive('exec')
            ->once()
            ->withArgs(fn ($server, $cmd) => str_contains($cmd, 'table_prefix'))
            ->andReturn(dropSentinel("\$table_prefix = 'wp_';"));
        $ssh->shouldReceive('exec')
            ->once()
            ->withArgs(fn ($server, $cmd) => str_contains($cmd, 'SHOW TABLES LIKE'))
            ->andReturn(dropSentinel('db connection error', 1));

        $this->artisan('clockwork:drop-orphan-prefix-tables', [
            'site' => $site->domain,
            'prefix' => 'delete_this_',
        ])
            ->expectsOutputToContain('Could not list tables')
            ->assertFailed();
    });

    it('lists matching tables and defaults to a dry-run, never issuing a DROP', function () {
        $site = dropOrphanSite();
        $ssh = $this->mock(SshClient::class);

        $ssh->shouldReceive('exec')
            ->once()
            ->withArgs(fn ($server, $cmd) => str_contains($cmd, 'table_prefix'))
            ->andReturn(dropSentinel("\$table_prefix = 'wp_';"));
        $ssh->shouldReceive('exec')
            ->once()
            ->withArgs(fn ($server, $cmd) => str_contains($cmd, 'SHOW TABLES LIKE'))
            ->andReturn(dropSentinel("delete_this_options\ndelete_this_posts"));
        // No third expectation is registered for a DROP-TABLE command — since
        // $this->mock() produces a full double (not a partial mock), any call
        // that doesn't match one of the two expectations above throws, which
        // is exactly what proves the dry-run path never issues a DROP.

        $this->artisan('clockwork:drop-orphan-prefix-tables', [
            'site' => $site->domain,
            'prefix' => 'delete_this_',
        ])
            ->expectsOutputToContain('Tables matched (2):')
            ->expectsOutputToContain('Dry-run. Re-run with --force to actually drop.')
            ->assertSuccessful();
    });
});

describe('clockwork:drop-orphan-prefix-tables — --force', function () {
    it('issues the DROP for every matched table and reports success', function () {
        $site = dropOrphanSite();
        $ssh = $this->mock(SshClient::class);

        $ssh->shouldReceive('exec')
            ->once()
            ->withArgs(fn ($server, $cmd) => str_contains($cmd, 'table_prefix'))
            ->andReturn(dropSentinel("\$table_prefix = 'wp_';"));
        $ssh->shouldReceive('exec')
            ->once()
            ->withArgs(fn ($server, $cmd) => str_contains($cmd, 'SHOW TABLES LIKE'))
            ->andReturn(dropSentinel("delete_this_options\ndelete_this_posts"));
        $ssh->shouldReceive('exec')
            ->once()
            ->withArgs(fn ($server, $cmd) => str_contains($cmd, 'DROP TABLE IF EXISTS') && str_contains($cmd, '`delete_this_options`') && str_contains($cmd, '`delete_this_posts`'))
            ->andReturn(dropSentinel(''));

        $this->artisan('clockwork:drop-orphan-prefix-tables', [
            'site' => $site->domain,
            'prefix' => 'delete_this_',
            '--force' => true,
        ])
            ->expectsOutputToContain("Dropped 2 table(s) starting with 'delete_this_' on {$site->domain}.")
            ->assertSuccessful();
    });

    it('fails when the DROP statement itself exits nonzero', function () {
        $site = dropOrphanSite();
        $ssh = $this->mock(SshClient::class);

        $ssh->shouldReceive('exec')
            ->once()
            ->withArgs(fn ($server, $cmd) => str_contains($cmd, 'table_prefix'))
            ->andReturn(dropSentinel("\$table_prefix = 'wp_';"));
        $ssh->shouldReceive('exec')
            ->once()
            ->withArgs(fn ($server, $cmd) => str_contains($cmd, 'SHOW TABLES LIKE'))
            ->andReturn(dropSentinel('delete_this_options'));
        $ssh->shouldReceive('exec')
            ->once()
            ->withArgs(fn ($server, $cmd) => str_contains($cmd, 'DROP TABLE IF EXISTS'))
            ->andReturn(dropSentinel('permission denied', 1));

        $this->artisan('clockwork:drop-orphan-prefix-tables', [
            'site' => $site->domain,
            'prefix' => 'delete_this_',
            '--force' => true,
        ])
            ->expectsOutputToContain('DROP failed')
            ->assertFailed();
    });
});
