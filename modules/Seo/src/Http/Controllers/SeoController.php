<?php

namespace Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\View\View;

class SeoController extends Controller
{
    public function index(): View
    {
        // hostMonitored() leaves out staging-tagged servers, which the
        // watchdog never probes, so every row here is a production site.
        $sites = Site::query()
            ->where('is_inactive', false)
            ->hostMonitored()
            ->with('server')
            ->orderBy('domain')
            ->get()
            ->sortBy(fn (Site $s) => $this->attentionRank($s))
            ->values();

        $monitored = $sites->where('seo_monitoring_enabled', true);

        $totals = [
            'sites' => $sites->count(),
            'indexable' => $monitored->where('seo_indexable', true)->whereNotNull('seo_checked_at')->count(),
            'blocking' => $monitored->where('seo_indexable', false)->count(),
            'unchecked' => $monitored->whereNull('seo_checked_at')->count(),
            'disabled' => $sites->count() - $monitored->count(),
        ];

        return view('seo::index', compact('sites', 'totals'));
    }

    private function attentionRank(Site $site): int
    {
        return match (true) {
            ! $site->seo_monitoring_enabled => 3,
            ! $site->seo_indexable => 0,
            $site->seo_checked_at === null => 1,
            default => 2,
        };
    }
}
