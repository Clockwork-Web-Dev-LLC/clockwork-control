<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per nightly run of the standalone backup-relay droplet. See
 * migration for column purposes.
 *
 * @property int $id
 * @property int $sites_total
 * @property int $sites_archived
 * @property int $sites_skipped
 * @property int $sites_failed
 * @property ?array $failures array of {domain, error}
 * @property Carbon $started_at
 * @property Carbon $finished_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class BackupRelayRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'sites_total',
        'sites_archived',
        'sites_skipped',
        'sites_failed',
        'failures',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'sites_total' => 'integer',
            'sites_archived' => 'integer',
            'sites_skipped' => 'integer',
            'sites_failed' => 'integer',
            'failures' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
