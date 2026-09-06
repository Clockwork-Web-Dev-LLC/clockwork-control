<?php

namespace Modules\BillCom;

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
 * Strategy: extract the first plausible domain via regex; lowercase + strip
 * `www.` so we match a `Site::domain` row.
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
     * TLDs we recognise. Intentionally focused on standard fleet TLDs (.com / .org /
     * .net / .io / .co / .us / .gov / .edu). Adding TLDs is one line; better to be
     * precise than catch random words ending in `.tv`.
     *
     * @var array<int, string>
     */
    private const TLDS = ['com', 'org', 'net', 'io', 'co', 'us', 'gov', 'edu'];

    public static function extract(string $description): ?string
    {
        $all = self::extractAll($description);

        return $all === [] ? null : $all[0];
    }

    /**
     * Extract every distinct domain in the description, in order. Use when a
     * line item description lists multiple sites — some invoices sometimes
     * have a "HOSTING WEBSITES:" line that names two or three domains.
     *
     * @return array<int, string> deduped, lowercase, www-stripped
     */
    public static function extractAll(string $description): array
    {
        $description = trim($description);
        if ($description === '') {
            return [];
        }

        $tldGroup = implode('|', self::TLDS);
        $pattern = '/(?:https?:\/\/)?(?:www\.)?([a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\.(?:'.$tldGroup.'))\b/i';

        if (preg_match_all($pattern, $description, $matches) === false) {
            return [];
        }

        $domains = [];
        foreach ($matches[1] as $match) {
            $domain = strtolower((string) $match);
            if (! in_array($domain, $domains, true)) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }
}
