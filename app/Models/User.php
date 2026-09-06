<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property ?Carbon $revoked_at
 * @property ?Carbon $last_login_at
 * @property ?string $google_id
 * @property ?string $github_id
 * @property ?string $microsoft_id
 * @property ?string $avatar_url
 * @property string $theme
 */
#[Fillable(['name', 'email', 'password', 'revoked_at', 'last_login_at', 'google_id', 'github_id', 'microsoft_id', 'avatar_url', 'theme'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Active = on the allowlist (row exists) AND not revoked.
     * Existence of a `users` row IS membership in the allowlist; `revoked_at`
     * is the soft-revoke flag.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Get the password for the user.
     * Guaranteed to return a string so SessionGuard::userFromRecaller never passes null to hash_equals().
     */
    public function getAuthPassword(): string
    {
        return (string) ($this->password ?? '');
    }
}
