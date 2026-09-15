<?php

use App\Models\BlockedIp;
use App\Models\ReviewQueueEntry;
use App\Models\Server;
use App\Models\Site;
use App\Services\Fail2ban\Fail2banClient;

describe('ProcessPendingBans command', function () {
    it('falls back to site server and backfills server_id when review entry server_id is null', function () {
        $server = Server::factory()->create(['name' => 'shared-host.clockworkwp.com']);
        $site = Site::factory()->create([
            'domain' => 'lockout-site.example.com',
            'server_id' => $server->id,
        ]);

        $entry = ReviewQueueEntry::factory()->create([
            'ip' => '203.0.113.50',
            'server_id' => null,
            'site_id' => $site->id,
            'status' => ReviewQueueEntry::STATUS_QUEUED_FOR_BAN,
            'decided_at' => now(),
            'decided_by' => 'auto-repeat',
        ]);

        $this->mock(Fail2banClient::class, function ($mock) use ($server) {
            $mock->shouldReceive('banIp')
                ->once()
                ->withArgs(fn ($s, $ip) => $s->id === $server->id && $ip === '203.0.113.50')
                ->andReturn([
                    'ok' => true,
                    'message' => 'Banned 203.0.113.50',
                    'output' => '1',
                ]);
        });

        $this->artisan('clockwork:process-pending-bans')
            ->assertSuccessful();

        $entry->refresh();
        expect($entry->status)->toBe(ReviewQueueEntry::STATUS_APPROVED);
        expect($entry->server_id)->toBe($server->id);

        $blockedIp = BlockedIp::query()->where('ip', '203.0.113.50')->first();
        expect($blockedIp)->not->toBeNull();
        expect($blockedIp->server_id)->toBe($server->id);
    });

    it('marks entry as failed if neither server nor site server can be found', function () {
        $entry = ReviewQueueEntry::factory()->create([
            'ip' => '192.0.2.99',
            'server_id' => null,
            'site_id' => null,
            'status' => ReviewQueueEntry::STATUS_QUEUED_FOR_BAN,
            'decided_at' => now(),
        ]);

        $this->artisan('clockwork:process-pending-bans')
            ->assertSuccessful();

        $entry->refresh();
        expect($entry->status)->toBe(ReviewQueueEntry::STATUS_FAILED);
        expect($entry->reason)->toContain('server record missing at process time');
    });
});
