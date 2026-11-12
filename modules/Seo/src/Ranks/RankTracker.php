<?php

namespace Modules\Seo\Ranks;

use Carbon\CarbonImmutable;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\DataForSeo\Feature;
use Modules\Seo\DataForSeo\Pricing;
use Modules\Seo\DataForSeo\TaskRunner;
use Modules\Seo\Models\ProviderTask;
use Modules\Seo\Models\RankCheck;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Models\TrackedKeyword;
use RuntimeException;

/**
 * Rank tracking: put every due keyword on DataForSEO's queue, then fetch
 * the finished results pages and store where the site ranks.
 *
 * A day that is not checked can never be bought later, so this runs on a
 * schedule and catches up by itself: a keyword is due whenever its last
 * check is older than the chosen interval.
 */
class RankTracker
{
    public const KIND = 'serp_organic';

    public const POST_PATH = '/v3/serp/google/organic/task_post';

    public const GET_PATH = '/v3/serp/google/organic/task_get/advanced/{id}';

    public function __construct(
        private readonly TaskRunner $tasks,
        private readonly RankSettings $settings,
    ) {}

    /**
     * Queue a check for every keyword that is due.
     *
     * @return array{due: int, posted: int, failed: int, cost: float, error: ?string, refused: bool}
     */
    public function postDue(?int $siteId = null): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $cutoff = $today->subDays($this->settings->intervalDays() - 1)->toDateString();
        $depth = $this->settings->depth();
        $summary = ['due' => 0, 'posted' => 0, 'failed' => 0, 'cost' => 0.0, 'error' => null, 'refused' => false];

        $due = TrackedKeyword::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('last_posted_on')->orWhere('last_posted_on', '<', $cutoff))
            // Only clients whose SEO profile is switched on.
            ->whereIn('site_id', SiteProfile::query()->where('is_enabled', true)->select('site_id'))
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->orderBy('site_id')
            ->orderBy('id')
            ->get();

        $summary['due'] = $due->count();

        foreach ($due->groupBy('site_id') as $site => $keywords) {
            $items = [];
            foreach ($keywords as $keyword) {
                $task = [
                    'keyword' => $keyword->keyword,
                    'language_code' => $keyword->language_code,
                    'device' => $keyword->device,
                    'depth' => $depth,
                    // 1 is the standard queue, the cheapest.
                    'priority' => 1,
                ];
                // A city is named; a country is a code. Never both.
                if (filled($keyword->location_name)) {
                    $task['location_name'] = $keyword->location_name;
                } else {
                    $task['location_code'] = $keyword->location_code;
                }

                $items[] = ['task' => $task, 'context' => [
                    'tracked_keyword_id' => $keyword->id,
                    'date' => $today->toDateString(),
                    'depth' => $depth,
                ]];
            }

            $startedAt = now();
            $result = $this->tasks->post(Feature::RANK_TRACKING, self::KIND, self::POST_PATH, $items, Pricing::serp($depth), (int) $site);

            $summary['posted'] += $result['posted'];
            $summary['failed'] += $result['failed'];
            $summary['cost'] += $result['cost'];
            $summary['error'] ??= $result['error'];

            // Mark only the keywords DataForSEO accepted, so a rejected one
            // is tried again on the next run.
            $accepted = ProviderTask::query()
                ->where('provider', DataForSeoClient::PROVIDER)
                ->where('kind', self::KIND)
                ->where('site_id', (int) $site)
                ->where('posted_at', '>=', $startedAt->copy()->subSecond())
                ->get()
                ->map(fn (ProviderTask $t) => (int) ($t->context['tracked_keyword_id'] ?? 0))
                ->filter()
                ->all();

            if ($accepted !== []) {
                TrackedKeyword::query()->whereIn('id', $accepted)->update(['last_posted_on' => $today->toDateString()]);
            }

            if ($result['refused']) {
                // Over budget, or the login is wrong. The next site would
                // get the same answer.
                $summary['refused'] = true;
                break;
            }
        }

        return $summary;
    }

    /**
     * Fetch finished checks and store them.
     *
     * @return array{collected: int, pending: int, failed: int}
     */
    public function collect(int $limit = 2000): array
    {
        return $this->tasks->collect(self::KIND, self::GET_PATH, function (array $task, ProviderTask $row): void {
            $this->store($task, $row);
        }, $limit);
    }

    public function pending(): int
    {
        return $this->tasks->pendingCount(self::KIND);
    }

    /** @param  array<string, mixed>  $task */
    private function store(array $task, ProviderTask $row): void
    {
        $context = (array) $row->context;
        $keyword = TrackedKeyword::query()->with('site')->find((int) ($context['tracked_keyword_id'] ?? 0));

        // The keyword or its site was removed while the check was queued.
        if ($keyword === null || $keyword->site === null) {
            return;
        }

        $result = $task['result'][0] ?? null;
        if (! is_array($result)) {
            throw new RuntimeException('DataForSEO returned no results page.');
        }

        $competitors = array_values(array_filter(
            (array) (SiteProfile::query()->where('site_id', $keyword->site_id)->first()?->competitors ?? []),
            'is_string',
        ));

        $parsed = SerpParser::parse($result, $keyword->site->domain, $competitors);

        RankCheck::query()->updateOrCreate(
            ['tracked_keyword_id' => $keyword->id, 'checked_on' => (string) ($context['date'] ?? now('UTC')->toDateString())],
            ['site_id' => $keyword->site_id] + $parsed,
        );
    }
}
