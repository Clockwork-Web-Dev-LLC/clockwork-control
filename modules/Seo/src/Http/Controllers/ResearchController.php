<?php

namespace Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Seo\DataForSeo\BudgetGuard;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\DataForSeo\Feature;
use Modules\Seo\Keywords\Market;
use Modules\Seo\Models\DomainCompetitor;
use Modules\Seo\Models\DomainSnapshot;
use Modules\Seo\Models\KeywordIdeaPull;
use Modules\Seo\Models\RankedKeyword;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Research\DomainResearch;
use Modules\Seo\Research\KeywordGap;

/**
 * /seo/sites/{site}/research: what a client and its competitors rank for.
 * The page reads stored data only. Each "buy" button states its price and
 * goes through the same spending caps as everything else.
 */
class ResearchController extends Controller
{
    public function show(Site $site, KeywordGap $gap, DataForSeoClient $client): View
    {
        $profile = SiteProfile::query()->where('site_id', $site->id)->first();
        $market = Market::forProfile($profile);
        $own = DomainResearch::domain((string) $site->domain);
        $named = array_values(array_filter((array) ($profile?->competitors ?? []), 'is_string'));
        $domains = array_values(array_unique([$own, ...$named]));

        $snapshots = DomainSnapshot::query()->whereIn('domain', $domains)->where('market_key', $market->key())
            ->orderBy('month')->get()->groupBy('domain');
        $lists = RankedKeyword::query()->whereIn('domain', $domains)->where('market_key', $market->key())
            ->selectRaw('domain, COUNT(*) AS stored, MAX(fetched_at) AS fetched_at')->groupBy('domain')->get()->keyBy('domain');

        $rows = [];
        foreach ($domains as $domain) {
            $history = $snapshots->get($domain, collect());
            $latest = $history->last();
            $before = $history->count() > 1 ? $history->get($history->count() - 2) : null;
            $rows[] = [
                'domain' => $domain,
                'is_client' => $domain === $own,
                'latest' => $latest,
                'change' => ($latest?->keywords !== null && $before?->keywords !== null) ? $latest->keywords - $before->keywords : null,
                'list_stored' => (int) ($lists->get($domain)->stored ?? 0),
                'list_fetched' => isset($lists[$domain]) ? substr((string) $lists[$domain]->fetched_at, 0, 10) : null,
                'counted_this_month' => $latest !== null && $latest->month->isSameMonth(now('UTC')),
            ];
        }

        $comparison = $named !== [] ? $gap->compare($own, $named, $market, 'missing', 25) : null;

        return view('seo::sites.research', [
            'site' => $site,
            'profile' => $profile,
            'market' => $market,
            'rows' => $rows,
            'named' => $named,
            'discovered' => DomainCompetitor::query()->where('domain', $own)->where('market_key', $market->key())
                ->orderByDesc('shared_keywords')->limit(25)->get(),
            'topKeywords' => RankedKeyword::query()->where('domain', $own)->where('market_key', $market->key())
                ->orderByDesc('estimated_traffic')->limit(25)->get(),
            'gap' => $comparison,
            'gapSegments' => KeywordGap::SEGMENTS,
            'ideaPulls' => KeywordIdeaPull::query()->where('site_id', $site->id)->orderByDesc('id')->limit(10)->get(),
            'configured' => $client->isConfigured(),
            'prices' => [
                'count' => DomainResearch::overviewCost(),
                'ranked_keywords' => DomainResearch::rankedCost(DomainResearch::MAX_KEYWORDS),
                'competitors' => DomainResearch::competitorsCost(50),
            ],
        ]);
    }

    public function buy(Request $request, Site $site, DomainResearch $research, BudgetGuard $budget, DataForSeoClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'what' => ['required', 'string', 'in:count,ranked_keywords,competitors'],
            'domain' => ['nullable', 'string', 'max:191'],
        ]);

        $back = redirect()->route('seo.sites.research', $site);
        $profile = SiteProfile::query()->where('site_id', $site->id)->first();
        if ($profile === null) {
            return redirect()->route('seo.sites.profile', $site)->with('warning', 'Save an SEO profile for this site first.');
        }
        if (! $client->isConfigured()) {
            return $back->with('warning', 'Add the DataForSEO API login under SEO on Settings → Integrations first.');
        }

        // Only the client and the competitors named for it can be bought
        // from this page, so a form can't be pointed at any domain.
        $own = DomainResearch::domain((string) $site->domain);
        $allowed = [$own, ...array_map([DomainResearch::class, 'domain'], array_filter((array) ($profile->competitors ?? []), 'is_string'))];
        $domain = filled($validated['domain'] ?? null) ? DomainResearch::domain((string) $validated['domain']) : $own;
        if (! in_array($domain, $allowed, true)) {
            return $back->with('warning', "{$domain} is not this client or one of its named competitors.");
        }

        $market = Market::forProfile($profile);
        $what = $validated['what'];
        $estimate = match ($what) {
            'count' => DomainResearch::overviewCost(),
            'competitors' => DomainResearch::competitorsCost(50),
            default => DomainResearch::rankedCost(DomainResearch::MAX_KEYWORDS),
        };

        $refusal = $budget->refusal(Feature::RESEARCH, $estimate, $request->user());
        if ($refusal !== null) {
            return $back->with('warning', $refusal);
        }

        $result = match ($what) {
            'count' => $research->snapshot($domain, $market, $site->id, $request->user()),
            'competitors' => $research->discoverCompetitors($domain, $market, 50, $site->id, $request->user()),
            default => $research->pullRankedKeywords($domain, $market, DomainResearch::MAX_KEYWORDS, $site->id, $request->user()),
        };

        if (! $result['ok']) {
            return $back->with('warning', 'DataForSEO did not return the data: '.$result['error']);
        }

        return $back->with('status', sprintf('%s for %s, $%.4f.', match ($what) {
            'count' => 'Counted ranking keywords',
            'competitors' => "Found {$result['stored']} competing domains",
            default => "Stored {$result['stored']} ranked keywords",
        }, $domain, $result['cost']));
    }
}
