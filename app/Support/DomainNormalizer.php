<?php

namespace App\Support;

/**
 * Reduces a domain-ish string to the one comparable key used when matching
 * domains that come from different sources (a Site row, an invoice line,
 * a CSV column) against each other.
 *
 *   " https://www.Example.com:443/about/?x=1 " → "example.com"
 *   "sub.example.org."                         → "sub.example.org"
 *
 * Steps: trim, lowercase, drop any scheme ("https://"), path, query,
 * fragment and port, drop a trailing dot (the DNS root), then drop a single
 * leading "www." so a site stored as "www.example.com" and an invoice that
 * says "example.com" compare equal. Other subdomains are kept as-is.
 */
final class DomainNormalizer
{
    public static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        $value = ltrim($value, '/');
        $value = (string) preg_split('#[/?\#]#', $value, 2)[0];
        $value = explode(':', $value, 2)[0];
        $value = rtrim($value, '.');

        // Only strip "www." when what's left is still a domain — "www.com"
        // stays "www.com" rather than collapsing to a bare TLD.
        if (str_starts_with($value, 'www.') && str_contains(substr($value, 4), '.')) {
            $value = substr($value, 4);
        }

        return $value;
    }

    /**
     * The top-level label of a domain ("example.co.uk" → "uk"), or null when
     * the value has no dot or the last label isn't purely alphabetic (an IP
     * address, a bare hostname).
     */
    public static function tld(string $value): ?string
    {
        $normalized = self::normalize($value);
        $dot = strrpos($normalized, '.');
        if ($dot === false) {
            return null;
        }

        $tld = substr($normalized, $dot + 1);

        return preg_match('/^[a-z]{2,24}$/', $tld) === 1 ? $tld : null;
    }
}
