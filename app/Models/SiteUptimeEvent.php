<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per uptime state transition (up→down or down→up). NOT one per probe.
 *
 * @property int $id
 * @property int $site_id
 * @property string $event_type 'down' or 'up'
 * @property ?int $status_code
 * @property ?string $error
 * @property ?int $response_time_ms
 * @property ?array<string, mixed> $diagnosis
 * @property Carbon $event_at
 * @property bool $is_sla_exempt
 * @property ?string $exemption_reason
 * @property ?string $exemption_notes
 * @property ?Carbon $exempted_at
 * @property ?string $exempted_by
 * @property ?Carbon $created_at
 * @property-read ?Site $site
 */
class SiteUptimeEvent extends Model
{
    use HasFactory;

    public const TYPE_DOWN = 'down';

    public const TYPE_UP = 'up';

    public const TYPE_MAINTENANCE = 'maintenance';

    public const REASON_CLIENT_DNS = 'client_dns';

    public const REASON_DOMAIN_EXPIRED = 'domain_expired';

    public const REASON_THIRD_PARTY = 'third_party';

    public const REASON_CLIENT_REQUESTED = 'client_requested';

    public const REASON_OTHER = 'other';

    public const EXEMPTION_REASONS = [
        self::REASON_CLIENT_DNS => 'Client DNS change',
        self::REASON_DOMAIN_EXPIRED => 'Domain expired',
        self::REASON_THIRD_PARTY => 'Third-party outage',
        self::REASON_CLIENT_REQUESTED => 'Client requested',
        self::REASON_OTHER => 'Other (not our fault)',
    ];

    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'event_type',
        'status_code',
        'error',
        'response_time_ms',
        'diagnosis',
        'event_at',
        'is_sla_exempt',
        'exemption_reason',
        'exemption_notes',
        'exempted_at',
        'exempted_by',
    ];

    protected function casts(): array
    {
        return [
            'event_at' => 'datetime',
            'created_at' => 'datetime',
            'status_code' => 'integer',
            'response_time_ms' => 'integer',
            'diagnosis' => 'array',
            'is_sla_exempt' => 'boolean',
            'exempted_at' => 'datetime',
        ];
    }

    public function isSlaExempt(): bool
    {
        return (bool) $this->is_sla_exempt;
    }

    public function exemptionReasonLabel(): ?string
    {
        return $this->exemption_reason ? (self::EXEMPTION_REASONS[$this->exemption_reason] ?? ucfirst(str_replace('_', ' ', $this->exemption_reason))) : null;
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
