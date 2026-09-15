<?php

use App\Models\BlockedIp;
use App\Models\Server;
use App\Support\Settings;

describe('PruneExpiredBans command', function () {
    it('prunes bans older than the default retention period of 12 months', function () {
        $server = Server::factory()->create();

        $recent = BlockedIp::factory()->create([
            'server_id' => $server->id,
            'ip' => '198.51.100.1',
            'banned_at' => now()->subMonths(6),
            'unbanned_at' => null,
        ]);

        $expired = BlockedIp::factory()->create([
            'server_id' => $server->id,
            'ip' => '198.51.100.2',
            'banned_at' => now()->subMonths(14),
            'unbanned_at' => null,
        ]);

        $this->artisan('clockwork:prune-expired-bans')
            ->expectsOutputToContain('Pruned 1 active ban(s)')
            ->assertSuccessful();

        $recent->refresh();
        $expired->refresh();

        expect($recent->unbanned_at)->toBeNull();
        expect($expired->unbanned_at)->not->toBeNull();
        expect($expired->llm_verdict)->not->toBe('expired');
        expect($expired->decided_by)->toBe('retention');
    });

    it('supports --dry-run without modifying database records', function () {
        $server = Server::factory()->create();

        $expired = BlockedIp::factory()->create([
            'server_id' => $server->id,
            'ip' => '198.51.100.3',
            'banned_at' => now()->subMonths(15),
            'unbanned_at' => null,
        ]);

        $this->artisan('clockwork:prune-expired-bans', ['--dry-run' => true])
            ->expectsOutputToContain('[Dry run] Would prune 1 active ban(s)')
            ->assertSuccessful();

        $expired->refresh();
        expect($expired->unbanned_at)->toBeNull();
    });

    it('respects --months option override', function () {
        $server = Server::factory()->create();

        $threeMonthsOld = BlockedIp::factory()->create([
            'server_id' => $server->id,
            'ip' => '198.51.100.4',
            'banned_at' => now()->subMonths(4),
            'unbanned_at' => null,
        ]);

        $this->artisan('clockwork:prune-expired-bans', ['--months' => 3])
            ->expectsOutputToContain('Pruned 1 active ban(s)')
            ->assertSuccessful();

        $threeMonthsOld->refresh();
        expect($threeMonthsOld->unbanned_at)->not->toBeNull();
    });

    it('skips pruning when retention is set to 0 (indefinite)', function () {
        app(Settings::class)->put('bans.retention_months', 0);

        $server = Server::factory()->create();

        $veryOld = BlockedIp::factory()->create([
            'server_id' => $server->id,
            'ip' => '198.51.100.5',
            'banned_at' => now()->subYears(3),
            'unbanned_at' => null,
        ]);

        $this->artisan('clockwork:prune-expired-bans')
            ->expectsOutputToContain('Ban retention is set to indefinite (0 months)')
            ->assertSuccessful();

        $veryOld->refresh();
        expect($veryOld->unbanned_at)->toBeNull();
    });

    it('prunes all active bans when --months=0 is passed even if retention is indefinite', function () {
        app(Settings::class)->put('bans.retention_months', 0);

        $server = Server::factory()->create();

        $veryOld = BlockedIp::factory()->create([
            'server_id' => $server->id,
            'ip' => '198.51.100.6',
            'banned_at' => now()->subYears(3),
            'unbanned_at' => null,
        ]);

        $this->artisan('clockwork:prune-expired-bans', ['--months' => '0'])
            ->expectsOutputToContain('Pruned 1 active ban(s)')
            ->assertSuccessful();

        $veryOld->refresh();
        expect($veryOld->unbanned_at)->not->toBeNull();
        expect($veryOld->decided_by)->toBe('retention');
    });
});
