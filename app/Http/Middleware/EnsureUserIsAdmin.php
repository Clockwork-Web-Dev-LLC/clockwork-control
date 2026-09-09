<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts user-management, DB backup download, panel self-update apply,
 * and Code Snippets writes/execute to admin-role operators.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isAdmin()) {
            abort(403, 'This action is limited to administrators.');
        }

        return $next($request);
    }
}
