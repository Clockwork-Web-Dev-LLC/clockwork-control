<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects authenticated users whose allowlist row has been revoked.
 *
 * Login-time checks are not enough: a revoked operator would otherwise keep
 * their existing database session (and remember-me cookie until the token
 * is cycled) until expiry. Applied to the authenticated route group.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = 'Your account has been revoked. Please contact an administrator.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 401);
            }

            return redirect()->route('login')->with('login_denial', $message);
        }

        return $next($request);
    }
}
