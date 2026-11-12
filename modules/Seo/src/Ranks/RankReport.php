<?php

namespace Modules\Seo\Ranks;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Seo\Models\RankCheck;
use Modules\Seo\Models\TrackedKeyword;

/**
 * A site's tracked keywords with their latest position and the change from
 * the check before. Read from stored checks only; never calls DataForSEO.
 */
class RankReport
{
    /**
     * @return array{rows: list<array<string, mixed>>, summary: array{tracked: int, active: int, checked: int, top3: int, top10: int, top30: int, not_found: int, improved: int, declined: int, last_checked: ?string}}
     */
    public function forSite(int $siteId, int $lookbackDays = 120): array
    {
        $keywords = TrackedKeyword::query()->where('site_id', $siteId)->orderBy('keyword')->orderBy('device')->get();

        // Newest first, so the first two rows per keyword are "now" and "before".
        $checks = RankCheck::query()
            ->where('site_id', $siteId)
            ->where('checked_on', '>=', CarbonImmutable::now('UTC')->subDays($lookbackDays)->toDateString())
            ->orderByDesc('checked_on')
            ->get()
            ->groupBy('tracked_keyword_id');

        $summary = ['tracked' => $keywords->count(), 'active' => 0, 'checked' => 0, 'top3' => 0, 'top10' => 0, 'top30' => 0, 'not_found' => 0, 'improved' => 0, 'declined' => 0, 'last_checked' => null];
        $rows = [];

        foreach ($keywords as $keyword) {
            /** @var Collection<int, RankCheck> $history */
            $history = $checks->get($keyword->id, collect());
            $latest = $history->get(0);
            $before = $history->get(1);

            // A change needs a position on both days. Appearing or dropping
            // out is reported in words, not as a made-up number.
            $change = ($latest?->position !== null && $before?->position !== null) ? $before->position - $latest->position : null;
            $movement = match (true) {
                $latest === null || $before === null => null,
                $latest->position !== null && $before->position === null => 'entered',
                $latest->position === null && $before->position !== null => 'dropped_out',
                default => null,
            };

            if ($keyword->is_active) {
                $summary['active']++;
                if ($latest !== null) {
                    $summary['checked']++;
                    $position = $latest->position;
                    $summary['top3'] += (int) ($position !== null && $position <= 3);
                    $summary['top10'] += (int) ($position !== null && $position <= 10);
                    $summary['top30'] += (int) ($position !== null && $position <= 30);
                    $summary['not_found'] += (int) ($position === null);
                    $summary['improved'] += (int) (($change !== null && $change > 0) || $movement === 'entered');
                    $summary['declined'] += (int) (($change !== null && $change < 0) || $movement === 'dropped_out');
                    $date = $latest->checked_on->toDateString();
                    $summary['last_checked'] = max($summary['last_checked'] ?? $date, $date);
                }
            }

            $rows[] = [
                'id' => $keyword->id,
                'keyword' => $keyword->keyword,
                'device' => $keyword->device,
                'place' => $keyword->location_name,
                'location_code' => $keyword->location_code,
                'is_active' => $keyword->is_active,
                'checked_on' => $latest?->checked_on->toDateString(),
                'position' => $latest?->position,
                'depth' => $latest?->depth,
                'url' => $latest?->url,
                'previous_position' => $before?->position,
                'previous_checked_on' => $before?->checked_on->toDateString(),
                'change' => $change,
                'movement' => $movement,
                'pages_ranking' => $latest?->pages_ranking,
                'has_ai_overview' => $latest?->has_ai_overview,
                'cited_in_ai_overview' => $latest?->cited_in_ai_overview,
                'competitors' => $latest?->competitors ?? [],
                'best_position' => $history->pluck('position')->filter()->min(),
            ];
        }

        return ['rows' => $rows, 'summary' => $summary];
    }
}
