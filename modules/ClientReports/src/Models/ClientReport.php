<?php

namespace Modules\ClientReports\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $site_id
 * @property ?int $template_id
 * @property string $title
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property array $sections_data
 * @property ?string $client_name
 * @property ?string $client_email
 * @property string $status
 * @property ?Carbon $sent_at
 * @property string $public_token
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Site $site
 * @property-read ?ClientReportTemplate $template
 */
class ClientReport extends Model
{
    use HasFactory;

    protected $table = 'client_reports';

    protected $fillable = [
        'site_id',
        'template_id',
        'title',
        'period_start',
        'period_end',
        'sections_data',
        'client_name',
        'client_email',
        'status',
        'sent_at',
        'public_token',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'sections_data' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ClientReport $report) {
            if (empty($report->public_token)) {
                $report->public_token = Str::random(40);
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ClientReportTemplate::class, 'template_id');
    }
}
