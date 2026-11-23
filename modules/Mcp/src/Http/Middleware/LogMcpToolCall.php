<?php

namespace Modules\Mcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * One app-log line per tool call: who, which tool, which site, how long,
 * and whether it worked. Arguments and answers are not logged. Writes are
 * recorded separately, with before and after values, by the tools.
 */
class LogMcpToolCall
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $response = $next($request);

        try {
            if ($request->input('method') === 'tools/call') {
                $site = $request->input('params.arguments.site');

                Log::info('mcp.tool_call', [
                    'user_id' => $request->user()?->getAuthIdentifier(),
                    'tool' => mb_substr((string) $request->input('params.name'), 0, 60),
                    'site' => is_scalar($site) ? mb_substr((string) $site, 0, 120) : null,
                    'ms' => (int) round((microtime(true) - $start) * 1000),
                    'ok' => $response->getStatusCode() === 200
                        && ! str_contains((string) $response->getContent(), '"isError":true'),
                ]);
            }
        } catch (Throwable) {
            // Logging must never break a tool call.
        }

        return $response;
    }
}
