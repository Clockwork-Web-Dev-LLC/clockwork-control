<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Seo\Casts\DateOnly;

/**
 * Daily GA4 rows for the chart and the pre-2026-05-13 AI estimate only.
 *
 * @property int $site_id
 * @property Carbon $date
 * @property string $dimension
 * @property string $value
 * @property int $sessions
 * @property int $engaged_sessions
 */
class Ga4Daily extends Model
{
    public $timestamps = false;

    protected $table = 'seo_ga4_daily';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => DateOnly::class, 'sessions' => 'integer', 'engaged_sessions' => 'integer', 'fetched_at' => 'datetime'];
    }
}
