<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cached metrics for one keyword in one market.
 *
 * @property int $id
 * @property string $keyword
 * @property string $market_key
 * @property string $provider
 * @property ?int $volume
 * @property ?array $monthly
 * @property ?float $cpc
 * @property ?float $paid_competition
 * @property ?int $difficulty
 * @property ?string $intent
 * @property ?array $secondary_intents
 * @property ?array $local_volumes
 * @property bool $no_data
 * @property ?Carbon $fetched_at
 */
class KeywordMetric extends Model
{
    public $timestamps = false;

    protected $table = 'seo_keyword_metrics';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'volume' => 'integer',
            'monthly' => 'array',
            'cpc' => 'float',
            'paid_competition' => 'float',
            'difficulty' => 'integer',
            'secondary_intents' => 'array',
            'local_volumes' => 'array',
            'no_data' => 'boolean',
            'fetched_at' => 'datetime',
        ];
    }
}
