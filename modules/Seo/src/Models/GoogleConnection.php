<?php

namespace Modules\Seo\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The agency's one Google connection (a shared agency account), owned by the SEO module and
 * separate from Google login. The refresh token is encrypted at rest and never
 * rendered or returned.
 *
 * @property int $id
 * @property ?string $account_email
 * @property ?array<int, string> $scopes
 * @property string $refresh_token
 * @property string $status
 * @property ?Carbon $last_refreshed_at
 * @property ?string $last_error
 * @property ?Carbon $last_error_at
 * @property ?int $connected_by_user_id
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class GoogleConnection extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_ERROR = 'error';

    protected $table = 'seo_google_connections';

    protected $fillable = [
        'account_email',
        'scopes',
        'refresh_token',
        'status',
        'last_refreshed_at',
        'last_error',
        'last_error_at',
        'connected_by_user_id',
    ];

    protected $hidden = ['refresh_token'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'refresh_token' => 'encrypted',
            'last_refreshed_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->orderBy('id')->first();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    /** @return BelongsTo<User, $this> */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    /** @return HasMany<GoogleProperty, $this> */
    public function properties(): HasMany
    {
        return $this->hasMany(GoogleProperty::class, 'connection_id');
    }
}
