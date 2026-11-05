<?php

namespace Modules\ClientReports\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Modules\ClientReports\Services\ClientDirectory;
use Modules\ClientReports\Support\ReportListFilters;

/**
 * The Clients tab (the module's landing page) and each client's reports
 * page: care plans, schedules and report history per client, with
 * previous/next through the same filtered list.
 */
class ClientsOverviewController extends Controller
{
    public const PER_PAGE = 30;

    public function index(Request $request, ClientDirectory $directory): View
    {
        $filters = ClientDirectory::filtersFrom($request->query());
        ['listed' => $rows, 'folded' => $folded] = $directory->arrange($directory->rows(), $filters);

        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => route('client-reports.index'), 'query' => ClientDirectory::queryFor($filters)],
        );

        // The tiles count what the care-plan selection shows (search and the
        // other filters aside), so they read as the list's own totals.
        $selection = $directory->arrange($directory->rows(), ['care' => $filters['care']])['listed'];
        $totals = [
            'clients' => $selection->filter(fn (array $row) => $row['client'] !== null)->count(),
            'care' => $selection->sum(fn (array $row) => $row['counts']['care']),
            'missing' => $selection->sum(fn (array $row) => $row['counts']['missing']),
            'waiting' => $selection->sum(fn (array $row) => $row['counts']['waiting']),
            'unassigned' => $selection->filter(fn (array $row) => $row['client'] === null)->sum(fn (array $row) => $row['counts']['sites']),
        ];

        return view('client-reports::clients.index', [
            'rows' => $paginator,
            'folded' => $folded,
            'filters' => $filters,
            'query' => ClientDirectory::queryFor($filters),
            'months' => $directory->months(),
            'totals' => $totals,
        ]);
    }

    public function show(Request $request, string $client, ClientDirectory $directory): View
    {
        abort_unless($client === ReportListFilters::UNASSIGNED || ctype_digit($client), 404);

        $row = $directory->find($client);
        abort_if($row === null, 404);

        // Previous/next follow the list the operator came from (same filters,
        // same order, the folded "No care plan" clients after the others);
        // the switcher offers every client.
        $filters = ClientDirectory::filtersFrom($request->query());
        $all = $directory->rows();
        ['listed' => $listed, 'folded' => $folded] = $directory->arrange($all, $filters);
        $list = $listed->concat($folded)->pluck('key')->unique()->values();
        $position = $list->search($row['key'], true);

        return view('client-reports::clients.show', [
            'row' => $row,
            'filters' => ClientDirectory::queryFor($filters),
            'months' => $directory->months(),
            'previousKey' => $position !== false && $position > 0 ? $list[$position - 1] : null,
            'nextKey' => $position !== false && $position < $list->count() - 1 ? $list[$position + 1] : null,
            'position' => $position === false ? null : $position + 1,
            'listCount' => $list->count(),
            'switcher' => $all->map(fn (array $r) => ['key' => $r['key'], 'name' => $r['name'], 'sites' => $r['counts']['sites']])->values(),
            'showSuperseded' => $request->boolean('superseded'),
        ]);
    }
}
