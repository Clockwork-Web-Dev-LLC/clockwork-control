<?php

namespace Modules\ClientReports\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property string $frequency
 * @property array $recipients
 * @property bool $is_enabled
 * @property ?Carbon $last_sent_at
 * @property ?Carbon $next_run_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Site $site
 */
class ClientReportSchedule extends Model
{
    use HasFactory;

    protected $table = 'client_report_schedules';

    protected $fillable = [
        'site_id',
        'frequency',
        'recipients',
        'is_enabled',
        'last_sent_at',
        'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'is_enabled' => 'boolean',
            'last_sent_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
