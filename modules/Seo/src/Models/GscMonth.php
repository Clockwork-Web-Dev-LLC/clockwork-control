<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Seo\Casts\DateOnly;

/**
 * Finality and truncation per site per month of GSC month rows.
 *
 * @property int $site_id
 * @property Carbon $month
 * @property bool $is_final
 * @property int $rows_page
 * @property int $rows_query
 * @property int $rows_page_query
 * @property bool $possibly_truncated
 * @property ?Carbon $synced_at
 */
class GscMonth extends Model
{
    public $timestamps = false;

    protected $table = 'seo_gsc_months';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['month' => DateOnly::class, 'is_final' => 'boolean', 'possibly_truncated' => 'boolean', 'rows_page' => 'integer', 'rows_query' => 'integer', 'rows_page_query' => 'integer', 'synced_at' => 'datetime'];
    }
}
