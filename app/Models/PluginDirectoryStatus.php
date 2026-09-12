<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tracks WordPress.org plugin directory status (open, closed/zombieware, not_found, error).
 *
 * @property int $id
 * @property string $slug
 * @property string $status
 * @property ?string $reason
 * @property ?string $closed_date
 * @property ?Carbon $checked_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class PluginDirectoryStatus extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'slug',
        'status',
        'reason',
        'closed_date',
        'checked_at',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
    ];

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isNotFound(): bool
    {
        return $this->status === self::STATUS_NOT_FOUND;
    }

    public function isError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }
}
