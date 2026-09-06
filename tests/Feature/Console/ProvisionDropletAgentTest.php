<?php

namespace Tests\Feature\Console;

use App\Models\Server;
use App\Services\Servers\DropletAgentProvisioner;

/*
|--------------------------------------------------------------------------
| ProvisionDropletAgent (artisan clockwork:provision-droplet-agent)
|--------------------------------------------------------------------------
|
| DropletAgentProvisioner is the command's sole constructor-injected
| collaborator and wraps SshClient internally — mocked here so this suite
| never attempts a real SSH connection. Covers option validation, server
| resolution (--server / --all), the plain provision() path, and the
| --restart flag routing to restart() instead.
*/

describe('option validation', function () {
    it('fails when neither --server nor --all is given', function () {
        $this->artisan('clockwork:provision-droplet-agent')
            ->assertFailed()
            ->expectsOutputToContain('Provide --server=<id|name> (repeatable) or --all.');
    });
});

describe('server resolution', function () {
    it('reports no match and exits successfully when nothing matches', function () {
        Server::factory()->create(['name' => 'unrelated-server']);

        $this->mock(DropletAgentProvisioner::class, function ($mock) {
            $mock->shouldNotReceive('provision');
            $mock->shouldNotReceive('restart');
        });

        $this->artisan('clockwork:provision-droplet-agent', ['--server' => ['no-such-server']])
            ->assertSuccessful()
            ->expectsOutputToContain('No servers matched.');
    });

    it('--all provisions every monitored server with a hostname', function () {
        $a = Server::factory()->create(['name' => 'fleet-a']);
        $b = Server::factory()->create(['name' => 'fleet-b']);
        Server::factory()->ignored()->create(['name' => 'ignored-c']);

        $this->mock(DropletAgentProvisioner::class, function ($mock) use ($a, $b) {
            $mock->shouldReceive('provision')
                ->twice()
                ->withArgs(fn (Server $s) => $s->is($a) || $s->is($b))
                ->andReturn(['ok' => true, 'status' => 'provisioned', 'output' => 'ok', 'message' => 'installed']);
        });

        $this->artisan('clockwork:provision-droplet-agent', ['--all' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('provisioned=2');
    });
});

describe('provisioning outcomes', function () {
    it('runs provision() by default and reports the outcome', function () {
        $server = Server::factory()->create(['name' => 'target-server']);

        $this->mock(DropletAgentProvisioner::class, function ($mock) use ($server) {
            $mock->shouldReceive('provision')
                ->once()
                ->withArgs(fn (Server $s) => $s->is($server))
                ->andReturn(['ok' => true, 'status' => 'already_active', 'output' => 'active', 'message' => 'already installed']);
            $mock->shouldNotReceive('restart');
        });

        $this->artisan('clockwork:provision-droplet-agent', ['--server' => ['target-server']])
            ->assertSuccessful()
            ->expectsOutputToContain('Provisioning droplet-agent')
            ->expectsOutputToContain('already_active=1');
    });

    it('prints the tail of output on a failed provision', function () {
        $server = Server::factory()->create(['name' => 'broken-server']);

        $this->mock(DropletAgentProvisioner::class, function ($mock) {
            $mock->shouldReceive('provision')
                ->once()
                ->andReturn(['ok' => false, 'status' => 'failed', 'output' => 'installer download failed', 'message' => 'failed']);
        });

        // Command always returns SUCCESS regardless of per-server failures
        // (unlike ProvisionConsoleAccess) — read straight from the source.
        $this->artisan('clockwork:provision-droplet-agent', ['--server' => ['broken-server']])
            ->assertSuccessful()
            ->expectsOutputToContain('installer download failed')
            ->expectsOutputToContain('failed=1');
    });
});

describe('--restart', function () {
    it('routes to restart() instead of provision() and reports the restarted outcome', function () {
        $server = Server::factory()->create(['name' => 'stuck-server']);

        $this->mock(DropletAgentProvisioner::class, function ($mock) use ($server) {
            $mock->shouldReceive('restart')
                ->once()
                ->withArgs(fn (Server $s) => $s->is($server))
                ->andReturn(['ok' => true, 'status' => 'restarted', 'output' => 'restarted', 'message' => 'droplet-agent restarted.']);
            $mock->shouldNotReceive('provision');
        });

        $this->artisan('clockwork:provision-droplet-agent', ['--server' => ['stuck-server'], '--restart' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Restarting droplet-agent')
            ->expectsOutputToContain('restarted=1');
    });
});
