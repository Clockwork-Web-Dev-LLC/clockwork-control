<?php

namespace Modules\Seo\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Seo\Keywords\KeywordMetricsService;
use Modules\Seo\Maps\KeywordMapService;
use Modules\Seo\Mcp\Support\ResponseEnvelope;
use Modules\Seo\Mcp\Support\SiteFinder;
use Modules\Seo\Models\KeywordMap;
use Modules\Seo\Models\KeywordMapRow;

#[Name('seo_keyword_map_get')]
#[Title('Read a site\'s keyword map')]
#[Description('The keyword map for one site: each page with its primary and secondary keywords, volume, difficulty, proposed title and meta description (with character counts), status and notes, plus the checks to fix and the recommended next page. Each row carries updated_at: pass it back as expected_updated_at when editing with seo_keyword_map_update. Never spends money.')]
#[IsReadOnly]
#[IsIdempotent]
class SeoKeywordMapGet extends SeoTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A Control site id or a domain.')->required(),
            'version_id' => $schema->integer()->description('An older version from the versions list. Default: the current map.'),
            'include_hidden' => $schema->boolean()->description('Include rows hidden from the map. Default false.'),
        ];
    }

    public function handle(Request $request, KeywordMapService $maps): Response|ResponseFactory
    {
        if ($this->caller($request) === null) {
            return Response::error(self::NO_USER);
        }

        $profile = SiteFinder::profile($request->get('site'));
        if (is_string($profile)) {
            return Response::error($profile);
        }

        $versions = KeywordMap::query()->where('site_id', $profile->site_id)->orderByDesc('id')->limit(30)->get();
        $map = $request->get('version_id') !== null
            ? $versions->firstWhere('id', (int) $request->get('version_id'))
            : $maps->current($profile->site_id);

        if ($request->get('version_id') !== null && $map === null) {
            return Response::error('No version with that id for this site. Call again without version_id to see the versions.');
        }

        $domain = $profile->site->domain ?? 'Site';
        $versionList = $versions->map(fn (KeywordMap $v) => [
            'version_id' => $v->id, 'name' => $v->name, 'status' => $v->status, 'source' => $v->source, 'created' => $v->created_at?->toDateString(),
        ])->all();

        if ($map === null) {
            return Response::structured(ResponseEnvelope::make(
                summary: "{$domain} has no keyword map yet. Start one with seo_keyword_map_update (action new_version), or a person can import a CSV on the site's Keyword map page in Control.",
                profile: $profile, period: [], dataState: null, finalAfter: null,
                caveats: [['code' => 'no_map', 'message' => 'There is no keyword map for this site.']],
                data: ['map' => null, 'versions' => $versionList, 'rows' => [], 'warnings' => [], 'recommended_next' => null],
            ));
        }

        $map->load('rows');
        $includeHidden = (bool) $request->get('include_hidden', false);

        $rows = $map->rows
            ->filter(fn (KeywordMapRow $r) => $includeHidden || ! $r->excluded)
            ->map(fn (KeywordMapRow $r) => [
                'row_id' => $r->id,
                'updated_at' => $r->updated_at?->toIso8601String(),
                'sort' => $r->sort,
                'page' => $r->page_name,
                'url' => ResponseEnvelope::clip($r->url, 300),
                'is_new_page' => $r->is_new_page,
                'primary_keyword' => $r->primary_keyword,
                'volume' => $r->volume,
                'difficulty' => $r->difficulty,
                // Only DataForSEO's score gets a band; an imported one is another tool's.
                'difficulty_band' => $r->metrics_provider === KeywordMetricsService::PROVIDER ? KeywordMetricsService::difficultyBand($r->difficulty) : null,
                'cpc' => $r->cpc,
                'metrics_source' => $r->metrics_provider,
                'secondary_keywords' => $r->secondary_keywords ?? [],
                'intent' => $r->intent,
                'proposed_title' => ResponseEnvelope::clip($r->proposed_title, 500),
                'title_length' => $r->proposed_title !== null ? mb_strlen($r->proposed_title) : null,
                'proposed_meta' => ResponseEnvelope::clip($r->proposed_meta, 1000),
                'meta_length' => $r->proposed_meta !== null ? mb_strlen($r->proposed_meta) : null,
                'schema_types' => $r->schema_types ?? [],
                'status' => $r->ship_status,
                'shipped_on' => $r->shipped_on?->toDateString(),
                'status_note' => $r->ship_note,
                'notes' => ResponseEnvelope::clip($r->notes, 2000),
                'hidden' => $r->excluded,
            ])->values()->all();

        $warnings = $maps->warnings($map);
        $caveats = [];
        if (! $map->isCurrent()) {
            $caveats[] = ['code' => 'read_only_version', 'message' => 'This is an older version and cannot be changed. Edits go to the current map.'];
        }
        if (collect($rows)->contains('metrics_source', 'imported')) {
            $caveats[] = ['code' => 'imported_metrics', 'message' => 'Some volume and difficulty figures were imported from another tool. Do not compare them with DataForSEO figures; fill them from stored data to replace them.'];
        }

        $live = count(array_filter($rows, fn (array $r) => $r['status'] === 'live'));

        return Response::structured(ResponseEnvelope::make(
            summary: "{$domain}: \"{$map->name}\"".($map->isCurrent() ? ' (current)' : ' (older, read-only)').', '.count($rows)." pages, {$live} live, ".count($warnings).' things to check.',
            profile: $profile,
            period: ['map_created' => $map->created_at?->toDateString()],
            dataState: null,
            finalAfter: null,
            caveats: $caveats,
            data: [
                'map' => ['version_id' => $map->id, 'name' => $map->name, 'status' => $map->status, 'source' => $map->source, 'editable' => $map->isCurrent()],
                'versions' => $versionList,
                'guide' => ['title_length' => implode(' to ', KeywordMapService::TITLE_RANGE), 'meta_length' => implode(' to ', KeywordMapService::META_RANGE), 'statuses' => array_keys(KeywordMapRow::STATUSES)],
                'rows' => $rows,
                'warnings' => array_slice($warnings, 0, 200),
                'recommended_next' => $map->isCurrent() ? $maps->recommendedNext($map) : null,
            ],
        ));
    }
}
