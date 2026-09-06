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
 * @property string $source llm|llar|wordfence|nginx|manual
 * @property ?string $reason
 * @property ?string $llm_verdict malicious|suspicious|benign
 * @property ?string $llm_reasoning
 * @property ?float $llm_score
 * @property ?array<string, mixed> $evidence
 * @property string $status pending|queued_for_ban|approved|dismissed|failed
 * @property ?Carbon $decided_at
 * @property ?string $decided_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read ?Server $server
 * @property-read ?Site $site
 */
class ReviewQueueEntry extends Model
{
    use HasFactory;

    protected $table = 'review_queue';

    public const STATUS_PENDING = 'pending';

    // Approved by a human but the actual fail2ban call hasn't fired yet — picked up by
    // clockwork:process-pending-bans on the next scheduler tick. We keep this distinct
    // from "approved" so the UI can show "queued" feedback and the processor knows what
    // to grab.
    public const STATUS_QUEUED_FOR_BAN = 'queued_for_ban';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_FAILED = 'failed';

    public const VERDICT_MALICIOUS = 'malicious';

    public const VERDICT_SUSPICIOUS = 'suspicious';

    public const VERDICT_BENIGN = 'benign';

    public const SOURCE_LLM = 'llm';

    public const SOURCE_LLAR = 'llar';

    public const SOURCE_WORDFENCE = 'wordfence';

    public const SOURCE_NGINX = 'nginx';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'ip',
        'server_id',
        'site_id',
        'source',
        'reason',
        'llm_verdict',
        'llm_reasoning',
        'llm_score',
        'evidence',
        'status',
        'decided_at',
        'decided_by',
    ];

    protected function casts(): array
    {
        return [
            'llm_score' => 'float',
            'evidence' => 'array',
            'decided_at' => 'datetime',
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
}
