<?php

use App\Models\Server;
use App\Models\Tag;
use App\Models\User;
use App\Services\Servers\ServerUpdater;
use Illuminate\Support\Carbon;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| ServerUpdateController
|--------------------------------------------------------------------------
|
| queue()/cancel() are pure state-flips on Server::update_status /
| scheduled_reboot_at — no external collaborator, so no mocking needed there.
| reboot()/cancelReboot()/probeReboot() all go through the injected
| ServerUpdater, which is SSH-based — mocked at the service level per the
| task brief ("mock whatever probes reboot status").
|
| Column/constant names confirmed by reading app/Models/Server.php:
|   update_status: null|queued|running|completed|failed (Server::UPDATE_STATUS_*)
|   update_queued_at / update_started_at / update_completed_at / scheduled_reboot_at
|   reboot_required (bool), last_polled_at, last_ssh_ok_at
| Server has no `timezone` column — $server->timezone is always null at the
| Eloquent level, so every code path here falls through to
| config('app.timezone') = 'UTC' (config/app.php).
*/

beforeEach(function () {
    $this->mockIssueCounterZero();
    Carbon::setTestNow(Carbon::parse('2026-09-03 10:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('requires authentication', function () {
    $server = Server::factory()->create();

    $this->post(route('servers.update.queue', $server))->assertRedirect(route('login'));
});

describe('queue', function () {
    it('marks the server queued and stamps update_queued_at', function () {
        $server = Server::factory()->create(['is_ignored' => false, 'update_status' => null]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('servers.update.queue', $server));

        $response->assertRedirect();
        $response->assertSessionHas('status', "Update queued for {$server->name}. The processor runs every minute.");
        $response->assertSessionMissing('status_error');

        $fresh = $server->fresh();
        expect($fresh->update_status)->toBe(Server::UPDATE_STATUS_QUEUED);
        expect($fresh->update_queued_at)->not->toBeNull();
        expect($fresh->update_started_at)->toBeNull();
        expect($fresh->update_completed_at)->toBeNull();
        expect($fresh->scheduled_reboot_at)->toBeNull();
    });

    it('schedules a same-day reboot when reboot_at is still ahead of now', function () {
        $server = Server::factory()->create(['is_ignored' => false, 'update_status' => null]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('servers.update.queue', $server), ['reboot_at' => '11:30']);

        $response->assertSessionHas('status');
        expect(session('status'))->toContain('Reboot will be scheduled for 11:30');

        $fresh = $server->fresh();
        expect($fresh->update_status)->toBe(Server::UPDATE_STATUS_QUEUED);
        expect($fresh->scheduled_reboot_at->toDateTimeString())->toBe('2026-09-03 11:30:00');
    });

    it('rolls a reboot_at that has already passed today to tomorrow', function () {
        $server = Server::factory()->create(['is_ignored' => false, 'update_status' => null]);

        $this->actingAs(User::factory()->create())
            ->post(route('servers.update.queue', $server), ['reboot_at' => '09:00']);

        expect($server->fresh()->scheduled_reboot_at->toDateTimeString())->toBe('2026-09-04 09:00:00');
    });

    it('refuses to queue an ignored server', function () {
        $server = Server::factory()->create(['is_ignored' => true, 'update_status' => null]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('servers.update.queue', $server));

        $response->assertSessionHas('status_error', "Cannot run updates on ignored server {$server->name}.");
        expect($server->fresh()->update_status)->toBeNull();
    });

    it('allows queuing a staging-tagged server — system updates are not gated on the staging tag', function () {
        $server = Server::factory()->create(['is_ignored' => false, 'update_status' => null]);
        $server->tags()->attach(Tag::factory()->create(['slug' => 'staging']));

        $response = $this->actingAs(User::factory()->create())
            ->post(route('servers.update.queue', $server));

        $response->assertSessionHas('status', "Update queued for {$server->name}. The processor runs every minute.");
        $response->assertSessionMissing('status_error');
        expect($server->fresh()->update_status)->toBe(Server::UPDATE_STATUS_QUEUED);
    });

    it('refuses to queue a server that already has an update queued or running', function () {
        $server = Server::factory()->create(['is_ignored' => false, 'update_status' => Server::UPDATE_STATUS_RUNNING]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('servers.update.queue', $server));

        $response->assertSessionHas('status_error', 'An update is already queued or running for this server.');
        expect($server->fresh()->update_status)->toBe(Server::UPDATE_STATUS_RUNNING);
    });

    it('rejects a malformed reboot_at value via validation', function () {
        $server = Server::factory()->create(['is_ignored' => false, 'update_status' => null]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('servers.update.queue', $server), ['reboot_at' => 'not-a-time']);

        $response->assertSessionHasErrors('reboot_at');
        expect($server->fresh()->update_status)->toBeNull();
    });
});

describe('cancel', function () {
    it('cancels a queued update', function () {
        $server = Server::factory()->create([
            'update_status' => Server::UPDATE_STATUS_QUEUED,
            'update_queued_at' => now(),
            'scheduled_reboot_at' => now()->addHour(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('servers.update.cancel', $server));

        $response->assertSessionHas('status', "Cancelled queued update for {$server->name}.");

        $fresh = $server->fresh();
        expect($fresh->update_status)->toBeNull();
        expect($fresh->update_queued_at)->toBeNull();
        expect($fresh->scheduled_reboot_at)->toBeNull();
    });

    it('refuses to cancel an update that is not in the queued state', function () {
        $server = Server::factory()->create(['update_status' => Server::UPDATE_STATUS_RUNNING]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('servers.update.cancel', $server));

        $response->assertSessionHas('status_error', 'Update is not in the queued state — cannot cancel.');
        expect($server->fresh()->update_status)->toBe(Server::UPDATE_STATUS_RUNNING);
    });
});

describe('reboot', function () {
    it('refuses to reboot an ignored server', function () {
        $server = Server::factory()->create(['is_ignored' => true, 'ssh_password' => 'pw']);

        $this->mock(ServerUpdater::class)->shouldNotReceive('reboot');

        $response = $this->actingAs(User::factory()->create())
            ->post(route('servers.reboot', $server));

        $response->assertSessionHas('status_error', "Cannot reboot ignored server {$server->name}.");
    });

    it('refuses to reboot a server with no SSH credentials and no prior fail2ban provisioning', function () {
        $server = Server::factory()->create([
            'is_ignored' => false,
            'clockwork_jail_provisioned_at' => null,
            'ssh_password' => null,
        ]);

        $this->mock(ServerUpdater::class)->shouldNotReceive('reboot');

        $response = $this->actingAs(User::factory()->create())
            ->post(route('servers.reboot', $server));

        $response->assertSessionHas('status_error', "Server {$server->name} has no SSH credentials.");
    });

    it('reboots successfully (HTML request) and clears the stale reboot_required flag', function () {
        $server = Server::factory()->create([
            'is_ignored' => false,
            'ssh_password' => 'pw',
            'reboot_required' => true,
            'last_polled_at' => now()->subDay(),
        ]);

        $this->mock(ServerUpdater::class)
            ->shouldReceive('reboot')
            ->once()
            ->withArgs(fn (Server $s, $at) => $s->is($server) && $at === null)
            ->andReturn([
                'ok' => true,
                'output' => 'STATUS: reboot-scheduled',
                'message' => "Reboot starting in ~1 minute on {$server->name}.",
                'scheduled_for' => '+1',
            ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('servers.reboot', $server));

        $response->assertRedirect();
        expect(session('status'))->toContain("Reboot starting in ~1 minute on {$server->name}.");

        $fresh = $server->fresh();
        expect($fresh->reboot_required)->toBeFalse();
        expect($fresh->last_polled_at)->toBeNull();
        expect($fresh->scheduled_reboot_at)->not->toBeNull();
    });

    it('reboots successfully (JSON request)', function () {
        $server = Server::factory()->create(['is_ignored' => false, 'ssh_password' => 'pw']);

        $this->mock(ServerUpdater::class)->shouldReceive('reboot')->once()->andReturn([
            'ok' => true,
            'output' => 'STATUS: reboot-scheduled',
            'message' => "Reboot starting in ~1 minute on {$server->name}.",
            'scheduled_for' => '+1',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('servers.reboot', $server));

        $response->assertOk();
        $response->assertJson(['ok' => true]);
        expect($response->json('message'))->toContain('Reboot starting');
        expect($response->json('scheduled_for'))->not->toBeNull();
    });

    it('flashes status_error and leaves the server untouched when the reboot command fails', function () {
        $server = Server::factory()->create([
            'is_ignored' => false,
            'ssh_password' => 'pw',
            'reboot_required' => true,
        ]);

        $this->mock(ServerUpdater::class)->shouldReceive('reboot')->once()->andReturn([
            'ok' => false,
            'output' => 'sudo: a password is required',
            'message' => "Reboot command failed on {$server->name}.",
            'scheduled_for' => null,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('servers.reboot', $server));

        $response->assertSessionHas('status_error');
        expect(session('status_error'))->toContain("Reboot command failed on {$server->name}.");
        expect(session('status_error'))->toContain('sudo: a password is required');
        expect($server->fresh()->reboot_required)->toBeTrue();
    });
});

describe('cancelReboot', function () {
    it('refuses to cancel a reboot on an ignored server', function () {
        $server = Server::factory()->create(['is_ignored' => true]);

        $this->mock(ServerUpdater::class)->shouldNotReceive('cancelReboot');

        $response = $this->actingAs(User::factory()->create())
            ->post(route('servers.reboot.cancel', $server));

        $response->assertSessionHas('status_error', "Cannot manage reboots on ignored server {$server->name}.");
    });

    it('cancels a scheduled reboot', function () {
        $server = Server::factory()->create([
            'is_ignored' => false,
            'scheduled_reboot_at' => now()->addHour(),
        ]);

        $this->mock(ServerUpdater::class)
            ->shouldReceive('cancelReboot')
            ->once()
            ->withArgs(fn (Server $s) => $s->is($server))
            ->andReturn(['ok' => true, 'output' => 'STATUS: cancel-issued', 'message' => "Cancel issued on {$server->name}."]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('servers.reboot.cancel', $server));

        $response->assertSessionHas('status', "Cancelled scheduled reboot on {$server->name}.");
        expect($server->fresh()->scheduled_reboot_at)->toBeNull();
    });

    it('flashes status_error and leaves scheduled_reboot_at intact when the cancel command fails', function () {
        $scheduledFor = now()->addHour();
        $server = Server::factory()->create([
            'is_ignored' => false,
            'scheduled_reboot_at' => $scheduledFor,
        ]);

        $this->mock(ServerUpdater::class)->shouldReceive('cancelReboot')->once()->andReturn([
            'ok' => false,
            'output' => 'connection refused',
            'message' => "Cancel failed on {$server->name}.",
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('servers.reboot.cancel', $server));

        $response->assertSessionHas('status_error');
        expect(session('status_error'))->toContain('connection refused');
        expect($server->fresh()->scheduled_reboot_at?->toDateTimeString())->toBe($scheduledFor->toDateTimeString());
    });
});

describe('probeReboot', function () {
    it('updates reboot_required from a live probe and reports uptime', function () {
        $server = Server::factory()->create(['reboot_required' => false, 'last_ssh_ok_at' => null]);

        $this->mock(ServerUpdater::class)
            ->shouldReceive('probeRebootState')
            ->once()
            ->withArgs(fn (Server $s) => $s->is($server))
            ->andReturn(['required' => true, 'uptime_seconds' => 700]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('servers.reboot.probe', $server));

        $response->assertOk();
        $response->assertJson(['ok' => true, 'reboot_required' => true, 'uptime_seconds' => 700]);

        $fresh = $server->fresh();
        expect($fresh->reboot_required)->toBeTrue();
        expect($fresh->last_ssh_ok_at)->not->toBeNull();
    });

    it('returns a 422 without touching the server when the box is unreachable', function () {
        $server = Server::factory()->create(['reboot_required' => false, 'last_ssh_ok_at' => null]);

        $this->mock(ServerUpdater::class)->shouldReceive('probeRebootState')->once()->andReturn(null);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('servers.reboot.probe', $server));

        $response->assertStatus(422);
        $response->assertJson(['ok' => false]);

        $fresh = $server->fresh();
        expect($fresh->reboot_required)->toBeFalse();
        expect($fresh->last_ssh_ok_at)->toBeNull();
    });
});
