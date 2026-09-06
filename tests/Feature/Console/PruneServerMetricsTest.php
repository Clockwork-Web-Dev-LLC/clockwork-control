<?php

use App\Models\Server;
use App\Models\ServerMetric;

describe('PruneServerMetrics', function () {
    it('deletes rows older than the retention window and keeps recent ones', function () {
        $server = Server::factory()->create();

        $old = ServerMetric::factory()->create([
            'server_id' => $server->id,
            'recorded_at' => now()->subDays(91),
        ]);
        $recent = ServerMetric::factory()->create([
            'server_id' => $server->id,
            'recorded_at' => now()->subDays(10),
        ]);

        $this->artisan('clockwork:prune-server-metrics')->assertSuccessful();

        expect(ServerMetric::query()->whereKey($old->id)->exists())->toBeFalse()
            ->and(ServerMetric::query()->whereKey($recent->id)->exists())->toBeTrue();
    });

    it('respects a custom --days retention window', function () {
        $server = Server::factory()->create();

        $tenDaysOld = ServerMetric::factory()->create([
            'server_id' => $server->id,
            'recorded_at' => now()->subDays(10),
        ]);
        $twoDaysOld = ServerMetric::factory()->create([
            'server_id' => $server->id,
            'recorded_at' => now()->subDays(2),
        ]);

        $this->artisan('clockwork:prune-server-metrics', ['--days' => 5])->assertSuccessful();

        expect(ServerMetric::query()->whereKey($tenDaysOld->id)->exists())->toBeFalse()
            ->and(ServerMetric::query()->whereKey($twoDaysOld->id)->exists())->toBeTrue();
    });

    it('deletes nothing and still succeeds when the table is empty', function () {
        $this->artisan('clockwork:prune-server-metrics')->assertSuccessful();

        expect(ServerMetric::query()->count())->toBe(0);
    });

    it('reports the deleted count in its output', function () {
        $server = Server::factory()->create();
        ServerMetric::factory()->count(3)->create([
            'server_id' => $server->id,
            'recorded_at' => now()->subDays(200),
        ]);

        $this->artisan('clockwork:prune-server-metrics')
            ->expectsOutputToContain('Pruned 3 row(s)')
            ->assertSuccessful();
    });
});
