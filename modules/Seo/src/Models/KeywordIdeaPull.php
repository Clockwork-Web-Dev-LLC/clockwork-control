<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One bought list of keyword ideas.
 *
 * @property int $id
 * @property int $site_id
 * @property ?int $user_id
 * @property string $mode
 * @property list<string> $seeds
 * @property string $market_key
 * @property list<string> $keywords
 * @property int $keyword_count
 * @property ?int $available
 * @property float $cost_usd
 * @property ?Carbon $created_at
 */
class KeywordIdeaPull extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'seo_keyword_idea_pulls';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['seeds' => 'array', 'keywords' => 'array', 'keyword_count' => 'integer', 'available' => 'integer', 'cost_usd' => 'float'];
    }
}
