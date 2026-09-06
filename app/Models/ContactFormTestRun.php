<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property Carbon $ran_at
 * @property string $mode lab|live
 * @property bool $accepted
 * @property bool $mail_invoked
 * @property ?string $mail_outcome sent|failed|suppressed|null
 * @property string $status success|failed
 * @property ?int $http_code
 * @property ?string $error
 * @property ?int $post_smtp_log_id
 * @property ?string $response_excerpt
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Site $site
 */
class ContactFormTestRun extends Model
{
    use HasFactory;

    public const MODE_LAB = 'lab';

    public const MODE_LIVE = 'live';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const MAIL_SENT = 'sent';

    public const MAIL_FAILED = 'failed';

    public const MAIL_SUPPRESSED = 'suppressed';

    protected $fillable = [
        'site_id',
        'contact_form_test_id',
        'ran_at',
        'mode',
        'accepted',
        'mail_invoked',
        'mail_outcome',
        'status',
        'http_code',
        'error',
        'post_smtp_log_id',
        'response_excerpt',
    ];

    protected function casts(): array
    {
        return [
            'ran_at' => 'datetime',
            'accepted' => 'boolean',
            'mail_invoked' => 'boolean',
            'http_code' => 'integer',
            'post_smtp_log_id' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function contactFormTest(): BelongsTo
    {
        return $this->belongsTo(ContactFormTest::class);
    }
}
