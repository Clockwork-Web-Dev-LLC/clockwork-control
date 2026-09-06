<?php

namespace Database\Factories;

use App\Models\PluginUpdateIgnore;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PluginUpdateIgnore>
 */
class PluginUpdateIgnoreFactory extends Factory
{
    protected $model = PluginUpdateIgnore::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'target_kind' => 'plugin',
            'target_slug' => $this->faker->unique()->slug(2),
            'ignored_at' => now(),
        ];
    }
}
