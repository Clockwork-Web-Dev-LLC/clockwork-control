<?php

namespace Database\Factories;

use App\Models\NotificationLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationLog>
 */
class NotificationLogFactory extends Factory
{
    protected $model = NotificationLog::class;

    public function definition(): array
    {
        return [
            'event' => NotificationLog::EVENT_SITE_DOWN,
            'phone' => '+15555550100',
            'ok' => true,
            'body' => $this->faker->sentence(),
            'sent_at' => now(),
        ];
    }

    public function failed(string $error = 'Twilio error 21211'): static
    {
        return $this->state(fn () => ['ok' => false, 'error' => $error]);
    }
}
