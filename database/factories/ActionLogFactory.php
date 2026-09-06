<?php

namespace Database\Factories;

use App\Models\ActionLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActionLog>
 */
class ActionLogFactory extends Factory
{
    protected $model = ActionLog::class;

    public function definition(): array
    {
        return [
            'action_type' => ActionLog::TYPE_UPTIME_TRANSITION,
            'summary' => $this->faker->sentence(),
            'ok' => true,
            'actor' => 'system',
            'ran_at' => now(),
        ];
    }

    public function failed(string $error = 'something went wrong'): static
    {
        return $this->state(fn () => ['ok' => false, 'error' => $error]);
    }
}
