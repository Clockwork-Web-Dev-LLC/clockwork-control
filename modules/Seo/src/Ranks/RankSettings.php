<?php

namespace Modules\Seo\Ranks;

use App\Support\Settings;
use Modules\Seo\DataForSeo\Pricing;

/**
 * How often and how deep positions are checked. These two choices decide
 * most of the DataForSEO bill, so they are settings, not code.
 */
class RankSettings
{
    public const FREQUENCIES = ['daily' => 1, 'weekly' => 7];

    public const DEPTHS = [10, 20, 30, 50, 100];

    public const KEY_FREQUENCY = 'clockwork.seo.rank_tracking.frequency';

    public const KEY_DEPTH = 'clockwork.seo.rank_tracking.depth';

    public function __construct(private readonly Settings $settings) {}

    public function frequency(): string
    {
        $value = (string) $this->settings->get(self::KEY_FREQUENCY, config('clockwork.seo.rank_tracking.frequency', 'weekly'));

        return array_key_exists($value, self::FREQUENCIES) ? $value : 'weekly';
    }

    /** Days between checks of the same keyword. */
    public function intervalDays(): int
    {
        return self::FREQUENCIES[$this->frequency()];
    }

    public function depth(): int
    {
        $value = (int) $this->settings->get(self::KEY_DEPTH, config('clockwork.seo.rank_tracking.depth', 30));

        return in_array($value, self::DEPTHS, true) ? $value : 30;
    }

    public function save(string $frequency, int $depth): void
    {
        $this->settings->putMany([
            self::KEY_FREQUENCY => array_key_exists($frequency, self::FREQUENCIES) ? $frequency : 'weekly',
            self::KEY_DEPTH => in_array($depth, self::DEPTHS, true) ? $depth : 30,
        ]);
    }

    /** What a month of tracking is expected to cost for this many keywords. */
    public function monthlyEstimate(int $keywords): float
    {
        $checks = $keywords * (30.4 / $this->intervalDays());

        return round($checks * Pricing::serp($this->depth()), 2);
    }
}
