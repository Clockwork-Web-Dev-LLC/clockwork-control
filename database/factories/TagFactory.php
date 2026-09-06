<?php

namespace Database\Factories;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'sort_order' => 0,
        ];
    }

    public function staging(): static
    {
        return $this->state(fn () => ['name' => 'staging', 'slug' => 'staging']);
    }
}
