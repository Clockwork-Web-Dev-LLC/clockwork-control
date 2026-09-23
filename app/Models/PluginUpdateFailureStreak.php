<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PluginUpdateFailureStreak extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'target_kind',
        'target_slug',
        'consecutive_failures',
        'last_target_version',
        'last_from_version',
        'last_error',
        'last_failed_at',
        'last_job_id',
        'ignored_at',
        'ignore_id',
        'companion_pushed_at',
    ];

    protected $casts = [
        'consecutive_failures' => 'integer',
        'last_failed_at' => 'datetime',
        'ignored_at' => 'datetime',
        'companion_pushed_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function lastJob(): BelongsTo
    {
        return $this->belongsTo(PluginUpdateJob::class, 'last_job_id');
    }

    public function ignore(): BelongsTo
    {
        return $this->belongsTo(PluginUpdateIgnore::class, 'ignore_id');
    }

    public function scopeIgnored(Builder $query): Builder
    {
        return $query->whereNotNull('ignored_at');
    }

    public function scopeActiveStreak(Builder $query): Builder
    {
        return $query->where('consecutive_failures', '>', 0);
    }
}
