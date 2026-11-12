<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Seo\Casts\DateOnly;

/**
 * One check of one tracked keyword on one day. A null position means the
 * site was not within the depth looked at, which is not the same as 0 and
 * not the same as "not checked" (no row at all).
 *
 * @property int $id
 * @property int $tracked_keyword_id
 * @property int $site_id
 * @property Carbon $checked_on
 * @property ?int $position
 * @property ?int $absolute_position
 * @property ?string $url
 * @property int $depth
 * @property int $pages_ranking
 * @property bool $has_ai_overview
 * @property bool $cited_in_ai_overview
 * @property ?list<string> $features
 * @property ?list<string> $top_domains
 * @property ?array<string, ?int> $competitors
 */
class RankCheck extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'seo_rank_checks';

    protected $fillable = [
        'tracked_keyword_id', 'site_id', 'checked_on', 'position', 'absolute_position', 'url', 'depth',
        'pages_ranking', 'has_ai_overview', 'cited_in_ai_overview', 'features', 'top_domains', 'competitors',
    ];

    protected function casts(): array
    {
        return [
            'checked_on' => DateOnly::class,
            'position' => 'integer',
            'absolute_position' => 'integer',
            'depth' => 'integer',
            'pages_ranking' => 'integer',
            'has_ai_overview' => 'boolean',
            'cited_in_ai_overview' => 'boolean',
            'features' => 'array',
            'top_domains' => 'array',
            'competitors' => 'array',
        ];
    }

    /** @return BelongsTo<TrackedKeyword, $this> */
    public function trackedKeyword(): BelongsTo
    {
        return $this->belongsTo(TrackedKeyword::class);
    }
}
