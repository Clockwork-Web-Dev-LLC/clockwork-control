<?php

namespace Tests\Feature\Console;

use App\Models\Server;

/*
|--------------------------------------------------------------------------
| CleanFailedProvision (artisan clockwork:clean-failed-provision {server})
|--------------------------------------------------------------------------
|
| Pure output + a small DB state clear — no SSH/HTTP I/O of its own (it
| prints a recovery script for the human to run elsewhere). Covers the
| id/hostname/name argument resolution, the not-found path, and the
| provisioning-state clear (and its no-op variant).
*/

describe('server resolution', function () {
    it('finds the server by numeric id and prints the recovery script', function () {
        $server = Server::factory()->create([
            'name' => 'web12',
            'hostname' => 'web12.example.com',
            'ssh_user' => 'clockwork-deploy',
        ]);

        $this->artisan('clockwork:clean-failed-provision', ['server' => (string) $server->id])
            ->assertSuccessful()
            ->expectsOutputToContain("Recovery script for {$server->name} ({$server->hostname}):")
            ->expectsOutputToContain('rm -f /etc/sudoers.d/clockwork')
            ->expectsOutputToContain('sudo -l -U clockwork-deploy');
    });

    it('finds the server by hostname when the argument is not numeric', function () {
        $server = Server::factory()->create([
            'name' => 'web13',
            'hostname' => 'web13.example.com',
        ]);

        $this->artisan('clockwork:clean-failed-provision', ['server' => 'web13.example.com'])
            ->assertSuccessful()
            ->expectsOutputToContain("Recovery script for {$server->name}");
    });

    it('finds the server by name when the argument is not numeric', function () {
        $server = Server::factory()->create(['name' => 'web14']);

        $this->artisan('clockwork:clean-failed-provision', ['server' => 'web14'])
            ->assertSuccessful()
            ->expectsOutputToContain("Recovery script for {$server->name}");
    });

    it('fails cleanly when no server matches', function () {
        $this->artisan('clockwork:clean-failed-provision', ['server' => 'does-not-exist'])
            ->assertFailed()
            ->expectsOutputToContain("Server 'does-not-exist' not found.");
    });
});

describe('provisioning-state clear', function () {
    it('clears clockwork_jail_provisioned_at and last_provision_log when either is set', function () {
        $server = Server::factory()->create([
            'clockwork_jail_provisioned_at' => now(),
            'last_provision_log' => 'v0 provisioner bug output',
        ]);

        $this->artisan('clockwork:clean-failed-provision', ['server' => (string) $server->id])
            ->assertSuccessful()
            ->expectsOutputToContain("Cleared provisioning state on '{$server->name}' in our DB.");

        $server->refresh();
        expect($server->clockwork_jail_provisioned_at)->toBeNull();
        expect($server->last_provision_log)->toBeNull();
    });

    it('does not print the "cleared" line when there is nothing to clear', function () {
        $server = Server::factory()->create([
            'clockwork_jail_provisioned_at' => null,
            'last_provision_log' => null,
        ]);

        $this->artisan('clockwork:clean-failed-provision', ['server' => (string) $server->id])
            ->assertSuccessful()
            ->doesntExpectOutputToContain('Cleared provisioning state');
    });
});
