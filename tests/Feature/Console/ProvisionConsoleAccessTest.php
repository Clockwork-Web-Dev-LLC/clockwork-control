<?php

namespace Tests\Feature\Console;

use App\Models\Server;
use App\Services\Servers\ConsoleAccessProvisioner;

/*
|--------------------------------------------------------------------------
| ProvisionConsoleAccess (artisan clockwork:provision-console-access)
|--------------------------------------------------------------------------
|
| ConsoleAccessProvisioner is the command's sole constructor-injected
| collaborator and is itself a thin wrapper around SshClient — mocking it
| here (rather than SshClient underneath it) keeps this suite from ever
| attempting a real SSH connection, matching how ProcessServerUpdatesTest
| mocks ServerUpdater rather than the SSH layer beneath it.
*/

describe('option validation', function () {
    it('fails when neither --server nor --all is given', function () {
        $this->artisan('clockwork:provision-console-access')
            ->assertFailed()
            ->expectsOutputToContain('Provide --server=<id|name> (repeatable) or --all.');
    });
});

describe('server resolution', function () {
    it('reports no match and exits successfully when --all matches no monitored server', function () {
        Server::factory()->ignored()->create();

        $this->mock(ConsoleAccessProvisioner::class, function ($mock) {
            $mock->shouldNotReceive('provision');
        });

        $this->artisan('clockwork:provision-console-access', ['--all' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('No servers matched.');
    });

    it('limits to the servers named via repeatable --server', function () {
        $target = Server::factory()->create(['name' => 'target-a', 'hostname' => 'target-a.example.com']);
        Server::factory()->create(['name' => 'other-b', 'hostname' => 'other-b.example.com']);

        $this->mock(ConsoleAccessProvisioner::class, function ($mock) use ($target) {
            $mock->shouldReceive('provision')
                ->once()
                ->withArgs(fn (Server $s) => $s->is($target))
                ->andReturn(['ok' => true, 'status' => 'provisioned', 'output' => 'ok', 'message' => 'Drop-in installed.']);
        });

        $this->artisan('clockwork:provision-console-access', ['--server' => ['target-a']])
            ->assertSuccessful();
    });
});

describe('provisioning outcomes', function () {
    it('exits successfully when every server is already active', function () {
        $server = Server::factory()->create();

        $this->mock(ConsoleAccessProvisioner::class, function ($mock) {
            $mock->shouldReceive('provision')
                ->once()
                ->andReturn(['ok' => true, 'status' => 'already_active', 'output' => 'already active', 'message' => 'Already active.']);
        });

        $this->artisan('clockwork:provision-console-access', ['--server' => [(string) $server->id]])
            ->assertSuccessful()
            ->expectsOutputToContain('already_active=1');
    });

    it('a rolled-back server does NOT flip the overall exit code to FAILURE (only failed/ssh_failed do — read straight from $hadFailure in the source), but its output is still printed since ok=false', function () {
        $server = Server::factory()->create(['name' => 'flaky-server']);

        $this->mock(ConsoleAccessProvisioner::class, function ($mock) {
            $mock->shouldReceive('provision')
                ->once()
                ->andReturn(['ok' => false, 'status' => 'rolled_back', 'output' => "sshd -t failed\nrolled back", 'message' => 'Auto-rollback succeeded.']);
        });

        $this->artisan('clockwork:provision-console-access', ['--server' => ['flaky-server']])
            ->assertSuccessful()
            ->expectsOutputToContain('rolled_back=1');
    });

    it('exits with FAILURE when SSH itself fails to connect', function () {
        $server = Server::factory()->create(['name' => 'unreachable-server']);

        $this->mock(ConsoleAccessProvisioner::class, function ($mock) {
            $mock->shouldReceive('provision')
                ->once()
                ->andReturn(['ok' => false, 'status' => 'ssh_failed', 'output' => '', 'message' => 'SSH connect failed: timeout']);
        });

        $this->artisan('clockwork:provision-console-access', ['--server' => ['unreachable-server']])
            ->assertFailed()
            ->expectsOutputToContain('ssh_failed=1');
    });

    it('--show-output prints the per-server log even on a successful/already-active result', function () {
        $server = Server::factory()->create();

        $this->mock(ConsoleAccessProvisioner::class, function ($mock) {
            $mock->shouldReceive('provision')
                ->once()
                ->andReturn(['ok' => true, 'status' => 'already_active', 'output' => 'verbose diagnostic line', 'message' => 'Already active.']);
        });

        $this->artisan('clockwork:provision-console-access', ['--server' => [(string) $server->id], '--show-output' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('verbose diagnostic line');
    });
});
