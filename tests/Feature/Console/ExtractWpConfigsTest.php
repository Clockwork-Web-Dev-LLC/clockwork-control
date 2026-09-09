<?php

use App\Models\Server;
use App\Models\Site;
use App\Services\Ssh\SshClient;

/*
|--------------------------------------------------------------------------
| clockwork:extract-wp-configs
|--------------------------------------------------------------------------
|
| WpConfigExtractor::extractAndStore() is exercised for real (it's the
| thing that parses wp-config.php and writes db_* columns) — only its SSH
| boundary (SshClient::exec) is faked, same pattern as DetectWpPluginsTest.
| Site-selection-only cases (--site/--server/--force skip) don't care about
| parsing correctness so they use a minimal fake wp-config.php body.
*/

function fakeWpConfig(array $overrides = []): string
{
    $vals = array_merge([
        'DB_NAME' => 'wp_live',
        'DB_USER' => 'wp_user',
        'DB_PASSWORD' => 'super-secret-pass',
        'DB_HOST' => 'localhost',
        'table_prefix' => 'wp_',
    ], $overrides);

    return <<<PHP
<?php
define( 'DB_NAME', '{$vals['DB_NAME']}' );
define( 'DB_USER', '{$vals['DB_USER']}' );
define( 'DB_PASSWORD', '{$vals['DB_PASSWORD']}' );
define( 'DB_HOST', '{$vals['DB_HOST']}' );
\$table_prefix = '{$vals['table_prefix']}';
PHP;
}

function wpConfigSite(array $siteOverrides = [], array $serverOverrides = []): Site
{
    $server = Server::factory()->create(array_merge([
        'ssh_password' => 'sudo-pass',
        'last_ssh_ok_at' => now(),
    ], $serverOverrides));

    return Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'is_wordpress' => true,
        'domain' => 'wpconfig.example.com',
        'db_password' => null,
    ], $siteOverrides));
}

describe('clockwork:extract-wp-configs — real extraction through a mocked SSH boundary', function () {
    it('parses wp-config.php over SSH and stores the DB credentials + prefix', function () {
        $site = wpConfigSite();

        // 3 calls: wpConfigPath()'s docroot + parent-dir existence probes
        // (both "fail" against this generic mock, since neither returns the
        // literal string "yes"), then the actual `cat` that succeeds.
        $this->mock(SshClient::class)->shouldReceive('exec')->times(3)->andReturn(fakeWpConfig());

        $this->artisan('clockwork:extract-wp-configs')
            ->expectsOutputToContain('Extracted: 1, skipped: 0, failed: 0')
            ->assertSuccessful();

        $site->refresh();
        expect($site->db_name)->toBe('wp_live')
            ->and($site->db_user)->toBe('wp_user')
            ->and($site->db_password)->toBe('super-secret-pass')
            ->and($site->db_host)->toBe('localhost')
            ->and($site->table_prefix)->toBe('wp_')
            ->and($site->wp_path)->toBe('/sites/wpconfig.example.com/files');
    });

    it('counts an unreadable wp-config.php as failed and exits FAILURE, without touching db_password', function () {
        $site = wpConfigSite();

        // 4 calls: the two wpConfigPath() existence probes (both "not found"),
        // then the plain `cat` and the sudo fallback, both returning empty
        // output — WpConfigExtractor throws, the command catches it and
        // tallies failed.
        $this->mock(SshClient::class)->shouldReceive('exec')->times(4)->andReturn('');

        $this->artisan('clockwork:extract-wp-configs')
            ->expectsOutputToContain('Extracted: 0, skipped: 0, failed: 1')
            ->assertFailed();

        expect($site->refresh()->db_password)->toBeNull();
    });
});

describe('clockwork:extract-wp-configs — site selection + skip logic', function () {
    it('warns and succeeds when no sites match', function () {
        $this->mock(SshClient::class)->shouldNotReceive('exec');

        $this->artisan('clockwork:extract-wp-configs')
            ->expectsOutputToContain('No sites match the given filters.')
            ->assertSuccessful();
    });

    it('excludes a WordPress site on a server that has never had a successful SSH connection', function () {
        wpConfigSite(serverOverrides: ['last_ssh_ok_at' => null], siteOverrides: ['domain' => 'never-ssh.example.com']);

        $this->mock(SshClient::class)->shouldNotReceive('exec');

        $this->artisan('clockwork:extract-wp-configs')
            ->expectsOutputToContain('No sites match the given filters.')
            ->assertSuccessful();
    });

    it('skips a site that already has db_password stored, unless --force is given', function () {
        $site = wpConfigSite(['db_password' => 'already-here']);

        $this->mock(SshClient::class)->shouldNotReceive('exec');

        $this->artisan('clockwork:extract-wp-configs')
            ->expectsOutputToContain('Extracted: 0, skipped: 1, failed: 0')
            ->assertSuccessful();

        expect($site->refresh()->db_password)->toBe('already-here');
    });

    it('--force re-extracts a site that already has db_password stored', function () {
        $site = wpConfigSite(['db_password' => 'stale-password']);

        $this->mock(SshClient::class)->shouldReceive('exec')->times(3)->andReturn(
            fakeWpConfig(['DB_PASSWORD' => 'fresh-password'])
        );

        $this->artisan('clockwork:extract-wp-configs', ['--force' => true])
            ->expectsOutputToContain('Extracted: 1, skipped: 0, failed: 0')
            ->assertSuccessful();

        expect($site->refresh()->db_password)->toBe('fresh-password');
    });

    it('--site limits to a single site by domain', function () {
        $target = wpConfigSite(['domain' => 'target.example.com']);
        wpConfigSite(['domain' => 'other.example.com']);

        $this->mock(SshClient::class)->shouldReceive('exec')->times(3)->andReturn(fakeWpConfig());

        $this->artisan('clockwork:extract-wp-configs', ['--site' => 'target.example.com'])
            ->expectsOutputToContain('Extracted: 1, skipped: 0, failed: 0')
            ->assertSuccessful();
    });

    it('--server limits to sites on a server matched by name', function () {
        $server = Server::factory()->create(['ssh_password' => 'sudo-pass', 'last_ssh_ok_at' => now()]);
        $inScope = wpConfigSite(['domain' => 'in-scope.example.com'], []);
        $inScope->update(['server_id' => $server->id]);
        wpConfigSite(['domain' => 'out-of-scope.example.com']);

        $this->mock(SshClient::class)->shouldReceive('exec')->times(3)->andReturn(fakeWpConfig());

        $this->artisan('clockwork:extract-wp-configs', ['--server' => $server->name])
            ->expectsOutputToContain('Extracted: 1, skipped: 0, failed: 0')
            ->assertSuccessful();
    });

    it('--server with no matching server yields no sites (empty collection short-circuit)', function () {
        wpConfigSite();

        $this->mock(SshClient::class)->shouldNotReceive('exec');

        $this->artisan('clockwork:extract-wp-configs', ['--server' => 'no-such-server'])
            ->expectsOutputToContain('No sites match the given filters.')
            ->assertSuccessful();
    });
});
