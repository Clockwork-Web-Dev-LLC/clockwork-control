<?php

namespace Tests\Feature\Console;

use App\Models\BlockedIp;
use App\Models\ReviewQueueEntry;
use App\Models\Server;
use App\Models\Site;
use App\Services\Chat\ChatNotifier;
use App\Services\Fail2ban\Fail2banClient;
use App\Services\Fail2ban\IgnoreIpMatcher;
use App\Services\Ingest\IngestScheduleGate;
use App\Services\Wordfence\WordfenceBlocksPuller;

/*
|--------------------------------------------------------------------------
| PullWordfenceBlocks — gap-fill coverage
|--------------------------------------------------------------------------
|
| Mirrors PullLlarLockoutsTest.php. tests/Feature/Chat/IpBlockedCallSitesTest.php
| already covers the auto-ban-on/off + review-queue-dedup paths for this
| command via ChatNotifier::ipBlocked call-site coverage. This file adds
| only: the --server= filter (resolveServers()), --dry-run's actual
| side-effect suppression, and the IgnoreIpMatcher protected-IP path with a
| real (non-null) reason.
*/

/**
 * @return array{0: Server, 1: Site}
 */
function pullWordfencePullableServer(array $serverOverrides = []): array
{
    $server = Server::factory()->create(array_merge([
        'is_ignored' => false,
    ], $serverOverrides));

    $site = Site::factory()->for($server)->create([
        'is_wordpress' => true,
        'db_password' => 'db-secret',
    ]);

    return [$server, $site];
}

describe('--server= filter (resolveServers)', function () {
    it('limits the pull to the matched server only, by hostname', function () {
        [$targetServer, $targetSite] = pullWordfencePullableServer(['hostname' => '10.20.30.40']);
        [$otherServer] = pullWordfencePullableServer();

        $this->mock(WordfenceBlocksPuller::class, function ($mock) use ($targetSite) {
            $mock->shouldReceive('activeBlocks')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($targetSite))
                ->andReturn([]);
        });

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('ipBlocked')->never();
        });

        $this->artisan('clockwork:pull-wordfence-blocks', ['--server' => '10.20.30.40'])
            ->assertSuccessful();

        expect($targetServer->refresh()->last_wordfence_pull_at)->not->toBeNull();
        expect($otherServer->refresh()->last_wordfence_pull_at)->toBeNull();
    });

    it('warns and exits successfully when nothing matches the filter', function () {
        pullWordfencePullableServer();

        $this->mock(WordfenceBlocksPuller::class, function ($mock) {
            $mock->shouldNotReceive('activeBlocks');
        });

        $this->artisan('clockwork:pull-wordfence-blocks', ['--server' => 'nonexistent-server'])
            ->assertSuccessful()
            ->expectsOutputToContain('No servers matched.');
    });
});

describe('--dry-run', function () {
    it('reports what WOULD happen but bans nothing, queues nothing, and does not advance the pull watermark', function () {
        [$server, $site] = pullWordfencePullableServer(['auto_ban_wordfence' => true]);

        $this->mock(WordfenceBlocksPuller::class, function ($mock) use ($site) {
            $mock->shouldReceive('activeBlocks')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($site))
                ->andReturn([
                    ['ip' => '198.51.100.20', 'expires_at' => null, 'source_table' => 'wp_wfBlocks7', 'reason' => 'brute force', 'type' => 'brute'],
                ]);
        });

        $this->mock(IgnoreIpMatcher::class, function ($mock) {
            $mock->shouldReceive('reason')->with('198.51.100.20')->andReturn(null);
        });

        $this->mock(Fail2banClient::class, function ($mock) {
            $mock->shouldNotReceive('banIp');
        });

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('ipBlocked')->never();
        });

        $this->mock(IngestScheduleGate::class, function ($mock) {
            $mock->shouldNotReceive('recordRun');
        });

        // All these fields are on ONE summary line from a single $this->info()
        // call — see PullLlarLockoutsTest's --dry-run test for why this is a
        // single consolidated substring rather than several chained
        // expectsOutputToContain() calls (each chained expectation only gets
        // to consume one console write call, and multiple checks aimed at
        // the same line only let the first resolve).
        $this->artisan('clockwork:pull-wordfence-blocks', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('blocks=1 queued=0 auto_banned=0 skipped_existing=0 filtered_protected=0 errors=0 (dry-run)');

        expect(BlockedIp::query()->where('ip', '198.51.100.20')->exists())->toBeFalse();
        expect(ReviewQueueEntry::query()->where('ip', '198.51.100.20')->exists())->toBeFalse();
        expect($server->refresh()->last_wordfence_pull_at)->toBeNull();
    });
});

describe('IgnoreIpMatcher protected-IP filtering, in isolation', function () {
    it('skips a block whose IP IgnoreIpMatcher reports as protected — no ban, no queue entry', function () {
        [$server, $site] = pullWordfencePullableServer(['auto_ban_wordfence' => true]);

        $this->mock(WordfenceBlocksPuller::class, function ($mock) use ($site) {
            $mock->shouldReceive('activeBlocks')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($site))
                ->andReturn([
                    ['ip' => '203.0.113.6', 'expires_at' => null, 'source_table' => 'wp_wfBlocks7', 'reason' => 'brute force', 'type' => 'brute'],
                ]);
        });

        $this->mock(IgnoreIpMatcher::class, function ($mock) {
            $mock->shouldReceive('reason')
                ->with('203.0.113.6')
                ->andReturn('fleet IP / loopback');
        });

        $this->mock(Fail2banClient::class, function ($mock) {
            $mock->shouldNotReceive('banIp');
        });

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('ipBlocked')->never();
        });

        // Single summary line — see the NOTE in the --dry-run test above.
        $this->artisan('clockwork:pull-wordfence-blocks')
            ->assertSuccessful()
            ->expectsOutputToContain('blocks=1 queued=0 auto_banned=0 skipped_existing=0 filtered_protected=1 errors=0');

        expect(BlockedIp::query()->where('ip', '203.0.113.6')->exists())->toBeFalse();
        expect(ReviewQueueEntry::query()->where('ip', '203.0.113.6')->exists())->toBeFalse();
        expect($server->refresh()->last_wordfence_pull_at)->not->toBeNull();
    });
});
