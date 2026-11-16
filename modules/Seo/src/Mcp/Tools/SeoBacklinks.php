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
use Modules\Seo\Backlinks\BacklinkResearch;
use Modules\Seo\Mcp\Support\ResponseEnvelope;
use Modules\Seo\Mcp\Support\SiteFinder;
use Modules\Seo\Models\BacklinkSnapshot;
use Modules\Seo\Models\ReferringDomain;

#[Name('seo_backlinks')]
#[Title('Backlinks and referring domains')]
#[Description('Backlinks for a site and its named competitors: month-by-month totals (backlinks, referring domains, rank, spam score), and the list of domains linking to the site with the ones that are new or lost. From storage; never spends money. Counts come from DataForSEO\'s own link index and will not match another tool\'s. Rank and spam score are DataForSEO\'s 0 to 100 measures, not Authority Score, Domain Rating or a toxicity score. A high spam score alone is not a reason to disavow a link.')]
#[IsReadOnly]
#[IsIdempotent]
class SeoBacklinks extends SeoTool
{
    private const SHOW = ['all', 'new', 'lost', 'high_spam'];

    private const HIGH_SPAM = 60;

    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('A Control site id or a domain.')->required(),
            'show' => $schema->string()->enum(self::SHOW)->description('Which referring domains to list: all live ones, new since the pull before, lost, or with a spam score of 60 or more. Default all.'),
            'days' => $schema->integer()->min(1)->max(400)->description('For new and lost: how far back. Default 45.'),
            'contains' => $schema->string()->max(100)->description('Only referring domains containing this text.'),
            'limit' => $schema->integer()->min(1)->max(1000)->description('Default 100, strongest first.'),
            'months' => $schema->integer()->min(1)->max(36)->description('Months of totals history. Default 12.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($this->caller($request) === null) {
            return Response::error(self::NO_USER);
        }

        $profile = SiteFinder::profile($request->get('site'));
        if (is_string($profile)) {
            return Response::error($profile);
        }

        $own = BacklinkResearch::domain((string) $profile->site->domain);
        $domains = array_values(array_unique([$own, ...array_map([BacklinkResearch::class, 'domain'], array_filter((array) ($profile->competitors ?? []), 'is_string'))]));
        $months = max(1, min(36, (int) $request->get('months', 12)));

        $snapshots = BacklinkSnapshot::query()->whereIn('domain', $domains)
            ->where('month', '>=', now('UTC')->startOfMonth()->subMonths($months - 1)->toDateString())
            ->orderBy('month')->get()->groupBy('domain');

        $totals = [];
        $missing = [];
        foreach ($domains as $domain) {
            $history = $snapshots->get($domain, collect());
            $latest = $history->last();
            if ($latest === null) {
                $missing[] = $domain;
            }
            $totals[] = [
                'domain' => $domain, 'is_client' => $domain === $own, 'latest_month' => $latest?->month->format('Y-m'),
                'backlinks' => $latest?->backlinks, 'referring_domains' => $latest?->referring_domains, 'rank' => $latest?->rank, 'spam_score' => $latest?->spam_score,
                'broken_backlinks' => $latest?->broken_backlinks,
                'history' => $history->map(fn (BacklinkSnapshot $s) => [$s->month->format('Y-m'), $s->backlinks, $s->referring_domains, $s->rank])->values()->all(),
            ];
        }

        $show = in_array($request->get('show'), self::SHOW, true) ? (string) $request->get('show') : 'all';
        $since = now()->subDays(max(1, min(400, (int) $request->get('days', 45))));
        $limit = max(1, min(1000, (int) $request->get('limit', 100)));
        $base = ReferringDomain::query()->where('domain', $own);
        $live = (clone $base)->whereNull('lost_at')->count();
        $pulled = (clone $base)->max('seen_at');

        $query = match ($show) {
            'new' => (clone $base)->whereNull('lost_at')->where('found_at', '>=', $since),
            'lost' => (clone $base)->where('lost_at', '>=', $since),
            'high_spam' => (clone $base)->whereNull('lost_at')->where('spam_score', '>=', self::HIGH_SPAM),
            default => (clone $base)->whereNull('lost_at'),
        };
        if (filled($request->get('contains'))) {
            $text = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower((string) $request->get('contains'))).'%';
            $query->whereRaw("LOWER(referring_domain) LIKE ? ESCAPE '!'", [$text]);
        }
        $matching = (clone $query)->count();
        $rows = $query->orderByDesc('rank')->orderBy('referring_domain')->limit($limit)->get()->map(fn (ReferringDomain $r) => [
            $r->referring_domain, $r->rank, $r->backlinks, $r->spam_score, $r->first_seen?->toDateString(), $r->found_at?->toDateString(), $r->lost_at?->toDateString(),
        ])->all();

        $caveats = [
            ['code' => 'provider_index', 'message' => 'Link counts come from DataForSEO\'s own index. They will differ from another tool\'s and must not share a trend line with them.'],
            ['code' => 'provider_scores', 'message' => 'Rank and spam score are DataForSEO\'s 0 to 100 measures. Do not call them Authority Score, DR, DA or toxicity. A high spam score alone is not a reason to disavow.'],
        ];
        if ($missing !== []) {
            $caveats[] = ['code' => 'not_counted_yet', 'message' => 'No stored totals for: '.implode(', ', $missing).sprintf('. Each costs about $%.3f with seo_domain_research_fetch (what "backlink_summary"); clients and named competitors are counted automatically on the 7th of each month.', BacklinkResearch::summaryCost())];
        }
        if ($live === 0 && $pulled === null) {
            $caveats[] = ['code' => 'no_referring_domains', 'message' => sprintf('No referring-domain list is stored for %s. One costs about $%.2f with seo_domain_research_fetch (what "referring_domains").', $own, BacklinkResearch::referringCost())];
        } elseif ($live >= BacklinkResearch::MAX_DOMAINS) {
            $caveats[] = ['code' => 'top_1000_only', 'message' => 'Only the 1,000 strongest referring domains are stored. With a list that long, lost domains cannot be told from ones past the cut, so "lost" may be empty.'];
        }
        if (in_array($show, ['new', 'lost'], true)) {
            $caveats[] = ['code' => 'between_pulls', 'message' => 'New and lost are worked out between Control\'s own monthly pulls, so the dates are when Control noticed, not the day the link changed.'];
        }

        $client = $totals[0];

        return Response::structured(ResponseEnvelope::make(
            summary: ($client['referring_domains'] === null
                ? "{$own}: no backlink totals stored yet."
                : "{$own}: ".number_format((int) $client['backlinks']).' backlinks from '.number_format($client['referring_domains'])." referring domains ({$client['latest_month']}, DataForSEO index).")
                .' Listing '.count($rows)." of {$matching} {$show} referring domains.",
            profile: $profile,
            period: ['months' => $months, 'referring_domains_pulled' => $pulled !== null ? substr((string) $pulled, 0, 10) : null],
            dataState: null,
            finalAfter: null,
            caveats: $caveats,
            data: [
                'history_columns' => ['month', 'backlinks', 'referring_domains', 'rank'],
                'totals' => $totals,
                'referring_domains' => [
                    'show' => $show,
                    'live_stored' => $live,
                    'columns' => ['domain', 'rank', 'backlinks', 'spam_score', 'first_seen_by_provider', 'found_by_control', 'lost'],
                    'rows' => $rows,
                    'rows_matching' => $matching,
                    'truncated' => $matching > count($rows),
                ],
            ],
        ));
    }
}
