<?php

use App\Models\Server;
use App\Models\Site;
use App\Services\Ssh\SshClient;

/*
|--------------------------------------------------------------------------
| clockwork:block-ua
|--------------------------------------------------------------------------
|
| Every non-dry-run path shells a bash script to the target server via the
| injectable SshClient — mocked throughout so this suite never opens a
| real SSH session. The command's own success/failure classification is
| purely string-matching the combined stdout+stderr for "STATUS: ok" /
| "STATUS: unchanged"; anything else is treated as a failure. That
| classification (not the bash script's internals, which run for real only
| on a live server) is what's under test here.
*/

function uaServer(array $overrides = []): Server
{
    return Server::factory()->create(array_merge(['ssh_password' => 'sudo-pass'], $overrides));
}

describe('clockwork:block-ua — argument/lookup validation', function () {
    it('requires --server', function () {
        $this->artisan('clockwork:block-ua')
            ->expectsOutputToContain('--server is required.')
            ->assertFailed();
    });

    it('errors when no server matches the given name/hostname/ID', function () {
        $this->artisan('clockwork:block-ua', ['--server' => 'no-such-server'])
            ->expectsOutputToContain('No server found matching: no-such-server')
            ->assertFailed();
    });

    it('warns and succeeds when the matched server has no sites', function () {
        $server = uaServer();

        $this->mock(SshClient::class)->shouldNotReceive('exec');

        $this->artisan('clockwork:block-ua', ['--server' => $server->name])
            ->expectsOutputToContain("No sites found on server {$server->name}.")
            ->assertSuccessful();
    });
});

describe('clockwork:block-ua --dry-run', function () {
    it('prints the nginx snippets without ever calling SshClient', function () {
        $server = uaServer();
        Site::factory()->create(['server_id' => $server->id, 'domain' => 'dryrun.example.com']);

        $this->mock(SshClient::class)->shouldNotReceive('exec');

        // NOTE: the map header text ("map $http_user_agent...") and the
        // "~*Chrome..." line both live inside the SAME single multi-line
        // $this->line($mapSnippet) call — Laravel's expectsOutputToContain()
        // matches one expectation per underlying write() call, so asserting
        // two substrings that only ever co-occur in that one call causes the
        // second to spuriously "not be found" even though it's plainly in
        // the output. Assert the Chrome pattern (proves the snippet body
        // built correctly) and the domain (a separate, earlier write call)
        // instead of also asserting the header text redundantly.
        $this->artisan('clockwork:block-ua', ['--server' => $server->name, '--dry-run' => true])
            ->expectsOutputToContain('~*Chrome/126\\.0\\.0\\.0 1;')
            ->expectsOutputToContain('dryrun.example.com')
            ->assertSuccessful();
    });

    it('prints a removal plan instead of the snippets when --remove is combined with --dry-run', function () {
        $server = uaServer();
        Site::factory()->create(['server_id' => $server->id, 'domain' => 'dryrun-remove.example.com']);

        $this->mock(SshClient::class)->shouldNotReceive('exec');

        $this->artisan('clockwork:block-ua', ['--server' => $server->name, '--remove' => true, '--dry-run' => true])
            ->expectsOutputToContain('Would remove: /etc/nginx/conf.d/clockwork-ua-block.conf')
            ->expectsOutputToContain('dryrun-remove.example.com')
            ->assertSuccessful();
    });
});

describe('clockwork:block-ua — live push', function () {
    it('fails when the server has no sudo password stored', function () {
        $server = uaServer(['ssh_password' => null]);
        Site::factory()->create(['server_id' => $server->id]);

        $this->mock(SshClient::class)->shouldNotReceive('exec');

        $this->artisan('clockwork:block-ua', ['--server' => $server->name])
            ->expectsOutputToContain('has no sudo password stored.')
            ->assertFailed();
    });

    it('succeeds and reports "applied" when the remote script reports STATUS: ok', function () {
        $server = uaServer();
        Site::factory()->create(['server_id' => $server->id, 'domain' => 'blocked.example.com']);

        $this->mock(SshClient::class)
            ->shouldReceive('exec')
            ->once()
            ->with(
                Mockery::on(fn ($s) => $s->is($server)),
                Mockery::on(fn ($cmd) => str_contains($cmd, 'CW_SUDO_PW=') && str_contains($cmd, 'bash -c')),
                60,
            )
            ->andReturn("[cw-block-ua] Wrote /etc/nginx/conf.d/clockwork-ua-block.conf\nSTATUS: ok");

        $this->artisan('clockwork:block-ua', ['--server' => $server->name])
            ->expectsOutputToContain('UA block applied and nginx reloaded.')
            ->assertSuccessful();
    });

    it('succeeds and reports "removed" when --remove is pushed and the script reports STATUS: ok', function () {
        $server = uaServer();
        Site::factory()->create(['server_id' => $server->id]);

        $this->mock(SshClient::class)->shouldReceive('exec')->once()->andReturn('STATUS: ok');

        $this->artisan('clockwork:block-ua', ['--server' => $server->name, '--remove' => true])
            ->expectsOutputToContain('UA block removed and nginx reloaded.')
            ->assertSuccessful();
    });

    it('succeeds and reports "already up to date" when the remote script reports STATUS: unchanged', function () {
        $server = uaServer();
        Site::factory()->create(['server_id' => $server->id]);

        $this->mock(SshClient::class)->shouldReceive('exec')->once()->andReturn('STATUS: unchanged');

        $this->artisan('clockwork:block-ua', ['--server' => $server->name])
            ->expectsOutputToContain('Already up to date — no reload needed.')
            ->assertSuccessful();
    });

    it('fails when the remote script output has neither STATUS marker (e.g. nginx -t failed)', function () {
        $server = uaServer();
        Site::factory()->create(['server_id' => $server->id]);

        $this->mock(SshClient::class)->shouldReceive('exec')->once()->andReturn(
            "ERROR: nginx -t failed. Reverting map snippet.\nnginx: [emerg] unexpected end of file"
        );

        $this->artisan('clockwork:block-ua', ['--server' => $server->name])
            ->expectsOutputToContain('Script did not report success. Review output above.')
            ->assertFailed();
    });

    it('fails without throwing when SshClient::exec itself throws', function () {
        $server = uaServer();
        Site::factory()->create(['server_id' => $server->id]);

        $this->mock(SshClient::class)->shouldReceive('exec')->once()->andThrow(
            new RuntimeException('Cannot reach clockwork-deploy@203.0.113.10:22 — TCP connect failed')
        );

        $this->artisan('clockwork:block-ua', ['--server' => $server->name])
            ->expectsOutputToContain('SSH failed: Cannot reach')
            ->assertFailed();
    });

    it('passes custom --ua patterns through into the map snippet', function () {
        $server = uaServer();
        Site::factory()->create(['server_id' => $server->id]);

        $this->mock(SshClient::class)
            ->shouldReceive('exec')
            ->once()
            // preg_quote() escapes the '.' in the UA string for the nginx
            // regex, so the command payload contains 'EvilBot/1\.0', not the
            // literal input string — match on the unambiguous 'EvilBot' stem.
            ->with(Mockery::any(), Mockery::on(fn ($cmd) => str_contains($cmd, 'EvilBot')), 60)
            ->andReturn('STATUS: ok');

        $this->artisan('clockwork:block-ua', ['--server' => $server->name, '--ua' => ['EvilBot/1.0']])
            ->assertSuccessful();
    });
});
