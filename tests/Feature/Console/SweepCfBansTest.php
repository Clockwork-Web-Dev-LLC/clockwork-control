<?php

namespace Tests\Feature\Console;

use App\Models\BlockedIp;
use App\Models\Server;
use App\Services\Cloudflare\CloudflareDetector;
use App\Services\Fail2ban\Fail2banClient;

/*
|--------------------------------------------------------------------------
| Call-site coverage for SweepCfBans
|--------------------------------------------------------------------------
|
| CloudflareDetector is mocked (ranges()/isCloudflareIp()) so this never
| hits the network, and Fail2banClient — the direct SSH-shelling
| collaborator — is mocked so this never opens a real SSH session. The
| behavioral assertions are on BlockedIp.unbanned_at, since this command's
| whole job is a DB + remote-state mutation, not a read/diagnose.
*/

function sweepCfMockDetector(array $cfIps = []): void
{
    test()->mock(CloudflareDetector::class, function ($mock) use ($cfIps) {
        $mock->shouldReceive('ranges')->andReturn(['1.2.3.0/24']);
        $mock->shouldReceive('isCloudflareIp')->andReturnUsing(fn ($ip) => in_array($ip, $cfIps, true));
    });
}

describe('SweepCfBans', function () {
    it('does nothing and exits successfully when there are no active bans at all', function () {
        sweepCfMockDetector();
        $this->mock(Fail2banClient::class, fn ($mock) => $mock->shouldNotReceive('unbanIps'));

        $this->artisan('clockwork:sweep-cf-bans')
            ->expectsOutputToContain('No active CF-IP bans found across the fleet.')
            ->assertSuccessful();
    });

    it('ignores an active ban whose IP is not in the Cloudflare range', function () {
        $server = Server::factory()->create(['clockwork_jail_provisioned_at' => now()]);
        BlockedIp::factory()->create(['server_id' => $server->id, 'ip' => '9.9.9.9']);

        sweepCfMockDetector(cfIps: []); // 9.9.9.9 is never a CF IP
        $this->mock(Fail2banClient::class, fn ($mock) => $mock->shouldNotReceive('unbanIps'));

        $this->artisan('clockwork:sweep-cf-bans')
            ->expectsOutputToContain('No active CF-IP bans found across the fleet.')
            ->assertSuccessful();
    });

    it('ignores a CF-IP ban on a server whose jail was never provisioned', function () {
        $server = Server::factory()->create(['clockwork_jail_provisioned_at' => null]);
        BlockedIp::factory()->create(['server_id' => $server->id, 'ip' => '104.16.1.1']);

        sweepCfMockDetector(cfIps: ['104.16.1.1']);
        $this->mock(Fail2banClient::class, fn ($mock) => $mock->shouldNotReceive('unbanIps'));

        $this->artisan('clockwork:sweep-cf-bans')
            ->expectsOutputToContain('No active CF-IP bans found across the fleet.')
            ->assertSuccessful();
    });

    it('ignores a ban that was already unbanned', function () {
        $server = Server::factory()->create(['clockwork_jail_provisioned_at' => now()]);
        BlockedIp::factory()->unbanned()->create(['server_id' => $server->id, 'ip' => '104.16.1.1']);

        sweepCfMockDetector(cfIps: ['104.16.1.1']);
        $this->mock(Fail2banClient::class, fn ($mock) => $mock->shouldNotReceive('unbanIps'));

        $this->artisan('clockwork:sweep-cf-bans')
            ->expectsOutputToContain('No active CF-IP bans found across the fleet.')
            ->assertSuccessful();
    });

    it('--dry-run lists what would be unbanned without calling Fail2banClient or mutating the DB', function () {
        $server = Server::factory()->create(['clockwork_jail_provisioned_at' => now(), 'name' => 'jail-server.example.com']);
        $ban = BlockedIp::factory()->create(['server_id' => $server->id, 'ip' => '104.16.1.1']);

        sweepCfMockDetector(cfIps: ['104.16.1.1']);
        $this->mock(Fail2banClient::class, fn ($mock) => $mock->shouldNotReceive('unbanIps'));

        $this->artisan('clockwork:sweep-cf-bans', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run — not unbanning.')
            ->expectsOutputToContain('jail-server.example.com')
            ->expectsOutputToContain('104.16.1.1')
            ->assertSuccessful();

        expect($ban->refresh()->unbanned_at)->toBeNull();
    });

    it('unbans a CF-IP ban and marks it unbanned in the DB on success', function () {
        $server = Server::factory()->create(['clockwork_jail_provisioned_at' => now(), 'name' => 'jail-server.example.com']);
        $ban = BlockedIp::factory()->create(['server_id' => $server->id, 'ip' => '104.16.1.1']);

        sweepCfMockDetector(cfIps: ['104.16.1.1']);
        $this->mock(Fail2banClient::class, function ($mock) use ($server) {
            $mock->shouldReceive('unbanIps')
                ->once()
                ->withArgs(fn (Server $s, array $ips) => $s->is($server) && $ips === ['104.16.1.1'])
                ->andReturn([
                    'ok' => true,
                    'results' => ['104.16.1.1' => ['ok' => true, 'output' => '0']],
                    'message' => 'Unbanned 1 of 1 on jail-server.example.com.',
                ]);
        });

        $this->artisan('clockwork:sweep-cf-bans')
            ->expectsOutputToContain('Done. unbanned=1, failed=0')
            ->assertSuccessful();

        expect($ban->refresh()->unbanned_at)->not->toBeNull();
    });

    it('leaves the ban row untouched and exits non-zero when the unban attempt fails', function () {
        $server = Server::factory()->create(['clockwork_jail_provisioned_at' => now(), 'name' => 'jail-server.example.com']);
        $ban = BlockedIp::factory()->create(['server_id' => $server->id, 'ip' => '104.16.1.1']);

        sweepCfMockDetector(cfIps: ['104.16.1.1']);
        $this->mock(Fail2banClient::class, function ($mock) {
            $mock->shouldReceive('unbanIps')->once()->andReturn([
                'ok' => false,
                'results' => ['104.16.1.1' => ['ok' => false, 'output' => 'ERROR sudo failed']],
                'message' => 'Unbanned 0 of 1 on jail-server.example.com.',
            ]);
        });

        $this->artisan('clockwork:sweep-cf-bans')
            ->expectsOutputToContain('104.16.1.1 on jail-server.example.com')
            ->expectsOutputToContain('Done. unbanned=0, failed=1')
            ->assertFailed();

        expect($ban->refresh()->unbanned_at)->toBeNull();
    });
});
