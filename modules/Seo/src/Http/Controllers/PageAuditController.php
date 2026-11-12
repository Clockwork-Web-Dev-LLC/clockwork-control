<?php

namespace Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Seo\Models\PageAudit;
use Modules\Seo\Pages\PageAuditor;

/**
 * /seo/sites/{site}/page-audit: read live pages on the client's own site
 * and keep the latest result per URL. Free.
 */
class PageAuditController extends Controller
{
    public function show(Site $site): View
    {
        return view('seo::sites.page-audit', [
            'site' => $site,
            'audits' => PageAudit::query()->where('site_id', $site->id)->orderByDesc('audited_at')->orderByDesc('id')->limit(100)->get(),
            'max' => PageAuditor::MAX_URLS,
        ]);
    }

    public function run(Request $request, Site $site, PageAuditor $auditor): RedirectResponse
    {
        $validated = $request->validate(['urls' => ['required', 'string', 'max:12000']]);

        $urls = array_values(array_unique(array_filter(array_map('trim', preg_split('/\s+/u', $validated['urls']) ?: []))));
        // "/services" and "acme.com/services" are what people type.
        $urls = array_map(function (string $url) use ($site): string {
            if (str_starts_with($url, '/')) {
                return 'https://'.$site->domain.$url;
            }

            return preg_match('#^https?://#i', $url) === 1 ? $url : 'https://'.$url;
        }, $urls);

        if (count($urls) > PageAuditor::MAX_URLS) {
            return back()->withInput()->withErrors(['urls' => 'Audit '.PageAuditor::MAX_URLS.' pages at a time.']);
        }
        foreach ($urls as $url) {
            $refusal = $auditor->refusal($site, $url);
            if ($refusal !== null) {
                return back()->withInput()->withErrors(['urls' => "{$url}: {$refusal}"]);
            }
        }

        $audits = $auditor->audit($site, $urls);
        $read = count(array_filter($audits, fn (PageAudit $a) => $a->fetch_status === PageAudit::STATUS_OK));

        return redirect()->route('seo.sites.page-audit', $site)->with(
            $read === count($audits) ? 'status' : 'warning',
            "Read {$read} of ".count($audits).' pages.'.($read < count($audits) ? ' The others are marked below with the reason.' : ''),
        );
    }
}
