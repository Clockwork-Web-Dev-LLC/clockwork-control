<?php

namespace Modules\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\Seo\Keywords\KeywordMetricsService;
use Modules\Seo\Maps\KeywordMapCsv;
use Modules\Seo\Maps\KeywordMapService;
use Modules\Seo\Models\KeywordMap;
use Modules\Seo\Models\SiteProfile;
use Symfony\Component\HttpFoundation\Response;

/**
 * /seo/sites/{site}/keyword-map: read a client's keyword map, import one
 * from CSV, start a new dated version and download it. Rows are edited
 * through Claude's tools; this page is for reading, importing and export.
 */
class KeywordMapController extends Controller
{
    public function show(Request $request, Site $site, KeywordMapService $maps): View
    {
        $versions = KeywordMap::query()->where('site_id', $site->id)->orderByDesc('id')->limit(50)->get();
        $map = $request->filled('version')
            ? $versions->firstWhere('id', (int) $request->query('version'))
            : $maps->current($site->id);
        $map?->load('rows');

        return view('seo::sites.keyword-map', [
            'site' => $site,
            'hasProfile' => SiteProfile::query()->where('site_id', $site->id)->exists(),
            'map' => $map,
            'versions' => $versions,
            'rows' => $map?->rows ?? collect(),
            'warnings' => $map !== null ? $maps->warnings($map) : [],
            'next' => $map !== null && $map->isCurrent() ? $maps->recommendedNext($map) : null,
            'bands' => fn (?int $difficulty) => KeywordMetricsService::difficultyBand($difficulty),
        ]);
    }

    public function import(Request $request, Site $site, KeywordMapService $maps): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['nullable', 'file', 'max:2048', 'mimes:csv,txt'],
            'csv' => ['nullable', 'string', 'max:1000000'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $csv = $request->file('file')?->get() ?: ($validated['csv'] ?? '');
        if (trim((string) $csv) === '') {
            return back()->withInput()->withErrors(['file' => 'Choose a CSV file or paste the CSV text.']);
        }
        if (! mb_check_encoding($csv, 'UTF-8')) {
            // Spreadsheets on Windows often save CSV in a legacy encoding.
            $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }

        try {
            $result = $maps->importCsv($site, $csv, $validated['name'] ?? null, $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['file' => $e->getMessage()]);
        }

        $message = "Imported {$result['rows']} pages as the current map.";
        if ($result['needs_review'] > 0) {
            $message .= " {$result['needs_review']} have a status that could not be read; they are flagged below.";
        }
        if ($result['unknown_columns'] !== []) {
            $message .= ' Columns not used: '.implode(', ', array_slice($result['unknown_columns'], 0, 8)).'.';
        }
        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} rows past the limit of ".KeywordMapCsv::MAX_ROWS.' were left out.';
        }

        return redirect()->route('seo.sites.keyword-map', $site)->with('status', $message);
    }

    public function newVersion(Request $request, Site $site, KeywordMapService $maps): RedirectResponse
    {
        $validated = $request->validate(['name' => ['nullable', 'string', 'max:120']]);
        $map = $maps->newVersion($site, $validated['name'] ?? null, $request->user()?->id);

        return redirect()->route('seo.sites.keyword-map', $site)
            ->with('status', "\"{$map->name}\" is now the current map. The previous version is kept, read-only.");
    }

    /** Fill figures from keyword data already bought. Spends nothing. */
    public function fill(Site $site, KeywordMapService $maps): RedirectResponse
    {
        $map = $maps->current($site->id);
        if ($map === null) {
            return redirect()->route('seo.sites.keyword-map', $site)->with('warning', 'There is no map yet.');
        }

        $result = $maps->fillMetrics($map);
        $message = "Filled figures for {$result['filled']} pages from keyword data already bought.";
        if ($result['missing'] !== []) {
            $message .= ' '.count($result['missing']).' keywords have no stored figures yet; ask Claude to quote and buy them, then fill again.';
        }

        return redirect()->route('seo.sites.keyword-map', $site)->with('status', $message);
    }

    public function download(Site $site, KeywordMap $map): Response
    {
        abort_unless($map->site_id === $site->id, 404);
        $map->load('rows');

        $filename = preg_replace('/[^a-z0-9.-]+/i', '-', "keyword-map-{$site->domain}-{$map->name}").'.csv';

        // The BOM makes Excel read accented characters correctly.
        return response("\xEF\xBB\xBF".KeywordMapCsv::export($map), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
