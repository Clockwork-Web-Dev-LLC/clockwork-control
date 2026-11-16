<?php

namespace Modules\Seo\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Seo\Maps\KeywordMapService;
use Modules\Seo\Maps\StaleMapRowException;
use Modules\Seo\Mcp\Support\AuditLog;
use Modules\Seo\Mcp\Support\ResponseEnvelope;
use Modules\Seo\Mcp\Support\SiteFinder;
use Modules\Seo\Models\KeywordMapRow;

#[Name('seo_keyword_map_update')]
#[Title('Edit a site\'s keyword map')]
#[Description('Change the current keyword map of one site. action "new_version" starts a new dated map as a copy of the current one (do this once before a new round of work, so last month\'s map stays as it was). "edit_rows" changes or adds rows: give row_id and the row\'s updated_at as expected_updated_at to change one, or leave row_id out to add one. "fill_metrics" fills volume, difficulty and CPC from keyword data already bought; it never spends. Rows cannot be deleted, only hidden. Volume, difficulty and CPC cannot be typed in. Em dashes are refused in titles, descriptions and notes. All rows in one call succeed or none do.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
class SeoKeywordMapUpdate extends SeoTool
{
    public const NAME = 'seo_keyword_map_update';

    private const MAX_ROWS = 50;

    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A Control site id or a domain.')->required(),
            'action' => $schema->string()->enum(['new_version', 'edit_rows', 'fill_metrics'])->required(),
            'name' => $schema->string()->max(120)->description('For new_version: the map\'s name. Default: "Keyword map" and today\'s date.'),
            'rows' => $schema->array()->max(self::MAX_ROWS)->items($schema->object([
                'row_id' => $schema->integer()->description('The row to change. Leave out to add a row.'),
                'expected_updated_at' => $schema->string()->description('The row\'s updated_at from seo_keyword_map_get. Required when changing a row.'),
                'page' => $schema->string()->max(191),
                'url' => $schema->string()->max(2000),
                'is_new_page' => $schema->boolean(),
                'primary_keyword' => $schema->string()->max(191),
                'secondary_keywords' => $schema->array()->max(50)->items($schema->string()),
                'intent' => $schema->string()->max(30),
                'proposed_title' => $schema->string()->max(500),
                'proposed_meta' => $schema->string()->max(1000),
                'schema_types' => $schema->array()->max(20)->items($schema->string()),
                'status' => $schema->string()->enum(array_keys(KeywordMapRow::STATUSES)),
                'shipped_on' => $schema->string()->format('date'),
                'status_note' => $schema->string()->max(255),
                'notes' => $schema->string()->max(5000),
                'hidden' => $schema->boolean()->description('true hides the row from the map without deleting it.'),
            ]))->description('For edit_rows: up to 50 rows. Only the fields given are changed.'),
        ];
    }

    public function handle(Request $request, KeywordMapService $maps, AuditLog $audit): Response|ResponseFactory
    {
        $user = $this->caller($request);
        if ($user === null) {
            return Response::error(self::NO_USER);
        }

        $profile = SiteFinder::profile($request->get('site'));
        if (is_string($profile)) {
            return Response::error($profile);
        }

        $domain = (string) $profile->site->domain;
        $action = (string) $request->get('action');

        if ($action === 'new_version') {
            $name = $request->get('name');
            if ($name !== null && (! is_string($name) || mb_strlen($name) > 120)) {
                return Response::error('name must be text of 120 characters at most.');
            }

            $previous = $maps->current($profile->site_id);
            $map = $maps->newVersion($profile->site, $name, $user->id);

            $audit->record(AuditLog::KEYWORD_MAP_UPDATED, $user, self::NAME, $domain, "Started keyword map \"{$map->name}\" for {$domain}.", ['keyword_map' => [
                'before' => ['version_id' => $previous?->id, 'name' => $previous?->name],
                'after' => ['version_id' => $map->id, 'name' => $map->name],
            ]]);

            return Response::structured([
                'summary' => "\"{$map->name}\" is now the current map for {$domain}".($previous !== null ? ", copied from \"{$previous->name}\", which is kept read-only." : ', and it is empty.'),
                'site' => ResponseEnvelope::site($profile),
                'map' => ['version_id' => $map->id, 'name' => $map->name, 'rows' => $map->rows()->count()],
                'next' => 'Row ids are new in this version. Read it with seo_keyword_map_get before editing.',
            ]);
        }

        $map = $maps->current($profile->site_id);
        if ($map === null) {
            return Response::error("{$domain} has no keyword map yet. Call this tool with action new_version first.");
        }

        if ($action === 'fill_metrics') {
            $result = $maps->fillMetrics($map);

            if ($result['filled'] > 0) {
                $audit->record(AuditLog::KEYWORD_MAP_UPDATED, $user, self::NAME, $domain, "Filled keyword figures on {$result['filled']} rows of \"{$map->name}\" for {$domain}.", ['keyword_map' => [
                    'before' => null, 'after' => ['version_id' => $map->id, 'filled' => $result['filled']],
                ]]);
            }

            return Response::structured([
                'summary' => "Filled figures on {$result['filled']} pages from stored keyword data."
                    .($result['missing'] !== [] ? ' '.count($result['missing']).' keywords have nothing stored: quote them with seo_keyword_metrics, and buy only if the person agrees.' : ''),
                'site' => ResponseEnvelope::site($profile),
                'filled' => $result['filled'],
                'missing_keywords' => array_slice($result['missing'], 0, 700),
            ]);
        }

        if ($action !== 'edit_rows') {
            return Response::error('action must be new_version, edit_rows or fill_metrics.');
        }

        $rows = $request->get('rows');
        if (! is_array($rows) || $rows === [] || count($rows) > self::MAX_ROWS) {
            return Response::error('rows must list 1 to '.self::MAX_ROWS.' rows.');
        }

        $rename = ['page' => 'page_name', 'status' => 'ship_status', 'status_note' => 'ship_note', 'hidden' => 'excluded'];
        $changes = [];
        $saved = [];

        try {
            DB::transaction(function () use ($rows, $map, $maps, $rename, &$changes, &$saved): void {
                foreach ($rows as $index => $input) {
                    if (! is_array($input)) {
                        throw new InvalidArgumentException('Row '.($index + 1).' is not an object.');
                    }

                    $attributes = [];
                    foreach ($input as $key => $value) {
                        if (! in_array($key, ['row_id', 'expected_updated_at'], true)) {
                            $attributes[$rename[$key] ?? $key] = $value;
                        }
                    }
                    if (array_diff(array_keys($attributes), KeywordMapService::EDITABLE) !== []) {
                        throw new InvalidArgumentException('Row '.($index + 1).' sets a field that cannot be edited: '.implode(', ', array_diff(array_keys($attributes), KeywordMapService::EDITABLE)).'.');
                    }

                    if (! isset($input['row_id'])) {
                        if (blank($attributes['page_name'] ?? null) && blank($attributes['url'] ?? null)) {
                            throw new InvalidArgumentException('Row '.($index + 1).' is new, so it needs a page or a url.');
                        }
                        $row = $maps->addRow($map, $attributes);
                        $changes[] = ['row_id' => $row->id, 'before' => null, 'after' => $attributes];
                    } else {
                        $row = KeywordMapRow::query()->where('map_id', $map->id)->find((int) $input['row_id']);
                        if ($row === null) {
                            throw new InvalidArgumentException("Row {$input['row_id']} is not in the current map. Row ids change with each version; read the map again.");
                        }
                        if (! is_string($input['expected_updated_at'] ?? null)) {
                            throw new InvalidArgumentException("Row {$input['row_id']} needs expected_updated_at, the updated_at it had when you read it.");
                        }
                        $before = array_intersect_key($row->only(KeywordMapService::EDITABLE), $attributes);
                        $row = $maps->updateRow($row, $attributes, $input['expected_updated_at']);
                        $changes[] = ['row_id' => $row->id, 'before' => $before, 'after' => $attributes];
                    }

                    $saved[] = ['row_id' => $row->id, 'updated_at' => $row->updated_at?->toIso8601String(), 'page' => $row->page_name, 'title_length' => $row->proposed_title !== null ? mb_strlen($row->proposed_title) : null, 'meta_length' => $row->proposed_meta !== null ? mb_strlen($row->proposed_meta) : null];
                }
            });
        } catch (StaleMapRowException|InvalidArgumentException $e) {
            return Response::error($e->getMessage().' Nothing was saved.');
        }

        $audit->record(AuditLog::KEYWORD_MAP_UPDATED, $user, self::NAME, $domain, 'Edited '.count($saved)." rows of keyword map \"{$map->name}\" for {$domain}.", ['rows' => [
            'before' => array_column($changes, 'before', 'row_id'),
            'after' => array_column($changes, 'after', 'row_id'),
        ]]);

        $ids = array_column($saved, 'row_id');
        $warnings = array_values(array_filter($maps->warnings($map->fresh('rows')), fn (array $w) => $w['row_id'] === null || in_array($w['row_id'], $ids, true)));

        return Response::structured([
            'summary' => 'Saved '.count($saved).' '.(count($saved) === 1 ? 'row' : 'rows')." of \"{$map->name}\" for {$domain}.".($warnings !== [] ? ' '.count($warnings).' things to check on them.' : ''),
            'site' => ResponseEnvelope::site($profile),
            'map' => ['version_id' => $map->id, 'name' => $map->name],
            'rows' => $saved,
            'warnings' => $warnings,
        ]);
    }
}
