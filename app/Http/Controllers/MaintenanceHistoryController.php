<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cross-site action_log view, scoped by month. The "what to bill" view —
 * each row tags whether the site is on a care plan so we can read the
 * page top-to-bottom and assemble invoices.
 *
 * Reads the same action_logs table the per-site Recent activity card
 * reads. Different filters, different aggregation, same data.
 */
class MaintenanceHistoryController extends Controller
{
    /**
     * Action types we surface in the type dropdown — keep aligned with
     * what's actually being logged. New types are added as they get wired.
     *
     * @var array<string, string>
     */
    private const TYPE_LABELS = [
        self::FILTER_ALL_UPDATES => 'All updates (plugin/theme/core/translations)',
        ActionLog::TYPE_PLUGIN_UPDATE => 'Plugin update',
        ActionLog::TYPE_THEME_UPDATE => 'Theme update',
        ActionLog::TYPE_CORE_UPDATE => 'WordPress core update',
        ActionLog::TYPE_TRANSLATIONS_UPDATE => 'Translations update',
        ActionLog::TYPE_SSO_LOGIN => 'SSO login',
        ActionLog::TYPE_COMPANION_INSTALL => 'Companion install',
        ActionLog::TYPE_COMPANION_UPDATE => 'Companion update',
        ActionLog::TYPE_COMPANION_UNINSTALL => 'Companion uninstall',
        ActionLog::TYPE_MANUAL_BAN => 'Manual ban',
        ActionLog::TYPE_MANUAL_UNBAN => 'Manual unban',
        ActionLog::TYPE_REVIEW_APPROVE => 'Review approve',
        ActionLog::TYPE_REVIEW_DISMISS => 'Review dismiss',
        ActionLog::TYPE_CARE_PLAN_TOGGLED => 'Care plan toggled',
        ActionLog::TYPE_AUTO_UPDATES_TOGGLED => 'Auto-updates toggled',
        ActionLog::TYPE_SECURITY_SCAN => 'Security scan',
        ActionLog::TYPE_BILL_COM_SYNC => 'Bill.com sync',
        ActionLog::TYPE_UPTIME_TRANSITION => 'Uptime transition',
        ActionLog::TYPE_UPTIME_IGNORED => 'Uptime ignored',
        ActionLog::TYPE_UPTIME_UNIGNORED => 'Uptime unignored',
        ActionLog::TYPE_PERFORMANCE_SCAN => 'Performance scan',
        ActionLog::TYPE_COMPANION_SECRET_ROTATED => 'Companion secret rotated',
        ActionLog::TYPE_SERVER_UPDATE_REAPED => 'Server update reaped',
        ActionLog::TYPE_WP_CORE_REPAIRED => 'WP core repaired',
        ActionLog::TYPE_LOGIN => 'Login',
        ActionLog::TYPE_USER_ADDED => 'User added',
        ActionLog::TYPE_USER_REVOKED => 'User revoked',
        ActionLog::TYPE_USER_RESTORED => 'User restored',
    ];

    /**
     * Not a real action_type value — a synthetic filter the dropdown offers
     * that expands to ActionLog::UPDATE_TYPES in the query. Linked to
     * directly from the Updates page's "History" action.
     */
    private const FILTER_ALL_UPDATES = '_updates';

    public function index(Request $request): View
    {
        // Default to current calendar month. month=YYYY-MM in URL.
        $monthParam = (string) $request->query('month', '');
        $month = $this->parseMonth($monthParam);
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();

        $siteFilter = $request->query('site_id');
        $typeFilter = (string) $request->query('action_type', '');
        $carePlanFilter = (string) $request->query('care_plan', ''); // '', '1', '0'
        $outcomeFilter = (string) $request->query('outcome', '');    // '', 'ok', 'fail'
        $actorFilter = (string) $request->query('actor', '');

        $query = ActionLog::query()
            ->whereBetween('ran_at', [$start, $end])
            ->when($siteFilter, fn ($q) => $q->where('site_id', $siteFilter))
            ->when($typeFilter === self::FILTER_ALL_UPDATES, fn ($q) => $q->whereIn('action_type', ActionLog::UPDATE_TYPES))
            ->when($typeFilter !== '' && $typeFilter !== self::FILTER_ALL_UPDATES, fn ($q) => $q->where('action_type', $typeFilter))
            ->when($outcomeFilter === 'ok', fn ($q) => $q->where('ok', true))
            ->when($outcomeFilter === 'fail', fn ($q) => $q->where('ok', false))
            ->when($actorFilter !== '', fn ($q) => $q->where('actor', $actorFilter));

        // care_plan filter joins through sites — pulled separately below for the
        // grand-totals view; here we narrow with a sub-query.
        if ($carePlanFilter === '1' || $carePlanFilter === '0') {
            $careValue = $carePlanFilter === '1';
            $query->whereIn('site_id', Site::withoutGlobalScopes()->where('care_plan_enabled', $careValue)->pluck('id'));
        }

        $entries = (clone $query)
            ->with(['site', 'server'])
            ->orderByDesc('ran_at')
            ->limit(500)
            ->get();

        // Per-site rollup — one row per site that has activity in the window,
        // with action-type counts and care-plan flag (so we can render
        // 'included in plan' vs 'billable').
        $bySite = $entries
            ->whereNotNull('site_id')
            ->groupBy('site_id')
            ->map(function ($rows) {
                /** @var Site|null $site */
                $site = $rows->first()->site;
                $byType = $rows->groupBy('action_type')->map->count();

                return [
                    'site' => $site,
                    'site_id' => $rows->first()->site_id,
                    'domain' => $site !== null ? $site->domain : '(unknown)',
                    'care_plan_enabled' => $site !== null ? (bool) $site->care_plan_enabled : false,
                    'total' => $rows->count(),
                    'by_type' => $byType,
                    'failed' => $rows->where('ok', false)->count(),
                ];
            })
            ->sortByDesc('total')
            ->values();

        $serverOnly = $entries->whereNull('site_id');

        // Grand totals split by care plan, so the page header can say "you
        // did X covered actions and Y billable actions this month."
        $coveredCount = $bySite->where('care_plan_enabled', true)->sum('total');
        $billableCount = $bySite->where('care_plan_enabled', false)->sum('total');

        // Dropdown sources.
        $allSites = Site::query()
            ->orderBy('domain')
            ->get(['id', 'domain', 'care_plan_enabled']);

        $actorOptions = ActionLog::query()
            ->whereBetween('ran_at', [$start, $end])
            ->distinct()
            ->orderBy('actor')
            ->pluck('actor');

        // Month nav — three months either side, plus the current month.
        $months = [];
        for ($i = -6; $i <= 1; $i++) {
            $m = $month->addMonths($i);
            $months[] = [
                'value' => $m->format('Y-m'),
                'label' => $m->format('M Y'),
                'is_current' => $m->equalTo($month),
                'is_future' => $m->greaterThan(CarbonImmutable::now()->startOfMonth()),
            ];
        }

        return view('dashboard.maintenance-history', [
            'month' => $month,
            'months' => $months,
            'entries' => $entries,
            'bySite' => $bySite,
            'serverOnly' => $serverOnly,
            'coveredCount' => $coveredCount,
            'billableCount' => $billableCount,
            'serverOnlyCount' => $serverOnly->count(),
            'totalCount' => $entries->count(),
            'failedCount' => $entries->where('ok', false)->count(),
            'allSites' => $allSites,
            'actorOptions' => $actorOptions,
            'siteFilter' => $siteFilter,
            'typeFilter' => $typeFilter,
            'carePlanFilter' => $carePlanFilter,
            'outcomeFilter' => $outcomeFilter,
            'actorFilter' => $actorFilter,
            'typeLabels' => self::TYPE_LABELS,
        ]);
    }

    private function parseMonth(string $raw): CarbonImmutable
    {
        $raw = trim($raw);
        if ($raw === '' || ! preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return CarbonImmutable::now()->startOfMonth();
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $raw.'-01')?->startOfMonth()
                ?? CarbonImmutable::now()->startOfMonth();
        } catch (\Throwable) {
            return CarbonImmutable::now()->startOfMonth();
        }
    }
}
