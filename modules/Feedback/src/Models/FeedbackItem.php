<?php

namespace Modules\Feedback\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Feedback\Services\FeedbackPromptBuilder;

/**
 * @property int $id
 * @property int $user_id
 * @property string $url
 * @property string $path
 * @property ?string $route_name
 * @property ?string $controller_action
 * @property ?string $view_name
 * @property ?string $selector
 * @property ?string $element_tag
 * @property ?string $element_text
 * @property float $x_pos
 * @property float $y_pos
 * @property ?int $viewport_width
 * @property ?int $viewport_height
 * @property string $type
 * @property string $status
 * @property string $title
 * @property string $content
 * @property ?array<string, mixed> $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ?User $user
 * @property-read Collection<int, FeedbackComment> $comments
 *
 * @method static Builder<FeedbackItem> forPath(string $path)
 * @method static Builder<FeedbackItem> active()
 * @method static Builder<FeedbackItem> approved()
 */
class FeedbackItem extends Model
{
    public const TYPE_BUG = 'bug';

    public const TYPE_TWEAK = 'tweak';

    public const TYPE_FEATURE = 'feature';

    public const TYPE_COPY = 'copy';

    public const STATUS_OPEN = 'open';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_DISMISSED = 'dismissed';

    protected $table = 'feedback_items';

    protected $fillable = [
        'user_id',
        'url',
        'path',
        'route_name',
        'controller_action',
        'view_name',
        'selector',
        'element_tag',
        'element_text',
        'x_pos',
        'y_pos',
        'viewport_width',
        'viewport_height',
        'type',
        'status',
        'title',
        'content',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'x_pos' => 'float',
            'y_pos' => 'float',
            'viewport_width' => 'integer',
            'viewport_height' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<FeedbackComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(FeedbackComment::class, 'feedback_item_id')->orderBy('created_at', 'asc');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForPath(Builder $query, string $path): Builder
    {
        return $query->where('path', $path);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_APPROVED, self::STATUS_IN_PROGRESS]);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function formattedType(): string
    {
        return match ($this->type) {
            self::TYPE_BUG => 'Bug Report',
            self::TYPE_FEATURE => 'Feature Idea',
            self::TYPE_COPY => 'Copy / Content',
            default => 'UI Tweak',
        };
    }

    public function typeBadgeClass(): string
    {
        return match ($this->type) {
            self::TYPE_BUG => 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border border-rose-500/30',
            self::TYPE_FEATURE => 'bg-purple-500/15 text-purple-600 dark:text-purple-400 border border-purple-500/30',
            self::TYPE_COPY => 'bg-sky-500/15 text-sky-600 dark:text-sky-400 border border-sky-500/30',
            default => 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/30',
        };
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_RESOLVED => 'bg-[var(--color-status-green)]/15 text-[var(--color-status-green)] border border-[var(--color-status-green)]/30',
            self::STATUS_APPROVED => 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30',
            self::STATUS_IN_PROGRESS => 'bg-blue-500/15 text-blue-600 dark:text-blue-400 border border-blue-500/30',
            self::STATUS_DISMISSED => 'bg-[var(--color-ink-soft)]/15 text-[var(--color-ink-muted)] border border-[var(--color-border-light)]',
            default => 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border border-amber-500/30',
        };
    }

    /**
     * Generate a structured, ready-to-paste prompt for Claude / Antigravity
     * containing the report, discussion transcript, and all code context.
     */
    public function toClaudePrompt(): string
    {
        return (new FeedbackPromptBuilder)->buildForSingle($this);
    }
}
