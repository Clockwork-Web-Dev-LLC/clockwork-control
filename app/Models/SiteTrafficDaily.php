<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property Carbon $date
 * @property int $requests
 * @property int $unique_ips
 * @property int $visits
 * @property int $bytes_sent
 * @property int $status_2xx
 * @property int $status_3xx
 * @property int $status_4xx
 * @property int $status_5xx
 * @property ?array<int, array<string, mixed>> $top_paths
 * @property ?array<int, array<string, mixed>> $top_ips
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Site $site
 *
 * Aggregate-alias columns produced by GROUP-BY queries on this table —
 * selectRaw('SUM(visits) AS rolling_visits') etc. Only present on rows
 * hydrated by those specific queries.
 * @property-read int|null $rolling_visits
 * @property-read int|null $month_visits
 * @property-read int|null $last_7d_visits
 */
class SiteTrafficDaily extends Model
{
    use HasFactory;

    protected $table = 'site_traffic_daily';

    protected $fillable = [
        'site_id',
        'date',
        'requests',
        'unique_ips',
        'visits',
        'bytes_sent',
        'status_2xx',
        'status_3xx',
        'status_4xx',
        'status_5xx',
        'top_paths',
        'top_ips',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'requests' => 'integer',
            'unique_ips' => 'integer',
            'visits' => 'integer',
            'bytes_sent' => 'integer',
            'status_2xx' => 'integer',
            'status_3xx' => 'integer',
            'status_4xx' => 'integer',
            'status_5xx' => 'integer',
            'top_paths' => 'array',
            'top_ips' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
