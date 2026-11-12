<?php

namespace Modules\Seo\Audit;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\DataForSeo\Feature;
use Modules\Seo\DataForSeo\Pricing;
use Modules\Seo\Models\SiteAudit;
use Modules\Seo\Models\SiteAuditPage;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Ranks\SerpParser;
use Throwable;

/**
 * Site audit: start a crawl of a whole site on DataForSEO, wait for it, and
 * copy the result into Control.
 *
 * A crawl is paid for when it starts, by the page, up to the page cap. So
 * the cap is the most a crawl can cost, and reading the result afterwards
 * is free.
 */
class SiteAuditRunner
{
    public const START_PATH = '/v3/on_page/task_post';

    public const SUMMARY_PATH = '/v3/on_page/summary/{id}';

    public const PAGES_PATH = '/v3/on_page/pages';

    /** A crawl that has not finished after this long is written off. */
    public const GIVE_UP_AFTER_HOURS = 48;

    private const PAGE_BATCH = 1000;

    public function __construct(
        private readonly DataForSeoClient $client,
        private readonly SiteAuditSettings $settings,
    ) {}

    /**
     * Start a crawl. Refused while another crawl of the same site is running.
     *
     * @return array{ok: bool, audit: ?SiteAudit, error: ?string, refused: bool}
     */
    public function start(Site $site, ?User $user = null): array
    {
        if (SiteAudit::query()->where('site_id', $site->id)->where('status', SiteAudit::STATUS_CRAWLING)->exists()) {
            return ['ok' => false, 'audit' => null, 'error' => "A crawl of {$site->domain} is already running.", 'refused' => false];
        }

        $maxPages = $this->settings->maxPages();
        $javascript = $this->settings->javascript();

        $result = $this->client->post(self::START_PATH, [[
            'target' => SerpParser::host((string) $site->domain),
            'max_crawl_pages' => $maxPages,
            'enable_javascript' => $javascript,
            // Site-wide checks (sitemap, robots.txt, HTTPS redirect) even
            // when the crawl stops at the page cap.
            'force_sitewide_checks' => true,
            'enable_content_parsing' => true,
            'respect_sitemap' => true,
        ]], Feature::SITE_AUDIT, Pricing::onPage($maxPages, $javascript), $site->id, $user);

        $task = $result->okTasks()[0] ?? null;
        if (! $result->ok || $task === null || blank($task['id'] ?? null)) {
            return [
                'ok' => false, 'audit' => null, 'refused' => $result->refused,
                'error' => $result->error ?? (string) ($result->tasks[0]['status_message'] ?? 'DataForSEO did not start the crawl.'),
            ];
        }

        return ['ok' => true, 'error' => null, 'refused' => false, 'audit' => SiteAudit::create([
            'site_id' => $site->id,
            'provider' => DataForSeoClient::PROVIDER,
            'task_id' => (string) $task['id'],
            'status' => SiteAudit::STATUS_CRAWLING,
            'max_pages' => $maxPages,
            'javascript' => $javascript,
            'cost_usd' => $result->cost,
            'started_by' => $user?->id,
            'started_at' => now(),
        ])];
    }

    /**
     * Start a crawl for every enabled client whose last one is older than
     * the chosen interval.
     *
     * @return array{due: int, started: int, failed: int, error: ?string, refused: bool}
     */
    public function startDue(?int $siteId = null): array
    {
        $summary = ['due' => 0, 'started' => 0, 'failed' => 0, 'error' => null, 'refused' => false];
        $interval = $this->settings->intervalDays();
        if ($interval === null) {
            return $summary;
        }

        $cutoff = Carbon::now()->subDays($interval);
        $profiles = SiteProfile::query()->with('site')->where('is_enabled', true)
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))->get();

        foreach ($profiles as $profile) {
            if ($profile->site === null || $profile->site->is_inactive) {
                continue;
            }
            // A failed crawl counts too, so a site that keeps failing is
            // retried at the normal interval, not every hour.
            $recent = SiteAudit::query()->where('site_id', $profile->site_id)->where('started_at', '>', $cutoff)->exists();
            if ($recent) {
                continue;
            }

            $summary['due']++;
            $result = $this->start($profile->site);

            if ($result['ok']) {
                $summary['started']++;

                continue;
            }

            $summary['failed']++;
            $summary['error'] ??= $result['error'];
            if ($result['refused']) {
                $summary['refused'] = true;
                break;
            }
        }

        return $summary;
    }

    /**
     * Check every running crawl and store the finished ones.
     *
     * @return array{finished: int, crawling: int, failed: int}
     */
    public function collect(): array
    {
        $summary = ['finished' => 0, 'crawling' => 0, 'failed' => 0];

        foreach (SiteAudit::query()->where('status', SiteAudit::STATUS_CRAWLING)->orderBy('started_at')->get() as $audit) {
            $audit->attempts++;
            $result = $this->client->get(str_replace('{id}', (string) $audit->task_id, self::SUMMARY_PATH), Feature::SITE_AUDIT, $audit->site_id);
            $data = $result->firstResult()[0] ?? null;

            if (! $result->ok || ! is_array($data)) {
                // DataForSEO unreachable is not a failed crawl.
                if ($result->statusCode === 0 || $result->isPending()) {
                    $this->stillCrawling($audit, $summary, $result->error);
                } else {
                    $this->fail($audit, $result->error ?? (string) ($result->tasks[0]['status_message'] ?? 'DataForSEO returned no result for this crawl.'), $summary);
                }

                continue;
            }

            if (($data['crawl_progress'] ?? null) !== 'finished') {
                $audit->pages_crawled = isset($data['crawl_status']['pages_crawled']) ? (int) $data['crawl_status']['pages_crawled'] : $audit->pages_crawled;
                $this->stillCrawling($audit, $summary, null);

                continue;
            }

            try {
                $this->store($audit, $data);
            } catch (Throwable $e) {
                report($e);
                $this->fail($audit, 'Could not store the crawl: '.$e->getMessage(), $summary);

                continue;
            }
            $summary['finished']++;
        }

        return $summary;
    }

    /** @param  array<string, mixed>  $data  the crawl summary */
    private function store(SiteAudit $audit, array $data): void
    {
        $pages = [];
        for ($offset = 0; $offset < $audit->max_pages; $offset += self::PAGE_BATCH) {
            $result = $this->client->post(self::PAGES_PATH, [[
                'id' => $audit->task_id,
                'limit' => self::PAGE_BATCH,
                'offset' => $offset,
                'filters' => [['resource_type', '=', 'html']],
            ]], Feature::SITE_AUDIT, 0.0, $audit->site_id);

            if (! $result->ok) {
                throw new \RuntimeException($result->error ?? 'DataForSEO did not return the crawled pages.');
            }

            $items = $result->firstResult()[0]['items'] ?? [];
            $items = is_array($items) ? $items : [];
            foreach ($items as $item) {
                $row = $this->pageRow($audit->id, $item);
                if ($row !== null) {
                    $pages[$row['url_hash']] = $row;
                }
            }
            if (count($items) < self::PAGE_BATCH) {
                break;
            }
        }

        $metrics = (array) ($data['page_metrics'] ?? []);
        $checkCounts = array_filter(array_map(fn ($v) => is_numeric($v) ? (int) $v : null, (array) ($metrics['checks'] ?? [])), fn ($v) => $v !== null);
        $totals = array_filter(array_diff_key($metrics, ['checks' => true, 'onpage_score' => true]), 'is_numeric');
        $siteChecks = array_filter((array) ($data['domain_info']['checks'] ?? []), 'is_bool');

        DB::transaction(function () use ($audit, $pages, $data, $metrics, $checkCounts, $totals, $siteChecks): void {
            SiteAuditPage::query()->where('audit_id', $audit->id)->delete();
            foreach (array_chunk(array_values($pages), 200) as $chunk) {
                SiteAuditPage::query()->insert($chunk);
            }

            $audit->forceFill([
                'status' => SiteAudit::STATUS_FINISHED,
                'pages_crawled' => isset($data['crawl_status']['pages_crawled']) ? (int) $data['crawl_status']['pages_crawled'] : count($pages),
                'score' => is_numeric($metrics['onpage_score'] ?? null) ? round((float) $metrics['onpage_score'], 2) : null,
                'totals' => $totals === [] ? null : array_map('intval', $totals),
                'check_counts' => $checkCounts === [] ? null : $checkCounts,
                'site_checks' => $siteChecks === [] ? null : $siteChecks,
                'finished_at' => now(),
                'error' => null,
            ])->save();
        });
    }

    /**
     * @param  mixed  $item
     * @return array<string, mixed>|null
     */
    private function pageRow(int $auditId, $item): ?array
    {
        if (! is_array($item) || blank($item['url'] ?? null)) {
            return null;
        }

        $url = mb_substr((string) $item['url'], 0, 2048);
        $meta = (array) ($item['meta'] ?? []);
        $int = fn ($v): ?int => is_numeric($v) ? max(0, (int) $v) : null;
        $text = fn ($v, int $max): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;

        // Only the checks that failed are kept, by name.
        $failed = [];
        foreach ((array) ($item['checks'] ?? []) as $name => $value) {
            if ($value === true) {
                $failed[] = (string) $name;
            }
        }

        return [
            'audit_id' => $auditId,
            'url' => $url,
            'url_hash' => sha1($url),
            'status_code' => $int($item['status_code'] ?? null),
            'title' => $text($meta['title'] ?? null, 1000),
            'description' => $text($meta['description'] ?? null, 2000),
            'h1' => $text($meta['htags']['h1'][0] ?? null, 1000),
            'canonical' => $text($meta['canonical'] ?? null, 2048),
            'word_count' => $int($meta['content']['plain_text_word_count'] ?? null),
            'click_depth' => $int($item['click_depth'] ?? null),
            'internal_links' => $int($meta['internal_links_count'] ?? null),
            'external_links' => $int($meta['external_links_count'] ?? null),
            'size_bytes' => $int($item['size'] ?? null),
            'load_ms' => $int($item['page_timing']['duration_time'] ?? null),
            'score' => is_numeric($item['onpage_score'] ?? null) ? round((float) $item['onpage_score'], 2) : null,
            'failed_checks' => json_encode($failed),
        ];
    }

    /** @param  array{finished: int, crawling: int, failed: int}  $summary */
    private function stillCrawling(SiteAudit $audit, array &$summary, ?string $note): void
    {
        if ($audit->started_at !== null && $audit->started_at->lt(Carbon::now()->subHours(self::GIVE_UP_AFTER_HOURS))) {
            $this->fail($audit, 'The crawl had not finished after '.self::GIVE_UP_AFTER_HOURS.' hours and was written off.', $summary);

            return;
        }

        $audit->error = $note === null ? null : mb_substr($note, 0, 480);
        $audit->save();
        $summary['crawling']++;
    }

    /** @param  array{finished: int, crawling: int, failed: int}  $summary */
    private function fail(SiteAudit $audit, string $message, array &$summary): void
    {
        $audit->forceFill(['status' => SiteAudit::STATUS_FAILED, 'error' => mb_substr($message, 0, 480), 'finished_at' => now()])->save();
        $summary['failed']++;
    }
}
