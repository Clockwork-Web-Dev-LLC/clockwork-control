<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One row per form-to-test on a site. Up to 3 active rows per site
 * (enforced at the controller). Replaces the old per-site
 * sites.contact_form_test_* columns; those stay populated for one
 * release as a safety net before a follow-up drop migration.
 *
 * @property int $id
 * @property int $site_id
 * @property int $slot 1..3, stable display order
 * @property string $form_id
 * @property string $form_plugin
 * @property ?string $form_url
 * @property string $frequency daily|weekly
 * @property bool $enabled
 * @property string $state pending|success|failed
 * @property int $failure_streak
 * @property ?Carbon $last_test_at
 * @property ?Carbon $state_changed_at
 * @property ?string $last_test_error
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Site $site
 * @property-read Collection<int, ContactFormTestRun> $runs
 */
class ContactFormTest extends Model
{
    use HasFactory;

    public const STATE_PENDING = 'pending';

    public const STATE_SUCCESS = 'success';

    public const STATE_FAILED = 'failed';

    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const CREATED_BY_AGENCY = 'agency';

    public const CREATED_BY_CLIENT = 'client';

    /** Streak threshold above which we surface failures (Mattermost). */
    public const ALERT_STREAK_THRESHOLD = 2;

    /** Hard cap on active rows per site. UI enforces, controller asserts. */
    public const MAX_PER_SITE = 3;

    protected $fillable = [
        'site_id',
        'slot',
        'form_id',
        'form_plugin',
        'form_url',
        'frequency',
        'created_by',
        'enabled',
        'state',
        'failure_streak',
        'last_test_at',
        'state_changed_at',
        'last_test_error',
    ];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'enabled' => 'boolean',
            'failure_streak' => 'integer',
            'last_test_at' => 'datetime',
            'state_changed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ContactFormTestRun::class)->orderByDesc('ran_at');
    }

    /**
     * Cadence in days. Drives the "is it due?" check in the scheduler loop.
     */
    public function frequencyDays(): int
    {
        return match ($this->frequency) {
            self::FREQUENCY_DAILY => 1,
            // Unknown/legacy values fall back to weekly rather than throwing
            // a MatchError mid-scheduler-loop and killing the whole run.
            default => 7,
        };
    }
}
