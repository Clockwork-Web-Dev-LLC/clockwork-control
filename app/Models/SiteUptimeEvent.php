<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per uptime state transition (up→down or down→up). NOT one per probe.
 *
 * @property int $id
 * @property int $site_id
 * @property string $event_type 'down' or 'up'
 * @property ?int $status_code
 * @property ?string $error
 * @property ?int $response_time_ms
 * @property ?array<string, mixed> $diagnosis
 * @property Carbon $event_at
 * @property ?Carbon $created_at
 * @property-read ?Site $site
 */
class SiteUptimeEvent extends Model
{
    use HasFactory;

    public const TYPE_DOWN = 'down';

    public const TYPE_UP = 'up';

    public const TYPE_MAINTENANCE = 'maintenance';

    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'event_type',
        'status_code',
        'error',
        'response_time_ms',
        'diagnosis',
        'event_at',
    ];

    protected function casts(): array
    {
        return [
            'event_at' => 'datetime',
            'created_at' => 'datetime',
            'status_code' => 'integer',
            'response_time_ms' => 'integer',
            'diagnosis' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
