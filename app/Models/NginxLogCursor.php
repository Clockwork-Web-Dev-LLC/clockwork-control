<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property string $log_path
 * @property int $inode
 * @property int $offset
 * @property ?Carbon $last_read_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Site $site
 */
class NginxLogCursor extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'log_path',
        'inode',
        'offset',
        'last_read_at',
    ];

    protected function casts(): array
    {
        return [
            'inode' => 'integer',
            'offset' => 'integer',
            'last_read_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
