<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Seo\Casts\DateOnly;

/**
 * One page of a keyword map.
 *
 * @property int $id
 * @property int $map_id
 * @property int $sort
 * @property ?string $page_name
 * @property ?string $url
 * @property bool $is_new_page
 * @property ?string $primary_keyword
 * @property ?int $volume
 * @property ?int $difficulty
 * @property ?float $cpc
 * @property ?string $metrics_provider
 * @property ?Carbon $metrics_fetched_at
 * @property ?list<array{keyword: string, volume: ?int}> $secondary_keywords
 * @property ?string $intent
 * @property ?string $proposed_title
 * @property ?string $proposed_meta
 * @property ?list<string> $schema_types
 * @property string $ship_status
 * @property ?Carbon $shipped_on
 * @property ?string $ship_note
 * @property ?string $notes
 * @property bool $excluded
 * @property bool $needs_review
 * @property ?Carbon $updated_at
 */
class KeywordMapRow extends Model
{
    public const STATUSES = ['not_started' => 'Not started', 'drafted' => 'Drafted', 'live' => 'Live', 'planned_new' => 'Planned (new page)'];

    protected $table = 'seo_keyword_map_rows';

    protected $fillable = [
        'map_id', 'sort', 'page_name', 'url', 'is_new_page', 'primary_keyword', 'volume', 'difficulty', 'cpc',
        'metrics_provider', 'metrics_fetched_at', 'secondary_keywords', 'intent', 'proposed_title', 'proposed_meta',
        'schema_types', 'ship_status', 'shipped_on', 'ship_note', 'notes', 'excluded', 'needs_review',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_new_page' => 'boolean',
            'volume' => 'integer',
            'difficulty' => 'integer',
            'cpc' => 'float',
            'metrics_fetched_at' => 'datetime',
            'secondary_keywords' => 'array',
            'schema_types' => 'array',
            'shipped_on' => DateOnly::class,
            'excluded' => 'boolean',
            'needs_review' => 'boolean',
        ];
    }

    /** @return BelongsTo<KeywordMap, $this> */
    public function map(): BelongsTo
    {
        return $this->belongsTo(KeywordMap::class, 'map_id');
    }
}
