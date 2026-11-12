<?php

namespace Modules\Seo\Support;

use Carbon\CarbonImmutable;

/** Search Console days are Pacific Time, and it keeps 16 months of data. */
final class GscDates
{
    public const TIMEZONE = 'America/Los_Angeles';

    public const RETENTION_MONTHS = 16;

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }

    public static function yesterday(): CarbonImmutable
    {
        return self::today()->subDay();
    }

    /** The oldest day Search Console still holds. */
    public static function windowStart(): CarbonImmutable
    {
        return self::today()->subMonthsNoOverflow(self::RETENTION_MONTHS);
    }
}
