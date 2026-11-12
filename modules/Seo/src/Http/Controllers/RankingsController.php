<?php

namespace Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\Keywords\Market;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Models\TrackedKeyword;
use Modules\Seo\Ranks\RankReport;
use Modules\Seo\Ranks\RankSettings;
use Modules\Seo\Ranks\RankTracker;
use Modules\Seo\Ranks\TrackedKeywords;

/**
 * /seo/sites/{site}/rankings: a client's tracked keywords, where they rank,
 * and the forms to add keywords and name competitors. Shows stored checks
 * only; the hourly command is what talks to DataForSEO.
 */
class RankingsController extends Controller
{
    public function show(Site $site, RankReport $report, RankSettings $settings, DataForSeoClient $client, RankTracker $tracker): View
    {
        $profile = SiteProfile::query()->where('site_id', $site->id)->first();
        $data = $report->forSite($site->id);

        return view('seo::sites.rankings', [
            'site' => $site,
            'profile' => $profile,
            'rows' => $data['rows'],
            'summary' => $data['summary'],
            'competitors' => array_values(array_filter((array) ($profile?->competitors ?? []), 'is_string')),
            'market' => Market::forProfile($profile),
            'frequency' => $settings->frequency(),
            'depth' => $settings->depth(),
            'monthlyEstimate' => $settings->monthlyEstimate($data['summary']['active']),
            'perKeywordMonthly' => $settings->monthlyEstimate(1000) / 1000,
            'configured' => $client->isConfigured(),
            'pending' => $tracker->pending(),
        ]);
    }

    public function store(Request $request, Site $site, TrackedKeywords $tracked): RedirectResponse
    {
        if (! SiteProfile::query()->where('site_id', $site->id)->exists()) {
            return redirect()->route('seo.sites.profile', $site)->with('warning', 'Save an SEO profile for this site before tracking keywords.');
        }

        $validated = $request->validate([
            'keywords' => ['required', 'string', 'max:60000'],
            'devices' => ['nullable', 'array'],
            'devices.*' => ['string', 'in:desktop,mobile'],
            'city' => ['nullable', 'string', 'max:191'],
        ]);

        $keywords = array_values(array_filter(array_map('trim', preg_split('/\R/u', $validated['keywords']) ?: [])));
        $result = $tracked->add($site, $keywords, $validated['devices'] ?? ['desktop'], $validated['city'] ?? null, $request->user()?->id);

        $message = sprintf('%d %s added.', $result['added'], $result['added'] === 1 ? 'keyword' : 'keywords');
        if ($result['existing'] > 0) {
            $message .= sprintf(' %d already tracked.', $result['existing']);
        }
        if ($result['added'] > 0) {
            $message .= ' The first check is queued within the hour.';
        }

        $redirect = redirect()->route('seo.sites.rankings', $site)->with('status', $message);

        if ($result['rejected'] !== []) {
            $redirect->with('warning', 'Not added: '.implode('; ', array_map(
                fn (array $r) => "{$r['keyword']} ({$r['reason']})",
                array_slice($result['rejected'], 0, 10),
            )).'.');
        }

        return $redirect;
    }

    /** Pause or resume one keyword. A paused keyword keeps its history and costs nothing. */
    public function toggle(Site $site, TrackedKeyword $keyword): RedirectResponse
    {
        abort_unless($keyword->site_id === $site->id, 404);

        $keyword->forceFill(['is_active' => ! $keyword->is_active])->save();

        return redirect()->route('seo.sites.rankings', $site)
            ->with('status', "\"{$keyword->keyword}\" is ".($keyword->is_active ? 'being tracked again.' : 'paused. Its history is kept.'));
    }

    /** Remove a keyword and its history for good. */
    public function destroy(Site $site, TrackedKeyword $keyword): RedirectResponse
    {
        abort_unless($keyword->site_id === $site->id, 404);

        $name = $keyword->keyword;
        $keyword->delete();

        return redirect()->route('seo.sites.rankings', $site)->with('status', "\"{$name}\" and its rank history were deleted.");
    }

    public function competitors(Request $request, Site $site): RedirectResponse
    {
        $validated = $request->validate(['competitors' => ['nullable', 'string', 'max:2000']]);

        $profile = SiteProfile::query()->where('site_id', $site->id)->first();
        if ($profile === null) {
            return redirect()->route('seo.sites.profile', $site)->with('warning', 'Save an SEO profile for this site first.');
        }

        $domains = TrackedKeywords::parseDomains($validated['competitors'] ?? null, $site->domain);
        $profile->forceFill(['competitors' => $domains === [] ? null : $domains])->save();

        return redirect()->route('seo.sites.rankings', $site)->with('status', $domains === []
            ? 'Competitors cleared.'
            : count($domains).' '.(count($domains) === 1 ? 'competitor' : 'competitors').' saved. Their positions are recorded from the next check on.');
    }
}
