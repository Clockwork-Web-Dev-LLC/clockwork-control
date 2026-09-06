<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Live tracker row for one update against one site. Covers all four kinds
 * (plugins/themes/core/translations) — `kind` distinguishes them.
 *
 * Status state machine:
 *   pending → running → (complete | failed | skipped)
 *   pending → cancelled        (operator-initiated before pickup)
 *
 * Pair with `action_logs` (the audit-trail archive) — these rows are the
 * live progress substrate. See AbstractRunUpdate for the worker that drives
 * transitions.
 */
class PluginUpdateJob extends Model
{
    use HasFactory;

    public const KIND_PLUGIN = 'plugin';

    public const KIND_THEME = 'theme';

    public const KIND_CORE = 'core';

    public const KIND_TRANSLATION = 'translation';

    public const KINDS = [self::KIND_PLUGIN, self::KIND_THEME, self::KIND_CORE, self::KIND_TRANSLATION];

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_CANCELLED = 'cancelled';

    public const LIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING];

    public const TERMINAL_STATUSES = [self::STATUS_COMPLETE, self::STATUS_FAILED, self::STATUS_SKIPPED, self::STATUS_CANCELLED];

    protected $fillable = [
        'site_id',
        'target_kind',
        'target_slug',
        'target_name',
        'status',
        'before_version',
        'target_version',
        'after_version',
        'was_active',
        'reactivated',
        'elapsed_ms',
        'error',
        'messages',
        'state_before',
        'repairs',
        'requested_by_user_id',
        'batch_id',
        'queued_at',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'was_active' => 'boolean',
        'reactivated' => 'boolean',
        'messages' => 'array',
        'state_before' => 'array',
        'repairs' => 'array',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', self::LIVE_STATUSES);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForBatch(Builder $query, string $batchId): void
    {
        $query->where('batch_id', $batchId);
    }
}
