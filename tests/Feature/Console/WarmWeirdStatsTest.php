<?php

use App\Services\Stats\WeirdStatsAggregator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Call-site coverage for WarmWeirdStats
|--------------------------------------------------------------------------
|
| WeirdStatsAggregator's own methods run raw MySQL-only SQL (USE INDEX
| hints, HOUR(), TIMESTAMPDIFF, FLOOR-bucketed GROUP BY) that the sqlite
| test DB can't execute — same class of gotcha as IssueCounter
| (tests/Concerns/RendersAuthenticatedPages.php). Unlike IssueCounter
| though, WarmWeirdStats's ENTIRE job is to invoke the aggregator's cached
| methods, so a full mock (not partial) of WeirdStatsAggregator is the
| right substitute here — it lets us assert the command's real behavior
| (which methods it calls, in what shape) without ever touching the
| MySQL-only query bodies.
*/

describe('WarmWeirdStats', function () {
    it('busts the four threat_logs-derived cache keys and recomputes them via the aggregator', function () {
        Cache::put('weird_stats:most_attacked_paths', ['stale'], now()->addMinutes(30));
        Cache::put('weird_stats:hour_histogram', ['stale'], now()->addMinutes(30));
        Cache::put('weird_stats:attacks_7d_count', 999, now()->addMinutes(30));
        Cache::put('weird_stats:cf_attack_reduction', ['stale'], now()->addMinutes(30));

        $this->mock(WeirdStatsAggregator::class, function ($mock) {
            $mock->shouldReceive('summaryTiles')->once()->andReturn([
                'total_sites' => 5, 'attacks_7d' => 10, 'active_bans' => 1, 'unprotected_count' => 0,
            ]);
            $mock->shouldReceive('mostAttackedPaths')->once()->andReturn([]);
            $mock->shouldReceive('attackHourHistogram')->once()->andReturn(['labels' => [], 'attacks' => [], 'approvals' => []]);
            $mock->shouldReceive('settlingPointStats')->once()->andReturn([
                'cf_attack_reduction' => null, 'auto_ban_speedup' => null, 'tier_density' => [], 'self_ban_prevention' => [],
            ]);
        });

        Log::spy();

        $this->artisan('clockwork:warm-weird-stats')
            ->expectsOutputToContain('weird_stats.warmed')
            ->assertSuccessful();

        Log::shouldHaveReceived('info')->once()->withArgs(fn ($message) => str_contains($message, 'weird_stats.warmed'));

        // Cache::forget runs unconditionally before recomputing (the mocked
        // aggregator never repopulates these keys itself), so they're gone.
        expect(Cache::has('weird_stats:most_attacked_paths'))->toBeFalse()
            ->and(Cache::has('weird_stats:hour_histogram'))->toBeFalse()
            ->and(Cache::has('weird_stats:attacks_7d_count'))->toBeFalse()
            ->and(Cache::has('weird_stats:cf_attack_reduction'))->toBeFalse();
    });

    it('still forgets the cache keys and calls every aggregator method with --force', function () {
        Cache::put('weird_stats:most_attacked_paths', ['stale'], now()->addMinutes(30));

        $this->mock(WeirdStatsAggregator::class, function ($mock) {
            $mock->shouldReceive('summaryTiles')->once()->andReturn([]);
            $mock->shouldReceive('mostAttackedPaths')->once()->andReturn([]);
            $mock->shouldReceive('attackHourHistogram')->once()->andReturn([]);
            $mock->shouldReceive('settlingPointStats')->once()->andReturn([]);
        });

        $this->artisan('clockwork:warm-weird-stats', ['--force' => true])->assertSuccessful();

        expect(Cache::has('weird_stats:most_attacked_paths'))->toBeFalse();
    });

    it('propagates a failure from the aggregator instead of silently succeeding', function () {
        $this->mock(WeirdStatsAggregator::class, function ($mock) {
            $mock->shouldReceive('summaryTiles')->once()->andThrow(new RuntimeException('cold compute timed out'));
        });

        $this->artisan('clockwork:warm-weird-stats')->run();
    })->throws(RuntimeException::class, 'cold compute timed out');
});
