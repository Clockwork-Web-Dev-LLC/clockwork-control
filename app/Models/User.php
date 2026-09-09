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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
 * @property string $role
 */
#[Fillable(['name', 'email', 'password', 'last_login_at', 'google_id', 'github_id', 'microsoft_id', 'avatar_url', 'theme', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_OPERATOR = 'operator';

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

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Cycle remember_token and drop stored database sessions so a revoke or
     * password change takes effect immediately, not at session expiry.
     */
    public function invalidateSessions(bool $keepCurrent = false): void
    {
        $this->forceFill([
            'remember_token' => Str::random(60),
        ])->save();

        if (! Schema::hasTable('sessions')) {
            return;
        }

        $query = DB::table('sessions')->where('user_id', $this->id);

        if ($keepCurrent) {
            $currentId = session()->getId();
            if (is_string($currentId) && $currentId !== '') {
                $query->where('id', '!=', $currentId);
            }
        }

        $query->delete();
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
