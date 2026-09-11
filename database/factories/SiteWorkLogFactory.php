<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteWorkLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteWorkLog>
 */
class SiteWorkLogFactory extends Factory
{
    protected $model = SiteWorkLog::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'worked_on' => $this->faker->date(),
            'hours' => $this->faker->randomElement(['0.50', '1.00', '1.50', '2.00']),
            'description' => $this->faker->sentence(),
            'user_id' => User::factory(),
        ];
    }
}
