<?php

use App\Services\Backups\BackupHistoryRetention;
use Illuminate\Support\Carbon;

/**
 * BackupHistoryRetention::filter() is the shared 90-day (care plan) /
 * 30-day (standard) retention window used by both the SpinupWP and
 * Pressable backup-report commands. It is a pure function, separate from
 * the S3-mediated relay chain covered in BackupRelayCommandsTest.
 *
 * Boundary behavior (read from the source, not assumed): the comparison is
 * `$ts >= $cutoff->getTimestamp()`, so a row dated exactly now()->subDays($days)
 * is KEPT, not dropped.
 */
describe('BackupHistoryRetention::filter', function () {
    beforeEach(function () {
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00:00'));
    });

    afterEach(function () {
        Carbon::setTestNow();
    });

    it('keeps a row dated within the retention window', function () {
        $history = [
            ['date' => now()->subDays(5)->toDateTimeString()],
        ];

        $result = BackupHistoryRetention::filter($history, 30);

        expect($result)->toHaveCount(1)
            ->and($result[0])->toBe($history[0]);
    });

    it('drops a row dated older than the retention window', function () {
        $history = [
            ['date' => now()->subDays(31)->toDateTimeString()],
        ];

        $result = BackupHistoryRetention::filter($history, 30);

        expect($result)->toBe([]);
    });

    it('keeps a row dated exactly at the boundary (now minus $days)', function () {
        $days = 30;
        $history = [
            ['date' => now()->subDays($days)->toDateTimeString()],
        ];

        $result = BackupHistoryRetention::filter($history, $days);

        expect($result)->toHaveCount(1)
            ->and($result[0])->toBe($history[0]);
    });

    it('keeps a row with a missing date key rather than silently dropping it', function () {
        $history = [
            ['note' => 'no date key at all'],
        ];

        $result = BackupHistoryRetention::filter($history, 30);

        expect($result)->toBe($history);
    });

    it('keeps a row with a null date value', function () {
        $history = [
            ['date' => null],
        ];

        $result = BackupHistoryRetention::filter($history, 30);

        expect($result)->toBe($history);
    });

    it('keeps a row with an unparseable date string', function () {
        $history = [
            ['date' => 'not-a-real-date-string'],
        ];

        $result = BackupHistoryRetention::filter($history, 30);

        expect($result)->toBe($history);
    });

    it('applies the 90-day care-plan window to a realistic mixed history', function () {
        $recent = ['date' => now()->subDays(10)->toDateTimeString(), 'label' => 'recent'];
        $withinWindow = ['date' => now()->subDays(89)->toDateTimeString(), 'label' => 'within-window'];
        $onBoundary = ['date' => now()->subDays(90)->toDateTimeString(), 'label' => 'on-boundary'];
        $tooOld = ['date' => now()->subDays(91)->toDateTimeString(), 'label' => 'too-old'];
        $malformed = ['date' => 'garbage', 'label' => 'malformed'];
        $missing = ['label' => 'missing-date'];

        $history = [$recent, $withinWindow, $onBoundary, $tooOld, $malformed, $missing];

        $result = BackupHistoryRetention::filter($history, 90);

        expect($result)->toBe([$recent, $withinWindow, $onBoundary, $malformed, $missing]);
    });

    it('applies the 30-day standard-hosting window to a realistic mixed history', function () {
        $recent = ['date' => now()->subDays(2)->toDateTimeString(), 'label' => 'recent'];
        $withinWindow = ['date' => now()->subDays(29)->toDateTimeString(), 'label' => 'within-window'];
        $onBoundary = ['date' => now()->subDays(30)->toDateTimeString(), 'label' => 'on-boundary'];
        $tooOld = ['date' => now()->subDays(31)->toDateTimeString(), 'label' => 'too-old'];
        $malformed = ['date' => 'not-a-date', 'label' => 'malformed'];
        $missing = ['label' => 'missing-date'];

        $history = [$recent, $withinWindow, $onBoundary, $tooOld, $malformed, $missing];

        $result = BackupHistoryRetention::filter($history, 30);

        expect($result)->toBe([$recent, $withinWindow, $onBoundary, $malformed, $missing]);
    });
});
