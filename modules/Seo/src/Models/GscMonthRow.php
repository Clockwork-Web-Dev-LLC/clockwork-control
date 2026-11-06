<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Seo\Casts\DateOnly;

/**
 * One page, query or page×query row for a calendar month.
 *
 * @property int $site_id
 * @property Carbon $month
 * @property string $dimension
 * @property ?string $page
 * @property ?string $query
 * @property string $row_hash
 * @property ?bool $is_branded
 * @property int $clicks
 * @property int $impressions
 * @property float $ctr
 * @property float $position
 */
class GscMonthRow extends Model
{
    public $timestamps = false;

    protected $table = 'seo_gsc_month_rows';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['month' => DateOnly::class, 'is_branded' => 'boolean', 'clicks' => 'integer', 'impressions' => 'integer', 'ctr' => 'float', 'position' => 'float', 'fetched_at' => 'datetime'];
    }
}
