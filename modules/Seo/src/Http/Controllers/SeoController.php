<?php

namespace Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\View\View;

class SeoController extends Controller
{
    public function index(): View
    {
        $sites = Site::query()
            ->where('is_inactive', false)
            ->hostMonitored()
            ->with('server.tags')
            ->orderBy('domain')
            ->get()
            ->sortBy(fn (Site $s) => $this->attentionRank($s))
            ->values();

        $monitored = $sites->where('seo_monitoring_enabled', true);
        $blocked = $monitored->where('seo_indexable', false);
        $staging = $blocked->filter(fn (Site $s) => (bool) $s->server?->isStaging());

        $totals = [
            'sites' => $sites->count(),
            'indexable' => $monitored->where('seo_indexable', true)->count(),
            'blocking' => $blocked->count() - $staging->count(),
            'protected' => $staging->count(),
            'unchecked' => $monitored->whereNull('seo_checked_at')->count(),
            'disabled' => $sites->count() - $monitored->count(),
        ];

        return view('seo::index', compact('sites', 'totals'));
    }

    private function attentionRank(Site $site): int
    {
        return match (true) {
            ! $site->seo_monitoring_enabled => 4,
            ! $site->seo_indexable => $site->server?->isStaging() ? 1 : 0,
            $site->seo_checked_at === null => 2,
            default => 3,
        };
    }
}
