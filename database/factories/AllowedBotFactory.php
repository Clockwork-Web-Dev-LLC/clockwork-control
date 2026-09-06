<?php

namespace Database\Factories;

use App\Models\AllowedBot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AllowedBot>
 */
class AllowedBotFactory extends Factory
{
    protected $model = AllowedBot::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word().'bot',
            'ua_pattern' => $this->faker->unique()->word().'bot/1.0',
            'pattern_type' => AllowedBot::PATTERN_SUBSTRING,
            'source' => AllowedBot::SOURCE_ARCJET,
            'synced_at' => now(),
        ];
    }
}
