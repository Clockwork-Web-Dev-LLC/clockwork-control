<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteTrafficDaily;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteTrafficDaily>
 */
class SiteTrafficDailyFactory extends Factory
{
    protected $model = SiteTrafficDaily::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'date' => now()->toDateString(),
            'requests' => $this->faker->numberBetween(100, 10000),
            'unique_ips' => $this->faker->numberBetween(50, 1000),
            'visits' => $this->faker->numberBetween(50, 1000),
            'bytes_sent' => $this->faker->numberBetween(1000000, 100000000),
            'status_2xx' => $this->faker->numberBetween(80, 100),
            'status_3xx' => $this->faker->numberBetween(0, 10),
            'status_4xx' => $this->faker->numberBetween(0, 10),
            'status_5xx' => 0,
        ];
    }
}
