<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ip
 * @property ?int $server_id
 * @property ?int $site_id
 * @property string $source wordfence|llar|nginx|manual
 * @property ?string $reason
 * @property ?string $llm_verdict malicious|suspicious|benign
 * @property ?string $llm_reasoning
 * @property ?string $decision approved|dismissed|auto
 * @property ?string $decided_by
 * @property ?Carbon $banned_at
 * @property ?Carbon $expires_at
 * @property ?Carbon $unbanned_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read ?Server $server
 * @property-read ?Site $site
 */
class BlockedIp extends Model
{
    use HasFactory;

    public const SOURCE_WORDFENCE = 'wordfence';

    public const SOURCE_LLAR = 'llar';

    public const SOURCE_NGINX = 'nginx';

    public const SOURCE_MANUAL = 'manual';

    public const VERDICT_MALICIOUS = 'malicious';

    public const VERDICT_SUSPICIOUS = 'suspicious';

    public const VERDICT_BENIGN = 'benign';

    public const DECISION_APPROVED = 'approved';

    public const DECISION_DISMISSED = 'dismissed';

    public const DECISION_AUTO = 'auto';

    protected $fillable = [
        'ip',
        'server_id',
        'site_id',
        'source',
        'reason',
        'llm_verdict',
        'llm_reasoning',
        'decision',
        'decided_by',
        'banned_at',
        'expires_at',
        'unbanned_at',
    ];

    protected function casts(): array
    {
        return [
            'banned_at' => 'datetime',
            'expires_at' => 'datetime',
            'unbanned_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isActive(): bool
    {
        if ($this->unbanned_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return $this->banned_at !== null;
    }
}
