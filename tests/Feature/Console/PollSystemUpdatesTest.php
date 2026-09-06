<?php

namespace Tests\Feature\Console;

use App\Models\Server;
use App\Models\ServerUpdateSnapshot;
use App\Models\Tag;
use App\Services\Servers\AptUpdateProbe;

/*
|--------------------------------------------------------------------------
| Phase 6 console-command coverage: clockwork:poll-system-updates
|--------------------------------------------------------------------------
|
| AptUpdateProbe shells out over SSH (see its own class docblock), so it is
| always mocked here — this suite must never attempt real SSH. Coverage is
| about the command's own logic: which servers it selects (default vs
| --all vs --server filter), the snapshot upsert, and the "only a
| STATUS_OK probe may overwrite the denormalised upgrade_required /
| reboot_required flags" rule documented inline in the command.
*/

function okProbePayload(array $overrides = []): array
{
    return array_merge([
        'total_updates' => 3,
        'security_updates' => 1,
        'reboot_required' => false,
        'reboot_required_pkgs' => [],
        'poll_status' => ServerUpdateSnapshot::STATUS_OK,
        'poll_error' => null,
    ], $overrides);
}

describe('default server selection (upgrade_required=true only)', function () {
    it('probes only servers flagged upgrade_required=true and persists a snapshot + refreshed flags', function () {
        $due = Server::factory()->create(['upgrade_required' => true, 'reboot_required' => false]);
        $notDue = Server::factory()->create(['upgrade_required' => false]);

        $this->mock(AptUpdateProbe::class, function ($mock) use ($due) {
            $mock->shouldReceive('probe')
                ->once()
                ->withArgs(fn (Server $s) => $s->id === $due->id)
                ->andReturn(okProbePayload(['total_updates' => 5, 'security_updates' => 2, 'reboot_required' => true]));
        });

        $this->artisan('clockwork:poll-system-updates')->assertSuccessful();

        $snapshot = ServerUpdateSnapshot::query()->where('server_id', $due->id)->first();
        expect($snapshot)->not->toBeNull()
            ->and($snapshot->total_updates)->toBe(5)
            ->and($snapshot->security_updates)->toBe(2)
            ->and($snapshot->reboot_required)->toBeTrue()
            ->and($snapshot->poll_status)->toBe(ServerUpdateSnapshot::STATUS_OK);

        expect(Server::find($due->id)->upgrade_required)->toBeTrue()
            ->and(Server::find($due->id)->reboot_required)->toBeTrue();

        expect(ServerUpdateSnapshot::query()->where('server_id', $notDue->id)->exists())->toBeFalse();
    });

    it('excludes non-monitored (ignored) servers even when upgrade_required=true', function () {
        $ignored = Server::factory()->ignored()->create(['upgrade_required' => true]);

        $this->mock(AptUpdateProbe::class, function ($mock) {
            $mock->shouldReceive('probe')->never();
        });

        $this->artisan('clockwork:poll-system-updates')->assertSuccessful();

        expect(ServerUpdateSnapshot::query()->where('server_id', $ignored->id)->exists())->toBeFalse();
    });

    // Note: `hostname` is a NOT NULL column at the schema level (see
    // 2026_04_29_192640_create_servers_table.php), so the command's own
    // whereNotNull('hostname') guard can never actually exclude a row in
    // practice — there is no factory state that produces a persistable null
    // hostname to exercise it against, so it's not covered here.
});

describe('--all', function () {
    it('probes every non-ignored server regardless of upgrade_required or staging tag', function () {
        $due = Server::factory()->create(['upgrade_required' => true]);
        $notDue = Server::factory()->create(['upgrade_required' => false]);
        $staging = Server::factory()->create(['upgrade_required' => false]);
        $stagingTag = Tag::factory()->create(['name' => 'Staging', 'slug' => 'staging']);
        $staging->tags()->attach($stagingTag);
        $ignored = Server::factory()->ignored()->create(['upgrade_required' => false]);

        $this->mock(AptUpdateProbe::class, function ($mock) {
            $mock->shouldReceive('probe')->times(3)->andReturn(okProbePayload());
        });

        $this->artisan('clockwork:poll-system-updates', ['--all' => true])->assertSuccessful();

        expect(ServerUpdateSnapshot::query()->where('server_id', $due->id)->exists())->toBeTrue()
            ->and(ServerUpdateSnapshot::query()->where('server_id', $notDue->id)->exists())->toBeTrue()
            ->and(ServerUpdateSnapshot::query()->where('server_id', $staging->id)->exists())->toBeTrue()
            ->and(ServerUpdateSnapshot::query()->where('server_id', $ignored->id)->exists())->toBeFalse();
    });

    it('gracefully handles an exception on one server and continues polling subsequent servers', function () {
        $server1 = Server::factory()->create(['name' => 'server1.example.com']);
        $server2 = Server::factory()->create(['name' => 'server2.example.com']);

        $this->mock(AptUpdateProbe::class, function ($mock) use ($server1, $server2) {
            $mock->shouldReceive('probe')
                ->once()
                ->withArgs(fn (Server $s) => $s->id === $server1->id)
                ->andThrow(new \RuntimeException('Unexpected network glitch'));

            $mock->shouldReceive('probe')
                ->once()
                ->withArgs(fn (Server $s) => $s->id === $server2->id)
                ->andReturn(okProbePayload(['total_updates' => 4]));
        });

        $this->artisan('clockwork:poll-system-updates', ['--all' => true])->assertSuccessful();

        $snap1 = ServerUpdateSnapshot::query()->where('server_id', $server1->id)->first();
        expect($snap1)->not->toBeNull()
            ->and($snap1->poll_status)->toBe(ServerUpdateSnapshot::STATUS_SSH_FAILED)
            ->and($snap1->poll_error)->toContain('Unexpected network glitch');

        $snap2 = ServerUpdateSnapshot::query()->where('server_id', $server2->id)->first();
        expect($snap2)->not->toBeNull()
            ->and($snap2->poll_status)->toBe(ServerUpdateSnapshot::STATUS_OK)
            ->and($snap2->total_updates)->toBe(4);
    });
});

describe('--server filter', function () {
    it('limits the probe to a single server given by id, ignoring other eligible servers', function () {
        $target = Server::factory()->create(['upgrade_required' => true]);
        $other = Server::factory()->create(['upgrade_required' => true]);

        $this->mock(AptUpdateProbe::class, function ($mock) use ($target) {
            $mock->shouldReceive('probe')
                ->once()
                ->withArgs(fn (Server $s) => $s->id === $target->id)
                ->andReturn(okProbePayload());
        });

        $this->artisan('clockwork:poll-system-updates', ['--server' => (string) $target->id])->assertSuccessful();

        expect(ServerUpdateSnapshot::query()->where('server_id', $target->id)->exists())->toBeTrue()
            ->and(ServerUpdateSnapshot::query()->where('server_id', $other->id)->exists())->toBeFalse();
    });

    it('limits the probe to a single server given by name', function () {
        $target = Server::factory()->create(['upgrade_required' => true, 'name' => 'named-target.example.com']);

        $this->mock(AptUpdateProbe::class, function ($mock) use ($target) {
            $mock->shouldReceive('probe')
                ->once()
                ->withArgs(fn (Server $s) => $s->id === $target->id)
                ->andReturn(okProbePayload());
        });

        $this->artisan('clockwork:poll-system-updates', ['--server' => 'named-target.example.com'])->assertSuccessful();

        expect(ServerUpdateSnapshot::query()->where('server_id', $target->id)->exists())->toBeTrue();
    });
});

describe('non-OK probe results do not clobber SpinupWP-sourced flags', function () {
    it('persists an ssh_failed snapshot but leaves upgrade_required/reboot_required untouched', function () {
        $server = Server::factory()->create(['upgrade_required' => true, 'reboot_required' => false]);

        $this->mock(AptUpdateProbe::class, function ($mock) {
            $mock->shouldReceive('probe')->once()->andReturn([
                'total_updates' => 0,
                'security_updates' => 0,
                'reboot_required' => false,
                'reboot_required_pkgs' => [],
                'poll_status' => ServerUpdateSnapshot::STATUS_SSH_FAILED,
                'poll_error' => 'connection refused',
            ]);
        });

        $this->artisan('clockwork:poll-system-updates')->assertSuccessful();

        $snapshot = ServerUpdateSnapshot::query()->where('server_id', $server->id)->first();
        expect($snapshot->poll_status)->toBe(ServerUpdateSnapshot::STATUS_SSH_FAILED);

        // Flags stay exactly as they were before the probe — the failed
        // probe carries no signal worth trusting over SpinupWP's.
        expect(Server::find($server->id)->upgrade_required)->toBeTrue()
            ->and(Server::find($server->id)->reboot_required)->toBeFalse();
    });
});

describe('no-op run', function () {
    it('exits successfully and probes nothing when no server matches', function () {
        Server::factory()->create(['upgrade_required' => false]);

        $this->mock(AptUpdateProbe::class, function ($mock) {
            $mock->shouldReceive('probe')->never();
        });

        $this->artisan('clockwork:poll-system-updates')->assertSuccessful();

        expect(ServerUpdateSnapshot::query()->count())->toBe(0);
    });
});
