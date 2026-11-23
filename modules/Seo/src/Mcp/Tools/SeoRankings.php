<?php

namespace Modules\Seo\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Seo\Mcp\Support\ResponseEnvelope;
use Modules\Seo\Mcp\Support\SiteFinder;
use Modules\Seo\Ranks\RankReport;
use Modules\Seo\Ranks\RankSettings;

#[Name('seo_rankings')]
#[Title('Google rankings for tracked keywords')]
#[Description('Where one site ranks on Google for its tracked keywords: latest position, the check before, the change, the ranking page, competitor positions and AI Overview. From stored checks; never spends money. A null position with a checked_on date means "checked, not within the depth looked at". A null checked_on means not checked yet. Neither is position 0. For one keyword over time use seo_rank_history.')]
#[IsReadOnly]
#[IsIdempotent]
class SeoRankings extends SeoTool
{
    private const ONLY = ['all', 'top3', 'top10', 'improved', 'declined', 'not_found', 'ai_overview', 'unchecked'];

    private const COLUMNS = ['keyword_id', 'keyword', 'device', 'place', 'checked_on', 'position', 'previous_position', 'change', 'movement', 'url', 'pages_ranking', 'has_ai_overview', 'cited_in_ai_overview', 'best_position'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A Control site id or a domain.')->required(),
            'only' => $schema->string()->enum(self::ONLY)->description('Filter the rows. improved and declined compare the latest check with the one before. Default all.'),
            'device' => $schema->string()->enum(['desktop', 'mobile'])->description('Default: both.'),
            'include_paused' => $schema->boolean()->description('Include keywords that are no longer checked. Default false.'),
            'limit' => $schema->integer()->min(1)->max(1000)->description('Default 200. Best positions first.'),
        ];
    }

    public function handle(Request $request, RankReport $report, RankSettings $settings): Response|ResponseFactory
    {
        if ($this->caller($request) === null) {
            return Response::error(self::NO_USER);
        }

        $profile = SiteFinder::profile($request->get('site'));
        if (is_string($profile)) {
            return Response::error($profile);
        }

        $only = in_array($request->get('only'), self::ONLY, true) ? (string) $request->get('only') : 'all';
        $device = in_array($request->get('device'), ['desktop', 'mobile'], true) ? (string) $request->get('device') : null;
        $includePaused = (bool) $request->get('include_paused', false);
        $limit = max(1, min(1000, (int) $request->get('limit', 200)));

        $data = $report->forSite($profile->site_id);
        $competitors = array_values(array_filter((array) ($profile->competitors ?? []), 'is_string'));

        $rows = array_values(array_filter($data['rows'], function (array $row) use ($only, $device, $includePaused): bool {
            if (! $includePaused && ! $row['is_active']) {
                return false;
            }
            if ($device !== null && $row['device'] !== $device) {
                return false;
            }

            return match ($only) {
                'top3' => $row['position'] !== null && $row['position'] <= 3,
                'top10' => $row['position'] !== null && $row['position'] <= 10,
                'improved' => ($row['change'] !== null && $row['change'] > 0) || $row['movement'] === 'entered',
                'declined' => ($row['change'] !== null && $row['change'] < 0) || $row['movement'] === 'dropped_out',
                'not_found' => $row['checked_on'] !== null && $row['position'] === null,
                'ai_overview' => (bool) $row['has_ai_overview'],
                'unchecked' => $row['checked_on'] === null,
                default => true,
            };
        }));

        // Ranked first by position, then not found, then not checked.
        usort($rows, fn (array $a, array $b) => [$a['position'] ?? 1000 + (int) ($a['checked_on'] === null), $a['keyword']] <=> [$b['position'] ?? 1000 + (int) ($b['checked_on'] === null), $b['keyword']]);

        $total = count($rows);
        $rows = array_slice($rows, 0, $limit);

        $columns = [...self::COLUMNS, ...array_map(fn (string $c) => "competitor:{$c}", $competitors)];
        $table = array_map(function (array $row) use ($competitors): array {
            $out = [];
            foreach (self::COLUMNS as $column) {
                $out[] = $column === 'keyword_id' ? $row['id'] : ($column === 'url' ? ResponseEnvelope::clip($row['url'], 300) : $row[$column]);
            }
            foreach ($competitors as $competitor) {
                $out[] = $row['competitors'][$competitor] ?? null;
            }

            return $out;
        }, $rows);

        $summary = $data['summary'];
        $depth = $settings->depth();
        $caveats = [
            ['code' => 'one_check_one_place', 'message' => 'Each position is one check from one place on one device. Google shows different results to different people, so treat a move of one or two places as noise.'],
            ['code' => 'not_found_is_not_zero', 'message' => "A null position on a checked keyword means the site was not in the top {$depth} looked at. Say \"not in the top {$depth}\", never position 0 or 100."],
            ['code' => 'own_history_only', 'message' => 'Positions are comparable with earlier checks in Control only, not with figures from another rank tracker.'],
        ];
        if ($summary['active'] === 0) {
            $caveats[] = ['code' => 'nothing_tracked', 'message' => 'No keywords are tracked for this site. Someone can add them on its Rankings page in Control.'];
        } elseif ($summary['checked'] === 0) {
            $caveats[] = ['code' => 'no_checks_yet', 'message' => 'Keywords are tracked but no check has come back yet. Checks are queued hourly and need a DataForSEO login.'];
        }

        $domain = $profile->site->domain ?? 'Site';

        return Response::structured(ResponseEnvelope::make(
            summary: $summary['checked'] === 0
                ? "{$domain}: {$summary['active']} keywords tracked, none checked yet."
                : "{$domain}: {$summary['checked']} of {$summary['active']} tracked keywords checked (latest {$summary['last_checked']}). {$summary['top3']} in the top 3, {$summary['top10']} in the top 10, {$summary['not_found']} not in the top {$depth}. {$summary['improved']} moved up and {$summary['declined']} moved down since the check before.",
            profile: $profile,
            period: ['latest_check' => $summary['last_checked'], 'frequency' => $settings->frequency(), 'depth' => $depth],
            dataState: null,
            finalAfter: null,
            caveats: $caveats,
            data: [
                'totals' => $summary,
                'competitors' => $competitors,
                'columns' => $columns,
                'rows' => $table,
                'rows_matching' => $total,
                'truncated' => $total > count($table),
            ],
        ));
    }
}
