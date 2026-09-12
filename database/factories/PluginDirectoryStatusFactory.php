<?php

namespace Database\Factories;

use App\Models\PluginDirectoryStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PluginDirectoryStatus>
 */
class PluginDirectoryStatusFactory extends Factory
{
    protected $model = PluginDirectoryStatus::class;

    public function definition(): array
    {
        return [
            'slug' => $this->faker->unique()->slug(2),
            'status' => PluginDirectoryStatus::STATUS_OPEN,
            'reason' => null,
            'closed_date' => null,
            'checked_at' => now(),
        ];
    }

    public function closed(string $reason = 'This plugin has been closed as of 2024-01-01 and is not available for download. Reason: Security Issue.'): static
    {
        return $this->state(fn () => [
            'status' => PluginDirectoryStatus::STATUS_CLOSED,
            'reason' => $reason,
            'closed_date' => '2024-01-01',
        ]);
    }

    public function notFound(): static
    {
        return $this->state(fn () => [
            'status' => PluginDirectoryStatus::STATUS_NOT_FOUND,
            'reason' => null,
            'closed_date' => null,
        ]);
    }

    public function error(): static
    {
        return $this->state(fn () => [
            'status' => PluginDirectoryStatus::STATUS_ERROR,
            'reason' => null,
            'closed_date' => null,
        ]);
    }
}
