<?php

use App\Models\Server;
use App\Models\Site;
use App\Services\Fail2ban\IgnoreIpListBuilder;
use App\Services\Ssh\SshClient;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| clockwork:security-check
|--------------------------------------------------------------------------
|
| SecurityCheck is 515 lines running 8 local invariant checks plus an
| optional --ssh fleet-wide ignoreip drift check. Per the Phase 6 scope
| note, this file covers the main dispatch (default run, exit code wiring,
| the --ssh opt-in gate) plus the checks most worth protecting against
| regression: the two ciphertext-format checks (a plaintext credential
| slipping into an "encrypted" column is exactly the kind of bug this
| command exists to catch) and the SSH-heavy ignoreip drift check, which is
| the only sub-check with an injectable, mockable collaborator
| (SshClient + IgnoreIpListBuilder come in via handle()'s method injection).
|
| The remaining checks (serve-bind, .gitignore/.env-in-git, log/cache
| credential-leak scanning) read real process state / the real repo
| filesystem rather than the database and have no injectable seam to mock,
| so every test here runs them for real. That means the command's OVERALL
| exit code is NOT deterministic in this dev environment — verified by
| actually running `php artisan clockwork:security-check` directly:
|   - serve_bind fails whenever a real `php artisan serve --host=0.0.0.0`
|     happens to be running on the machine (it was, while writing this).
|   - env_in_gitignore fails against THIS repo's real .gitignore even
|     though `.env` genuinely is covered, because it's ignored via the
|     glob `.env*` (see .gitignore) — checkEnvInGitignore()'s regex
|     `^\.env(\.|$)` and its two string-contains fallbacks only recognize
|     an exact `.env` line, not a `.env*` glob. Likely a real, pre-existing
|     false-positive bug in SecurityCheck; not fixed here per Phase 6 scope
|     (test-only, no app/ changes) — flagged in the task report instead.
| Because of that, tests below never assert overall assertSuccessful() /
| assertFailed() for paths that depend on those two checks — only on the
| specific check(s) each test's fixture actually targets, via
| expectsOutputToContain()/doesntExpectOutputToContain() plus ->run() to
| execute the command. Tests that fail a check we DO control (ciphertext,
| --ssh drift) still assert the overall exit code — SecurityCheck fails
| overall on ANY fail, so assertFailed() stays valid regardless of what
| else the real environment does.
*/

describe('clockwork:security-check — default dispatch', function () {
    it('runs to completion with an empty database and no --ssh, skipping the SSH-heavy drift check', function () {
        $this->artisan('clockwork:security-check')
            ->expectsOutputToContain('Skipped (pass --ssh')
            ->run();
    });
});

describe('clockwork:security-check — ciphertext invariants', function () {
    it('fails when a site db_password column holds a plaintext value instead of Laravel ciphertext', function () {
        $site = Site::factory()->create();
        // Bypass the model's `encrypted` cast to simulate real corruption/plaintext leakage.
        DB::table('sites')->where('id', $site->id)->update(['db_password' => 'not-actually-encrypted']);

        $this->artisan('clockwork:security-check')
            ->expectsOutputToContain('FAIL · sites_db_password_ciphertext')
            ->assertFailed();
    });

    it('fails when a server ssh_password column holds a plaintext value instead of Laravel ciphertext', function () {
        $server = Server::factory()->create();
        DB::table('servers')->where('id', $server->id)->update(['ssh_password' => 'plaintext-oops']);

        $this->artisan('clockwork:security-check')
            ->expectsOutputToContain('FAIL · servers_ssh_password_ciphertext')
            ->assertFailed();
    });

    it('passes the ciphertext checks for a properly-encrypted db_password and ssh_password', function () {
        Site::factory()->create(['db_password' => 'real-password-123']);
        Server::factory()->create(['ssh_password' => 'real-sudo-password']);

        $this->artisan('clockwork:security-check')
            ->doesntExpectOutputToContain('FAIL · sites_db_password_ciphertext')
            ->doesntExpectOutputToContain('FAIL · servers_ssh_password_ciphertext')
            ->run();
    });
});

describe('clockwork:security-check --ssh — fail2ban ignoreip drift', function () {
    it('reports ok / no drift when every provisioned server\'s remote ignoreip list matches the expected list', function () {
        $server = Server::factory()->create([
            'ssh_password' => 'sudo-pass',
            'clockwork_jail_provisioned_at' => now(),
        ]);

        $this->mock(IgnoreIpListBuilder::class)
            ->shouldReceive('build')
            ->once()
            ->andReturn(['127.0.0.1/8', '::1', '203.0.113.5']);

        $this->mock(SshClient::class)
            ->shouldReceive('exec')
            ->once()
            ->with(Mockery::on(fn ($s) => $s->is($server)), Mockery::on(fn ($cmd) => str_contains($cmd, 'fail2ban-client get clockwork ignoreip')))
            ->andReturn(implode("\n", [
                'These IP addresses/networks are ignored:',
                '|- 127.0.0.1/8',
                '|- ::1',
                '`- 203.0.113.5',
            ]));

        $this->artisan('clockwork:security-check', ['--ssh' => true])
            ->expectsOutputToContain('All 1 provisioned servers have current ignoreip. No drift.')
            ->run();
    });

    it('fails and reports drift when the remote ignoreip list is missing an expected entry', function () {
        $server = Server::factory()->create([
            'ssh_password' => 'sudo-pass',
            'clockwork_jail_provisioned_at' => now(),
        ]);

        $this->mock(IgnoreIpListBuilder::class)
            ->shouldReceive('build')
            ->once()
            ->andReturn(['127.0.0.1/8', '::1', '203.0.113.5']);

        $this->mock(SshClient::class)
            ->shouldReceive('exec')
            ->once()
            ->andReturn(implode("\n", [
                'These IP addresses/networks are ignored:',
                '|- 127.0.0.1/8',
                '`- ::1',
                // 203.0.113.5 missing on the remote side -> drift.
            ]));

        $this->artisan('clockwork:security-check', ['--ssh' => true, '--quiet-ok' => true])
            ->expectsOutputToContain('FAIL · fail2ban_ignoreip_drift')
            ->assertFailed();
    });

    it('skips the drift check cleanly (info, not fail) when no server has been fail2ban-provisioned yet', function () {
        Server::factory()->create(['clockwork_jail_provisioned_at' => null]);

        $this->mock(IgnoreIpListBuilder::class)->shouldReceive('build')->once()->andReturn(['127.0.0.1/8']);
        $this->mock(SshClient::class)->shouldNotReceive('exec');

        $this->artisan('clockwork:security-check', ['--ssh' => true])
            ->doesntExpectOutputToContain('FAIL · fail2ban_ignoreip_drift')
            ->run();
    });
});
