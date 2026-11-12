<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Seo\Casts\DateOnly;

/**
 * Daily GSC site totals, segment 'all' or 'nonbranded'.
 *
 * @property int $site_id
 * @property Carbon $date
 * @property string $segment
 * @property int $clicks
 * @property int $impressions
 * @property float $ctr
 * @property float $position
 */
class GscDaily extends Model
{
    public $timestamps = false;

    protected $table = 'seo_gsc_daily';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => DateOnly::class, 'clicks' => 'integer', 'impressions' => 'integer', 'ctr' => 'float', 'position' => 'float', 'fetched_at' => 'datetime'];
    }
}
