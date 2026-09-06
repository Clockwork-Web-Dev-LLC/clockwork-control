<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per SMS attempt (or fallback event). Useful for
 * post-mortem questions like "did the text actually go out at 3am?"
 *
 * @property int $id
 * @property ?int $recipient_id null for fallback / all-off events
 * @property ?int $site_id null for test events
 * @property string $event "site_down" | "site_up" | "fallback_email" | "all_off_warning" | "test"
 * @property ?string $phone
 * @property bool $ok
 * @property ?string $error
 * @property ?string $body
 * @property ?string $twilio_sid Twilio's message ID, for cross-reference
 * @property Carbon $sent_at
 */
class NotificationLog extends Model
{
    use HasFactory;

    protected $table = 'notification_log';

    public const EVENT_SITE_DOWN = 'site_down';

    public const EVENT_SITE_UP = 'site_up';

    public const EVENT_FALLBACK_EMAIL = 'fallback_email';

    public const EVENT_ALL_OFF_WARNING = 'all_off_warning';

    public const EVENT_TEST = 'test';

    public const EVENT_SMS_STORM_PAUSED = 'sms_storm_paused';

    protected $fillable = [
        'recipient_id',
        'site_id',
        'event',
        'phone',
        'ok',
        'error',
        'body',
        'twilio_sid',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(NotificationRecipient::class, 'recipient_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }
}
