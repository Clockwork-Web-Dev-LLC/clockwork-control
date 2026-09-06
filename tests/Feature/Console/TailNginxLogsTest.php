<?php

use App\Models\Server;
use App\Models\Site;
use App\Services\Logs\NginxLogTailer;

/*
|--------------------------------------------------------------------------
| Call-site coverage for TailNginxLogs
|--------------------------------------------------------------------------
|
| NginxLogTailer itself is SSH-driven (see NginxLogTailerTest, if any, for
| its own coverage); here we only care that the command's CLI entry point
| (site targeting/filters, per-site error handling, exit-code rule) works,
| so NginxLogTailer is always mocked — this suite must never let a real SSH
| call happen.
*/

function tnlMonitoredSite(array $overrides = []): Site
{
    $server = Server::factory()->create(array_merge(
        ['last_ssh_ok_at' => now(), 'is_ignored' => false],
        $overrides['server'] ?? [],
    ));

    return Site::factory()->spinupwp()->create(array_merge(
        ['server_id' => $server->id],
        $overrides['site'] ?? [],
    ));
}

describe('TailNginxLogs', function () {
    it('tails every monitored site and reports aggregate totals', function () {
        $siteA = tnlMonitoredSite();
        $siteB = tnlMonitoredSite();

        $this->mock(NginxLogTailer::class, function ($mock) use ($siteA, $siteB) {
            $mock->shouldReceive('tail')
                ->once()
                ->with(Mockery::on(fn (Site $s) => $s->is($siteA)))
                ->andReturn(['bytes' => 1000, 'parsed' => 10, 'inserted' => 8, 'log_path' => '/x']);
            $mock->shouldReceive('tail')
                ->once()
                ->with(Mockery::on(fn (Site $s) => $s->is($siteB)))
                ->andReturn(['bytes' => 500, 'parsed' => 5, 'inserted' => 5, 'log_path' => '/y']);
        });

        $this->artisan('clockwork:tail-nginx-logs')
            ->expectsOutputToContain('Read 1,500 bytes, parsed 15 lines, inserted 13 rows. Failed sites: 0.')
            ->assertSuccessful();
    });

    it('returns SUCCESS with a warning when no sites match the filters', function () {
        $this->mock(NginxLogTailer::class, function ($mock) {
            $mock->shouldNotReceive('tail');
        });

        $this->artisan('clockwork:tail-nginx-logs', ['--site' => 'nope.example.com'])
            ->expectsOutputToContain('No sites match the given filters.')
            ->assertSuccessful();
    });

    it('excludes sites on unmonitored servers (ignored or missing last_ssh_ok_at)', function () {
        $ignoredServer = Server::factory()->ignored()->create(['last_ssh_ok_at' => now()]);
        $ignoredSite = Site::factory()->spinupwp()->create(['server_id' => $ignoredServer->id]);

        $neverConnectedServer = Server::factory()->create(['last_ssh_ok_at' => null]);
        $neverConnectedSite = Site::factory()->spinupwp()->create(['server_id' => $neverConnectedServer->id]);

        $goodSite = tnlMonitoredSite();

        $this->mock(NginxLogTailer::class, function ($mock) use ($goodSite) {
            $mock->shouldReceive('tail')
                ->once()
                ->with(Mockery::on(fn (Site $s) => $s->is($goodSite)))
                ->andReturn(['bytes' => 0, 'parsed' => 0, 'inserted' => 0, 'log_path' => '/x']);
        });

        $this->artisan('clockwork:tail-nginx-logs')->assertSuccessful();
    });

    it('filters to a single site by domain via --site', function () {
        $target = tnlMonitoredSite(['site' => ['domain' => 'target.example.com']]);
        tnlMonitoredSite(); // decoy, must not be tailed

        $this->mock(NginxLogTailer::class, function ($mock) use ($target) {
            $mock->shouldReceive('tail')
                ->once()
                ->with(Mockery::on(fn (Site $s) => $s->is($target)))
                ->andReturn(['bytes' => 0, 'parsed' => 0, 'inserted' => 0, 'log_path' => '/x']);
        });

        $this->artisan('clockwork:tail-nginx-logs', ['--site' => 'target.example.com'])->assertSuccessful();
    });

    it('filters to a server by name via --server', function () {
        $targetServer = Server::factory()->create(['name' => 'tail-target.example.com', 'last_ssh_ok_at' => now()]);
        $targetSite = Site::factory()->spinupwp()->create(['server_id' => $targetServer->id]);
        tnlMonitoredSite(); // decoy on a different server

        $this->mock(NginxLogTailer::class, function ($mock) use ($targetSite) {
            $mock->shouldReceive('tail')
                ->once()
                ->with(Mockery::on(fn (Site $s) => $s->is($targetSite)))
                ->andReturn(['bytes' => 0, 'parsed' => 0, 'inserted' => 0, 'log_path' => '/x']);
        });

        $this->artisan('clockwork:tail-nginx-logs', ['--server' => 'tail-target.example.com'])->assertSuccessful();
    });

    it('returns SUCCESS with zero matches when --server does not resolve to any server', function () {
        $this->mock(NginxLogTailer::class, function ($mock) {
            $mock->shouldNotReceive('tail');
        });

        $this->artisan('clockwork:tail-nginx-logs', ['--server' => 'does-not-exist'])
            ->expectsOutputToContain('No sites match the given filters.')
            ->assertSuccessful();
    });

    it('continues past a per-site failure and still returns SUCCESS when at least one site succeeded', function () {
        $ok = tnlMonitoredSite();
        $bad = tnlMonitoredSite();

        $this->mock(NginxLogTailer::class, function ($mock) use ($ok, $bad) {
            $mock->shouldReceive('tail')
                ->with(Mockery::on(fn (Site $s) => $s->is($ok)))
                ->andReturn(['bytes' => 10, 'parsed' => 1, 'inserted' => 1, 'log_path' => '/x']);
            $mock->shouldReceive('tail')
                ->with(Mockery::on(fn (Site $s) => $s->is($bad)))
                ->andThrow(new RuntimeException('Cannot reach server'));
        });

        $this->artisan('clockwork:tail-nginx-logs')
            ->expectsOutputToContain('Failed sites: 1.')
            ->assertSuccessful();
    });

    it('returns FAILURE only when every targeted site failed', function () {
        $bad = tnlMonitoredSite();

        $this->mock(NginxLogTailer::class, function ($mock) use ($bad) {
            $mock->shouldReceive('tail')
                ->with(Mockery::on(fn (Site $s) => $s->is($bad)))
                ->andThrow(new RuntimeException('Cannot reach server'));
        });

        $this->artisan('clockwork:tail-nginx-logs')->assertFailed();
    });
});
