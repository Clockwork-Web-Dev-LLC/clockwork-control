<?php

namespace Modules\Seo\Audit;

use Modules\Seo\Models\SiteAudit;
use Modules\Seo\Models\SiteAuditPage;

/**
 * The issues in a crawl, and what changed since the crawl before it.
 */
class SiteAuditIssues
{
    private const ORDER = ['error' => 0, 'warning' => 1, 'notice' => 2];

    /**
     * Issues from raw answers. Used when a crawl is stored.
     *
     * @param  list<array<string, bool>>  $pageChecks  one map per page
     * @param  array<string, bool>  $siteChecks
     * @param  array<string, int>  $totals
     * @return list<array{key: string, scope: string, severity: string, category: string, title: string, fix: string, pages: int}>
     */
    public function fromRows(array $pageChecks, array $siteChecks, array $totals): array
    {
        $counts = [];
        foreach ($pageChecks as $checks) {
            foreach (IssueRules::PAGE as $key => [$problemWhen]) {
                if (array_key_exists($key, $checks) && $checks[$key] === $problemWhen) {
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }
        }

        $issues = [];
        foreach ($counts as $key => $count) {
            [, $severity, $category, $title, $fix] = IssueRules::PAGE[$key];
            $issues[] = ['key' => $key, 'scope' => 'page', 'severity' => $severity, 'category' => $category, 'title' => $title, 'fix' => $fix, 'pages' => $count];
        }
        foreach (IssueRules::SITE as $key => [$problemWhen, $severity, $category, $title, $fix]) {
            // Only a check the crawl actually answered can be an issue.
            if (array_key_exists($key, $siteChecks) && $siteChecks[$key] === $problemWhen) {
                $issues[] = ['key' => "site:{$key}", 'scope' => 'site', 'severity' => $severity, 'category' => $category, 'title' => $title, 'fix' => $fix, 'pages' => 0];
            }
        }
        foreach (IssueRules::TOTALS as $key => [$severity, $category, $title, $fix]) {
            if (($totals[$key] ?? 0) > 0) {
                $issues[] = ['key' => "total:{$key}", 'scope' => 'site', 'severity' => $severity, 'category' => $category, 'title' => $title, 'fix' => $fix, 'pages' => (int) $totals[$key]];
            }
        }

        usort($issues, fn (array $a, array $b) => [self::ORDER[$a['severity']], -$a['pages'], $a['title']] <=> [self::ORDER[$b['severity']], -$b['pages'], $b['title']]);

        return $issues;
    }

    /**
     * A finished crawl's issues, from the counts stored with it.
     *
     * @return list<array{key: string, scope: string, severity: string, category: string, title: string, fix: string, pages: int}>
     */
    public function forAudit(SiteAudit $audit): array
    {
        $issues = [];
        foreach ((array) ($audit->issue_counts ?? []) as $key => $pages) {
            $rule = $this->rule((string) $key);
            if ($rule !== null) {
                $issues[] = ['key' => (string) $key] + $rule + ['pages' => (int) $pages];
            }
        }

        usort($issues, fn (array $a, array $b) => [self::ORDER[$a['severity']], -$a['pages'], $a['title']] <=> [self::ORDER[$b['severity']], -$b['pages'], $b['title']]);

        return $issues;
    }

    /**
     * The pages affected by one page-level issue.
     *
     * @return list<array{url: string, status_code: ?int, title: ?string}>
     */
    public function pages(SiteAudit $audit, string $key, int $limit = 200): array
    {
        $rule = IssueRules::PAGE[$key] ?? null;
        if ($rule === null) {
            return [];
        }

        $pages = [];
        foreach (SiteAuditPage::query()->where('audit_id', $audit->id)->orderBy('url')->cursor() as $page) {
            if (array_key_exists($key, (array) $page->checks) && $page->checks[$key] === $rule[0]) {
                $pages[] = ['url' => $page->url, 'status_code' => $page->status_code, 'title' => $page->title];
                if (count($pages) >= $limit) {
                    break;
                }
            }
        }

        return $pages;
    }

    /** The finished crawl of the same site just before this one, if any. */
    public function previous(SiteAudit $audit): ?SiteAudit
    {
        return SiteAudit::query()
            ->where('site_id', $audit->site_id)
            ->where('status', SiteAudit::STATUS_FINISHED)
            ->where('id', '<', $audit->id)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * What changed between two crawls of the same site, issue by issue.
     *
     * "Fixed" means a page had the issue before and does not now. A page
     * the newer crawl did not reach is "not crawled this time", never
     * fixed: a smaller crawl must not look like progress.
     *
     * @return array{comparable: bool, note: ?string, issues: list<array<string, mixed>>, totals: array{new: int, fixed: int, still_open: int}}
     */
    public function compare(SiteAudit $audit, SiteAudit $previous): array
    {
        $now = $this->flagged($audit);
        $before = $this->flagged($previous);
        $crawledNow = $now['urls'];

        $keys = array_unique([...array_keys($now['by_issue']), ...array_keys($before['by_issue'])]);
        $issues = [];
        $totals = ['new' => 0, 'fixed' => 0, 'still_open' => 0];

        foreach ($keys as $key) {
            $rule = $this->rule($key);
            if ($rule === null) {
                continue;
            }
            $a = $now['by_issue'][$key] ?? [];
            $b = $before['by_issue'][$key] ?? [];

            $new = array_keys(array_diff_key($a, $b));
            $still = array_keys(array_intersect_key($a, $b));
            $gone = array_diff_key($b, $a);
            $fixed = array_keys(array_intersect_key($gone, $crawledNow));
            $notCrawled = array_keys(array_diff_key($gone, $crawledNow));

            $issues[] = ['key' => $key] + $rule + [
                'pages' => count($a),
                'pages_before' => count($b),
                'new' => count($new),
                'fixed' => count($fixed),
                'still_open' => count($still),
                'not_crawled_this_time' => count($notCrawled),
                'new_pages' => array_slice($new, 0, 20),
                'fixed_pages' => array_slice($fixed, 0, 20),
            ];
            $totals['new'] += count($new);
            $totals['fixed'] += count($fixed);
            $totals['still_open'] += count($still);
        }

        usort($issues, fn (array $x, array $y) => [self::ORDER[$x['severity']], -$x['new'], -$x['pages'], $x['title']] <=> [self::ORDER[$y['severity']], -$y['new'], -$y['pages'], $y['title']]);

        // Different page caps or JavaScript settings change what a crawl sees.
        $comparable = $audit->max_pages === $previous->max_pages && $audit->javascript === $previous->javascript;

        return [
            'comparable' => $comparable,
            'note' => $comparable ? null : 'The two crawls used different settings (page cap or JavaScript), so some of the change comes from what was crawled, not from the site.',
            'issues' => $issues,
            'totals' => $totals,
        ];
    }

    /**
     * @return array{scope: string, severity: string, category: string, title: string, fix: string}|null
     */
    private function rule(string $key): ?array
    {
        if (str_starts_with($key, 'site:')) {
            $rule = IssueRules::SITE[substr($key, 5)] ?? null;

            return $rule === null ? null : ['scope' => 'site', 'severity' => $rule[1], 'category' => $rule[2], 'title' => $rule[3], 'fix' => $rule[4]];
        }
        if (str_starts_with($key, 'total:')) {
            $rule = IssueRules::TOTALS[substr($key, 6)] ?? null;

            return $rule === null ? null : ['scope' => 'site', 'severity' => $rule[0], 'category' => $rule[1], 'title' => $rule[2], 'fix' => $rule[3]];
        }
        $rule = IssueRules::PAGE[$key] ?? null;

        return $rule === null ? null : ['scope' => 'page', 'severity' => $rule[1], 'category' => $rule[2], 'title' => $rule[3], 'fix' => $rule[4]];
    }

    /**
     * Every crawled URL, and for each page-level issue the URLs that have
     * it. Site-wide issues are keyed to the site itself so they compare too.
     *
     * @return array{urls: array<string, true>, by_issue: array<string, array<string, true>>}
     */
    private function flagged(SiteAudit $audit): array
    {
        $urls = [];
        $byIssue = [];

        foreach (SiteAuditPage::query()->where('audit_id', $audit->id)->cursor() as $page) {
            $urls[$page->url] = true;
            $checks = (array) $page->checks;
            foreach (IssueRules::PAGE as $key => [$problemWhen]) {
                if (array_key_exists($key, $checks) && $checks[$key] === $problemWhen) {
                    $byIssue[$key][$page->url] = true;
                }
            }
        }

        $site = '(whole site)';
        $urls[$site] = true;
        foreach ((array) ($audit->issue_counts ?? []) as $key => $count) {
            if (str_starts_with((string) $key, 'site:') || str_starts_with((string) $key, 'total:')) {
                $byIssue[(string) $key][$site] = true;
            }
        }

        return ['urls' => $urls, 'by_issue' => $byIssue];
    }
}
