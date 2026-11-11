<?php

namespace Modules\Mcp\Support;

use App\Models\User;
use Laravel\Mcp\Request;

/**
 * Who is calling a tool. Every tool goes through here, so a revoked user is
 * refused even if a transport let them in.
 */
class McpUser
{
    public static function from(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User && $user->isActive() ? $user : null;
    }

    public static function isAdmin(Request $request): bool
    {
        return (bool) self::from($request)?->isAdmin();
    }
}
