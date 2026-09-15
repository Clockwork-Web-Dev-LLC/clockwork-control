<?php

use App\Models\ActionLog;
use App\Models\BlockedIp;
use App\Models\Server;
use App\Services\Fail2ban\BanRetention;
use App\Support\Settings;

describe('BanRetention', function () {
    beforeEach(function () {
        $this->retention = app(BanRetention::class);
        $this->settings = app(Settings::class);
    });

    it('defaults to 12 months retention', function () {
        expect($this->retention->retentionMonths())->toBe(12);
    });

    it('updates and persists retention months', function () {
        $this->retention->setRetentionMonths(6);
        expect($this->retention->retentionMonths())->toBe(6);

        $this->retention->setRetentionMonths(0);
        expect($this->retention->retentionMonths())->toBe(0);
    });

    it('computes breakdown across age buckets', function () {
        $server = Server::factory()->create();

        // Active ban 2 months ago
        BlockedIp::factory()->create([
            'server_id' => $server->id,
            'ip' => '192.0.2.1',
            'banned_at' => now()->subMonths(2),
            'unbanned_at' => null,
        ]);

        // Active ban 4 months ago
        BlockedIp::factory()->create([
            'server_id' => $server->id,
            'ip' => '192.0.2.2',
            'banned_at' => now()->subMonths(4),
            'unbanned_at' => null,
        ]);

        // Active ban 14 months ago
        BlockedIp::factory()->create([
            'server_id' => $server->id,
            'ip' => '192.0.2.3',
            'banned_at' => now()->subMonths(14),
            'unbanned_at' => null,
        ]);

        // Already unbanned IP (should not be in active breakdown)
        BlockedIp::factory()->create([
            'server_id' => $server->id,
            'ip' => '192.0.2.4',
            'banned_at' => now()->subMonths(14),
            'unbanned_at' => now()->subMonths(13),
        ]);

        $breakdown = $this->retention->breakdown();

        expect($breakdown['total'])->toBe(3);
        expect($breakdown['older_than_1m'])->toBe(3);
        expect($breakdown['older_than_3m'])->toBe(2);
        expect($breakdown['older_than_6m'])->toBe(1);
        expect($breakdown['older_than_12m'])->toBe(1);
    });

    it('prunes active bans older than specified cutoff into history', function () {
        $server = Server::factory()->create();

        $recent = BlockedIp::factory()->create([
            'server_id' => $server->id,
            'ip' => '192.0.2.10',
            'banned_at' => now()->subDays(5),
            'unbanned_at' => null,
        ]);

        $old = BlockedIp::factory()->create([
            'server_id' => $server->id,
            'ip' => '192.0.2.20',
            'banned_at' => now()->subMonths(4),
            'unbanned_at' => null,
            'llm_verdict' => 'malicious',
        ]);

        $pruned = $this->retention->prune(3);

        expect($pruned)->toBe(1);

        $recent->refresh();
        $old->refresh();

        expect($recent->unbanned_at)->toBeNull();
        expect($old->unbanned_at)->not->toBeNull();
        expect($old->decided_by)->toBe(BanRetention::DECIDED_BY);
        expect($old->llm_verdict)->toBe('malicious');

        $log = ActionLog::query()->where('action_type', ActionLog::TYPE_BAN_EXPIRED)->first();
        expect($log)->not->toBeNull()
            ->and($log->actor)->toBe('auto');
    });

    it('prunes all active bans when cutoff is 0', function () {
        $server = Server::factory()->create();

        BlockedIp::factory()->count(3)->create([
            'server_id' => $server->id,
            'banned_at' => now()->subDays(2),
            'unbanned_at' => null,
        ]);

        $pruned = $this->retention->prune(0);

        expect($pruned)->toBe(3);
        expect(BlockedIp::query()->whereNull('unbanned_at')->count())->toBe(0);
    });
});
