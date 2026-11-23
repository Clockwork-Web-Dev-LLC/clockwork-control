<?php

namespace Modules\Seo\Audit;

use App\Support\Settings;
use Modules\Seo\DataForSeo\Pricing;

/**
 * How often sites are crawled, how many pages at most, and whether pages
 * are rendered with JavaScript (ten times the price). The page cap is also
 * the spending cap for one crawl.
 */
class SiteAuditSettings
{
    public const FREQUENCIES = ['off' => null, 'weekly' => 7, 'monthly' => 30];

    public const PAGE_CAPS = [100, 250, 500, 1000, 2000, 5000];

    public const KEY = 'clockwork.seo.site_audit';

    public function __construct(private readonly Settings $settings) {}

    public function frequency(): string
    {
        $value = (string) ($this->stored()['frequency'] ?? config('clockwork.seo.site_audit.frequency', 'monthly'));

        return array_key_exists($value, self::FREQUENCIES) ? $value : 'monthly';
    }

    /** Days between scheduled crawls, or null when scheduled crawls are off. */
    public function intervalDays(): ?int
    {
        return self::FREQUENCIES[$this->frequency()];
    }

    public function maxPages(): int
    {
        $value = (int) ($this->stored()['max_pages'] ?? config('clockwork.seo.site_audit.max_pages', 500));

        return in_array($value, self::PAGE_CAPS, true) ? $value : 500;
    }

    public function javascript(): bool
    {
        return (bool) ($this->stored()['javascript'] ?? false);
    }

    public function save(string $frequency, int $maxPages, bool $javascript): void
    {
        $this->settings->put(self::KEY, [
            'frequency' => array_key_exists($frequency, self::FREQUENCIES) ? $frequency : 'monthly',
            'max_pages' => in_array($maxPages, self::PAGE_CAPS, true) ? $maxPages : 500,
            'javascript' => $javascript,
        ]);
    }

    /** The most one crawl can cost at these settings. */
    public function crawlCost(): float
    {
        return Pricing::onPage($this->maxPages(), $this->javascript());
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        return (array) $this->settings->get(self::KEY, []);
    }
}
