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
use App\Services\Llar\LlarLockoutPuller;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| PullLlarLockouts — gap-fill coverage
|--------------------------------------------------------------------------
|
| tests/Feature/Chat/IpBlockedCallSitesTest.php already covers the
| auto-ban-on/off + review-queue-dedup paths for this command in depth (via
| ChatNotifier::ipBlocked call-site coverage). This file adds only what that
| one doesn't touch: the --server= filter (resolveServers()), the
| --dry-run flag's actual side-effect suppression, and the "protected IP"
| IgnoreIpMatcher path in isolation (a non-null reason(), not just the
| null/pass-through case exercised elsewhere).
*/

/**
 * @return array{0: Server, 1: Site}
 */
function pullLlarPullableServer(array $serverOverrides = []): array
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
    it('limits the pull to the matched server only, by name', function () {
        [$targetServer, $targetSite] = pullLlarPullableServer(['name' => 'target-server.example.com']);
        [$otherServer, $otherSite] = pullLlarPullableServer(['name' => 'other-server.example.com']);

        $this->mock(LlarLockoutPuller::class, function ($mock) use ($targetSite) {
            $mock->shouldReceive('activeLockouts')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($targetSite))
                ->andReturn([]);
        });

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('ipBlocked')->never();
        });

        $this->artisan('clockwork:pull-llar-lockouts', ['--server' => 'target-server.example.com'])
            ->assertSuccessful();

        expect($targetServer->refresh()->last_llar_pull_at)->not->toBeNull();
        expect($otherServer->refresh()->last_llar_pull_at)->toBeNull();
    });

    it('limits the pull to the matched server only, by numeric id', function () {
        [$targetServer, $targetSite] = pullLlarPullableServer();
        [$otherServer] = pullLlarPullableServer();

        $this->mock(LlarLockoutPuller::class, function ($mock) use ($targetSite) {
            $mock->shouldReceive('activeLockouts')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($targetSite))
                ->andReturn([]);
        });

        $this->artisan('clockwork:pull-llar-lockouts', ['--server' => (string) $targetServer->id])
            ->assertSuccessful();

        expect($targetServer->refresh()->last_llar_pull_at)->not->toBeNull();
        expect($otherServer->refresh()->last_llar_pull_at)->toBeNull();
    });

    it('warns and exits successfully when nothing matches the filter', function () {
        pullLlarPullableServer();

        $this->mock(LlarLockoutPuller::class, function ($mock) {
            $mock->shouldNotReceive('activeLockouts');
        });

        $this->artisan('clockwork:pull-llar-lockouts', ['--server' => 'nonexistent-server'])
            ->assertSuccessful()
            ->expectsOutputToContain('No servers matched.');
    });
});

describe('--dry-run', function () {
    it('reports what WOULD happen but bans nothing, queues nothing, and does not advance the pull watermark', function () {
        [$server, $site] = pullLlarPullableServer(['auto_ban_llar' => true]);

        $this->mock(LlarLockoutPuller::class, function ($mock) use ($site) {
            $mock->shouldReceive('activeLockouts')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($site))
                ->andReturn([
                    ['ip' => '198.51.100.10', 'unlock_at' => Carbon::now()->addMinutes(15), 'source_table' => 'wp_limit_login_lockouts'],
                ]);
        });

        $this->mock(IgnoreIpMatcher::class, function ($mock) {
            $mock->shouldReceive('reason')->with('198.51.100.10')->andReturn(null);
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

        // NOTE: all these fields are printed on a single summary line by one
        // $this->info() call. Laravel's expectsOutputToContain() matches each
        // chained expectation against ONE console write call each — several
        // expectations that all target substrings of the SAME single line
        // only let the first one actually resolve, so this asserts the
        // whole line as one substring rather than chaining multiple checks
        // against it.
        $this->artisan('clockwork:pull-llar-lockouts', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('lockouts=1 queued=0 auto_banned=0 skipped_existing=0 filtered_protected=0 errors=0 (dry-run)');

        expect(BlockedIp::query()->where('ip', '198.51.100.10')->exists())->toBeFalse();
        expect(ReviewQueueEntry::query()->where('ip', '198.51.100.10')->exists())->toBeFalse();
        expect($server->refresh()->last_llar_pull_at)->toBeNull();
    });
});

describe('IgnoreIpMatcher protected-IP filtering, in isolation', function () {
    it('skips a lockout whose IP IgnoreIpMatcher reports as protected — no ban, no queue entry', function () {
        [$server, $site] = pullLlarPullableServer(['auto_ban_llar' => true]);

        $this->mock(LlarLockoutPuller::class, function ($mock) use ($site) {
            $mock->shouldReceive('activeLockouts')
                ->once()
                ->withArgs(fn (Site $s) => $s->is($site))
                ->andReturn([
                    ['ip' => '203.0.113.5', 'unlock_at' => Carbon::now()->addMinutes(15), 'source_table' => 'wp_limit_login_lockouts'],
                ]);
        });

        $this->mock(IgnoreIpMatcher::class, function ($mock) {
            $mock->shouldReceive('reason')
                ->with('203.0.113.5')
                ->andReturn('fleet IP / loopback');
        });

        $this->mock(Fail2banClient::class, function ($mock) {
            $mock->shouldNotReceive('banIp');
        });

        $this->mock(ChatNotifier::class, function ($mock) {
            $mock->shouldReceive('ipBlocked')->never();
        });

        // Single summary line — see the NOTE in the --dry-run test above for
        // why this is one consolidated substring rather than several chained
        // expectsOutputToContain() calls.
        $this->artisan('clockwork:pull-llar-lockouts')
            ->assertSuccessful()
            ->expectsOutputToContain('lockouts=1 queued=0 auto_banned=0 skipped_existing=0 filtered_protected=1 errors=0');

        expect(BlockedIp::query()->where('ip', '203.0.113.5')->exists())->toBeFalse();
        expect(ReviewQueueEntry::query()->where('ip', '203.0.113.5')->exists())->toBeFalse();
        // The pull watermark still advances — this is a normal, successful pull.
        expect($server->refresh()->last_llar_pull_at)->not->toBeNull();
    });
});
