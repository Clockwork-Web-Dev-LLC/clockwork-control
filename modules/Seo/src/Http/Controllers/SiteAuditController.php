<?php

namespace Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Seo\Audit\IssueRules;
use Modules\Seo\Audit\SiteAuditIssues;
use Modules\Seo\Audit\SiteAuditRunner;
use Modules\Seo\Audit\SiteAuditSettings;
use Modules\Seo\DataForSeo\BudgetGuard;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\DataForSeo\Feature;
use Modules\Seo\DataForSeo\Pricing;
use Modules\Seo\Mcp\Support\AuditLog;
use Modules\Seo\Models\SiteAudit;
use Modules\Seo\Models\SiteProfile;

/**
 * /seo/sites/{site}/site-audit: the latest crawl of a client's site as a
 * list of issues, what changed since the crawl before, and a button to
 * crawl now. Reads stored crawls only; the hourly command fetches results.
 */
class SiteAuditController extends Controller
{
    public function show(Request $request, Site $site, SiteAuditIssues $issues, SiteAuditSettings $settings, DataForSeoClient $client): View
    {
        $audits = SiteAudit::query()->where('site_id', $site->id)->orderByDesc('id')->limit(24)->get();
        $finished = $audits->where('status', SiteAudit::STATUS_FINISHED);
        $audit = $request->filled('crawl') ? $finished->firstWhere('id', (int) $request->query('crawl')) : $finished->first();
        $previous = $audit !== null ? $issues->previous($audit) : null;
        $comparison = ($audit !== null && $previous !== null) ? $issues->compare($audit, $previous) : null;

        $list = $audit !== null ? $issues->forAudit($audit) : [];
        $changes = collect($comparison['issues'] ?? [])->keyBy('key');

        $issueKey = (string) $request->query('issue', '');
        $selected = collect($list)->firstWhere('key', $issueKey);

        return view('seo::sites.site-audit', [
            'site' => $site,
            'audit' => $audit,
            'previous' => $previous,
            'comparison' => $comparison,
            'issues' => $list,
            'changes' => $changes,
            // Issues that were open last time and are gone now.
            'resolved' => $changes->filter(fn (array $c) => $c['pages'] === 0 && $c['still_open'] === 0 && $c['fixed'] > 0)->values(),
            'selected' => $selected,
            'selectedPages' => ($selected !== null && $selected['scope'] === 'page') ? $issues->pages($audit, $issueKey, 500) : [],
            'running' => $audits->firstWhere('status', SiteAudit::STATUS_CRAWLING),
            'history' => $audits,
            'severities' => IssueRules::SEVERITIES,
            'categories' => IssueRules::CATEGORIES,
            'configured' => $client->isConfigured(),
            'maxPages' => $settings->maxPages(),
            'javascript' => $settings->javascript(),
            'frequency' => $settings->frequency(),
            'crawlCost' => $settings->crawlCost(),
        ]);
    }

    public function run(Request $request, Site $site, SiteAuditRunner $runner, SiteAuditSettings $settings, BudgetGuard $budget, DataForSeoClient $client): RedirectResponse
    {
        $back = redirect()->route('seo.sites.site-audit', $site);

        if (! SiteProfile::query()->where('site_id', $site->id)->exists()) {
            return redirect()->route('seo.sites.profile', $site)->with('warning', 'Save an SEO profile for this site first.');
        }
        if (! $client->isConfigured()) {
            return $back->with('warning', 'Add the DataForSEO API login under SEO on Settings → Integrations first.');
        }

        $refusal = $budget->refusal(Feature::SITE_AUDIT, $settings->crawlCost(), $request->user());
        if ($refusal !== null) {
            return $back->with('warning', $refusal);
        }

        $result = $runner->start($site, $request->user());

        return $result['ok']
            ? $back->with('status', sprintf('Crawl started, up to %s pages. Results usually arrive within the hour and appear here by themselves.', number_format($result['audit']->max_pages)))
            : $back->with('warning', (string) $result['error']);
    }

    /** How often, how many pages and whether to render JavaScript: what a crawl costs. */
    public function updateSettings(Request $request, SiteAuditSettings $settings, AuditLog $audit): RedirectResponse
    {
        $validated = $request->validate([
            'frequency' => ['required', 'string', 'in:'.implode(',', array_keys(SiteAuditSettings::FREQUENCIES))],
            'max_pages' => ['required', 'integer', 'in:'.implode(',', SiteAuditSettings::PAGE_CAPS)],
            'javascript' => ['nullable', 'boolean'],
        ]);

        $current = fn () => ['frequency' => $settings->frequency(), 'max_pages' => $settings->maxPages(), 'javascript' => $settings->javascript()];
        $before = $current();
        $settings->save($validated['frequency'], (int) $validated['max_pages'], $request->boolean('javascript'));

        $audit->page(AuditLog::SETTINGS_UPDATED, $request->user(), null, 'Changed the site audit settings.', AuditLog::diff(['site_audit' => $before], ['site_audit' => $current()]));
        $clients = SiteProfile::query()->where('is_enabled', true)->count();
        $runs = ['off' => 0, 'weekly' => 4.35, 'monthly' => 1][$validated['frequency']];

        return redirect()->route('seo.settings')->with('status', sprintf(
            'Site audit set to %s, up to %s pages%s. At most $%.2f a crawl; with %d %s that is at most about $%.2f a month.',
            $validated['frequency'], number_format((int) $validated['max_pages']), $request->boolean('javascript') ? ', with JavaScript' : '',
            $settings->crawlCost(), $clients, $clients === 1 ? 'client' : 'clients', $settings->crawlCost() * $clients * $runs,
        ));
    }

    /** Page caps with what each costs, for the settings form. */
    public static function capPrices(bool $javascript): array
    {
        $prices = [];
        foreach (SiteAuditSettings::PAGE_CAPS as $cap) {
            $prices[$cap] = Pricing::onPage($cap, $javascript);
        }

        return $prices;
    }
}
