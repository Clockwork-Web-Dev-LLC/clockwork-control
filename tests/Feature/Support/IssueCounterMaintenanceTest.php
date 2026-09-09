<?php

use App\Models\Server;
use App\Models\Site;
use App\Support\IssueCounter;
use Illuminate\Support\Facades\DB;

describe('IssueCounter maintenance tracking', function () {
    beforeEach(function () {
        $pdo = DB::connection()->getPdo();
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('JSON_UNQUOTE', fn ($value) => $value);
        }
    });
    it('flags sites that have been in maintenance mode for over 2 hours', function () {
        $server = Server::factory()->create(['is_ignored' => false]);

        $initialTotal = (new IssueCounter)->total();

        Site::factory()->create([
            'server_id' => $server->id,
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'maintenance',
            'uptime_maintenance_since' => now()->subMinutes(30),
            'uptime_ignored_at' => null,
        ]);

        expect((new IssueCounter)->total())->toBe($initialTotal);

        Site::factory()->create([
            'server_id' => $server->id,
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'maintenance',
            'uptime_maintenance_since' => now()->subHours(3),
            'uptime_ignored_at' => null,
        ]);

        expect(Site::query()->stuckInMaintenance()->count())->toBe(1);
        expect((new IssueCounter)->total())->toBe($initialTotal + 1);
    });

    it('does not flag ignored or unmonitored maintenance sites', function () {
        $server = Server::factory()->create(['is_ignored' => false]);

        Site::factory()->create([
            'server_id' => $server->id,
            'uptime_monitoring_enabled' => true,
            'uptime_state' => 'maintenance',
            'uptime_maintenance_since' => now()->subHours(3),
            'uptime_ignored_at' => now(),
        ]);

        Site::factory()->create([
            'server_id' => $server->id,
            'uptime_monitoring_enabled' => false,
            'uptime_state' => 'maintenance',
            'uptime_maintenance_since' => now()->subHours(3),
            'uptime_ignored_at' => null,
        ]);

        expect(Site::query()->stuckInMaintenance()->count())->toBe(0);
    });
});
