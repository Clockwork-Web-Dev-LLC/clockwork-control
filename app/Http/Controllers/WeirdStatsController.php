<?php

namespace App\Http\Controllers;

use App\Services\Stats\WeirdStatsAggregator;
use Illuminate\View\View;

/**
 * /settings/weird-stats — fleet-level patterns the regular dashboards
 * don't compose into a single picture. Read-only; runs the aggregator's
 * six query methods on every page load. v1 doesn't cache.
 */
class WeirdStatsController extends Controller
{
    public function index(WeirdStatsAggregator $stats): View
    {
        return view('settings.weird-stats', [
            'summary' => $stats->summaryTiles(),
            'sellingPoints' => $stats->settlingPointStats(),
            'pluginCoverage' => $stats->pluginCoverageMatrix(),
            'topSites' => $stats->topSitesByVisits(),
            'attackedPaths' => $stats->mostAttackedPaths(),
            'repeatOffenders' => $stats->worstRepeatOffenders(),
            'unprotectedSites' => $stats->unprotectedSitesByTraffic(),
        ]);
    }
}
