<?php

namespace Modules\AiRemedy\Models;

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $trigger_type
 * @property string $status
 * @property ?int $server_id
 * @property ?int $site_id
 * @property ?int $user_id
 * @property string $actor
 * @property string $model_used
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property string $total_cost_usd
 * @property ?string $trigger_reason
 * @property ?array<string, mixed> $telemetry_snapshot
 * @property ?string $diagnosis_summary
 * @property ?string $root_cause
 * @property string $safety_tier
 * @property ?array<int, string> $proposed_commands
 * @property ?array<int, string> $approved_commands
 * @property ?string $execution_output
 * @property ?array<string, mixed> $before_metrics
 * @property ?array<string, mixed> $after_metrics
 * @property ?string $error_message
 * @property Carbon $started_at
 * @property ?Carbon $completed_at
 * @property ?Carbon $hidden_at
 * @property ?int $hidden_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ?Server $server
 * @property-read ?Site $site
 * @property-read ?User $user
 */
class AiRemedyRun extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ANALYZED = 'analyzed';

    public const STATUS_EXECUTING = 'executing';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_UNFIXABLE = 'unfixable';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ALLOWED_MAINTENANCE = 'allowed_maintenance';

    public const TIER_1_SAFE = 'tier_1_safe';

    public const TIER_2_CAUTIOUS = 'tier_2_cautious';

    public const TIER_3_PROHIBITED = 'tier_3_prohibited';

    public const TIER_UNFIXABLE = 'unfixable';

    public const TRIGGER_SERVER_SPIKE = 'server_spike';

    public const TRIGGER_SITE_DOWNTIME = 'site_downtime';

    public const TRIGGER_MANUAL_AUDIT = 'manual_audit';

    protected $table = 'ai_remedy_runs';

    protected $fillable = [
        'trigger_type',
        'status',
        'server_id',
        'site_id',
        'user_id',
        'actor',
        'model_used',
        'prompt_tokens',
        'completion_tokens',
        'total_cost_usd',
        'trigger_reason',
        'telemetry_snapshot',
        'diagnosis_summary',
        'root_cause',
        'safety_tier',
        'proposed_commands',
        'approved_commands',
        'execution_output',
        'before_metrics',
        'after_metrics',
        'error_message',
        'started_at',
        'completed_at',
        'hidden_at',
        'hidden_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'hidden_at' => 'datetime',
            'telemetry_snapshot' => 'array',
            'proposed_commands' => 'array',
            'approved_commands' => 'array',
            'before_metrics' => 'array',
            'after_metrics' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_cost_usd' => 'decimal:4',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function durationSeconds(): ?int
    {
        if (! $this->completed_at || ! $this->started_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->completed_at);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_RESOLVED => 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30',
            self::STATUS_ANALYZED => 'bg-blue-500/15 text-blue-600 dark:text-blue-400 border-blue-500/30',
            self::STATUS_ALLOWED_MAINTENANCE => 'bg-sky-500/15 text-sky-600 dark:text-sky-400 border-sky-500/30',
            self::STATUS_EXECUTING => 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30 animate-pulse',
            self::STATUS_UNFIXABLE => 'bg-purple-500/15 text-purple-600 dark:text-purple-400 border-purple-500/30',
            self::STATUS_REJECTED => 'bg-neutral-500/15 text-neutral-600 dark:text-neutral-400 border-neutral-500/30',
            self::STATUS_FAILED => 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border-rose-500/30',
            default => 'bg-neutral-500/15 text-neutral-600 dark:text-neutral-400 border-neutral-500/30',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ALLOWED_MAINTENANCE => 'Allowed Maintenance',
            self::STATUS_RESOLVED => 'Resolved',
            self::STATUS_ANALYZED => 'Analyzed',
            self::STATUS_EXECUTING => 'Executing',
            self::STATUS_UNFIXABLE => 'Unfixable',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_FAILED => 'Failed',
            default => ucfirst(str_replace('_', ' ', (string) $this->status)),
        };
    }

    public function isAllowedMaintenance(): bool
    {
        return $this->status === self::STATUS_ALLOWED_MAINTENANCE;
    }

    public function safetyBadgeClass(): string
    {
        return match ($this->safety_tier) {
            self::TIER_1_SAFE => 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30',
            self::TIER_2_CAUTIOUS => 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30',
            self::TIER_3_PROHIBITED, self::TIER_UNFIXABLE => 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border-rose-500/30',
            default => 'bg-neutral-500/15 text-neutral-600 dark:text-neutral-400 border-neutral-500/30',
        };
    }

    public function safetyLabel(): string
    {
        return match ($this->safety_tier) {
            self::TIER_1_SAFE => 'Tier 1 · Safe',
            self::TIER_2_CAUTIOUS => 'Tier 2 · Cautious',
            self::TIER_3_PROHIBITED => 'Tier 3 · Prohibited',
            self::TIER_UNFIXABLE => 'Unfixable',
            default => ucfirst(str_replace(['tier_', '_'], ['', ' '], (string) $this->safety_tier)),
        };
    }

    public function isWatchMode(): bool
    {
        return in_array($this->actor, ['watch_mode', 'simulation'], true);
    }

    public function isSimulation(): bool
    {
        return $this->actor === 'simulation';
    }

    public function modeLabel(): string
    {
        return match ($this->actor) {
            'watch_mode' => 'Watch Mode',
            'simulation' => 'Simulation',
            'autonomous' => 'Auto-Heal',
            'interactive' => 'Interactive',
            'manual' => 'Manual',
            default => ucfirst(str_replace('_', ' ', $this->actor)),
        };
    }

    public function modeBadgeClass(): string
    {
        return match ($this->actor) {
            'watch_mode' => 'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 border-indigo-500/30',
            'simulation' => 'bg-cyan-500/15 text-cyan-600 dark:text-cyan-400 border-cyan-500/30',
            'autonomous' => 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30',
            'interactive' => 'bg-blue-500/15 text-blue-600 dark:text-blue-400 border-blue-500/30',
            default => 'bg-neutral-500/15 text-neutral-600 dark:text-neutral-400 border-neutral-500/30',
        };
    }

    public function summary_safe(): string
    {
        return $this->diagnosis_summary ?: $this->trigger_reason ?: 'Remedy execution';
    }
}
