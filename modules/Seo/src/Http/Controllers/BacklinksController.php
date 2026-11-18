<?php

namespace Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Seo\Backlinks\BacklinkResearch;
use Modules\Seo\DataForSeo\BudgetGuard;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\DataForSeo\Feature;
use Modules\Seo\Models\BacklinkGapDomain;
use Modules\Seo\Models\BacklinkSnapshot;
use Modules\Seo\Models\ReferringDomain;
use Modules\Seo\Models\SiteProfile;

/**
 * /seo/sites/{site}/backlinks: backlink totals for a client and its named
 * competitors, the domains linking to the client, and the backlink gap.
 * Reads stored data; each buy button states its price.
 */
class BacklinksController extends Controller
{
    private const SHOW = ['all', 'new', 'lost', 'high_spam'];

    public function show(Request $request, Site $site, BacklinkResearch $backlinks, DataForSeoClient $client): View
    {
        $validated = $request->validate([
            'show' => ['nullable', 'string', 'in:'.implode(',', self::SHOW)],
            'contains' => ['nullable', 'string', 'max:100'],
        ]);

        $profile = SiteProfile::query()->where('site_id', $site->id)->first();
        $own = BacklinkResearch::domain((string) $site->domain);
        $named = array_values(array_unique(array_map([BacklinkResearch::class, 'domain'], array_filter((array) ($profile->competitors ?? []), 'is_string'))));
        $domains = array_values(array_unique([$own, ...$named]));

        $snapshots = BacklinkSnapshot::query()->whereIn('domain', $domains)->orderBy('month')->get()->groupBy('domain');
        $gapStored = BacklinkGapDomain::query()->where('domain', $own)
            ->selectRaw('competitor_domain, COUNT(*) AS stored, MAX(fetched_at) AS fetched_at')->groupBy('competitor_domain')->get()->keyBy('competitor_domain');

        $rows = [];
        foreach ($domains as $domain) {
            $history = $snapshots->get($domain, collect());
            $latest = $history->last();
            $before = $history->count() > 1 ? $history->get($history->count() - 2) : null;
            $rows[] = [
                'domain' => $domain,
                'is_client' => $domain === $own,
                'latest' => $latest,
                'change' => ($latest?->referring_domains !== null && $before?->referring_domains !== null) ? $latest->referring_domains - $before->referring_domains : null,
                'counted_this_month' => $latest !== null && $latest->month->isSameMonth(now('UTC')),
                'gap_stored' => (int) ($gapStored->get($domain)->stored ?? 0),
            ];
        }

        $show = $validated['show'] ?? 'all';
        $since = now()->subDays(45);
        $base = ReferringDomain::query()->where('domain', $own);
        $query = match ($show) {
            'new' => (clone $base)->whereNull('lost_at')->where('found_at', '>=', $since),
            'lost' => (clone $base)->where('lost_at', '>=', $since),
            'high_spam' => (clone $base)->whereNull('lost_at')->where('spam_score', '>=', 60),
            default => (clone $base)->whereNull('lost_at'),
        };
        if (filled($validated['contains'] ?? null)) {
            $text = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower((string) $validated['contains'])).'%';
            $query->whereRaw("LOWER(referring_domain) LIKE ? ESCAPE '!'", [$text]);
        }

        $gap = $backlinks->gap($own, null, 50);

        return view('seo::sites.backlinks', [
            'site' => $site,
            'own' => $own,
            'rows' => $rows,
            'named' => $named,
            'show' => $show,
            'contains' => $validated['contains'] ?? '',
            'referring' => $query->orderByDesc('rank')->orderBy('referring_domain')->paginate(100)->withQueryString(),
            'counts' => [
                'all' => (clone $base)->whereNull('lost_at')->count(),
                'new' => (clone $base)->whereNull('lost_at')->where('found_at', '>=', $since)->count(),
                'lost' => (clone $base)->where('lost_at', '>=', $since)->count(),
                'high_spam' => (clone $base)->whereNull('lost_at')->where('spam_score', '>=', 60)->count(),
            ],
            'pulled' => (clone $base)->max('seen_at'),
            'gap' => $gap,
            'configured' => $client->isConfigured(),
            'prices' => ['backlink_summary' => BacklinkResearch::summaryCost(), 'referring_domains' => BacklinkResearch::referringCost(), 'backlink_gap' => BacklinkResearch::gapCost()],
        ]);
    }

    public function buy(Request $request, Site $site, BacklinkResearch $backlinks, BudgetGuard $budget, DataForSeoClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'what' => ['required', 'string', 'in:backlink_summary,referring_domains,backlink_gap'],
            'domain' => ['nullable', 'string', 'max:191'],
        ]);

        $back = redirect()->route('seo.sites.backlinks', $site);
        $profile = SiteProfile::query()->where('site_id', $site->id)->first();
        if ($profile === null) {
            return redirect()->route('seo.sites.profile', $site)->with('warning', 'Save an SEO profile for this site first.');
        }
        if (! $client->isConfigured()) {
            return $back->with('warning', 'Add the DataForSEO API login under SEO on Settings → Integrations first.');
        }

        // Only the client and its named competitors, so the form can't be
        // pointed at any domain.
        $own = BacklinkResearch::domain((string) $site->domain);
        $named = array_map([BacklinkResearch::class, 'domain'], array_filter((array) ($profile->competitors ?? []), 'is_string'));
        $domain = filled($validated['domain'] ?? null) ? BacklinkResearch::domain((string) $validated['domain']) : $own;
        $what = $validated['what'];

        $allowed = match ($what) {
            'referring_domains' => [$own],
            'backlink_gap' => $named,
            default => [$own, ...$named],
        };
        if (! in_array($domain, $allowed, true)) {
            return $back->with('warning', $what === 'backlink_gap' ? 'The backlink gap is bought against a named competitor.' : "{$domain} is not this client or one of its named competitors.");
        }

        $estimate = match ($what) {
            'referring_domains' => BacklinkResearch::referringCost(),
            'backlink_gap' => BacklinkResearch::gapCost(),
            default => BacklinkResearch::summaryCost(),
        };
        $refusal = $budget->refusal(Feature::BACKLINKS, $estimate, $request->user());
        if ($refusal !== null) {
            return $back->with('warning', $refusal);
        }

        $result = match ($what) {
            'referring_domains' => $backlinks->pullReferringDomains($own, BacklinkResearch::MAX_DOMAINS, $site->id, $request->user()),
            'backlink_gap' => $backlinks->pullGap($own, $domain, BacklinkResearch::GAP_LIMIT, $site->id, $request->user()),
            default => $backlinks->snapshot($domain, $site->id, $request->user()),
        };

        if (! $result['ok']) {
            return $back->with('warning', 'DataForSEO did not return the data: '.$result['error']);
        }

        return $back->with('status', sprintf('%s, $%.4f.', match ($what) {
            'referring_domains' => "Stored {$result['stored']} referring domains ({$result['new']} new, {$result['lost']} lost)",
            'backlink_gap' => "Found {$result['stored']} domains that link to {$domain} but not to {$own}",
            default => "Counted backlinks for {$domain}",
        }, $result['cost']));
    }
}
