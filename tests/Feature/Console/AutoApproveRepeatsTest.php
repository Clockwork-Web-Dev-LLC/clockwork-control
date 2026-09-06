<?php

use App\Console\Commands\AutoApproveRepeats;
use App\Models\ReviewQueueEntry;
use App\Models\Server;
use App\Support\Settings;

/*
|--------------------------------------------------------------------------
| AutoApproveRepeats — the console command's own sweep logic
|--------------------------------------------------------------------------
|
| tests/Feature/Controllers/ReviewQueueControllerTest.php already covers
| toggleAutoApprove() calling sweep() synchronously from the HTTP layer.
| This file exercises the command's actual CLI entry point instead: option
| parsing (--threshold, --force), the Settings-gated enable/disable check,
| and the per-IP occurrence-aggregation logic itself (evidence.occurrences
| summed across all pending entries for that IP, vs. defaulting to 1 per
| entry with no evidence at all).
*/

describe('AutoApproveRepeats', function () {
    it('does nothing and exits successfully when the setting is disabled and --force is not passed', function () {
        expect(app(Settings::class)->get('auto_approve_repeats_enabled', false))->toBeFalse();

        ReviewQueueEntry::factory()->count(2)->create([
            'ip' => '203.0.113.10',
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);

        $this->artisan('clockwork:auto-approve-repeats')->assertSuccessful();

        expect(ReviewQueueEntry::query()->where('ip', '203.0.113.10')->pluck('status')->unique()->all())
            ->toBe([ReviewQueueEntry::STATUS_PENDING]);
    });

    it('runs the sweep even when disabled, given --force', function () {
        ReviewQueueEntry::factory()->count(2)->create([
            'ip' => '203.0.113.11',
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);

        $this->artisan('clockwork:auto-approve-repeats', ['--force' => true])->assertSuccessful();

        expect(ReviewQueueEntry::query()->where('ip', '203.0.113.11')->pluck('status')->unique()->all())
            ->toBe([ReviewQueueEntry::STATUS_QUEUED_FOR_BAN]);
    });

    it('promotes two pending entries for the same IP banned on two different servers', function () {
        app(Settings::class)->put('auto_approve_repeats_enabled', true);

        $serverA = Server::factory()->create();
        $serverB = Server::factory()->create();

        ReviewQueueEntry::factory()->create([
            'ip' => '203.0.113.12',
            'server_id' => $serverA->id,
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);
        ReviewQueueEntry::factory()->create([
            'ip' => '203.0.113.12',
            'server_id' => $serverB->id,
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);

        $this->artisan('clockwork:auto-approve-repeats')
            ->expectsOutputToContain('Promoted 2 pending entries (threshold ≥ 2).')
            ->assertSuccessful();

        $entries = ReviewQueueEntry::query()->where('ip', '203.0.113.12')->get();
        expect($entries->pluck('status')->unique()->all())->toBe([ReviewQueueEntry::STATUS_QUEUED_FOR_BAN]);
        expect($entries->pluck('decided_by')->unique()->all())->toBe([AutoApproveRepeats::DECIDED_BY]);
        $entries->each(fn ($entry) => expect($entry->decided_at)->not->toBeNull());
    });

    it('promotes a single entry whose own evidence.occurrences already meets the threshold (same site banned twice)', function () {
        app(Settings::class)->put('auto_approve_repeats_enabled', true);

        ReviewQueueEntry::factory()->create([
            'ip' => '203.0.113.13',
            'status' => ReviewQueueEntry::STATUS_PENDING,
            'evidence' => ['occurrences' => 2],
        ]);

        $this->artisan('clockwork:auto-approve-repeats')->assertSuccessful();

        expect(ReviewQueueEntry::query()->where('ip', '203.0.113.13')->first()->status)
            ->toBe(ReviewQueueEntry::STATUS_QUEUED_FOR_BAN);
    });

    it('leaves a lone entry with no evidence (defaults to 1 occurrence) pending, below the default threshold', function () {
        app(Settings::class)->put('auto_approve_repeats_enabled', true);

        ReviewQueueEntry::factory()->create([
            'ip' => '203.0.113.14',
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);

        $this->artisan('clockwork:auto-approve-repeats')
            ->expectsOutputToContain('Promoted 0 pending entries (threshold ≥ 2).')
            ->assertSuccessful();

        expect(ReviewQueueEntry::query()->where('ip', '203.0.113.14')->first()->status)
            ->toBe(ReviewQueueEntry::STATUS_PENDING);
    });

    it('honors a custom --threshold, requiring more total occurrences before promoting', function () {
        app(Settings::class)->put('auto_approve_repeats_enabled', true);

        // Two occurrences total -- meets the default threshold of 2, but not --threshold=3.
        ReviewQueueEntry::factory()->count(2)->create([
            'ip' => '203.0.113.15',
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);

        $this->artisan('clockwork:auto-approve-repeats', ['--threshold' => 3])->assertSuccessful();

        expect(ReviewQueueEntry::query()->where('ip', '203.0.113.15')->first()->status)
            ->toBe(ReviewQueueEntry::STATUS_PENDING);

        // A third pending entry for the same IP tips the total over the raised threshold.
        ReviewQueueEntry::factory()->create([
            'ip' => '203.0.113.15',
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);

        $this->artisan('clockwork:auto-approve-repeats', ['--threshold' => 3])->assertSuccessful();

        expect(ReviewQueueEntry::query()->where('ip', '203.0.113.15')->pluck('status')->unique()->all())
            ->toBe([ReviewQueueEntry::STATUS_QUEUED_FOR_BAN]);
    });

    it('does not touch entries for other IPs or entries that are not pending', function () {
        app(Settings::class)->put('auto_approve_repeats_enabled', true);

        $untouchedApproved = ReviewQueueEntry::factory()->approved()->create(['ip' => '203.0.113.16']);
        $untouchedOtherIp = ReviewQueueEntry::factory()->create([
            'ip' => '203.0.113.17',
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);

        ReviewQueueEntry::factory()->count(2)->create([
            'ip' => '203.0.113.18',
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ]);

        $this->artisan('clockwork:auto-approve-repeats')->assertSuccessful();

        expect($untouchedApproved->fresh()->status)->toBe(ReviewQueueEntry::STATUS_APPROVED);
        expect($untouchedOtherIp->fresh()->status)->toBe(ReviewQueueEntry::STATUS_PENDING);
    });
});
