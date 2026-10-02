<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type 'team' | 'client'
 * @property string $name
 * @property ?string $company
 * @property ?string $phone E.164 (e.g. "+15555550100")
 * @property ?string $email
 * @property ?string $email_fallback
 * @property bool $notify_sms
 * @property bool $notify_email
 * @property bool $enabled
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Collection<int, NotificationOffWindow> $offWindows
 * @property-read Collection<int, Site> $sites
 */
class NotificationRecipient extends Model
{
    use HasFactory;

    public const TYPE_TEAM = 'team';

    public const TYPE_CLIENT = 'client';

    protected $fillable = [
        'type',
        'name',
        'company',
        'phone',
        'email',
        'email_fallback',
        'notify_sms',
        'notify_email',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'notify_sms' => 'boolean',
            'notify_email' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    public function offWindows(): HasMany
    {
        return $this->hasMany(NotificationOffWindow::class, 'recipient_id');
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_notification_recipient')
            ->withTimestamps();
    }

    public function isTeam(): bool
    {
        return ($this->type ?? self::TYPE_TEAM) === self::TYPE_TEAM;
    }

    public function isClient(): bool
    {
        return ($this->type ?? self::TYPE_TEAM) === self::TYPE_CLIENT;
    }

    public function resolvedEmail(): ?string
    {
        return $this->email ?: $this->email_fallback;
    }

    /**
     * @param  Builder<NotificationRecipient>  $query
     * @return Builder<NotificationRecipient>
     */
    public function scopeTeam(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_TEAM);
    }

    /**
     * @param  Builder<NotificationRecipient>  $query
     * @return Builder<NotificationRecipient>
     */
    public function scopeClient(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_CLIENT);
    }
}
