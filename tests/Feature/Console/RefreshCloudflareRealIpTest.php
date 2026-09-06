<?php

namespace Tests\Feature\Console;

use App\Models\Server;
use App\Services\Cloudflare\CloudflareDetector;
use App\Services\Ssh\SshClient;

/*
|--------------------------------------------------------------------------
| Call-site coverage for RefreshCloudflareRealIp
|--------------------------------------------------------------------------
|
| CloudflareDetector::ranges()/rangesV6() hit the network on a cache miss,
| and pushTo() shells the snippet over SshClient::exec() — both are mocked
| here so this suite never touches DNS/HTTP/SSH. Only servers with
| clockwork_jail_provisioned_at set are targeted (targetServers()), and a
| server also needs ssh_password set or pushTo() short-circuits with "No
| sudo password stored." before ever calling SshClient.
*/

function refreshCfMockDetector(): void
{
    test()->mock(CloudflareDetector::class, function ($mock) {
        $mock->shouldReceive('ranges')->andReturn(['1.2.3.0/24']);
        $mock->shouldReceive('rangesV6')->andReturn(['2400:cb00::/32']);
    });
}

describe('RefreshCloudflareRealIp', function () {
    it('reports no match and exits successfully when no server has a provisioned jail', function () {
        refreshCfMockDetector();
        $this->mock(SshClient::class, fn ($mock) => $mock->shouldNotReceive('exec'));

        Server::factory()->create(['clockwork_jail_provisioned_at' => null]);

        $this->artisan('clockwork:refresh-cloudflare-real-ip')
            ->expectsOutputToContain('No servers match.')
            ->assertSuccessful();
    });

    it('--dry-run prints the snippet and targets without touching SSH', function () {
        refreshCfMockDetector();
        $this->mock(SshClient::class, fn ($mock) => $mock->shouldNotReceive('exec'));

        $server = Server::factory()->create([
            'clockwork_jail_provisioned_at' => now(),
            'ssh_password' => 'secret',
        ]);

        $this->artisan('clockwork:refresh-cloudflare-real-ip', ['--dry-run' => true])
            ->expectsOutputToContain('set_real_ip_from 1.2.3.0/24;')
            ->expectsOutputToContain($server->name)
            ->assertSuccessful();
    });

    it('pushes the snippet to every provisioned server and reports ok when SSH succeeds', function () {
        refreshCfMockDetector();

        $a = Server::factory()->create(['clockwork_jail_provisioned_at' => now(), 'ssh_password' => 'secret', 'name' => 'server-a.example.com']);
        $b = Server::factory()->create(['clockwork_jail_provisioned_at' => now(), 'ssh_password' => 'secret', 'name' => 'server-b.example.com']);

        $this->mock(SshClient::class, function ($mock) {
            $mock->shouldReceive('exec')
                ->twice()
                ->andReturn('STATUS: refreshed');
        });

        $this->artisan('clockwork:refresh-cloudflare-real-ip')
            ->expectsOutputToContain('Done. ok=2, failed=0')
            ->assertSuccessful();
    });

    it('limits to a single server via --server', function () {
        refreshCfMockDetector();

        $target = Server::factory()->create(['clockwork_jail_provisioned_at' => now(), 'ssh_password' => 'secret', 'name' => 'only-me.example.com']);
        Server::factory()->create(['clockwork_jail_provisioned_at' => now(), 'ssh_password' => 'secret', 'name' => 'not-me.example.com']);

        $this->mock(SshClient::class, function ($mock) use ($target) {
            $mock->shouldReceive('exec')
                ->once()
                ->withArgs(fn (Server $server) => $server->is($target))
                ->andReturn('STATUS: unchanged');
        });

        $this->artisan('clockwork:refresh-cloudflare-real-ip', ['--server' => 'only-me.example.com'])
            ->expectsOutputToContain('Done. ok=1, failed=0')
            ->assertSuccessful();
    });

    it('fails and reports the missing-password server without calling SSH at all', function () {
        refreshCfMockDetector();
        $this->mock(SshClient::class, fn ($mock) => $mock->shouldNotReceive('exec'));

        Server::factory()->create(['clockwork_jail_provisioned_at' => now(), 'ssh_password' => null, 'name' => 'no-password.example.com']);

        $this->artisan('clockwork:refresh-cloudflare-real-ip')
            ->expectsOutputToContain('No sudo password stored.')
            ->expectsOutputToContain('Done. ok=0, failed=1')
            ->assertFailed();
    });

    it('catches an SSH connect exception, marks the server failed, and exits non-zero', function () {
        refreshCfMockDetector();

        Server::factory()->create(['clockwork_jail_provisioned_at' => now(), 'ssh_password' => 'secret', 'name' => 'unreachable.example.com']);

        $this->mock(SshClient::class, function ($mock) {
            $mock->shouldReceive('exec')->once()->andThrow(new \RuntimeException('TCP connect failed'));
        });

        $this->artisan('clockwork:refresh-cloudflare-real-ip')
            ->expectsOutputToContain('SSH connect failed: TCP connect failed')
            ->assertFailed();
    });
});
