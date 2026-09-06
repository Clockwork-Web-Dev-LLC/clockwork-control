<?php

namespace App\Services\Auth;

use App\Models\ActionLog;
use App\Models\User;
use App\Services\ActionLog\ActionLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Handles unified OAuth login verification, team allowlist enforcement,
 * user identity sync, and audit logging across all auth providers.
 */
class OAuthLoginHandler
{
    public function __construct(
        protected ActionLogger $logger,
    ) {}

    /**
     * @param  string  $providerName  Display name (e.g. 'Google', 'GitHub', 'Microsoft')
     * @param  ?string  $email  Verified email address from OAuth provider
     * @param  ?string  $providerId  Unique user identifier on provider
     * @param  ?string  $providerIdColumn  Database column on users table ('google_id', 'github_id', 'microsoft_id')
     * @param  ?string  $name  User display name from provider
     * @param  ?string  $avatarUrl  Avatar URL from provider
     */
    public function handle(
        Request $request,
        string $providerName,
        ?string $email,
        ?string $providerId = null,
        ?string $providerIdColumn = null,
        ?string $name = null,
        ?string $avatarUrl = null,
    ): RedirectResponse {
        $normalizedEmail = strtolower((string) $email);

        if ($normalizedEmail === '') {
            return redirect()->route('login')->with(
                'login_denial',
                "{$providerName} didn't return an email address. Make sure you're signed into a {$providerName} account."
            );
        }

        $user = User::where('email', $normalizedEmail)->first();

        if (! $user) {
            return redirect()->route('login')->with(
                'login_denial',
                "{$normalizedEmail} isn't on the Clockwork team. Ask an administrator to add you."
            );
        }

        if (! $user->isActive()) {
            return redirect()->route('login')->with(
                'login_denial',
                "Access for {$normalizedEmail} has been revoked."
            );
        }

        $fillData = [
            'name' => (string) ($name ?: $user->name ?: $normalizedEmail),
            // Unconditional, not `if ($avatarUrl)` — a user who removes their
            // provider avatar (or a provider that omits it on a given login)
            // should clear the stored URL, not keep showing a stale photo.
            'avatar_url' => $avatarUrl,
            'last_login_at' => now(),
        ];

        if ($providerIdColumn && $providerId) {
            $fillData[$providerIdColumn] = $providerId;
        }

        $user->forceFill($fillData)->save();

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        $this->logger->record(
            actionType: ActionLog::TYPE_LOGIN,
            summary: "{$user->email} signed in via {$providerName}.",
            ok: true,
            actor: $user->email,
        );

        return redirect()->intended(route('dashboard'));
    }
}
