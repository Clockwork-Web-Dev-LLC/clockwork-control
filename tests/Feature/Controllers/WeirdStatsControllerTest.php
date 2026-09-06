<?php

use App\Models\User;
use App\Services\Stats\WeirdStatsAggregator;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/**
 * WeirdStatsAggregator's methods use MySQL-only SQL in several places
 * (mostAttackedPaths' `USE INDEX` hint, attackHourHistogram's HOUR(),
 * settlingPointStats' TIMESTAMPDIFF) that sqlite can't parse — same class
 * of gotcha as IssueCounter. The whole service is swapped for a full mock
 * here rather than hitting the DB, matching the pattern used for
 * IssueCounter::total() elsewhere in this suite.
 */
function mockWeirdStatsAggregator(): void
{
    test()->mock(WeirdStatsAggregator::class, function ($mock) {
        $mock->shouldReceive('summaryTiles')->andReturn([
            'total_sites' => 3,
            'attacks_7d' => 42,
            'active_bans' => 2,
            'unprotected_count' => 1,
        ]);
        $mock->shouldReceive('settlingPointStats')->andReturn([
            'cf_attack_reduction' => null,
            'auto_ban_speedup' => null,
            'tier_density' => [],
            'self_ban_prevention' => [
                'filtered_at_ingest' => 0,
                'jail_protected_ips' => 0,
                'fleet_servers' => 3,
                'cf_ranges_covered' => 0,
            ],
        ]);
        $mock->shouldReceive('pluginCoverageMatrix')->andReturn([]);
        $mock->shouldReceive('topSitesByVisits')->andReturn([
            'rows' => [],
            'fleet_visits_30d' => 0,
            'top10_pct_of_fleet' => 0.0,
            'ratio_first_to_tenth' => null,
        ]);
        $mock->shouldReceive('mostAttackedPaths')->andReturn([]);
        $mock->shouldReceive('worstRepeatOffenders')->andReturn([]);
        $mock->shouldReceive('unprotectedSitesByTraffic')->andReturn(collect());
    });
}

describe('WeirdStatsController', function () {
    beforeEach(function () {
        $this->mockIssueCounterZero();
    });

    it('redirects unauthenticated requests to login', function () {
        $this->get(route('settings.weird-stats.index'))->assertRedirect(route('login'));
    });

    it('renders the weird-stats page using the aggregator', function () {
        mockWeirdStatsAggregator();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('settings.weird-stats.index'));

        $response->assertOk()
            ->assertSee('Weird Stats')
            ->assertDontSee('Attack hour-of-day');
    });
});
