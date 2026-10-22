<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property string $source
 * @property string $kind
 * @property string $external_ref
 * @property Carbon $backup_at
 * @property ?int $bytes
 * @property bool $is_manual
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property-read Site $site
 */
class SiteBackup extends Model
{
    public const SOURCE_PRESSABLE = 'pressable';

    public const SOURCE_SPINUPWP_SPACES = 'spinupwp_spaces';

    public const SOURCE_S3_ARCHIVE = 's3_archive';

    public const KIND_FILES = 'fs';

    public const KIND_DATABASE = 'db';

    public const KIND_FULL = 'full';

    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'source',
        'kind',
        'external_ref',
        'backup_at',
        'bytes',
        'is_manual',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'backup_at' => 'datetime',
            'bytes' => 'integer',
            'is_manual' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
