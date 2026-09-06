<?php

namespace Tests\Feature\Console;

use App\Models\ActionLog;
use App\Models\Server;
use App\Services\Chat\ChatNotifier;
use Illuminate\Support\Facades\Log;
use Mockery;

/**
 * Phase 4 notification-firing coverage for
 * clockwork:reap-stale-server-updates. ChatNotifierGatingTest already fully
 * covers ChatNotifierDispatcher's own is_inactive gating — serverUpdateFailed
 * has no Site parameter and is never gated on it (see that file's
 * 'neverGatedMethods'/non-site-scoped notes), so this file is only about
 * whether ReapStaleServerUpdates itself calls serverUpdateFailed() under the
 * right condition: unlike ReapStaleUpdateJobs, there is no nightly-vs-manual
 * distinction here (see the class docblock) — every reaped row alerts.
 */
describe('ReapStaleServerUpdates notification firing', function () {
    it('fires serverUpdateFailed for a server stuck running past the stale threshold', function () {
        $server = Server::factory()->create([
            'update_status' => Server::UPDATE_STATUS_RUNNING,
            'update_started_at' => now()->subMinutes(130),
        ]);

        $this->mock(ChatNotifier::class, function ($mock) use ($server) {
            $mock->shouldReceive('serverUpdateFailed')
                ->once()
                ->with(
                    Mockery::on(fn ($s) => $s instanceof Server && $s->id === $server->id),
                    Mockery::on(fn ($reason) => is_string($reason) && str_contains($reason, 'reaped:'))
                )
                ->andReturn(true);
        });

        $this->artisan('clockwork:reap-stale-server-updates')->assertSuccessful();

        $server->refresh();
        expect($server->update_status)->toBe(Server::UPDATE_STATUS_FAILED);

        expect(ActionLog::query()
            ->where('server_id', $server->id)
            ->where('action_type', ActionLog::TYPE_SERVER_UPDATE_REAPED)
            ->exists())->toBeTrue();
    });

    it('does not fire for a server whose update_started_at is still within the threshold (legitimately in progress)', function () {
        Server::factory()->create([
            'update_status' => Server::UPDATE_STATUS_RUNNING,
            'update_started_at' => now()->subMinutes(30),
        ]);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('serverUpdateFailed')->never();
        });

        $this->artisan('clockwork:reap-stale-server-updates')->assertSuccessful();
    });

    it('does not fire for servers whose update_status is not running, regardless of update_started_at age', function () {
        Server::factory()->create([
            'update_status' => Server::UPDATE_STATUS_COMPLETED,
            'update_started_at' => now()->subMinutes(200),
        ]);
        Server::factory()->create([
            'update_status' => Server::UPDATE_STATUS_FAILED,
            'update_started_at' => now()->subMinutes(200),
        ]);
        Server::factory()->create([
            'update_status' => Server::UPDATE_STATUS_QUEUED,
            'update_started_at' => now()->subMinutes(200),
        ]);
        Server::factory()->create([
            'update_status' => null,
            'update_started_at' => null,
        ]);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('serverUpdateFailed')->never();
        });

        $this->artisan('clockwork:reap-stale-server-updates')->assertSuccessful();
    });

    it('fires once per stale server when multiple servers are stuck', function () {
        $stale1 = Server::factory()->create([
            'update_status' => Server::UPDATE_STATUS_RUNNING,
            'update_started_at' => now()->subMinutes(125),
        ]);
        $stale2 = Server::factory()->create([
            'update_status' => Server::UPDATE_STATUS_RUNNING,
            'update_started_at' => now()->subMinutes(300),
        ]);
        // A healthy, recently-started row mixed in should not add a call.
        Server::factory()->create([
            'update_status' => Server::UPDATE_STATUS_RUNNING,
            'update_started_at' => now()->subMinutes(10),
        ]);

        $seen = [];
        $this->mock(ChatNotifier::class, function ($mock) use (&$seen) {
            $mock->shouldReceive('serverUpdateFailed')
                ->twice()
                ->andReturnUsing(function ($server, $reason) use (&$seen) {
                    $seen[] = $server->id;

                    return true;
                });
        });

        $this->artisan('clockwork:reap-stale-server-updates')->assertSuccessful();

        expect($seen)->toEqualCanonicalizing([$stale1->id, $stale2->id]);
    });

    it('does not fail the command when the notifier throws (wrapped in try/catch)', function () {
        Server::factory()->create([
            'update_status' => Server::UPDATE_STATUS_RUNNING,
            'update_started_at' => now()->subMinutes(130),
        ]);

        Log::spy();

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('serverUpdateFailed')->once()->andThrow(new \RuntimeException('webhook unreachable'));
        });

        $this->artisan('clockwork:reap-stale-server-updates')->assertSuccessful();

        Log::shouldHaveReceived('warning')->once();
    });
});
