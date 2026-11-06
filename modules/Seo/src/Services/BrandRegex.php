<?php

namespace Modules\Seo\Services;

use InvalidArgumentException;

/**
 * Turns a site's brand terms ("Acme, A+ Plumbing") into the RE2 regex Search
 * Console takes as excludingRegex for the non-branded totals.
 *
 * Every term is escaped, so the regex only ever matches the literal terms,
 * case-insensitively, anywhere in the query. That is exactly what a
 * case-insensitive LIKE '%term%' matches, which is how stored month rows are
 * re-marked when the terms change (no REGEXP, so it works on MySQL and on the
 * SQLite test database alike).
 */
class BrandRegex
{
    public const MAX_LENGTH = 4096;

    /** @return list<string> */
    public static function parseTerms(?string $terms): array
    {
        $parts = array_map(fn (string $t) => trim(preg_replace('/\s+/u', ' ', $t) ?? ''), explode(',', (string) $terms));
        $unique = [];

        foreach ($parts as $part) {
            if ($part !== '' && ! in_array(mb_strtolower($part), array_map('mb_strtolower', $unique), true)) {
                $unique[] = $part;
            }
        }

        return $unique;
    }

    /**
     * @throws InvalidArgumentException when the regex would pass Google's 4,096 character limit
     */
    public static function compile(?string $terms): ?string
    {
        $list = self::parseTerms($terms);
        if ($list === []) {
            return null;
        }

        $regex = '(?i)('.implode('|', array_map(fn (string $t) => preg_quote($t), $list)).')';

        if (strlen($regex) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Brand terms are too long: Search Console takes at most '.self::MAX_LENGTH.' characters of regex.');
        }

        return $regex;
    }

    /** Same answer the compiled regex gives, without compiling it. */
    public static function matches(string $query, ?string $terms): bool
    {
        $haystack = mb_strtolower($query);

        foreach (self::parseTerms($terms) as $term) {
            if (str_contains($haystack, mb_strtolower($term))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which of these queries the terms would exclude, for the profile preview.
     *
     * @param  iterable<string>  $queries
     * @return list<string>
     */
    public static function excluded(iterable $queries, ?string $terms): array
    {
        $out = [];
        foreach ($queries as $query) {
            if (self::matches($query, $terms)) {
                $out[] = $query;
            }
        }

        return $out;
    }
}
