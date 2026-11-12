<?php

namespace Modules\Seo\Mcp\Tools;

use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;
use Modules\Mcp\Support\McpUser;
use Modules\Seo\Support\GscDates;

/**
 * What every SEO tool shares: the caller check and month parsing.
 * shouldRegister() hides the tools from a connection with no active user;
 * handle() checks again, because hints and hiding are advice to the client.
 */
abstract class SeoTool extends Tool
{
    public const NO_USER = 'No active Control user is attached to this connection.';

    public function shouldRegister(Request $request): bool
    {
        return McpUser::from($request) !== null;
    }

    protected function caller(Request $request): ?User
    {
        return McpUser::from($request);
    }

    /** The last complete calendar month, Pacific. It can still be provisional. */
    protected function lastCompleteMonth(): CarbonImmutable
    {
        return GscDates::today()->startOfMonth()->subMonthNoOverflow();
    }

    /** "2026-10" to the first of that month, or null when it isn't a month. */
    protected function parseMonth(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $m) !== 1) {
            return null;
        }

        return CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, GscDates::TIMEZONE);
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $default
     * @return list<string>
     */
    protected function pick(mixed $value, array $allowed, array $default): array
    {
        if (! is_array($value)) {
            return $default;
        }

        return array_values(array_intersect($allowed, array_map('strval', $value)));
    }
}
