<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $server_id
 * @property Carbon $recorded_at
 * @property ?string $cpu_pct decimal:2
 * @property ?string $memory_pct decimal:2
 * @property ?string $disk_pct decimal:2
 * @property ?string $load_1 decimal:2
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Server $server
 *
 * Aggregate-alias columns produced by GROUP-BY queries (selectRaw with AVG/MAX
 * aliases). Only present on rows hydrated by those specific queries — accessing
 * them on a plain Eloquent fetch returns null. Documented here so PHPStan
 * doesn't flag the read in CapacityController / IssuesController / IssueCounter.
 * @property-read float|null $avg_cpu
 * @property-read float|null $avg_memory
 * @property-read float|null $avg_disk
 * @property-read float|null $peak_cpu
 */
class ServerMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_id',
        'recorded_at',
        'cpu_pct',
        'memory_pct',
        'disk_pct',
        'load_1',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'cpu_pct' => 'decimal:2',
            'memory_pct' => 'decimal:2',
            'disk_pct' => 'decimal:2',
            'load_1' => 'decimal:2',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
