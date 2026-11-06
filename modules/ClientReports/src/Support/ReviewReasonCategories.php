<?php

namespace Modules\ClientReports\Support;

/**
 * Sorts a report's stored review reasons (meta.review_reasons, plain
 * sentences written by ReportReviewFlags) into a few categories the
 * operator screens can filter on, show as short chips and link to the
 * right tab of the site dashboard.
 *
 * It reads the wording, not a code, because the reasons are stored as
 * text: the rules look for a few stable keywords, most specific first, so a
 * reworded sentence still lands in the same place. Anything unrecognised is
 * "Other", never dropped.
 */
final class ReviewReasonCategories
{
    public const FIRST_REPORT = 'first_report';

    public const MALWARE = 'malware';

    public const VULNERABILITIES = 'vulnerabilities';

    public const UPDATES = 'updates';

    public const BACKUPS = 'backups';

    public const UPTIME = 'uptime';

    public const TRAFFIC = 'traffic';

    public const FORMS = 'forms';

    public const PARTIAL_MONTH = 'partial_month';

    public const OTHER = 'other';

    /** @var array<string, array{label: string, short: string, icon: string, tab: ?string}> */
    private const META = [
        self::FIRST_REPORT => ['label' => 'First report', 'short' => 'First report', 'icon' => 'fa-flag', 'tab' => null],
        self::MALWARE => ['label' => 'Malware & core files', 'short' => 'Malware', 'icon' => 'fa-bug', 'tab' => 'security'],
        self::VULNERABILITIES => ['label' => 'Vulnerable plugins', 'short' => 'Vulnerability', 'icon' => 'fa-shield-halved', 'tab' => 'updates'],
        self::UPDATES => ['label' => 'Updates', 'short' => 'Updates', 'icon' => 'fa-arrows-rotate', 'tab' => 'updates'],
        self::BACKUPS => ['label' => 'Backups', 'short' => 'Backups', 'icon' => 'fa-database', 'tab' => 'overview'],
        self::UPTIME => ['label' => 'Uptime', 'short' => 'Uptime', 'icon' => 'fa-heart-pulse', 'tab' => 'overview'],
        self::TRAFFIC => ['label' => 'Traffic', 'short' => 'Traffic', 'icon' => 'fa-chart-line', 'tab' => 'traffic'],
        self::FORMS => ['label' => 'Contact forms', 'short' => 'Forms', 'icon' => 'fa-envelope-open-text', 'tab' => 'forms'],
        self::PARTIAL_MONTH => ['label' => 'Partial month', 'short' => 'Partial month', 'icon' => 'fa-calendar-minus', 'tab' => 'overview'],
        self::OTHER => ['label' => 'Other', 'short' => 'Other', 'icon' => 'fa-circle-info', 'tab' => 'overview'],
    ];

    /**
     * Keyword rules, most specific first: the first match wins. A reason
     * about the Companion plugin's stale snapshot mentions vulnerabilities
     * in passing, so it is matched before them; "Uptime monitoring began"
     * is an uptime gap, so uptime comes before the partial-month rule.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const RULES = [
        ['/first report from this schedule/i', self::FIRST_REPORT],
        ['/companion .*(reported|snapshot)/i', self::UPDATES],
        ['/vulnerab/i', self::VULNERABILITIES],
        ['/malware|blocklist|core[- ]file/i', self::MALWARE],
        ['/backup|backed up/i', self::BACKUPS],
        ['/uptime|still down|monitor was blocked|http 429/i', self::UPTIME],
        ['/traffic|requests came from/i', self::TRAFFIC],
        ['/contact[- ]form|\bforms?\b/i', self::FORMS],
        ['/monitoring began|part of the month|covers none/i', self::PARTIAL_MONTH],
        ['/update/i', self::UPDATES],
    ];

    public static function categorize(string $reason): string
    {
        foreach (self::RULES as [$pattern, $category]) {
            if (preg_match($pattern, $reason) === 1) {
                return $category;
            }
        }

        return self::OTHER;
    }

    /**
     * The categories a report's reasons fall into, in the order they first appear.
     *
     * @param  iterable<mixed>  $reasons
     * @return list<string>
     */
    public static function forReasons(iterable $reasons): array
    {
        return array_keys(self::group($reasons));
    }

    /**
     * The reasons grouped by category, each group in its stored order.
     *
     * @param  iterable<mixed>  $reasons
     * @return array<string, list<string>>
     */
    public static function group(iterable $reasons): array
    {
        $groups = [];
        foreach ($reasons as $reason) {
            if (! is_string($reason) || trim($reason) === '') {
                continue;
            }
            $groups[self::categorize($reason)][] = $reason;
        }

        return $groups;
    }

    /**
     * Whether the only thing holding a report is that it is its schedule's
     * first: nothing in it needs fixing, it just waits for a person to send it.
     *
     * @param  iterable<mixed>  $reasons
     */
    public static function onlyFirstReport(iterable $reasons): bool
    {
        $categories = self::forReasons($reasons);

        return $categories === [self::FIRST_REPORT];
    }

    /**
     * The categories an operator can filter the reports list by.
     *
     * @return array<string, string>
     */
    public static function filterOptions(): array
    {
        $options = [];
        foreach (self::META as $key => $meta) {
            if ($key !== self::FIRST_REPORT) {
                $options[$key] = $meta['label'];
            }
        }

        return $options;
    }

    public static function isKnown(?string $category): bool
    {
        return $category !== null && isset(self::META[$category]);
    }

    public static function label(string $category): string
    {
        return self::META[$category]['label'] ?? self::META[self::OTHER]['label'];
    }

    public static function short(string $category): string
    {
        return self::META[$category]['short'] ?? self::META[self::OTHER]['short'];
    }

    public static function icon(string $category): string
    {
        return self::META[$category]['icon'] ?? self::META[self::OTHER]['icon'];
    }

    /** The site dashboard tab where the problem can be looked at, if there is one. */
    public static function siteTab(string $category): ?string
    {
        return self::META[$category]['tab'] ?? null;
    }
}
