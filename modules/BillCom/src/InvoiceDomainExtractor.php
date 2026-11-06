<?php

namespace Modules\BillCom;

use App\Support\DomainNormalizer;

/**
 * Pure-function helper. Given a Bill.com line item description, returns the
 * site domain embedded in it (or null if none found).
 *
 * Invoicing convention: every line item description contains the site
 * domain it relates to, in one of these shapes (observed):
 *   "Website hosting for example.com"
 *   "http://www.example.com - - Plugin updates, backups, security scans, etc."
 *   "Monthly maintenance — example.com"
 *
 * Strategy: extract every plausible domain via regex, then run each through
 * DomainNormalizer (lowercase, no scheme, no leading `www.`) so it compares
 * equal to a normalized `Site::domain` — see SiteDomainIndex.
 *
 * Out of scope: validating the domain is reachable, distinguishing the
 * customer's domain from incidental ones in the description (e.g. if a
 * description says "migrating from godaddy.com to example.com" we'd grab
 * godaddy first). For now: take the first match. Adjust if false positives
 * appear.
 */
class InvoiceDomainExtractor
{
    /**
     * TLDs we always recognise. Intentionally focused on standard fleet TLDs
     * (.com / .org / .net / .io / .co / .us / .gov / .edu) — better to be
     * precise than catch random words that happen to end in a short suffix.
     *
     * Fleet sites on any other TLD (.tv, .law, .media, ...) are covered by the
     * `$extraTlds` argument: the sync services pass the TLDs of every
     * non-archived Site (SiteDomainIndex::tlds()), so a domain on a TLD the
     * fleet actually uses is recognised without accepting every dotted word.
     *
     * @var array<int, string>
     */
    public const TLDS = ['com', 'org', 'net', 'io', 'co', 'us', 'gov', 'edu'];

    /**
     * @param  iterable<string>  $extraTlds  TLDs to accept on top of TLDS
     */
    public static function extract(string $description, iterable $extraTlds = []): ?string
    {
        $all = self::extractAll($description, $extraTlds);

        return $all === [] ? null : $all[0];
    }

    /**
     * Extract every distinct domain in the description, in order. Use when a
     * line item description lists multiple sites — some invoices sometimes
     * have a "HOSTING WEBSITES:" line that names two or three domains.
     *
     * @param  iterable<string>  $extraTlds  TLDs to accept on top of TLDS
     * @return array<int, string> deduped, normalized (lowercase, www-stripped)
     */
    public static function extractAll(string $description, iterable $extraTlds = []): array
    {
        $description = trim($description);
        if ($description === '') {
            return [];
        }

        $tldGroup = implode('|', array_map(
            fn (string $tld) => preg_quote($tld, '/'),
            self::allowedTlds($extraTlds),
        ));
        $pattern = '/(?:https?:\/\/)?(?:www\.)?([a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\.(?:'.$tldGroup.'))\b/i';

        if (preg_match_all($pattern, $description, $matches) === false) {
            return [];
        }

        $domains = [];
        foreach ($matches[1] as $match) {
            $domain = DomainNormalizer::normalize((string) $match);
            if ($domain !== '' && ! in_array($domain, $domains, true)) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    /**
     * TLDS plus every well-formed extra TLD (alphabetic, 2-24 letters),
     * lowercased and deduped. Anything else in `$extraTlds` is ignored, so a
     * stray value can never inject regex syntax or widen the match.
     *
     * @param  iterable<string>  $extraTlds
     * @return array<int, string>
     */
    private static function allowedTlds(iterable $extraTlds): array
    {
        $tlds = array_fill_keys(self::TLDS, true);
        foreach ($extraTlds as $tld) {
            $tld = strtolower(trim((string) $tld, " \t\n\r\0\x0B."));
            if (preg_match('/^[a-z]{2,24}$/', $tld) === 1) {
                $tlds[$tld] = true;
            }
        }

        return array_keys($tlds);
    }
}
