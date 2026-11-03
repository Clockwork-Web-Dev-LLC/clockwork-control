<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This (site, target) pair should never appear as a pending update."
 * Exact analogue of ManageWP Orion's "Ignored" tab.
 *
 * No expiry column. A manual ignore holds until an operator removes it. An
 * auto_failure pause is softer: the nightly loop still tries a newly
 * released version or a version that fixes a known vulnerability
 * (RunNightlyPluginUpdates::filterIgnored), and a successful update to the
 * target version deletes the row (UpdateFailureStreakRecorder::record).
 */
class PluginUpdateIgnore extends Model
{
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AUTO_FAILURE = 'auto_failure';

    protected static function booted(): void
    {
        static::creating(function ($ignore) {
            if ($ignore->ignored_at === null) {
                $ignore->ignored_at = now();
            }
        });
    }

    protected $fillable = [
        'site_id',
        'target_kind',
        'target_slug',
        'source',
        'failure_count',
        'last_error',
        'client_visible',
        'note',
        'ignored_by_user_id',
        'ignored_at',
    ];

    protected $casts = [
        'ignored_at' => 'datetime',
        'client_visible' => 'boolean',
        'failure_count' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function ignoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ignored_by_user_id');
    }

    public function isAutoFailure(): bool
    {
        return $this->source === self::SOURCE_AUTO_FAILURE;
    }

    public function isManual(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
    }
}
