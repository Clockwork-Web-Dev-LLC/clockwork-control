<?php

namespace Database\Factories;

use App\Models\ReviewQueueEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewQueueEntry>
 */
class ReviewQueueEntryFactory extends Factory
{
    protected $model = ReviewQueueEntry::class;

    public function definition(): array
    {
        return [
            'ip' => $this->faker->unique()->ipv4(),
            'source' => ReviewQueueEntry::SOURCE_LLM,
            'llm_verdict' => ReviewQueueEntry::VERDICT_SUSPICIOUS,
            'status' => ReviewQueueEntry::STATUS_PENDING,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => ReviewQueueEntry::STATUS_APPROVED, 'decided_at' => now()]);
    }

    public function dismissed(): static
    {
        return $this->state(fn () => ['status' => ReviewQueueEntry::STATUS_DISMISSED, 'decided_at' => now()]);
    }
}
