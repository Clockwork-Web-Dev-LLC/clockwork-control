<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Span-aware off-window — a single row may cross midnight or a day
 * boundary. The wraparound math lives in OnCallResolver::isInWindow.
 *
 * @property int $id
 * @property int $recipient_id
 * @property string $label e.g. "Shabbat", "Vacation Aug 5–10"
 * @property int $start_dow 0=Sun..6=Sat (Carbon::dayOfWeek)
 * @property string $start_time HH:MM:SS
 * @property int $end_dow 0=Sun..6=Sat
 * @property string $end_time HH:MM:SS
 * @property string $timezone IANA tz, default America/New_York
 * @property bool $enabled
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read NotificationRecipient $recipient
 */
class NotificationOffWindow extends Model
{
    use HasFactory;

    protected $fillable = [
        'recipient_id',
        'label',
        'start_dow',
        'start_time',
        'end_dow',
        'end_time',
        'timezone',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'start_dow' => 'integer',
            'end_dow' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(NotificationRecipient::class, 'recipient_id');
    }
}
