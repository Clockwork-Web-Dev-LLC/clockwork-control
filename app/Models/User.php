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
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Laravel\Passport\Passport;

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
 *
 * @method static Builder<User> active()
 */
#[Fillable(['name', 'email', 'password', 'last_login_at', 'google_id', 'github_id', 'microsoft_id', 'avatar_url', 'theme', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

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

        // Before the early return below: Claude's tokens must die with the
        // sessions, whatever the session driver is.
        $this->revokeClaudeAccess();

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
     * Revoke every Claude access (OAuth) token this user holds, access and
     * refresh, so a connected Claude stops working on its next call. Always
     * all of them: there is no "current" token to keep, unlike a session.
     *
     * @return int access tokens revoked
     */
    public function revokeClaudeAccess(?string $clientId = null): int
    {
        if (! Schema::hasTable('oauth_access_tokens')) {
            return 0;
        }

        $tokenIds = Passport::token()->newQuery()
            ->where('user_id', $this->id)
            ->where('revoked', false)
            ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
            ->pluck('id');

        if ($tokenIds->isEmpty()) {
            return 0;
        }

        Passport::refreshToken()->newQuery()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);

        return Passport::token()->newQuery()->whereIn('id', $tokenIds)->update(['revoked' => true]);
    }

    /**
     * Get the password for the user.
     * Guaranteed to return a string so SessionGuard::userFromRecaller never passes null to hash_equals().
     */
    public function getAuthPassword(): string
    {
        return (string) ($this->password ?? '');
    }

    /**
     * Get the avatar URL for the user.
     * Prefers custom avatar_url (e.g. from OAuth), falls back to Gravatar.
     */
    public function avatarUrl(int $size = 80, string $default = 'mp'): string
    {
        if (! empty($this->avatar_url)) {
            return $this->avatar_url;
        }

        return $this->gravatarUrl($size, $default);
    }

    /**
     * Get the Gravatar URL for the user's email address.
     */
    public function gravatarUrl(int $size = 80, string $default = 'mp'): string
    {
        $email = strtolower(trim($this->email ?? ''));
        $hash = $email !== '' ? md5($email) : md5('unknown');

        return "https://www.gravatar.com/avatar/{$hash}?s={$size}&d={$default}";
    }

    /**
     * Get user initials (1-2 uppercase characters).
     */
    public function initials(): string
    {
        $name = trim($this->name ?? '');
        if ($name === '') {
            return 'OP';
        }

        $parts = preg_split('/\s+/', $name);
        if ($parts && count($parts) >= 2) {
            $first = substr($parts[0], 0, 1);
            $last = substr($parts[count($parts) - 1], 0, 1);
            if ($first !== '' && $last !== '') {
                return strtoupper($first.$last);
            }
        }

        return strtoupper(substr($name, 0, 2));
    }
}
