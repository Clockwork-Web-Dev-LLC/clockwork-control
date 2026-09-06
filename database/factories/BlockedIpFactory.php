<?php

namespace Database\Factories;

use App\Models\BlockedIp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlockedIp>
 */
class BlockedIpFactory extends Factory
{
    protected $model = BlockedIp::class;

    public function definition(): array
    {
        return [
            'ip' => $this->faker->unique()->ipv4(),
            'source' => BlockedIp::SOURCE_LLAR,
            'decision' => BlockedIp::DECISION_AUTO,
            'banned_at' => now(),
        ];
    }

    public function unbanned(): static
    {
        return $this->state(fn () => ['unbanned_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subHour()]);
    }
}
