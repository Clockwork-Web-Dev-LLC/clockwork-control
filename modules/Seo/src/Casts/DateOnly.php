<?php

namespace Modules\Seo\Casts;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Stores a plain Y-m-d and reads it back as a Carbon at midnight.
 *
 * Laravel's 'date' cast writes 'Y-m-d H:i:s'. MySQL's DATE column drops the
 * time, but SQLite keeps the string, so where('month', '2026-10-01') and the
 * rows the sync writes with the query builder would stop matching. Every SEO
 * date column goes through this cast so both databases hold the same value.
 *
 * @implements CastsAttributes<Carbon, string>
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null || $value === '' ? null : Carbon::parse(substr((string) $value, 0, 10))->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof CarbonInterface ? $value->toDateString() : Carbon::parse((string) $value)->toDateString();
    }
}
