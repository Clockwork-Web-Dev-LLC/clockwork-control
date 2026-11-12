<?php

namespace Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Seo\Casts\DateOnly;

/**
 * Key events by event name across all channels, per month.
 *
 * @property int $site_id
 * @property Carbon $month
 * @property string $event_name
 * @property float $key_events
 * @property int $event_count
 * @property bool $is_final
 * @property bool $subject_to_thresholding
 */
class Ga4KeyEventMonth extends Model
{
    public $timestamps = false;

    protected $table = 'seo_ga4_key_event_months';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['month' => DateOnly::class, 'key_events' => 'float', 'event_count' => 'integer', 'is_final' => 'boolean', 'subject_to_thresholding' => 'boolean', 'fetched_at' => 'datetime'];
    }
}
