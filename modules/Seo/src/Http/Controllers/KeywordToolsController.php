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
use Modules\Seo\DataForSeo\Pricing;
use Modules\Seo\Keywords\KeywordMetricsService;
use Modules\Seo\Keywords\Market;
use Modules\Seo\Models\KeywordIdeaPull;
use Modules\Seo\Models\KeywordMetric;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Research\KeywordIdeas;

/**
 * /seo/sites/{site}/keywords: look up keyword figures and get keyword
 * ideas from the back end. Looking up shows what is stored and what the
 * rest would cost; nothing is bought without pressing a priced button.
 */
class KeywordToolsController extends Controller
{
    private const MAX_LOOKUP = 700;

    public function show(Request $request, Site $site, DataForSeoClient $client): View
    {
        return $this->page($request, $site, $client, null);
    }

    /** Show stored figures for a pasted list and price the rest. Spends nothing. */
    public function lookup(Request $request, Site $site, KeywordMetricsService $metrics, BudgetGuard $budget, DataForSeoClient $client): View
    {
        $validated = $request->validate(['keywords' => ['required', 'string', 'max:60000']]);

        return $this->page($request, $site, $client, $this->lookupData($validated['keywords'], $site, $metrics, $budget, $request));
    }

    /** Buy the keywords that have no stored figures, then show the list again. */
    public function buy(Request $request, Site $site, KeywordMetricsService $metrics, BudgetGuard $budget, DataForSeoClient $client): View|RedirectResponse
    {
        $validated = $request->validate(['keywords' => ['required', 'string', 'max:60000']]);
        $back = redirect()->route('seo.sites.keywords', $site);

        if (! $client->isConfigured()) {
            return $back->with('warning', 'Add the DataForSEO API login under SEO on Settings → Integrations first.');
        }

        $market = Market::forProfile(SiteProfile::query()->where('site_id', $site->id)->first());
        $cleaned = $metrics->clean($this->lines($validated['keywords']));
        $stored = $metrics->lookup($cleaned['valid'], $market);
        $toBuy = array_values(array_unique([...$stored['missing'], ...$stored['stale']]));

        if ($toBuy === []) {
            return $this->page($request, $site, $client, $this->lookupData($validated['keywords'], $site, $metrics, $budget, $request));
        }

        $estimate = Pricing::keywordOverview(count($toBuy))['usd'] + ($market->city !== null ? Pricing::cityVolume(count($toBuy))['usd'] : 0.0);
        $refusal = $budget->refusal(Feature::KEYWORDS, $estimate, $request->user());
        if ($refusal !== null) {
            return $back->withInput()->with('warning', $refusal);
        }

        $result = $metrics->fetch($toBuy, $market, $request->user(), $site->id);
        $data = $this->lookupData($validated['keywords'], $site, $metrics, $budget, $request);
        $data['notice'] = $result['error'] !== null
            ? 'DataForSEO did not return everything: '.$result['error']
            : sprintf('Bought figures for %d keywords for $%.4f.%s', $result['stored'] + $result['no_data'], $result['cost'], $result['no_data'] > 0 ? " DataForSEO had nothing on {$result['no_data']} of them." : '');
        $data['notice_ok'] = $result['error'] === null;

        return $this->page($request, $site, $client, $data);
    }

    public function ideas(Request $request, Site $site, KeywordIdeas $ideas, KeywordMetricsService $metrics, BudgetGuard $budget, DataForSeoClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'seeds' => ['required', 'string', 'max:4000'],
            'mode' => ['required', 'string', 'in:'.implode(',', array_keys(KeywordIdeas::MODES))],
            'limit' => ['required', 'integer', 'in:100,300,1000'],
        ]);
        $back = redirect()->route('seo.sites.keywords', $site);

        if (! $client->isConfigured()) {
            return $back->withInput()->with('warning', 'Add the DataForSEO API login under SEO on Settings → Integrations first.');
        }

        $cleaned = $metrics->clean($this->lines($validated['seeds']));
        $maxSeeds = KeywordIdeas::MODES[$validated['mode']][2];
        if ($cleaned['valid'] === []) {
            return $back->withInput()->withErrors(['seeds' => 'None of those can be used as seed keywords.']);
        }
        if (count($cleaned['valid']) > $maxSeeds) {
            return $back->withInput()->withErrors(['seeds' => $maxSeeds === 1 ? 'This kind of list takes one seed keyword. Use "Same topics" for several.' : "Use {$maxSeeds} seed keywords at most."]);
        }

        $refusal = $budget->refusal(Feature::RESEARCH, KeywordIdeas::cost((int) $validated['limit']), $request->user());
        if ($refusal !== null) {
            return $back->withInput()->with('warning', $refusal);
        }

        $market = Market::forProfile(SiteProfile::query()->where('site_id', $site->id)->first());
        $result = $ideas->fetch($validated['mode'], $cleaned['valid'], $market, (int) $validated['limit'], $site->id, $request->user());

        if (! $result['ok']) {
            return $back->withInput()->with('warning', 'DataForSEO did not return ideas: '.$result['error']);
        }

        return redirect()->route('seo.sites.keywords', [$site, 'list' => $result['pull']->id])
            ->with('status', sprintf('Bought %d ideas for $%.4f.', $result['pull']->keyword_count, $result['cost']));
    }

    /** @param  array<string, mixed>|null  $lookup */
    private function page(Request $request, Site $site, DataForSeoClient $client, ?array $lookup): View
    {
        $profile = SiteProfile::query()->where('site_id', $site->id)->first();
        $market = Market::forProfile($profile);
        $pulls = KeywordIdeaPull::query()->where('site_id', $site->id)->orderByDesc('id')->limit(30)->get();

        $pull = $request->filled('list') ? $pulls->firstWhere('id', (int) $request->query('list')) : null;
        $ideaRows = collect();
        if ($pull !== null) {
            $stored = KeywordMetric::query()->where('market_key', $pull->market_key)->where('provider', KeywordMetricsService::PROVIDER)
                ->whereIn('keyword', $pull->keywords)->get()->keyBy('keyword');
            $ideaRows = collect($pull->keywords)
                ->map(fn (string $k) => ['keyword' => $k, 'metric' => $stored->get($k)])
                ->sortByDesc(fn (array $r) => $r['metric']?->volume ?? -1)->values();
        }

        return view('seo::sites.keywords', [
            'site' => $site,
            'market' => $market,
            'configured' => $client->isConfigured(),
            'lookup' => $lookup,
            'pulls' => $pulls,
            'pull' => $pull,
            'ideaRows' => $ideaRows,
            'modes' => KeywordIdeas::MODES,
            'ideaPrices' => [100 => KeywordIdeas::cost(100), 300 => KeywordIdeas::cost(300), 1000 => KeywordIdeas::cost(1000)],
        ]);
    }

    /** @return array<string, mixed> */
    private function lookupData(string $text, Site $site, KeywordMetricsService $metrics, BudgetGuard $budget, Request $request): array
    {
        $market = Market::forProfile(SiteProfile::query()->where('site_id', $site->id)->first());
        $lines = $this->lines($text);
        $cleaned = $metrics->clean(array_slice($lines, 0, self::MAX_LOOKUP));
        $stored = $metrics->lookup($cleaned['valid'], $market);
        $toBuy = array_values(array_unique([...$stored['missing'], ...$stored['stale']]));
        $estimate = $toBuy === [] ? 0.0 : Pricing::keywordOverview(count($toBuy))['usd'] + ($market->city !== null ? Pricing::cityVolume(count($toBuy))['usd'] : 0.0);

        return [
            'text' => $text,
            'rows' => array_map(fn (string $k) => [
                'keyword' => $k,
                'metric' => $stored['found'][$k] ?? null,
                'stale' => in_array($k, $stored['stale'], true),
                'city_volume' => $market->city !== null ? (($stored['found'][$k] ?? null)?->local_volumes[$market->city] ?? null) : null,
            ], $cleaned['valid']),
            'rejected' => $cleaned['rejected'],
            'over_limit' => max(0, count($lines) - self::MAX_LOOKUP),
            'to_buy' => count($toBuy),
            'estimate' => round($estimate, 4),
            'refusal' => $toBuy === [] ? null : $budget->refusal(Feature::KEYWORDS, $estimate, $request->user()),
            'notice' => null,
            'notice_ok' => true,
        ];
    }

    /** @return list<string> */
    private function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/u', $text) ?: []), fn (string $l) => $l !== ''));
    }
}
