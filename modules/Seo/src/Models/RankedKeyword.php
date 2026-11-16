<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A keyword a domain ranks for, from the latest pull.
 *
 * @property int $id
 * @property string $domain
 * @property string $market_key
 * @property string $provider
 * @property string $keyword
 * @property int $position
 * @property ?int $previous_position
 * @property ?string $url
 * @property ?int $volume
 * @property ?float $cpc
 * @property ?int $difficulty
 * @property ?string $intent
 * @property ?float $estimated_traffic
 * @property ?Carbon $seen_at
 * @property ?Carbon $fetched_at
 */
class RankedKeyword extends Model
{
    public $timestamps = false;

    protected $table = 'seo_ranked_keywords';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'position' => 'integer', 'previous_position' => 'integer', 'volume' => 'integer', 'cpc' => 'float',
            'difficulty' => 'integer', 'estimated_traffic' => 'float', 'seen_at' => 'datetime', 'fetched_at' => 'datetime',
        ];
    }
}
