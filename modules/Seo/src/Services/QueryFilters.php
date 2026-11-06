<?php

namespace Modules\Seo\Services;

/**
 * Queries that aren't real searches for a page: a whole query in quotes, or
 * one using a search operator (site:, inurl: ...). Usually the team or a
 * tool checking indexing. seo_search_rows leaves them out by default, and
 * Phase 2's keyword candidates reuse this.
 */
class QueryFilters
{
    private const OPERATOR_PATTERN = '/(^|\s)-?(site|inurl|allinurl|intitle|allintitle|intext|allintext|filetype|ext|related|cache|link|info|define|source|before|after):\S/i';

    public static function isJunk(string $query): bool
    {
        $q = trim($query);

        if ($q === '') {
            return true;
        }

        if (preg_match(self::OPERATOR_PATTERN, $q) === 1) {
            return true;
        }

        // The whole query wrapped in quotes, e.g. "acme roofing". A quote
        // inside an ordinary query (a brand name, an inch mark) is fine.
        return mb_strlen($q) >= 2 && preg_match('/^["“”].*["“”]$/u', $q) === 1;
    }
}
