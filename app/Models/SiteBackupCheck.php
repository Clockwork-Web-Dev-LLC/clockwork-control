<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property string $source
 * @property Carbon $checked_at
 * @property bool $ok
 * @property int $backups_seen
 * @property ?string $error
 * @property-read Site $site
 */
class SiteBackupCheck extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'source',
        'checked_at',
        'ok',
        'backups_seen',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'ok' => 'boolean',
            'backups_seen' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
