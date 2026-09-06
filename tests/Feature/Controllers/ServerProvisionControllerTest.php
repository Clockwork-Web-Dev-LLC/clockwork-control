<?php

use App\Models\Server;
use App\Models\User;
use App\Services\Fail2ban\Fail2banProvisioner;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| ServerProvisionController::fail2ban()
|--------------------------------------------------------------------------
|
| Fail2banProvisioner::provision() is SSH-based (it shells a bash script onto
| the box) — mocked at the service level here rather than digging into its
| SshClient/IgnoreIpListBuilder collaborators, matching the task brief
| ("mock the real provisioning call"). Where a test needs the
| clockwork_jail_provisioned_at side effect the real provision() performs
| (stamping the server row before returning), the mock reproduces it via
| andReturnUsing() so the controller's own `$server->fresh()->
| clockwork_jail_provisioned_at?->diffForHumans()` read has something to see.
*/

beforeEach(function () {
    $this->mockIssueCounterZero();
});

it('requires authentication', function () {
    $server = Server::factory()->create();

    $this->post(route('servers.provision.fail2ban', $server))
        ->assertRedirect(route('login'));
});

it('refuses to provision, without calling the provisioner, when the server has no SSH password or key', function () {
    $server = Server::factory()->create([
        'ssh_password' => null,
        'ssh_private_key' => null,
    ]);

    $this->mock(Fail2banProvisioner::class)->shouldNotReceive('provision');

    $response = $this->actingAs(User::factory()->create())
        ->post(route('servers.provision.fail2ban', $server));

    $response->assertStatus(422);
    $response->assertJson([
        'ok' => false,
        'message' => 'Set an SSH password (or key) on this server before provisioning.',
        'output' => '',
        'already_provisioned' => false,
    ]);
});

it('provisions successfully and reports the provisioned_at timestamp', function () {
    $server = Server::factory()->create(['ssh_password' => 'stored-ssh-password']);

    $this->mock(Fail2banProvisioner::class)
        ->shouldReceive('provision')
        ->once()
        ->withArgs(fn (Server $s) => $s->is($server))
        ->andReturnUsing(function (Server $s) {
            $s->update(['clockwork_jail_provisioned_at' => now(), 'last_provision_log' => 'STATUS: provisioned']);

            return [
                'ok' => true,
                'output' => 'STATUS: provisioned',
                'already_provisioned' => false,
                'message' => 'Provisioned successfully.',
            ];
        });

    $response = $this->actingAs(User::factory()->create())
        ->post(route('servers.provision.fail2ban', $server));

    $response->assertOk();
    $response->assertJson([
        'ok' => true,
        'output' => 'STATUS: provisioned',
        'already_provisioned' => false,
        'message' => 'Provisioned successfully.',
    ]);

    $body = $response->json();
    expect($body['provisioned_at'])->not->toBeNull();
    expect($server->fresh()->clockwork_jail_provisioned_at)->not->toBeNull();
});

it('reports a failure without throwing when provisioning fails (e.g. SSH connect failure)', function () {
    $server = Server::factory()->create(['ssh_password' => 'stored-ssh-password']);

    $this->mock(Fail2banProvisioner::class)
        ->shouldReceive('provision')
        ->once()
        ->andReturn([
            'ok' => false,
            'output' => '',
            'already_provisioned' => false,
            'message' => 'SSH connect failed: Cannot reach clockwork-deploy@203.0.113.10:22 — TCP connect failed',
        ]);

    $response = $this->actingAs(User::factory()->create())
        ->post(route('servers.provision.fail2ban', $server));

    $response->assertOk();
    $response->assertJson([
        'ok' => false,
        'already_provisioned' => false,
    ]);
    expect($response->json('message'))->toContain('SSH connect failed');
    expect($response->json('provisioned_at'))->toBeNull();
    expect($server->fresh()->clockwork_jail_provisioned_at)->toBeNull();
});
