<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Seo\Casts\DateOnly;

/**
 * GA4 month figures per channel group, from a calendar-month request.
 *
 * @property int $site_id
 * @property Carbon $month
 * @property string $channel_group
 * @property int $sessions
 * @property int $engaged_sessions
 * @property float $key_events
 * @property bool $is_final
 * @property bool $subject_to_thresholding
 */
class Ga4Month extends Model
{
    public $timestamps = false;

    protected $table = 'seo_ga4_months';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['month' => DateOnly::class, 'sessions' => 'integer', 'engaged_sessions' => 'integer', 'key_events' => 'float', 'is_final' => 'boolean', 'subject_to_thresholding' => 'boolean', 'fetched_at' => 'datetime'];
    }
}
