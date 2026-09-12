<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One row per scheduler tick outcome, written by
 * App\Listeners\Scheduling\RecordScheduledTaskResult in response to Laravel's
 * ScheduledTaskFinished/Failed/Skipped events — fires for every entry in
 * routes/console.php (and module-registered schedules) with no per-command
 * wiring needed. Read by the /settings/scheduled-jobs dashboard.
 *
 * @property int $id
 * @property string $command
 * @property string $status
 * @property ?int $duration_ms
 * @property ?int $exit_code
 * @property ?string $output
 * @property Carbon $created_at
 */
class ScheduledJobRun extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'command',
        'status',
        'duration_ms',
        'exit_code',
        'output',
    ];

    public function scopeForCommand(Builder $query, string $command): Builder
    {
        return $query->where('command', $command);
    }

    /**
     * Most recent run per distinct command, keyed by command string.
     *
     * @return Collection<string, self>
     */
    public static function latestPerCommand(): Collection
    {
        $latestIds = self::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('command')
            ->pluck('id');

        return self::query()
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy('command');
    }
}
