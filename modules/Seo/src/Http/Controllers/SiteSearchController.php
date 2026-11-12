<?php

namespace Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Services\SearchNumbers;

/**
 * /seo/sites/{site}: a bare read-only table over the same SearchNumbers
 * arrays Claude's tools return. It keeps the numbers readable from the LAN
 * (by a person, or Claude in Chrome) if the public endpoint slips. Tiles and
 * the chart come in Phase 1b.
 */
class SiteSearchController extends Controller
{
    public function show(Request $request, Site $site, SearchNumbers $numbers): View|RedirectResponse
    {
        $profile = SiteProfile::query()->with('site')->where('site_id', $site->id)->first();
        if ($profile === null) {
            return redirect()->route('seo.sites.profile', $site)->with('warning', 'Set up this site\'s SEO profile first.');
        }

        $available = $numbers->availableMonths($profile);
        $requested = (string) $request->query('month', '');
        $month = preg_match('/^\d{4}-\d{2}$/', $requested) === 1
            ? CarbonImmutable::createFromFormat('Y-m-d', $requested.'-01')
            : CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();

        return view('seo::sites.show', [
            'site' => $site,
            'profile' => $profile,
            'numbers' => $numbers->month($profile, $month ?: CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth()),
            'months' => $available,
        ]);
    }
}
