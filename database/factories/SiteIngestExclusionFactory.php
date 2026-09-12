<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteIngestExclusion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteIngestExclusion>
 */
class SiteIngestExclusionFactory extends Factory
{
    protected $model = SiteIngestExclusion::class;

    public function definition(): array
    {
        return [
            'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP,
            'provider_site_id' => (string) $this->faker->unique()->numberBetween(1000, 999999),
            'domain' => $this->faker->unique()->domainName(),
            'site_id' => null,
            'reason' => null,
            'excluded_by' => null,
        ];
    }

    public function pressable(): static
    {
        return $this->state(fn () => [
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
        ]);
    }
}
