<?php

namespace Modules\Mcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Mcp\Support\ClaudeConnector;
use Symfony\Component\HttpFoundation\Response;

/**
 * The first thing every /mcp request meets.
 *
 * - 404 when Claude access is switched off. The routes are only registered
 *   while it is on, but a long-running worker keeps the routes it booted
 *   with, so the switch is checked again on every call.
 * - 403 for a browser origin that isn't on the list. A request with no
 *   Origin header (a server calling a server, which is how Claude calls)
 *   passes. This is stricter than laravel/mcp's own check, which also lets
 *   localhost and the app's own URL through.
 * - Marks the request as wanting JSON, so a missing token gets a 401 with
 *   the sign-in pointer Claude needs, never a redirect to the login page.
 */
class McpGate
{
    public function __construct(private readonly ClaudeConnector $connector) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->connector->enabled()) {
            abort(404);
        }

        $request->headers->set('Accept', 'application/json');

        $origin = $request->headers->get('Origin');
        if ($origin !== null && $origin !== '' && ! in_array(rtrim($origin, '/'), $this->allowedOrigins(), true)) {
            return response()->json(['message' => 'This origin may not call Clockwork Control.'], 403);
        }

        return $next($request);
    }

    /** @return list<string> */
    private function allowedOrigins(): array
    {
        return array_map(
            fn ($origin) => rtrim((string) $origin, '/'),
            (array) config('clockwork.mcp.allowed_origins', []),
        );
    }
}
