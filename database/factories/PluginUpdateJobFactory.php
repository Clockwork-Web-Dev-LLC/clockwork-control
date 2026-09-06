<?php

namespace Database\Factories;

use App\Models\PluginUpdateJob;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PluginUpdateJob>
 */
class PluginUpdateJobFactory extends Factory
{
    protected $model = PluginUpdateJob::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'target_kind' => PluginUpdateJob::KIND_PLUGIN,
            'target_slug' => $this->faker->unique()->slug(2),
            'target_name' => $this->faker->words(2, true),
            'status' => PluginUpdateJob::STATUS_PENDING,
            'batch_id' => $this->faker->uuid(),
            'queued_at' => now(),
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => ['status' => PluginUpdateJob::STATUS_RUNNING, 'started_at' => now()]);
    }

    public function failed(string $error = 'update failed'): static
    {
        return $this->state(fn () => [
            'status' => PluginUpdateJob::STATUS_FAILED,
            'error' => $error,
            'completed_at' => now(),
        ]);
    }

    public function complete(): static
    {
        return $this->state(fn () => ['status' => PluginUpdateJob::STATUS_COMPLETE, 'completed_at' => now()]);
    }
}
