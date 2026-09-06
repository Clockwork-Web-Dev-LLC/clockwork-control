<?php

use App\Models\BlockedIp;
use App\Models\ReviewQueueEntry;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| BansController — GET /bans, /bans/queue, /bans/active, /bans/history
|--------------------------------------------------------------------------
|
| BansController is a thin shell: queue() delegates to
| ReviewQueueController::assembleData(), active() delegates to
| BlockedIpsController::assembleData(), and history() does its own merge of
| decided ReviewQueueEntry rows + unbanned BlockedIp rows via
| App\Support\BanHistoryRow. None of these actions touch Fail2banClient,
| SSH, or any external API — no mocking needed beyond the IssueCounter
| view-composer workaround every authenticated page needs.
*/

beforeEach(function () {
    $this->mockIssueCounterZero();
});

it('redirects unauthenticated requests away from the bans pages', function () {
    $this->get(route('bans.queue'))->assertRedirect(route('login'));
});

it('index redirects to the queue tab', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('bans.index'))
        ->assertRedirect(route('bans.queue'));
});

describe('queue() — delegates to ReviewQueueController::assembleData()', function () {
    it('renders pending review-queue entries aggregated by IP', function () {
        $server = Server::factory()->create(['name' => 'repeat-offender-server.example.com']);

        // Two pending entries for the same IP on the same server — no explicit
        // evidence, so aggregateByIp() defaults each to 1 occurrence, summing
        // to 2 and landing this IP in the "repeat offenders" bucket.
        ReviewQueueEntry::factory()->count(2)->create([
            'ip' => '203.0.113.10',
            'server_id' => $server->id,
            'status' => ReviewQueueEntry::STATUS_PENDING,
            'source' => ReviewQueueEntry::SOURCE_LLAR,
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('bans.queue'));

        $response->assertOk()
            ->assertSee('203.0.113.10')
            ->assertSee('repeat-offender-server.example.com')
            ->assertSee('Repeat offenders');
    });

    it('filters pending entries by source query param', function () {
        $server = Server::factory()->create();

        ReviewQueueEntry::factory()->create([
            'ip' => '203.0.113.20',
            'server_id' => $server->id,
            'status' => ReviewQueueEntry::STATUS_PENDING,
            'source' => ReviewQueueEntry::SOURCE_WORDFENCE,
        ]);
        ReviewQueueEntry::factory()->create([
            'ip' => '203.0.113.21',
            'server_id' => $server->id,
            'status' => ReviewQueueEntry::STATUS_PENDING,
            'source' => ReviewQueueEntry::SOURCE_LLAR,
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('bans.queue', ['source' => ReviewQueueEntry::SOURCE_WORDFENCE]));

        $response->assertOk()
            ->assertSee('203.0.113.20')
            ->assertDontSee('203.0.113.21');
    });
});

describe('active() — delegates to BlockedIpsController::assembleData()', function () {
    it('renders currently-active blocked IPs', function () {
        $server = Server::factory()->create(['name' => 'active-ban-server.example.com']);

        BlockedIp::factory()->create([
            'ip' => '198.51.100.30',
            'server_id' => $server->id,
            'reason' => 'Brute force login attempts',
            'unbanned_at' => null,
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('bans.active'));

        $response->assertOk()
            ->assertSee('198.51.100.30')
            ->assertSee('active-ban-server.example.com')
            ->assertSee('Brute force login attempts');
    });

    it('excludes already-unbanned IPs from the active list', function () {
        BlockedIp::factory()->unbanned()->create(['ip' => '198.51.100.31']);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('bans.active'));

        $response->assertOk()->assertDontSee('198.51.100.31');
    });

    it('renders the live search input, clear button, and data-search rows', function () {
        $server = Server::factory()->create(['name' => 'search-ban-server.example.com']);
        BlockedIp::factory()->create([
            'ip' => '198.51.100.99',
            'server_id' => $server->id,
            'reason' => 'Test reason',
            'unbanned_at' => null,
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('bans.active'));

        $response->assertOk()
            ->assertSee('id="bans-search"', false)
            ->assertSee('id="bans-search-clear"', false)
            ->assertSee('data-search="198.51.100.99', false);
    });
});

describe('history() — merges decided ReviewQueueEntry rows + unbanned BlockedIp rows', function () {
    it('lists both a decided review-queue entry and an unbanned IP, newest first', function () {
        $server = Server::factory()->create(['name' => 'history-server.example.com']);
        $site = Site::factory()->for($server)->create(['domain' => 'history-site.example.com']);

        // Older: an approved (decided) review-queue entry.
        ReviewQueueEntry::factory()->create([
            'ip' => '198.51.100.40',
            'server_id' => $server->id,
            'site_id' => $site->id,
            'status' => ReviewQueueEntry::STATUS_APPROVED,
            'decided_at' => Carbon::parse('2026-08-01 10:00:00'),
            'decided_by' => 'manual',
        ]);

        // Newer: an unbanned BlockedIp.
        BlockedIp::factory()->create([
            'ip' => '198.51.100.41',
            'server_id' => $server->id,
            'site_id' => $site->id,
            'banned_at' => Carbon::parse('2026-08-01 09:00:00'),
            'unbanned_at' => Carbon::parse('2026-08-02 10:00:00'),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('bans.history'));

        $response->assertOk()
            ->assertSee('198.51.100.40')
            ->assertSee('198.51.100.41')
            ->assertSee('history-server.example.com')
            ->assertSeeInOrder(['198.51.100.41', '198.51.100.40']);
    });

    it('filters history rows by the ip query param', function () {
        $server = Server::factory()->create();

        ReviewQueueEntry::factory()->create([
            'ip' => '198.51.100.42',
            'server_id' => $server->id,
            'status' => ReviewQueueEntry::STATUS_DISMISSED,
            'decided_at' => now(),
        ]);
        ReviewQueueEntry::factory()->create([
            'ip' => '198.51.100.43',
            'server_id' => $server->id,
            'status' => ReviewQueueEntry::STATUS_DISMISSED,
            'decided_at' => now(),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('bans.history', ['ip' => '198.51.100.42']));

        $response->assertOk()
            ->assertSee('198.51.100.42')
            ->assertDontSee('198.51.100.43');
    });

    it('excludes still-pending review-queue entries and still-active blocked IPs', function () {
        ReviewQueueEntry::factory()->create([
            'ip' => '198.51.100.44',
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);
        BlockedIp::factory()->create([
            'ip' => '198.51.100.45',
            'unbanned_at' => null,
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('bans.history'));

        $response->assertOk()
            ->assertDontSee('198.51.100.44')
            ->assertDontSee('198.51.100.45')
            ->assertSee('No history yet.');
    });
});
