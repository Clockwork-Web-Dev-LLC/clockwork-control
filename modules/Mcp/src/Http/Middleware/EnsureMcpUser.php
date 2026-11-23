<?php

namespace Modules\Mcp\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A token is only as good as the person behind it: no user, or a user whose
 * access was revoked, gets a JSON 401 on every call, not just at sign-in.
 *
 * The "active" middleware can't do this job here: it logs the session out,
 * and this route has no session.
 */
class EnsureMcpUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isActive()) {
            return response()->json(['message' => 'This Control account can no longer use Claude access.'], 401);
        }

        return $next($request);
    }
}
