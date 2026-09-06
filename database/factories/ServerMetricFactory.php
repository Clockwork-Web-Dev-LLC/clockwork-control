<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerMetric>
 */
class ServerMetricFactory extends Factory
{
    protected $model = ServerMetric::class;

    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'recorded_at' => now(),
            'cpu_pct' => $this->faker->randomFloat(2, 0, 100),
            'memory_pct' => $this->faker->randomFloat(2, 0, 100),
            'disk_pct' => $this->faker->randomFloat(2, 0, 100),
            'load_1' => $this->faker->randomFloat(2, 0, 4),
        ];
    }
}
