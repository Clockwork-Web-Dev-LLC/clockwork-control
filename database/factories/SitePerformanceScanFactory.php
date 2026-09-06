<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SitePerformanceScan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SitePerformanceScan>
 */
class SitePerformanceScanFactory extends Factory
{
    protected $model = SitePerformanceScan::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'scanned_at' => now(),
            'status' => SitePerformanceScan::STATUS_OK,
            'strategy' => SitePerformanceScan::STRATEGY_MOBILE,
            'engine' => SitePerformanceScan::ENGINE_GTMETRIX,
            'performance_score' => $this->faker->numberBetween(50, 100),
        ];
    }

    public function failed(string $error = 'scan timed out'): static
    {
        return $this->state(fn () => [
            'status' => SitePerformanceScan::STATUS_FAILED,
            'error' => $error,
            'performance_score' => null,
        ]);
    }
}
