<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $site_id
 * @property string $source wordfence|llar|nginx
 * @property Carbon $event_at
 * @property string $ip
 * @property ?string $user_agent
 * @property ?string $request_path
 * @property ?string $request_method
 * @property int $status_code
 * @property ?array<string, mixed> $raw
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Site $site
 */
class ThreatLog extends Model
{
    use HasFactory;

    public const SOURCE_WORDFENCE = 'wordfence';

    public const SOURCE_LLAR = 'llar';

    public const SOURCE_NGINX = 'nginx';

    protected $fillable = [
        'site_id',
        'source',
        'event_at',
        'ip',
        'user_agent',
        'request_path',
        'request_method',
        'status_code',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'event_at' => 'datetime',
            'status_code' => 'integer',
            'raw' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
