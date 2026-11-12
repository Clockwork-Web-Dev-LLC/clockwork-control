<?php

namespace Modules\Seo\Keywords;

use Modules\Seo\Models\SiteProfile;

/**
 * Where and in which language people search: a DataForSEO location code
 * and language code, plus an optional city for local volume.
 */
final readonly class Market
{
    /** United States. */
    public const DEFAULT_LOCATION_CODE = 2840;

    public const DEFAULT_LANGUAGE_CODE = 'en';

    public function __construct(
        public int $locationCode = self::DEFAULT_LOCATION_CODE,
        public string $languageCode = self::DEFAULT_LANGUAGE_CODE,
        public ?string $city = null,
    ) {}

    public static function forProfile(?SiteProfile $profile): self
    {
        return new self(
            (int) ($profile?->market_location_code ?: self::DEFAULT_LOCATION_CODE),
            (string) ($profile?->market_language_code ?: self::DEFAULT_LANGUAGE_CODE),
            filled($profile?->market_city) ? (string) $profile->market_city : null,
        );
    }

    /** The cache key for country-level metrics. The city is not part of it. */
    public function key(): string
    {
        return "{$this->locationCode}-{$this->languageCode}";
    }

    /** @return array{location_code: int, language_code: string, city: ?string} */
    public function toArray(): array
    {
        return ['location_code' => $this->locationCode, 'language_code' => $this->languageCode, 'city' => $this->city];
    }
}
