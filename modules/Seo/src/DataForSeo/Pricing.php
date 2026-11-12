<?php

namespace Modules\Seo\DataForSeo;

/**
 * What we EXPECT calls to cost, used for quotes and for the budget check
 * before a call. The ledger records what DataForSEO actually charged.
 *
 * These figures were read from search excerpts of DataForSEO's pricing
 * pages in November 2026, not from the pages themselves, and are not
 * confirmed. They are deliberately a little high: a quote that turns out
 * too high is harmless, one that is too low breaks trust. Correct them
 * here once the first real calls show the true cost.
 */
final class Pricing
{
    /** Labs keyword_overview: per call, and per keyword returned. */
    public const LABS_PER_TASK = 0.012;

    public const LABS_PER_ITEM = 0.00012;

    /** Google Ads search volume (the only city-level source): per call of up to 1,000 keywords. */
    public const ADS_VOLUME_PER_TASK = 0.108;

    public const KEYWORD_OVERVIEW_MAX = 700;

    public const ADS_VOLUME_MAX = 1000;

    /** @return array{calls: int, usd: float} */
    public static function keywordOverview(int $keywords): array
    {
        $calls = (int) ceil($keywords / self::KEYWORD_OVERVIEW_MAX);

        return ['calls' => $calls, 'usd' => round($calls * self::LABS_PER_TASK + $keywords * self::LABS_PER_ITEM, 4)];
    }

    /** @return array{calls: int, usd: float} */
    public static function cityVolume(int $keywords): array
    {
        $calls = (int) ceil($keywords / self::ADS_VOLUME_MAX);

        return ['calls' => $calls, 'usd' => round($calls * self::ADS_VOLUME_PER_TASK, 4)];
    }
}
