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
use Modules\Seo\Maps\StaleMapRowException;
use Modules\Seo\Models\KeywordMap;
use Modules\Seo\Models\KeywordMapRow;
use Modules\Seo\Models\SiteProfile;
use Symfony\Component\HttpFoundation\Response;

/**
 * /seo/sites/{site}/keyword-map: read a client's keyword map, import one
 * from CSV, start a new dated version, edit or add rows and download it.
 * Row edits go through the same rules Claude's tools use.
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

    public function editRow(Site $site, KeywordMapRow $row): View|RedirectResponse
    {
        $map = $this->rowMap($site, $row);
        if (! $map->isCurrent()) {
            return redirect()->route('seo.sites.keyword-map', $site)->with('warning', 'That row belongs to an older version, which is read-only.');
        }

        return view('seo::sites.keyword-map-row', ['site' => $site, 'map' => $map, 'row' => $row]);
    }

    public function createRow(Site $site, KeywordMapService $maps): View|RedirectResponse
    {
        $map = $maps->current($site->id);
        if ($map === null) {
            return redirect()->route('seo.sites.keyword-map', $site)->with('warning', 'Start or import a map first.');
        }

        return view('seo::sites.keyword-map-row', ['site' => $site, 'map' => $map, 'row' => new KeywordMapRow(['ship_status' => 'not_started'])]);
    }

    public function storeRow(Request $request, Site $site, KeywordMapService $maps): RedirectResponse
    {
        $map = $maps->current($site->id);
        if ($map === null) {
            return redirect()->route('seo.sites.keyword-map', $site)->with('warning', 'Start or import a map first.');
        }

        $attributes = $this->rowInput($request);
        if (blank($attributes['page_name']) && blank($attributes['url'])) {
            return back()->withInput()->withErrors(['page_name' => 'Give the page a name or a URL.']);
        }

        try {
            $maps->addRow($map, $attributes);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['row' => $e->getMessage()]);
        }

        return redirect()->route('seo.sites.keyword-map', $site)->with('status', 'Page added to the map.');
    }

    public function updateRow(Request $request, Site $site, KeywordMapRow $row, KeywordMapService $maps): RedirectResponse
    {
        $this->rowMap($site, $row);
        $attributes = $this->rowInput($request);

        try {
            // The version the form was loaded with: if someone else (or
            // Claude) saved this row since, the edit is refused, not merged.
            $maps->updateRow($row, $attributes, (string) $request->input('expected_updated_at'));
        } catch (StaleMapRowException) {
            return redirect()->route('seo.sites.keyword-map.rows.edit', [$site, $row])->withInput()
                ->withErrors(['row' => 'Someone else changed this row while you were editing. Their version is loaded below, with what you typed kept in the boxes. Check it and save again.']);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['row' => $e->getMessage()]);
        }

        return redirect()->route('seo.sites.keyword-map', $site)->with('status', 'Saved "'.($row->page_name ?? $row->url ?? 'row').'".');
    }

    /** @return array<string, mixed> */
    private function rowInput(Request $request): array
    {
        $validated = $request->validate([
            'page_name' => ['nullable', 'string', 'max:191'],
            'url' => ['nullable', 'string', 'max:2000'],
            'is_new_page' => ['nullable', 'boolean'],
            'primary_keyword' => ['nullable', 'string', 'max:191'],
            'secondary_keywords' => ['nullable', 'string', 'max:6000'],
            'intent' => ['nullable', 'string', 'max:30'],
            'proposed_title' => ['nullable', 'string', 'max:500'],
            'proposed_meta' => ['nullable', 'string', 'max:1000'],
            'schema_types' => ['nullable', 'string', 'max:500'],
            'ship_status' => ['required', 'string', 'in:'.implode(',', array_keys(KeywordMapRow::STATUSES))],
            'shipped_on' => ['nullable', 'date'],
            'ship_note' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'excluded' => ['nullable', 'boolean'],
        ]);

        $split = fn (?string $text, string $pattern) => array_values(array_filter(array_map('trim', preg_split($pattern, (string) $text) ?: [])));

        $attributes = [
            'page_name' => $validated['page_name'] ?? null,
            'url' => $validated['url'] ?? null,
            'is_new_page' => $request->boolean('is_new_page'),
            'primary_keyword' => $validated['primary_keyword'] ?? null,
            'secondary_keywords' => $split($validated['secondary_keywords'] ?? null, '/[\r\n;]+/u'),
            'intent' => $validated['intent'] ?? null,
            'proposed_title' => $validated['proposed_title'] ?? null,
            'proposed_meta' => $validated['proposed_meta'] ?? null,
            'schema_types' => $split($validated['schema_types'] ?? null, '/[\r\n,;]+/u'),
            'ship_status' => $validated['ship_status'],
            'shipped_on' => $validated['shipped_on'] ?? null,
            'ship_note' => $validated['ship_note'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'excluded' => $request->boolean('excluded'),
        ];

        // Marked live with no date given: leave the date out, so the map
        // records today. A page that is not live has no live date.
        if ($attributes['ship_status'] === 'live' && $attributes['shipped_on'] === null) {
            unset($attributes['shipped_on']);
        } elseif ($attributes['ship_status'] !== 'live') {
            $attributes['shipped_on'] = null;
        }

        return $attributes;
    }

    private function rowMap(Site $site, KeywordMapRow $row): KeywordMap
    {
        $map = $row->map;
        abort_unless($map !== null && $map->site_id === $site->id, 404);

        return $map;
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
