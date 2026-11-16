<?php

namespace Modules\Seo\Backlinks;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\DataForSeo\Feature;
use Modules\Seo\DataForSeo\Pricing;
use Modules\Seo\Models\BacklinkSnapshot;
use Modules\Seo\Models\ReferringDomain;
use Modules\Seo\Ranks\SerpParser;
use Throwable;

/**
 * Backlinks from DataForSEO: a domain's totals, and the domains that link
 * to it.
 *
 * Every backlink index is different, so these counts will not match
 * another tool's. Rank and spam score are DataForSEO's own 0 to 100
 * measures, not an authority or toxicity score from elsewhere.
 */
class BacklinkResearch
{
    public const SUMMARY_PATH = '/v3/backlinks/summary/live';

    public const REFERRING_PATH = '/v3/backlinks/referring_domains/live';

    public const MAX_DOMAINS = 1000;

    public function __construct(private readonly DataForSeoClient $client) {}

    public static function domain(string $value): string
    {
        return SerpParser::host($value);
    }

    public static function summaryCost(): float
    {
        return Pricing::backlinks(1);
    }

    public static function referringCost(int $limit = self::MAX_DOMAINS): float
    {
        return Pricing::backlinks(max(1, min(self::MAX_DOMAINS, $limit)));
    }

    /**
     * Buy this month's backlink totals for a domain.
     *
     * @return array{ok: bool, snapshot: ?BacklinkSnapshot, cost: float, error: ?string, refused: bool}
     */
    public function snapshot(string $domain, ?int $siteId = null, ?User $user = null): array
    {
        $domain = self::domain($domain);

        $result = $this->client->post(self::SUMMARY_PATH, [[
            'target' => $domain,
            'include_subdomains' => true,
            // Links from the site to itself say nothing about its standing.
            'exclude_internal_backlinks' => true,
            'internal_list_limit' => 1,
            'backlinks_status_type' => 'live',
            'rank_scale' => 'one_hundred',
        ]], Feature::BACKLINKS, self::summaryCost(), $siteId, $user);

        if (! $result->ok) {
            return ['ok' => false, 'snapshot' => null, 'cost' => 0.0, 'error' => $result->error, 'refused' => $result->refused];
        }

        $data = $result->firstResult()[0] ?? [];
        $int = fn (string $key): ?int => isset($data[$key]) && is_numeric($data[$key]) ? max(0, (int) $data[$key]) : null;

        $snapshot = BacklinkSnapshot::query()->updateOrCreate(
            ['domain' => $domain, 'month' => CarbonImmutable::now('UTC')->startOfMonth()->toDateString(), 'provider' => DataForSeoClient::PROVIDER],
            [
                'backlinks' => $int('backlinks'),
                'referring_domains' => $int('referring_domains'),
                'referring_main_domains' => $int('referring_main_domains'),
                'referring_domains_nofollow' => $int('referring_domains_nofollow'),
                'broken_backlinks' => $int('broken_backlinks'),
                'rank' => ($rank = $int('rank')) === null ? null : min(100, $rank),
                'spam_score' => ($spam = $int('backlinks_spam_score')) === null ? null : min(100, $spam),
                'fetched_at' => now(),
            ],
        );

        return ['ok' => true, 'snapshot' => $snapshot, 'cost' => $result->cost, 'error' => null, 'refused' => false];
    }

    /**
     * Buy the domains linking to a domain, strongest first, and reconcile
     * with what was stored: new ones are added, ones that have gone are
     * marked lost.
     *
     * A domain is only marked lost when the pull was complete. If it
     * stopped at the limit, a missing domain may simply be past the cut.
     *
     * @return array{ok: bool, stored: int, new: int, lost: int, complete: bool, total: ?int, cost: float, error: ?string, refused: bool}
     */
    public function pullReferringDomains(string $domain, int $limit = self::MAX_DOMAINS, ?int $siteId = null, ?User $user = null): array
    {
        $domain = self::domain($domain);
        $limit = max(1, min(self::MAX_DOMAINS, $limit));
        $empty = ['ok' => false, 'stored' => 0, 'new' => 0, 'lost' => 0, 'complete' => false, 'total' => null, 'cost' => 0.0, 'error' => null, 'refused' => false];

        $result = $this->client->post(self::REFERRING_PATH, [[
            'target' => $domain,
            'limit' => $limit,
            'order_by' => ['rank,desc'],
            'include_subdomains' => true,
            'exclude_internal_backlinks' => true,
            'internal_list_limit' => 1,
            'backlinks_status_type' => 'live',
            'rank_scale' => 'one_hundred',
        ]], Feature::BACKLINKS, self::referringCost($limit), $siteId, $user);

        if (! $result->ok) {
            return ['error' => $result->error, 'refused' => $result->refused] + $empty;
        }

        $data = $result->firstResult()[0] ?? [];
        $now = Carbon::now();
        $seen = [];

        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $item) {
            $referring = self::domain((string) ($item['domain'] ?? ''));
            if ($referring === '' || $referring === $domain || mb_strlen($referring) > 191 || isset($seen[$referring])) {
                continue;
            }
            $seen[$referring] = [
                'rank' => isset($item['rank']) && is_numeric($item['rank']) ? min(100, max(0, (int) $item['rank'])) : null,
                'backlinks' => isset($item['backlinks']) && is_numeric($item['backlinks']) ? max(0, (int) $item['backlinks']) : null,
                'spam_score' => isset($item['backlinks_spam_score']) && is_numeric($item['backlinks_spam_score']) ? min(100, max(0, (int) $item['backlinks_spam_score'])) : null,
                'first_seen' => self::date($item['first_seen'] ?? null),
            ];
        }

        $total = isset($data['total_count']) && is_numeric($data['total_count']) ? (int) $data['total_count'] : null;
        $complete = $total !== null ? $total <= $limit : count($seen) < $limit;
        $new = 0;
        $lost = 0;

        DB::transaction(function () use ($domain, $seen, $now, $complete, &$new, &$lost): void {
            $existing = ReferringDomain::query()->where('domain', $domain)->where('provider', DataForSeoClient::PROVIDER)->get()->keyBy('referring_domain');
            $firstPull = $existing->isEmpty();

            foreach ($seen as $referring => $attributes) {
                $row = $existing->get($referring);
                if ($row === null) {
                    ReferringDomain::query()->create($attributes + [
                        'domain' => $domain, 'provider' => DataForSeoClient::PROVIDER, 'referring_domain' => $referring,
                        // On the first pull everything is "found" at once;
                        // none of it is news.
                        'found_at' => $firstPull ? null : $now, 'seen_at' => $now,
                    ]);
                    $new += $firstPull ? 0 : 1;

                    continue;
                }
                // Back after being lost counts as new again.
                if ($row->lost_at !== null) {
                    $attributes['found_at'] = $now;
                    $new++;
                }
                $row->forceFill($attributes + ['seen_at' => $now, 'lost_at' => null])->save();
            }

            if ($complete) {
                $gone = $existing->filter(fn (ReferringDomain $r) => $r->lost_at === null && ! isset($seen[$r->referring_domain]));
                $lost = $gone->count();
                ReferringDomain::query()->whereIn('id', $gone->pluck('id'))->update(['lost_at' => $now]);
            }
        });

        return ['ok' => true, 'stored' => count($seen), 'new' => $new, 'lost' => $lost, 'complete' => $complete, 'total' => $total, 'cost' => $result->cost, 'error' => null, 'refused' => false];
    }

    public function hasSnapshotThisMonth(string $domain): bool
    {
        return BacklinkSnapshot::query()->where('domain', self::domain($domain))
            ->where('month', CarbonImmutable::now('UTC')->startOfMonth()->toDateString())->exists();
    }

    public function latestSnapshot(string $domain): ?BacklinkSnapshot
    {
        return BacklinkSnapshot::query()->where('domain', self::domain($domain))->orderByDesc('month')->first();
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
