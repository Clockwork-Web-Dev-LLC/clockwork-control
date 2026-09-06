<?php

use App\Models\ActionLog;
use App\Models\ReviewQueueEntry;
use App\Models\Server;
use App\Models\User;
use App\Support\Settings;

/*
|--------------------------------------------------------------------------
| ReviewQueueController — state-machine transitions NOT already covered by
| tests/Feature/Chat/IpBlockedCallSitesTest.php
|--------------------------------------------------------------------------
|
| IpBlockedCallSitesTest.php already proves approve()/banSingle() fires (or
| doesn't fire) ChatNotifier::ipBlocked() correctly. This file covers the
| remaining four actions: toggleAutoApprove, bulkApprove, bulkDismiss, and
| dismiss. None of these call Fail2banClient or ChatNotifier directly (read
| from source: bulkApprove/bulkDismiss only touch the DB — the actual
| fail2ban dispatch is deferred to clockwork:process-pending-bans on the
| next scheduler tick), so no external-I/O mocking is needed here.
*/

it('redirects unauthenticated requests', function () {
    $entry = ReviewQueueEntry::factory()->create();

    $this->post(route('review-queue.dismiss', $entry))->assertRedirect(route('login'));
});

describe('toggleAutoApprove()', function () {
    it('enables auto-approve and reports no promotions when nothing meets the 2+ threshold', function () {
        $user = User::factory()->create();

        // One pending entry, no evidence — aggregateByIp/sweep() default to 1
        // occurrence each, below the threshold of 2.
        ReviewQueueEntry::factory()->create([
            'ip' => '203.0.113.50',
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)->post(route('review-queue.toggleAutoApprove'));

        $response->assertRedirect();
        $response->assertSessionHas('queue_status', function ($message) {
            return str_contains($message, 'Auto-approve enabled') && str_contains($message, 'No pending entries currently meet');
        });

        expect(app(Settings::class)->get('auto_approve_repeats_enabled'))->toBeTrue();
        expect(ReviewQueueEntry::query()->where('ip', '203.0.113.50')->first()->status)
            ->toBe(ReviewQueueEntry::STATUS_PENDING);
    });

    it('enables auto-approve and synchronously promotes repeat offenders to queued_for_ban', function () {
        $user = User::factory()->create();
        $server = Server::factory()->create();

        // Two pending entries for the same IP -> 2 total occurrences, meets
        // the default threshold of 2 -> promoted immediately by the sweep.
        ReviewQueueEntry::factory()->count(2)->create([
            'ip' => '203.0.113.51',
            'server_id' => $server->id,
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)->post(route('review-queue.toggleAutoApprove'));

        $response->assertRedirect();
        $response->assertSessionHas('queue_status', function ($message) {
            return str_contains($message, 'Immediately promoted 2');
        });

        expect(
            ReviewQueueEntry::query()->where('ip', '203.0.113.51')->pluck('status')->unique()->all()
        )->toBe([ReviewQueueEntry::STATUS_QUEUED_FOR_BAN]);
    });

    it('disables auto-approve when it was previously enabled, without running a sweep', function () {
        $user = User::factory()->create();
        app(Settings::class)->put('auto_approve_repeats_enabled', true);

        $server = Server::factory()->create();
        ReviewQueueEntry::factory()->count(2)->create([
            'ip' => '203.0.113.52',
            'server_id' => $server->id,
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)->post(route('review-queue.toggleAutoApprove'));

        $response->assertRedirect();
        $response->assertSessionHas('queue_status', 'Auto-approve disabled. New repeat offenders will require manual approval.');

        expect(app(Settings::class)->get('auto_approve_repeats_enabled'))->toBeFalse();
        // Disabling must NOT run the sweep — the repeat-offender pair stays pending.
        expect(
            ReviewQueueEntry::query()->where('ip', '203.0.113.52')->pluck('status')->unique()->all()
        )->toBe([ReviewQueueEntry::STATUS_PENDING]);
    });
});

describe('bulkApprove()', function () {
    it('marks all pending entries for the given IPs as queued_for_ban', function () {
        $user = User::factory()->create();
        $server = Server::factory()->create();

        $a = ReviewQueueEntry::factory()->create([
            'ip' => '203.0.113.60',
            'server_id' => $server->id,
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);
        $b = ReviewQueueEntry::factory()->create([
            'ip' => '203.0.113.61',
            'server_id' => $server->id,
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);
        // Different IP, not selected — must stay untouched.
        $untouched = ReviewQueueEntry::factory()->create([
            'ip' => '203.0.113.62',
            'server_id' => $server->id,
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)->post(route('review-queue.bulkApprove'), [
            'ips' => ['203.0.113.60', '203.0.113.61'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('queue_status', function ($message) {
            return str_contains($message, 'Queued 2 ban');
        });

        expect($a->refresh()->status)->toBe(ReviewQueueEntry::STATUS_QUEUED_FOR_BAN);
        expect($b->refresh()->status)->toBe(ReviewQueueEntry::STATUS_QUEUED_FOR_BAN);
        expect($a->refresh()->decided_by)->toBe('manual');
        expect($a->refresh()->decided_at)->not->toBeNull();
        expect($untouched->refresh()->status)->toBe(ReviewQueueEntry::STATUS_PENDING);
    });

    it('does not touch entries that are already decided, even if the IP matches', function () {
        $user = User::factory()->create();

        $alreadyApproved = ReviewQueueEntry::factory()->approved()->create(['ip' => '203.0.113.63']);

        $this->actingAs($user)->post(route('review-queue.bulkApprove'), [
            'ips' => ['203.0.113.63'],
        ])->assertRedirect();

        // Still approved (untouched) — bulkApprove only targets STATUS_PENDING rows.
        expect($alreadyApproved->refresh()->status)->toBe(ReviewQueueEntry::STATUS_APPROVED);
    });

    it('rejects the request with no IPs selected', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('review-queue.bulkApprove'), ['ips' => []]);

        $response->assertRedirect();
        $response->assertSessionHas('queue_error', 'Select at least one IP.');
    });

    it('filters out malformed IP strings and only queues the DB flag when at least one valid IP remains', function () {
        $user = User::factory()->create();

        $valid = ReviewQueueEntry::factory()->create([
            'ip' => '203.0.113.64',
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)->post(route('review-queue.bulkApprove'), [
            'ips' => ['not-an-ip', '203.0.113.64'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('queue_status', function ($message) {
            // "across 1 IP" proves the malformed entry was dropped, not counted.
            return str_contains($message, 'across 1 IP');
        });
        expect($valid->refresh()->status)->toBe(ReviewQueueEntry::STATUS_QUEUED_FOR_BAN);
    });

    it('rejects the request when every submitted IP is malformed', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('review-queue.bulkApprove'), [
            'ips' => ['not-an-ip', 'also-bad'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('queue_error', 'Select at least one IP.');
    });
});

describe('bulkDismiss()', function () {
    it('marks all pending entries for the given IPs as dismissed', function () {
        $user = User::factory()->create();
        $server = Server::factory()->create();

        $a = ReviewQueueEntry::factory()->create([
            'ip' => '203.0.113.70',
            'server_id' => $server->id,
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);
        $untouched = ReviewQueueEntry::factory()->create([
            'ip' => '203.0.113.71',
            'server_id' => $server->id,
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)->post(route('review-queue.bulkDismiss'), [
            'ips' => ['203.0.113.70'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('queue_status', 'Dismissed 1 entries across 1 IPs.');

        expect($a->refresh()->status)->toBe(ReviewQueueEntry::STATUS_DISMISSED);
        expect($a->refresh()->decided_by)->toBe('manual');
        expect($untouched->refresh()->status)->toBe(ReviewQueueEntry::STATUS_PENDING);
    });

    it('rejects the request with no IPs selected', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('review-queue.bulkDismiss'), ['ips' => []]);

        $response->assertRedirect();
        $response->assertSessionHas('queue_error', 'Select at least one IP.');
    });

    it('does not touch entries that are already decided, even if the IP matches', function () {
        $user = User::factory()->create();

        $alreadyDismissed = ReviewQueueEntry::factory()->dismissed()->create(['ip' => '203.0.113.72']);

        $this->actingAs($user)->post(route('review-queue.bulkDismiss'), [
            'ips' => ['203.0.113.72'],
        ])->assertRedirect();

        expect($alreadyDismissed->refresh()->status)->toBe(ReviewQueueEntry::STATUS_DISMISSED);
    });
});

describe('dismiss()', function () {
    it('dismisses a pending entry and records an ActionLog row', function () {
        $user = User::factory()->create();
        $server = Server::factory()->create(['name' => 'dismiss-target.example.com']);
        $entry = ReviewQueueEntry::factory()->create([
            'ip' => '203.0.113.80',
            'server_id' => $server->id,
            'source' => ReviewQueueEntry::SOURCE_NGINX,
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)->post(route('review-queue.dismiss', $entry));

        $response->assertRedirect();
        $response->assertSessionHas('queue_status', 'Dismissed 203.0.113.80.');

        $entry->refresh();
        expect($entry->status)->toBe(ReviewQueueEntry::STATUS_DISMISSED);
        expect($entry->decided_by)->toBe('manual');
        expect($entry->decided_at)->not->toBeNull();

        $log = ActionLog::query()->where('action_type', ActionLog::TYPE_REVIEW_DISMISS)->first();
        expect($log)->not->toBeNull();
        expect($log->target)->toBe('203.0.113.80');
        expect($log->server_id)->toBe($server->id);
    });

    it('refuses to dismiss an entry that is not pending, leaving it unchanged', function () {
        $user = User::factory()->create();
        $entry = ReviewQueueEntry::factory()->approved()->create(['ip' => '203.0.113.81']);
        $decidedAt = $entry->decided_at;

        $response = $this->actingAs($user)->post(route('review-queue.dismiss', $entry));

        $response->assertRedirect();
        $response->assertSessionHas('queue_error', 'Entry is not pending.');

        $entry->refresh();
        expect($entry->status)->toBe(ReviewQueueEntry::STATUS_APPROVED);
        expect($entry->decided_at->equalTo($decidedAt))->toBeTrue();
    });

    it('404s for a nonexistent entry via route-model binding', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('review-queue.dismiss', ['entry' => 999999]))
            ->assertNotFound();
    });
});
