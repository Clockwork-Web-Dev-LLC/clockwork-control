<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A domain that ranks for the same keywords as another, from the latest discovery.
 *
 * @property int $id
 * @property string $domain
 * @property string $market_key
 * @property string $provider
 * @property string $competitor_domain
 * @property ?int $shared_keywords
 * @property ?float $avg_position
 * @property ?int $competitor_keywords
 * @property ?float $competitor_traffic
 * @property ?Carbon $fetched_at
 */
class DomainCompetitor extends Model
{
    public $timestamps = false;

    protected $table = 'seo_domain_competitors';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'shared_keywords' => 'integer', 'avg_position' => 'float', 'competitor_keywords' => 'integer',
            'competitor_traffic' => 'float', 'fetched_at' => 'datetime',
        ];
    }
}
