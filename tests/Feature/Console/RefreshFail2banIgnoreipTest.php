<?php

use App\Models\Server;
use App\Services\Fail2ban\IgnoreIpListBuilder;
use App\Services\Ssh\SshClient;

/*
|--------------------------------------------------------------------------
| clockwork:refresh-fail2ban-ignoreip
|--------------------------------------------------------------------------
|
| Fail2banProvisioner (the underlying jail-provisioning script + STATUS
| parsing convention) is already covered at the service level in
| tests/Feature/Security/Fail2banAndBlockedIpsTest.php. This command is a
| distinct code path — it doesn't call Fail2banProvisioner at all, it pushes
| a fresh jail file directly via its own bash script and classifies success
| by a different marker ("STATUS: refreshed") — so it has zero existing
| coverage and needs its own suite. IgnoreIpListBuilder and SshClient are
| always mocked; no real network/SSH ever happens here.
*/

function fail2banIgnoreipServer(array $overrides = []): Server
{
    return Server::factory()->create(array_merge([
        'ssh_password' => 'sudo-pass',
        'clockwork_jail_provisioned_at' => now(),
    ], $overrides));
}

describe('clockwork:refresh-fail2ban-ignoreip — targeting', function () {
    it('warns and succeeds when no provisioned server matches', function () {
        $this->mock(IgnoreIpListBuilder::class, function ($mock) {
            $mock->shouldReceive('build')->once()->andReturn(['127.0.0.1/8']);
            $mock->shouldReceive('render')->once()->andReturn('127.0.0.1/8');
        });
        $this->mock(SshClient::class)->shouldNotReceive('exec');

        $this->artisan('clockwork:refresh-fail2ban-ignoreip')
            ->expectsOutputToContain('No provisioned servers match.')
            ->assertSuccessful();
    });

    it('excludes servers that have never been fail2ban-provisioned', function () {
        fail2banIgnoreipServer(['clockwork_jail_provisioned_at' => null]);

        $this->mock(IgnoreIpListBuilder::class, function ($mock) {
            $mock->shouldReceive('build')->once()->andReturn(['127.0.0.1/8']);
            $mock->shouldReceive('render')->once()->andReturn('127.0.0.1/8');
        });
        $this->mock(SshClient::class)->shouldNotReceive('exec');

        $this->artisan('clockwork:refresh-fail2ban-ignoreip')
            ->expectsOutputToContain('No provisioned servers match.')
            ->assertSuccessful();
    });

    it('--server filters to a single server by name', function () {
        $target = fail2banIgnoreipServer(['name' => 'target-box']);
        fail2banIgnoreipServer(['name' => 'other-box']);

        $this->mock(IgnoreIpListBuilder::class, function ($mock) {
            $mock->shouldReceive('build')->once()->andReturn(['127.0.0.1/8']);
            $mock->shouldReceive('render')->once()->andReturn('127.0.0.1/8');
        });

        $this->mock(SshClient::class)
            ->shouldReceive('exec')
            ->once()
            ->with(Mockery::on(fn ($s) => $s->is($target)), Mockery::any())
            ->andReturn('STATUS: refreshed');

        $this->artisan('clockwork:refresh-fail2ban-ignoreip', ['--server' => 'target-box'])
            ->expectsOutputToContain('Done. ok=1, failed=0')
            ->assertSuccessful();
    });
});

describe('clockwork:refresh-fail2ban-ignoreip --dry-run', function () {
    it('lists the entries and target servers without ever calling SshClient', function () {
        $server = fail2banIgnoreipServer(['name' => 'dry-run-box']);

        $this->mock(IgnoreIpListBuilder::class, function ($mock) {
            $mock->shouldReceive('build')->once()->andReturn(['127.0.0.1/8', '::1', '203.0.113.5']);
            $mock->shouldReceive('render')->once()->andReturn('127.0.0.1/8 ::1 203.0.113.5');
        });
        $this->mock(SshClient::class)->shouldNotReceive('exec');

        $this->artisan('clockwork:refresh-fail2ban-ignoreip', ['--dry-run' => true])
            ->expectsOutputToContain('203.0.113.5')
            ->expectsOutputToContain('dry-run-box')
            ->assertSuccessful();
    });
});

describe('clockwork:refresh-fail2ban-ignoreip — live push', function () {
    it('counts a server ok when the remote script reports STATUS: refreshed', function () {
        $server = fail2banIgnoreipServer();

        $this->mock(IgnoreIpListBuilder::class, function ($mock) {
            $mock->shouldReceive('build')->once()->andReturn(['127.0.0.1/8']);
            $mock->shouldReceive('render')->once()->andReturn('127.0.0.1/8');
        });

        $this->mock(SshClient::class)
            ->shouldReceive('exec')
            ->once()
            ->with(
                Mockery::on(fn ($s) => $s->is($server)),
                Mockery::on(fn ($cmd) => str_contains($cmd, 'CW_SUDO_PW=') && str_contains($cmd, 'fail2ban-client reload clockwork')),
            )
            ->andReturn("Jail 'clockwork' reloaded\nSTATUS: refreshed\nactive ignoreip preview: 127.0.0.1/8");

        $this->artisan('clockwork:refresh-fail2ban-ignoreip')
            ->expectsOutputToContain('Done. ok=1, failed=0')
            ->assertSuccessful();
    });

    it('counts a server failed (without throwing) when it has no sudo password stored', function () {
        fail2banIgnoreipServer(['ssh_password' => null]);

        $this->mock(IgnoreIpListBuilder::class, function ($mock) {
            $mock->shouldReceive('build')->once()->andReturn(['127.0.0.1/8']);
            $mock->shouldReceive('render')->once()->andReturn('127.0.0.1/8');
        });
        $this->mock(SshClient::class)->shouldNotReceive('exec');

        $this->artisan('clockwork:refresh-fail2ban-ignoreip')
            ->expectsOutputToContain('No sudo password stored.')
            ->expectsOutputToContain('Done. ok=0, failed=1')
            ->assertFailed();
    });

    it('counts a server failed when SshClient::exec throws', function () {
        fail2banIgnoreipServer();

        $this->mock(IgnoreIpListBuilder::class, function ($mock) {
            $mock->shouldReceive('build')->once()->andReturn(['127.0.0.1/8']);
            $mock->shouldReceive('render')->once()->andReturn('127.0.0.1/8');
        });

        $this->mock(SshClient::class)->shouldReceive('exec')->once()->andThrow(
            new RuntimeException('Cannot reach clockwork-deploy@203.0.113.10:22 — TCP connect failed')
        );

        $this->artisan('clockwork:refresh-fail2ban-ignoreip')
            ->expectsOutputToContain('SSH connect failed')
            ->expectsOutputToContain('Done. ok=0, failed=1')
            ->assertFailed();
    });

    it('counts a server failed when the remote script output has no STATUS: refreshed marker', function () {
        fail2banIgnoreipServer();

        $this->mock(IgnoreIpListBuilder::class, function ($mock) {
            $mock->shouldReceive('build')->once()->andReturn(['127.0.0.1/8']);
            $mock->shouldReceive('render')->once()->andReturn('127.0.0.1/8');
        });

        $this->mock(SshClient::class)->shouldReceive('exec')->once()->andReturn(
            'ERROR: clockwork jail still not active after restart.'
        );

        $this->artisan('clockwork:refresh-fail2ban-ignoreip')
            ->expectsOutputToContain('refresh did not report success')
            ->expectsOutputToContain('Done. ok=0, failed=1')
            ->assertFailed();
    });

    it('mixed run: succeeds for one server and fails for another, aggregating both in the exit code', function () {
        $ok = fail2banIgnoreipServer(['name' => 'ok-box']);
        fail2banIgnoreipServer(['name' => 'bad-box', 'ssh_password' => null]);

        $this->mock(IgnoreIpListBuilder::class, function ($mock) {
            $mock->shouldReceive('build')->once()->andReturn(['127.0.0.1/8']);
            $mock->shouldReceive('render')->once()->andReturn('127.0.0.1/8');
        });

        $this->mock(SshClient::class)
            ->shouldReceive('exec')
            ->once()
            ->with(Mockery::on(fn ($s) => $s->is($ok)), Mockery::any())
            ->andReturn('STATUS: refreshed');

        $this->artisan('clockwork:refresh-fail2ban-ignoreip')
            ->expectsOutputToContain('Done. ok=1, failed=1')
            ->assertFailed();
    });
});
