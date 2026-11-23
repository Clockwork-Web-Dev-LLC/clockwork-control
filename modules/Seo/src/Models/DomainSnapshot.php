<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Seo\Casts\DateOnly;

/**
 * A domain's ranking-keyword count for one month in one country.
 *
 * @property int $id
 * @property string $domain
 * @property string $market_key
 * @property Carbon $month
 * @property string $provider
 * @property ?int $keywords
 * @property ?int $top3
 * @property ?int $top10
 * @property ?int $top20
 * @property ?int $top100
 * @property ?array<string, ?int> $buckets
 * @property ?float $estimated_traffic
 * @property ?float $traffic_value
 * @property ?int $new_keywords
 * @property ?int $up_keywords
 * @property ?int $down_keywords
 * @property ?int $lost_keywords
 * @property ?Carbon $fetched_at
 */
class DomainSnapshot extends Model
{
    public $timestamps = false;

    protected $table = 'seo_domain_snapshots';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'month' => DateOnly::class,
            'keywords' => 'integer', 'top3' => 'integer', 'top10' => 'integer', 'top20' => 'integer', 'top100' => 'integer',
            'buckets' => 'array',
            'estimated_traffic' => 'float', 'traffic_value' => 'float',
            'new_keywords' => 'integer', 'up_keywords' => 'integer', 'down_keywords' => 'integer', 'lost_keywords' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }
}
