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
 * @property ?int $template_id
 * @property string $frequency
 * @property string $delivery_mode
 * @property array $recipients
 * @property bool $is_enabled
 * @property ?Carbon $last_sent_at
 * @property ?Carbon $next_run_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Site $site
 * @property-read ?ClientReportTemplate $template
 */
class ClientReportSchedule extends Model
{
    use HasFactory;

    public const MODE_AUTO = 'auto';

    public const MODE_DRAFT = 'draft';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    protected $table = 'client_report_schedules';

    protected $fillable = [
        'site_id',
        'template_id',
        'frequency',
        'delivery_mode',
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

    public function template(): BelongsTo
    {
        return $this->belongsTo(ClientReportTemplate::class, 'template_id');
    }

    public function isDue(): bool
    {
        return $this->next_run_at === null || $this->next_run_at->isPast();
    }

    public function computeNextRun(?Carbon $from = null): Carbon
    {
        $base = ($from ?? now())->copy();

        return match ($this->frequency) {
            self::FREQUENCY_WEEKLY => $base->addWeek(),
            default => $base->addMonth(),
        };
    }

    public function advanceNextRun(): void
    {
        // Always base the next run on now(), not the schedule's own (possibly
        // still-future) next_run_at — sendNow()/--force run unconditionally,
        // regardless of due date. Chaining from a not-yet-due next_run_at
        // would silently skip the originally planned dispatch and push the
        // cadence forward an extra cycle every time someone tests "Run Now".
        $this->update([
            'next_run_at' => $this->computeNextRun(),
        ]);
    }
}
