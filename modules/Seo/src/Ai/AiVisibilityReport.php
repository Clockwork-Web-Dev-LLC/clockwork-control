<?php

namespace Modules\Seo\Ai;

use Carbon\CarbonImmutable;
use Modules\Seo\Models\AiCheck;
use Modules\Seo\Models\AiPrompt;

/**
 * How visible a client is in AI answers, from stored checks: for each
 * engine, the share of its prompts where it was mentioned or cited in the
 * latest round, the same for competitors, and the trend by week.
 */
class AiVisibilityReport
{
    /**
     * @return array{prompts: list<array<string, mixed>>, engines: array<string, array<string, mixed>>, competitors: array<string, array<string, array{mentioned: int, cited: int}>>, weeks: list<array<string, mixed>>, active: int, checked: int, last_checked: ?string}
     */
    public function forSite(int $siteId, int $weeks = 12): array
    {
        $prompts = AiPrompt::query()->where('site_id', $siteId)->orderByDesc('is_active')->orderBy('id')->get();
        $since = CarbonImmutable::now('UTC')->subWeeks($weeks)->startOfWeek()->toDateString();

        $checks = AiCheck::query()->where('site_id', $siteId)->where('checked_on', '>=', $since)->orderByDesc('checked_on')->get();

        // Newest check per prompt and engine.
        $latest = [];
        foreach ($checks as $check) {
            $latest[$check->prompt_id][$check->engine] ??= $check;
        }

        $active = $prompts->where('is_active', true)->pluck('id')->flip();
        $engines = [];
        $competitors = [];
        $lastChecked = null;
        $rows = [];

        foreach ($prompts as $prompt) {
            $perEngine = [];
            foreach (array_keys(AiEngines::ALL) as $engine) {
                $check = $latest[$prompt->id][$engine] ?? null;
                $perEngine[$engine] = $check === null ? null : [
                    'checked_on' => $check->checked_on->toDateString(), 'answered' => $check->answered, 'mentioned' => $check->mentioned, 'cited' => $check->cited,
                ];

                if ($check === null || ! isset($active[$prompt->id])) {
                    continue;
                }

                $engines[$engine] ??= ['label' => AiEngines::label($engine), 'checked' => 0, 'answered' => 0, 'mentioned' => 0, 'cited' => 0];
                $engines[$engine]['checked']++;
                $engines[$engine]['answered'] += (int) $check->answered;
                $engines[$engine]['mentioned'] += (int) $check->mentioned;
                $engines[$engine]['cited'] += (int) $check->cited;
                $date = $check->checked_on->toDateString();
                $lastChecked = max($lastChecked ?? $date, $date);

                foreach ((array) $check->competitors as $domain => $seen) {
                    $competitors[$domain][$engine] ??= ['mentioned' => 0, 'cited' => 0];
                    $competitors[$domain][$engine]['mentioned'] += (int) ($seen['mentioned'] ?? false);
                    $competitors[$domain][$engine]['cited'] += (int) ($seen['cited'] ?? false);
                }
            }

            $rows[] = ['id' => $prompt->id, 'prompt' => $prompt->prompt, 'is_active' => $prompt->is_active, 'engines' => $perEngine];
        }

        foreach ($engines as $engine => $totals) {
            // A rate needs something to divide by; otherwise it is unknown.
            $engines[$engine]['mention_rate'] = $totals['checked'] > 0 ? round($totals['mentioned'] / $totals['checked'] * 100, 1) : null;
            $engines[$engine]['citation_rate'] = $totals['checked'] > 0 ? round($totals['cited'] / $totals['checked'] * 100, 1) : null;
        }

        // By week, every check in that week, all engines together.
        $byWeek = [];
        foreach ($checks as $check) {
            $week = CarbonImmutable::parse($check->checked_on)->startOfWeek()->toDateString();
            $byWeek[$week] ??= ['week' => $week, 'checks' => 0, 'mentioned' => 0, 'cited' => 0];
            $byWeek[$week]['checks']++;
            $byWeek[$week]['mentioned'] += (int) $check->mentioned;
            $byWeek[$week]['cited'] += (int) $check->cited;
        }
        ksort($byWeek);
        $weeksOut = array_map(fn (array $w) => $w + [
            'mention_rate' => round($w['mentioned'] / $w['checks'] * 100, 1),
            'citation_rate' => round($w['cited'] / $w['checks'] * 100, 1),
        ], array_values($byWeek));

        return [
            'prompts' => $rows,
            'engines' => $engines,
            'competitors' => $competitors,
            'weeks' => $weeksOut,
            'active' => $active->count(),
            'checked' => count(array_filter($rows, fn (array $r) => $r['is_active'] && array_filter($r['engines']) !== [])),
            'last_checked' => $lastChecked,
        ];
    }
}
