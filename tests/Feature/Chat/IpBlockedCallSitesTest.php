<?php

namespace Tests\Feature\Chat;

use App\Models\BlockedIp;
use App\Models\ReviewQueueEntry;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatNotifier;
use App\Services\Fail2ban\Fail2banClient;
use App\Services\Fail2ban\IgnoreIpMatcher;
use App\Services\Llar\LlarLockoutPuller;
use App\Services\Wordfence\WordfenceBlocksPuller;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| ChatNotifier::ipBlocked() call-site coverage
|--------------------------------------------------------------------------
|
| ipBlocked() had ZERO real call sites before the commit immediately prior
| to this one (2026-09-03) — it was wired into 5 real places in the same
| change. This file proves each of the 5 actually fires it, with the
| just-created BlockedIp row, right when a real ban is recorded, and stays
| silent when no ban is recorded. It is NOT re-testing
| ChatNotifierDispatcher's is_inactive gating (ipBlocked is one of the
| never-gated active-incident methods — see ChatNotifierGatingTest.php)
| and it is NOT re-testing fail2ban SSH mechanics, the LLAR/Wordfence
| puller internals, or the review-queue state machine — each call site is
| exercised only far enough to reach (or deliberately not reach) the
| BlockedIp::create() + $chat->ipBlocked() pair.
|
| The 5 real call sites, verified against the 2026-09-03 source:
|   1. BlockedIpsController::ban()               — POST servers.ban
|   2. ReviewQueueController::approve()/banSingle() — POST review-queue.approve
|   3. ProcessPendingBans::handle()               — artisan command
|   4. PullLlarLockouts::processLockout()         — artisan command
|   5. PullWordfenceBlocks::processBlock()        — artisan command
*/

/**
 * A monitored Server with a linked, LLAR/Wordfence-pullable site: is_wordpress
 * plus a non-null db_password (both required by resolveServers()'s whereHas
 * on PullLlarLockouts/PullWordfenceBlocks — read straight from their source).
 *
 * @return array{0: Server, 1: Site}
 */
function serverWithPullableSite(array $serverOverrides = []): array
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

describe('1. BlockedIpsController::ban()', function () {
    it('fires ipBlocked with the newly created BlockedIp when Fail2banClient::banIp() succeeds', function () {
        $server = Server::factory()->create(['clockwork_jail_provisioned_at' => now()]);
        $user = User::factory()->create();

        $this->mock(Fail2banClient::class)
            ->shouldReceive('banIp')
            ->once()
            ->andReturn(['ok' => true, 'output' => "1\n", 'message' => 'ok']);

        $this->mock(ChatNotifier::class, function ($mock) use ($server) {
            $mock->shouldReceive('ipBlocked')
                ->once()
                ->withArgs(fn (BlockedIp $blocked) => $blocked->ip === '198.51.100.50'
                    && $blocked->server_id === $server->id
                    && $blocked->source === BlockedIp::SOURCE_MANUAL)
                ->andReturn(true);
        });

        $this->actingAs($user)->post(route('servers.ban', $server), [
            'ip' => '198.51.100.50',
            'reason' => 'Manual test ban',
        ])->assertRedirect();
    });

    it('does NOT fire ipBlocked when Fail2banClient::banIp() fails (no BlockedIp is ever created)', function () {
        $server = Server::factory()->create(['clockwork_jail_provisioned_at' => now()]);
        $user = User::factory()->create();

        $this->mock(Fail2banClient::class)
            ->shouldReceive('banIp')
            ->once()
            ->andReturn(['ok' => false, 'output' => 'sudo failed', 'message' => 'Ban failed']);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('ipBlocked')->never();
        });

        $this->actingAs($user)->post(route('servers.ban', $server), [
            'ip' => '198.51.100.51',
        ])->assertRedirect();

        expect(BlockedIp::query()->where('ip', '198.51.100.51')->exists())->toBeFalse();
    });
});

describe('2. ReviewQueueController::approve() (private banSingle())', function () {
    it('fires ipBlocked with the newly created BlockedIp when Fail2banClient::banIp() succeeds', function () {
        $server = Server::factory()->create();
        $entry = ReviewQueueEntry::factory()->create([
            'ip' => '198.51.100.60',
            'server_id' => $server->id,
            'status' => ReviewQueueEntry::STATUS_PENDING,
            'source' => ReviewQueueEntry::SOURCE_LLAR,
        ]);
        $user = User::factory()->create();

        $this->mock(Fail2banClient::class)
            ->shouldReceive('banIp')
            ->once()
            ->andReturn(['ok' => true, 'output' => "1\n", 'message' => 'ok']);

        $this->mock(ChatNotifier::class, function ($mock) use ($server) {
            $mock->shouldReceive('ipBlocked')
                ->once()
                ->withArgs(fn (BlockedIp $blocked) => $blocked->ip === '198.51.100.60'
                    && $blocked->server_id === $server->id
                    && $blocked->source === ReviewQueueEntry::SOURCE_LLAR)
                ->andReturn(true);
        });

        $this->actingAs($user)->post(route('review-queue.approve', $entry))->assertRedirect();

        expect($entry->refresh()->status)->toBe(ReviewQueueEntry::STATUS_APPROVED);
    });

    it('does NOT fire ipBlocked when Fail2banClient::banIp() fails', function () {
        $server = Server::factory()->create();
        $entry = ReviewQueueEntry::factory()->create([
            'ip' => '198.51.100.61',
            'server_id' => $server->id,
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);
        $user = User::factory()->create();

        $this->mock(Fail2banClient::class)
            ->shouldReceive('banIp')
            ->once()
            ->andReturn(['ok' => false, 'output' => 'boom', 'message' => 'Ban failed']);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('ipBlocked')->never();
        });

        $this->actingAs($user)->post(route('review-queue.approve', $entry))->assertRedirect();

        expect(BlockedIp::query()->where('ip', '198.51.100.61')->exists())->toBeFalse();
        expect($entry->refresh()->status)->toBe(ReviewQueueEntry::STATUS_PENDING);
    });
});

describe('3. ProcessPendingBans (artisan clockwork:process-pending-bans)', function () {
    it('fires ipBlocked once for a newly created BlockedIp on a successful ban', function () {
        $server = Server::factory()->create();
        $entry = ReviewQueueEntry::factory()->create([
            'ip' => '198.51.100.70',
            'server_id' => $server->id,
            'status' => ReviewQueueEntry::STATUS_QUEUED_FOR_BAN,
            'source' => ReviewQueueEntry::SOURCE_WORDFENCE,
        ]);

        $this->mock(Fail2banClient::class)
            ->shouldReceive('banIp')
            ->once()
            ->andReturn(['ok' => true, 'output' => "1\n", 'message' => 'ok']);

        $this->mock(ChatNotifier::class, function ($mock) use ($server) {
            $mock->shouldReceive('ipBlocked')
                ->once()
                ->withArgs(fn (BlockedIp $blocked) => $blocked->ip === '198.51.100.70'
                    && $blocked->server_id === $server->id
                    && $blocked->source === ReviewQueueEntry::SOURCE_WORDFENCE)
                ->andReturn(true);
        });

        $this->artisan('clockwork:process-pending-bans')->assertSuccessful();

        expect($entry->refresh()->status)->toBe(ReviewQueueEntry::STATUS_APPROVED);
    });

    it('does NOT fire ipBlocked when an active BlockedIp already exists for that (server, ip) — the $alreadyTracked dedup path', function () {
        $server = Server::factory()->create();
        $entry = ReviewQueueEntry::factory()->create([
            'ip' => '198.51.100.71',
            'server_id' => $server->id,
            'status' => ReviewQueueEntry::STATUS_QUEUED_FOR_BAN,
        ]);

        // Already tracked: active (unbanned_at null, expires_at null) BlockedIp
        // for the exact same server_id + ip pair.
        BlockedIp::factory()->create([
            'ip' => '198.51.100.71',
            'server_id' => $server->id,
        ]);

        // The command still calls fail2ban->banIp() unconditionally before
        // checking $alreadyTracked — see ProcessPendingBans::handle().
        $this->mock(Fail2banClient::class)
            ->shouldReceive('banIp')
            ->once()
            ->andReturn(['ok' => true, 'output' => "1\n", 'message' => 'ok']);

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('ipBlocked')->never();
        });

        $this->artisan('clockwork:process-pending-bans')->assertSuccessful();

        expect($entry->refresh()->status)->toBe(ReviewQueueEntry::STATUS_APPROVED);
        // No second BlockedIp row was created for the dupe.
        expect(BlockedIp::query()->where('ip', '198.51.100.71')->count())->toBe(1);
    });
});

describe('4. PullLlarLockouts (artisan clockwork:pull-llar-lockouts)', function () {
    it('fires ipBlocked on auto-ban when auto_ban_llar is true and a new lockout is pulled', function () {
        [$server, $site] = serverWithPullableSite(['auto_ban_llar' => true]);

        $this->mock(LlarLockoutPuller::class)
            ->shouldReceive('activeLockouts')
            ->once()
            ->withArgs(fn (Site $s) => $s->is($site))
            ->andReturn([
                ['ip' => '198.51.100.80', 'unlock_at' => Carbon::now()->addMinutes(15), 'source_table' => 'wp_limit_login_lockouts'],
            ]);

        $this->mock(IgnoreIpMatcher::class)
            ->shouldReceive('reason')
            ->with('198.51.100.80')
            ->andReturn(null);

        $this->mock(Fail2banClient::class)
            ->shouldReceive('banIp')
            ->once()
            ->andReturn(['ok' => true, 'output' => "1\n", 'message' => 'ok']);

        $this->mock(ChatNotifier::class, function ($mock) use ($server, $site) {
            $mock->shouldReceive('ipBlocked')
                ->once()
                ->withArgs(fn (BlockedIp $blocked) => $blocked->ip === '198.51.100.80'
                    && $blocked->server_id === $server->id
                    && $blocked->site_id === $site->id
                    && $blocked->source === BlockedIp::SOURCE_LLAR
                    && $blocked->decision === BlockedIp::DECISION_AUTO)
                ->andReturn(true);
        });

        $this->artisan('clockwork:pull-llar-lockouts')->assertSuccessful();
    });

    it('does NOT fire ipBlocked when auto_ban_llar is false — the lockout is queued for review instead', function () {
        [$server, $site] = serverWithPullableSite(['auto_ban_llar' => false]);

        $this->mock(LlarLockoutPuller::class)
            ->shouldReceive('activeLockouts')
            ->once()
            ->andReturn([
                ['ip' => '198.51.100.81', 'unlock_at' => Carbon::now()->addMinutes(15), 'source_table' => 'wp_limit_login_lockouts'],
            ]);

        $this->mock(IgnoreIpMatcher::class)
            ->shouldReceive('reason')
            ->with('198.51.100.81')
            ->andReturn(null);

        $this->mock(Fail2banClient::class)->shouldNotReceive('banIp');

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('ipBlocked')->never();
        });

        $this->artisan('clockwork:pull-llar-lockouts')->assertSuccessful();

        expect(BlockedIp::query()->where('ip', '198.51.100.81')->exists())->toBeFalse();
        $queued = ReviewQueueEntry::query()->where('ip', '198.51.100.81')->first();
        expect($queued)->not->toBeNull();
        expect($queued->status)->toBe(ReviewQueueEntry::STATUS_PENDING);
        expect($queued->server_id)->toBe($server->id);
    });
});

describe('5. PullWordfenceBlocks (artisan clockwork:pull-wordfence-blocks)', function () {
    it('fires ipBlocked on auto-ban when auto_ban_wordfence is true and a new block is pulled', function () {
        [$server, $site] = serverWithPullableSite(['auto_ban_wordfence' => true]);

        $this->mock(WordfenceBlocksPuller::class)
            ->shouldReceive('activeBlocks')
            ->once()
            ->withArgs(fn (Site $s) => $s->is($site))
            ->andReturn([
                ['ip' => '198.51.100.90', 'expires_at' => null, 'source_table' => 'wp_wfBlocks7', 'reason' => 'brute force', 'type' => 'brute'],
            ]);

        $this->mock(IgnoreIpMatcher::class)
            ->shouldReceive('reason')
            ->with('198.51.100.90')
            ->andReturn(null);

        $this->mock(Fail2banClient::class)
            ->shouldReceive('banIp')
            ->once()
            ->andReturn(['ok' => true, 'output' => "1\n", 'message' => 'ok']);

        $this->mock(ChatNotifier::class, function ($mock) use ($server, $site) {
            $mock->shouldReceive('ipBlocked')
                ->once()
                ->withArgs(fn (BlockedIp $blocked) => $blocked->ip === '198.51.100.90'
                    && $blocked->server_id === $server->id
                    && $blocked->site_id === $site->id
                    && $blocked->source === BlockedIp::SOURCE_WORDFENCE
                    && $blocked->decision === BlockedIp::DECISION_AUTO)
                ->andReturn(true);
        });

        $this->artisan('clockwork:pull-wordfence-blocks')->assertSuccessful();
    });

    it('does NOT fire ipBlocked when auto_ban_wordfence is false — the block is queued for review instead', function () {
        [$server, $site] = serverWithPullableSite(['auto_ban_wordfence' => false]);

        $this->mock(WordfenceBlocksPuller::class)
            ->shouldReceive('activeBlocks')
            ->once()
            ->andReturn([
                ['ip' => '198.51.100.91', 'expires_at' => null, 'source_table' => 'wp_wfBlocks7', 'reason' => 'brute force', 'type' => 'brute'],
            ]);

        $this->mock(IgnoreIpMatcher::class)
            ->shouldReceive('reason')
            ->with('198.51.100.91')
            ->andReturn(null);

        $this->mock(Fail2banClient::class)->shouldNotReceive('banIp');

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('ipBlocked')->never();
        });

        $this->artisan('clockwork:pull-wordfence-blocks')->assertSuccessful();

        expect(BlockedIp::query()->where('ip', '198.51.100.91')->exists())->toBeFalse();
        $queued = ReviewQueueEntry::query()->where('ip', '198.51.100.91')->first();
        expect($queued)->not->toBeNull();
        expect($queued->status)->toBe(ReviewQueueEntry::STATUS_PENDING);
        expect($queued->server_id)->toBe($server->id);
    });
});
